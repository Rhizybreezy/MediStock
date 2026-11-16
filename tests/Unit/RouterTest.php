<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Route;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    private function route(string $path): Route
    {
        return new Route('GET', $path, ['X', 'y'], [], fn () => null);
    }

    public function testIdParametersOnlyMatchDigitsAndAreCastToInt(): void
    {
        $r = $this->route('/admin/users/{id}/edit');
        $this->assertSame(['id' => 15], $r->match('/admin/users/15/edit'));
        $this->assertNull($r->match('/admin/users/abc/edit'));
        $this->assertNull($r->match('/admin/users/1 OR 1=1/edit'));
    }

    public function testStaticSegmentsDoNotCollideWithIds(): void
    {
        $this->assertNull($this->route('/admin/users/{id}')->match('/admin/users/create'));
        $this->assertSame([], $this->route('/admin/users/create')->match('/admin/users/create'));
    }

    public function testNonIdParametersMatchOneSegment(): void
    {
        $r = $this->route('/scan/{code}');
        $this->assertSame(['code' => 'MSB:B26-000001'], $r->match('/scan/MSB:B26-000001'));
        $this->assertNull($r->match('/scan/a/b'));
    }
}
