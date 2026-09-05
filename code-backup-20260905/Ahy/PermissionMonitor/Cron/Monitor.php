<?php

namespace Ahy\PermissionMonitor\Cron;

use Magento\Framework\App\Filesystem\DirectoryList;
use Psr\Log\LoggerInterface;

class Monitor
{
    private const LOG_FILE = 'permission_monitor.log';

    private const FOLDERS = [
         '/app/code/Ahy/PermissionMonitor' => '0755',
    ];

    private const FILES = [];

    private DirectoryList $directoryList;
    private LoggerInterface $logger;

    public function __construct(
        DirectoryList $directoryList,
        LoggerInterface $logger
    ) {
        $this->directoryList = $directoryList;
        $this->logger        = $logger;
    }

    public function execute(): void
    {
        $root     = $this->directoryList->getRoot();
        $logPath  = $this->directoryList->getPath('log') . '/' . self::LOG_FILE;
        $changes  = 0;

        $this->writeLog($logPath, str_repeat('=', 64));
        $this->writeLog($logPath, 'Permission check started');
        $this->writeLog($logPath, 'Root: ' . $root);
        $this->writeLog($logPath, str_repeat('=', 64));

        foreach (self::FOLDERS as $relativePath => $expected) {
            $fullPath = $root . $relativePath;
            $result   = $this->checkAndRevert($fullPath, $expected, $logPath);
            $changes += $result;
        }

        foreach (self::FILES as $relativePath => $expected) {
            $fullPath = $root . $relativePath;
            $result   = $this->checkAndRevert($fullPath, $expected, $logPath);
            $changes += $result;
        }

        $this->writeLog($logPath, str_repeat('-', 64));
        if ($changes === 0) {
            $this->writeLog($logPath, 'All permissions are correct. No changes needed.');
        } else {
            $this->writeLog($logPath, 'Total violations found and reverted: ' . $changes);
        }
        $this->writeLog($logPath, 'Permission check complete');
        $this->writeLog($logPath, str_repeat('=', 64));
    }

    private function checkAndRevert(string $path, string $expected, string $logPath): int
    {
        if (!file_exists($path)) {
            return 0;
        }

        $currentRaw = fileperms($path);
        $current    = '0' . decoct($currentRaw & 0777);

        if ($this->isStrictlyPrivate($current)) {
            $this->writeLog($logPath, '  SKIPPING (strictly private): ' . $path . ' | permission: ' . $current);
            return 0;
        }

        if ($current !== $expected) {
            chmod($path, octdec($expected));
            clearstatcache(true, $path);
            $this->writeLog($logPath, '  REVERTED: ' . $path . ' | was: ' . $current . ' | fixed to: ' . $expected);
            return 1;
        }

        return 0;
    }

    private function isStrictlyPrivate(string $perm): bool
    {
        // Matches permissions like 0700, 0600, 0500, 0400
        return (bool) preg_match('/^0[0-9]00$/', $perm);
    }

    private function writeLog(string $logPath, string $message): void
    {
        $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
        file_put_contents($logPath, $line, FILE_APPEND);
    }
}
