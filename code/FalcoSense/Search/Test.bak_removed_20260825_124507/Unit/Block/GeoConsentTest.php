<?php
declare(strict_types=1);

namespace FalcoSense\Search\Test\Unit\Block;

use FalcoSense\Search\Block\GeoConsent;
use Magento\Framework\View\Element\Template\Context;
use PHPUnit\Framework\TestCase;

class GeoConsentTest extends TestCase
{
    private GeoConsent $block;

    protected function setUp(): void
    {
        $context = $this->createMock(Context::class);
        $this->block = new GeoConsent($context);
    }

    public function testBlockIsTemplateInstance(): void
    {
        $this->assertInstanceOf(\Magento\Framework\View\Element\Template::class, $this->block);
    }

    public function testDefaultTemplateIsNull(): void
    {
        // No template set by default — layout XML sets it at render time
        $this->assertNull($this->block->getTemplate());
    }

    public function testSetAndGetTemplate(): void
    {
        $this->block->setTemplate('FalcoSense_Search::geo-consent.phtml');
        $this->assertSame('FalcoSense_Search::geo-consent.phtml', $this->block->getTemplate());
    }

    public function testBlockNameCanBeSet(): void
    {
        $this->block->setNameInLayout('ahy_geo_consent');
        $this->assertSame('ahy_geo_consent', $this->block->getNameInLayout());
    }
}
