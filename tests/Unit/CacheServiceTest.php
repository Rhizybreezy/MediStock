<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Support\CacheService;
use PHPUnit\Framework\TestCase;

final class CacheServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/medistock-cache-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/data/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->root . '/data');
        @unlink($this->root . '/data_version');
        @rmdir($this->root);
    }

    public function testRemembersUntilBumped(): void
    {
        $cache = new CacheService($this->root, true);
        $calls = 0;
        $compute = function () use (&$calls) { return ['n' => ++$calls, 'f' => 1.0]; };

        $this->assertSame(['n' => 1, 'f' => 1.0], $cache->remember('k', 600, $compute));
        $this->assertSame(['n' => 1, 'f' => 1.0], $cache->remember('k', 600, $compute), 'second read is served from cache');
        $this->assertSame(1, $calls);

        $cache->bump();
        $this->assertSame(2, $cache->remember('k', 600, $compute)['n'], 'bump invalidates every key');

        // A separate instance (another request) sees the same version.
        $this->assertSame(2, (new CacheService($this->root, true))->remember('k', 600, $compute)['n']);
        $this->assertSame(2, $calls);
    }

    public function testKeysAreIndependentAndExpire(): void
    {
        $cache = new CacheService($this->root, true);
        $this->assertSame('a', $cache->remember('a', 600, fn () => 'a'));
        $this->assertSame('b', $cache->remember('b', 600, fn () => 'b'));
        $this->assertSame('fresh', $cache->remember('a', -1, fn () => 'fresh'), 'expired entries are recomputed');
    }

    public function testDisabledCacheAlwaysComputesAndWritesNothing(): void
    {
        $cache = new CacheService($this->root, false);
        $n = 0;
        $cache->remember('k', 600, function () use (&$n) { return ++$n; });
        $cache->remember('k', 600, function () use (&$n) { return ++$n; });
        $this->assertSame(2, $n);
        $this->assertDirectoryDoesNotExist($this->root . '/data');
    }

    public function testCacheStoresJsonNotSerializedObjects(): void
    {
        $cache = new CacheService($this->root, true);
        $cache->remember('k', 600, fn () => ['x' => 1]);
        $files = glob($this->root . '/data/*.json');
        $this->assertCount(1, $files);
        $this->assertSame(['v' => ['x' => 1]], json_decode((string) file_get_contents($files[0]), true));
    }
}
