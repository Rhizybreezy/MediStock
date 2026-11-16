<?php
declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class HelpersTest extends TestCase
{
    public function testQtyFormatting(): void
    {
        $this->assertSame('9,100', qty(9100));
        $this->assertSame('100', qty('100.000'));
        $this->assertSame('0', qty(0));
        $this->assertSame('12.5', qty('12.500'));
        $this->assertSame('1,234.75', qty(1234.75));
        $this->assertSame('0.001', qty(0.001));
        $this->assertSame('-5', qty(-5));
    }

    public function testEscapeNeutralisesHtml(): void
    {
        $this->assertSame('&lt;script&gt;alert(&#039;x&#039;)&lt;/script&gt;', e("<script>alert('x')</script>"));
        $this->assertSame('', e(null));
    }
}
