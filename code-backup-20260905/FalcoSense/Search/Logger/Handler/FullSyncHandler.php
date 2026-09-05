<?php
declare(strict_types=1);

namespace FalcoSense\Search\Logger\Handler;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class FullSyncHandler extends Base
{
    protected $loggerType = Logger::INFO;
    protected $fileName   = '/var/log/smartsearch-full-sync.log';
}
