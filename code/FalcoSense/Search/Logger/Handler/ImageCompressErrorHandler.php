<?php
declare(strict_types=1);

namespace FalcoSense\Search\Logger\Handler;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class ImageCompressErrorHandler extends Base
{
    protected $loggerType = Logger::ERROR;
    protected $fileName   = '/var/log/smartsearch-image-compress-error.log';
}
