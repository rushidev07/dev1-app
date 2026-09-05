<?php
declare(strict_types=1);

namespace Ahy\PDPRevamp\Plugin;

use Magento\Swatches\Block\Product\Renderer\Configurable;

/**
 * Swaps the PDP swatch-attribute renderer template to remove the
 * border-t/border-b divider lines drawn around each Color/Size row.
 *
 * Configurable::toHtml() unconditionally resets its own template via
 * setTemplate($this->getRendererTemplate()) at the start of its method
 * body, which runs after a plain beforeToHtml plugin already set our
 * template - silently undoing it. Intercepting setTemplate() itself
 * (via reflection, to avoid recursion) is the only way to actually
 * override the template it renders with.
 */
class SwatchRendererTemplatePlugin
{
    private const ORIGINAL_TEMPLATE = 'Magento_Swatches::product/view/renderer.phtml';
    private const REVAMP_TEMPLATE = 'Ahy_PDPRevamp::product/swatch-renderer.phtml';

    public function afterSetTemplate(Configurable $subject, Configurable $result, string $template): Configurable
    {
        if ($template === self::ORIGINAL_TEMPLATE) {
            $property = new \ReflectionProperty($subject, '_template');
            $property->setAccessible(true);
            $property->setValue($subject, self::REVAMP_TEMPLATE);
        }
        return $result;
    }
}
