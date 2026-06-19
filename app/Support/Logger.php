<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SyncLog;
use Throwable;

final class Logger
{
    public function info(string $source, string $message): void
    {
        $this->log('info', $source, $message);
    }

    public function warning(string $source, string $message): void
    {
        $this->log('warning', $source, $message);
    }

    public function error(string $source, string $message): void
    {
        $this->log('error', $source, $message);
    }

    public function log(string $level, string $source, string $message): void
    {
        $line = sprintf("[%s] %s.%s: %s\n", date('Y-m-d H:i:s'), $source, $level, $message);
        file_put_contents(BASE_PATH . '/storage/logs/app-' . date('Y-m-d') . '.log', $line, FILE_APPEND);

        try {
            (new SyncLog(Database::connection()))->create($level, $source, $message);
        } catch (Throwable) {
            // File logging still works when the database is not ready yet.
        }
    }
}
