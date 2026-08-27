<?php
declare(strict_types=1);

namespace FalcoSense\Search\Logger;

use Monolog\Logger;

/**
 * Dedicated logger for image compression — writes INFO+ to
 * var/log/smartsearch-image-compress.log and ERROR+ to
 * var/log/smartsearch-image-compress-error.log (see the two handlers).
 * Wired via di.xml virtual types — no manual instantiation needed.
 */
class ImageCompressLogger extends Logger
{
}
