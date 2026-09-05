<?php
declare(strict_types=1);

namespace FalcoSense\Search\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

class VersionInfo extends Field
{
    public function render(AbstractElement $element): string
    {
        return <<<HTML
<tr>
    <td colspan="4" style="padding:0 0 8px 0;">
        <img src="https://dev2.everest.com/media/wysiwyg/falcosense-logo.png" alt="FalcoSense" style="max-width:200px;width:100%;display:block;border-radius:4px;filter:drop-shadow(3px 4px 8px rgba(0, 0, 0, 0.5));" />
    </td>
</tr>
HTML;
    }
}
