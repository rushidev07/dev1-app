<?php
declare(strict_types=1);

namespace FalcoSense\Search\Logger\Handler;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class FullSyncErrorHandler extends Base
{
    protected $loggerType = Logger::ERROR;
    protected $fileName   = '/var/log/smartsearch-full-sync-error.log';
}
