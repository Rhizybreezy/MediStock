<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal daily-file logger: storage/logs/app-YYYY-MM-DD.log
 */
final class Logger
{
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $dir = BASE_PATH . '/storage/logs';
        if (!is_dir($dir) || !is_writable($dir)) {
            error_log("[MediStock][$level] $message");
            return;
        }
        $line = sprintf(
            "[%s] %s: %s %s\n",
            date('Y-m-d H:i:s'),
            $level,
            str_replace(["\r", "\n"], ' ', $message),
            $context ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) : ''
        );
        $file = $dir . '/app-' . date('Y-m-d') . '.log';
        $isNew = !is_file($file);
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
        if ($isNew) {
            @chmod($file, 0666); // writable by both the web server and CLI user
        }
    }
}
