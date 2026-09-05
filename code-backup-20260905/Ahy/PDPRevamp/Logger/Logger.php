<?php

declare(strict_types=1);

namespace Ahy\PDPRevamp\Logger;

/**
 * Falls back to a default channel name if the object manager ever supplies
 * null for it - which is exactly what a stale compiled DI cache does (see
 * generated/metadata/global.php recording this class's "name" argument as
 * null after etc/di.xml was changed without a recompile). Monolog's own
 * constructor requires a non-null string, and this class is constructed
 * wherever anything injects it - so a missing name previously crashed every
 * page in the site, including the 404/no-route page, not just this module.
 */
class Logger extends \Monolog\Logger
{
    private const DEFAULT_NAME = 'PDPRevamp';

    public function __construct(
        ?string $name = self::DEFAULT_NAME,
        array $handlers = [],
        array $processors = [],
        ?\DateTimeZone $timezone = null
    ) {
        parent::__construct($name ?: self::DEFAULT_NAME, $handlers, $processors, $timezone);
    }
}
