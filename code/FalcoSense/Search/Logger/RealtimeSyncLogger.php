<?php
declare(strict_types=1);

namespace FalcoSense\Search\Logger;

use Monolog\Logger;

/**
 * Dedicated logger that writes to var/log/smartsearch-realtime.log.
 * Wired via di.xml virtual types — no manual instantiation needed.
 */
class RealtimeSyncLogger extends Logger
{
}
