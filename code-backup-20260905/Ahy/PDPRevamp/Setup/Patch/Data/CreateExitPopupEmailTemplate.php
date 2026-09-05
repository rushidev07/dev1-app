<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Setup\Patch\Data;

use Magento\Email\Model\Template;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * Creates the exit-popup discount email as an editable row under
 * Marketing > Email Templates, and points the module's config at it.
 *
 * The template already existed as a file
 * (view/frontend/email/exit_popup_discount.html, registered in
 * etc/email_templates.xml). That file is read-only from the admin's point of
 * view: changing a word meant a code change and a deploy. This patch inserts a
 * database copy of it, which the admin grid can edit like any other template.
 *
 * Written as a patch rather than left to a manual "Add New Template" click so the
 * row exists identically on every environment. Clicking through the admin would
 * create it only where someone remembered to, and local/dev/prod would drift.
 *
 * orig_template_code is set to the file template's id, which is what the admin's
 * "Load default template" dropdown uses to offer a reset - without it the row
 * looks like a template with no origin. orig_template_variables drives the
 * "Insert Variable" picker in the editor.
 *
 * Idempotent: re-running finds the existing row by code and leaves its content
 * alone, so an admin's edits are never overwritten by a later setup:upgrade.
 * That is the important property here - a patch that reset the wording on every
 * deploy would be worse than no patch at all.
 */
class CreateExitPopupEmailTemplate implements DataPatchInterface
{
    /**
     * Shown in the Template column of the admin grid.
     */
    public const TEMPLATE_CODE = 'Welcome Discount Code';

    /**
     * The file template this is a copy of - see etc/email_templates.xml.
     */
    private const FILE_TEMPLATE_ID = 'pdprevamp_exit_popup_discount_email';

    private const CONFIG_PATH = 'pdprevamp_exit_popup/general/email_template';

    private ModuleDataSetupInterface $moduleDataSetup;

    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    public function apply(): self
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('email_template');

        // Matched on orig_template_code, not template_code: an admin can rename
        // the row in the grid (and renaming it is a legitimate thing to do), which
        // would make a template_code lookup miss and insert a second copy on the
        // next setup:upgrade. orig_template_code points back at this module's file
        // template and is not editable from the admin, so it stays a stable
        // identity for "the row we created".
        $existingId = (int) $connection->fetchOne(
            $connection->select()
                ->from($table, ['template_id'])
                ->where('orig_template_code = ?', self::FILE_TEMPLATE_ID)
                ->limit(1)
        );

        if ($existingId > 0) {
            // Already present. Deliberately does not touch template_text - an
            // admin may have edited the wording, and a data patch must not undo
            // that. Only make sure the config still points at it.
            $this->pointConfigAt($existingId);

            return $this;
        }

        $connection->insert($table, [
            'template_code' => self::TEMPLATE_CODE,
            'template_text' => $this->getTemplateText(),
            'template_type' => Template::TYPE_HTML,
            'template_subject' => 'Your discount code inside',
            'orig_template_code' => self::FILE_TEMPLATE_ID,
            'orig_template_variables' => $this->getTemplateVariables(),
        ]);

        $this->pointConfigAt((int) $connection->lastInsertId($table));

        return $this;
    }

    /**
     * Sets the module's Email Template setting to this row.
     *
     * Written at default scope only, so a website- or store-level override an
     * admin has chosen is left intact.
     */
    private function pointConfigAt(int $templateId): void
    {
        if ($templateId < 1) {
            return;
        }

        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');

        $existing = $connection->fetchOne(
            $connection->select()
                ->from($table, ['config_id'])
                ->where('path = ?', self::CONFIG_PATH)
                ->where('scope = ?', 'default')
                ->where('scope_id = ?', 0)
                ->limit(1)
        );

        if ($existing) {
            $connection->update(
                $table,
                ['value' => (string) $templateId],
                ['config_id = ?' => (int) $existing]
            );

            return;
        }

        $connection->insert($table, [
            'scope' => 'default',
            'scope_id' => 0,
            'path' => self::CONFIG_PATH,
            'value' => (string) $templateId,
        ]);
    }

    /**
     * The body, matching view/frontend/email/exit_popup_discount.html.
     *
     * Held here rather than read off disk: a data patch runs once and its result
     * is then owned by the admin, so reading the file at apply() time would give
     * the false impression the two stay in sync. They do not - after this runs,
     * the database row is the one that gets sent.
     *
     * The @subject and @vars comment blocks the file carries are not repeated;
     * the row's own template_subject and orig_template_variables columns serve
     * that purpose in the database.
     */
    private function getTemplateText(): string
    {
        return <<<'HTML'
{{template config_path="design/email/header_template"}}

<table><tr><td>
    <p>{{trans "Thanks for signing up!"}}</p>
    <p style="color:#666; font-size:13px;">
        {{trans "You'll need to be signed in to your account to use this code at checkout."}}
    </p>

    {{if product_name}}
    <p>
        {{trans "Here's your exclusive"}} <strong>{{var discount_percent}}%</strong>
        {{trans "off code for"}} <strong>{{var product_name}}</strong>:</p>
    {{else}}
    <p>{{trans "Here's your exclusive"}} <strong>{{var discount_percent}}%</strong> {{trans "off code:"}}</p>
    {{/if}}

    <p style="font-size:22px; font-weight:bold; letter-spacing:2px; text-align:center; padding:16px; border:2px dashed #e85d26; margin:16px 0;">
        {{var coupon_code}}
    </p>
    <p>{{trans "Enter this code at checkout to redeem your discount."}}</p>

    {{depend expires_at}}
    <p style="color:#666; font-size:13px;">
        {{trans "This code expires on %date" date=$expires_at}}
    </p>
    {{/depend}}
</td></tr></table>

{{template config_path="design/email/footer_template"}}
HTML;
    }

    /**
     * Populates the editor's "Insert Variable" picker.
     *
     * expires_at arrives already formatted in the store's timezone and rounded to
     * the hour (ExitPopupCouponMailer::formatExpiry), so a template author should
     * print it as-is rather than try to reformat it.
     */
    private function getTemplateVariables(): string
    {
        return (string) json_encode([
            'var coupon_code' => 'Coupon Code',
            'var discount_percent' => 'Discount Percent',
            'var product_name' => 'Product the code applies to',
            'var expires_at' => 'Expiry (already formatted in the store timezone)',
        ]);
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
