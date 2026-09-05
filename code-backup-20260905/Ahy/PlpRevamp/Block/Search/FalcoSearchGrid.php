<?php

declare(strict_types=1);

namespace Ahy\PlpRevamp\Block\Search;

use FalcoSense\Search\Block\Search as FalcoSearch;

class FalcoSearchGrid extends FalcoSearch
{
    public function getYotpoEndpointUrl(): string
    {
        return $this->getUrl('ahy_plprevamp/yotpo/bottomline');
    }
}
