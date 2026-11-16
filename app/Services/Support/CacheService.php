<?php
declare(strict_types=1);

namespace App\Services\Support;

/**
 * Small file cache for expensive, read-only analytics (forecasts, expiry risk,
 * charts). Every key includes a global "data version" that is bumped whenever
 * stock, purchase orders, department levels or settings change, so cached
 * results are never stale after a change; a TTL bounds age otherwise.
 * Values are stored as JSON (never unserialized PHP objects).
 */
final class CacheService
{
    private string $dir;
    private string $versionFile;
    private ?int $version = null;
    private bool $enabled;

    /**
     * @param string|null $root    cache folder (default storage/cache); tests pass a temp folder
     * @param bool|null   $enabled default from CACHE_ENABLED (phpunit.xml turns it off so rolled-back
     *                             test data can never be written into the live application cache)
     */
    public function __construct(?string $root = null, ?bool $enabled = null)
    {
        $root ??= BASE_PATH . '/storage/cache';
        $this->dir = $root . '/data';
        $this->versionFile = $root . '/data_version';
        $this->enabled = $enabled ?? (bool) env('CACHE_ENABLED', true);
    }

    public function remember(string $key, int $ttlSeconds, callable $compute): mixed
    {
        if (!$this->enabled) {
            return $compute();
        }
        $file = $this->file($key);
        if (is_file($file) && filemtime($file) > time() - $ttlSeconds) {
            $raw = @file_get_contents($file);
            if ($raw !== false) {
                $data = json_decode($raw, true);
                if (is_array($data) && array_key_exists('v', $data)) {
                    return $data['v'];
                }
            }
        }
        $value = $compute();
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0777, true);
            @chmod($this->dir, 0777); // shared by the web server and CLI users
        }
        $tmp = $file . '.' . bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode(['v' => $value], JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE)) !== false) {
            @chmod($tmp, 0666);
            @rename($tmp, $file);
        }
        return $value;
    }

    /** Invalidate everything (cheap: changes the version embedded in every key). */
    public function bump(): void
    {
        $file = $this->versionFile;
        if (!is_dir(dirname($file))) {
            @mkdir(dirname($file), 0777, true);
        }
        $v = (int) @file_get_contents($file) + 1;
        @file_put_contents($file, (string) $v, LOCK_EX);
        @chmod($file, 0666);
        $this->version = $v;
        // Occasionally clean up old entries.
        if (random_int(1, 50) === 1) {
            foreach (glob($this->dir . '/*.json') ?: [] as $f) {
                if (filemtime($f) < time() - 86400) {
                    @unlink($f);
                }
            }
        }
    }

    private function file(string $key): string
    {
        if ($this->version === null) {
            $this->version = (int) @file_get_contents($this->versionFile);
        }
        return $this->dir . '/' . hash('sha256', $this->version . '|' . date('Y-m-d') . '|' . $key) . '.json';
    }
}
