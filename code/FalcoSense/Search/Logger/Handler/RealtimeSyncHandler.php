<?php
declare(strict_types=1);

namespace FalcoSense\Search\Logger\Handler;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class RealtimeSyncHandler extends Base
{
    protected $loggerType = Logger::DEBUG;
    protected $fileName   = '/var/log/smartsearch-realtime.log';
}
