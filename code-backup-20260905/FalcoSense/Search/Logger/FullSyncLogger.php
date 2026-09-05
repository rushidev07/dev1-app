<?php
declare(strict_types=1);

namespace FalcoSense\Search\Logger;

use Monolog\Logger;

/**
 * Dedicated logger for smartsearch:sync:full — writes INFO+ to
 * var/log/smartsearch-full-sync.log and ERROR+ to
 * var/log/smartsearch-full-sync-error.log (see the two handlers).
 * Wired via di.xml virtual types — no manual instantiation needed.
 */
class FullSyncLogger extends Logger
{
}
