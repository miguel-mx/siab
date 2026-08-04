<?php

namespace App\Tests\Dashboard;

use App\Dashboard\Sparkline;
use PHPUnit\Framework\TestCase;

/**
 * The KPI sparkline must survive the shapes real data takes on a fresh install:
 * no runs at all, a single month, and an all-zero series.
 */
final class SparklineTest extends TestCase
{
    public function testPlotsOnePointPerMonth(): void
    {
        $spark = Sparkline::fromSeries([
            ['label' => '2026-05', 'count' => 0],
            ['label' => '2026-06', 'count' => 5],
            ['label' => '2026-07', 'count' => 10],
        ]);

        $this->assertSame([0, 5, 10], $spark->values);
        $this->assertCount(3, explode(' ', $spark->points));

        // Max value sits at the top of the box, zero at the bottom.
        [$firstX, $firstY] = explode(',', explode(' ', $spark->points)[0]);
        $this->assertSame('4', $firstX);
        $this->assertSame(30.0, (float) $firstY);
        $this->assertSame(4.0, $spark->lastY);
        $this->assertStringEndsWith('Z', $spark->areaPath);
    }

    public function testAllZeroSeriesSitsOnTheBaselineWithoutDividingByZero(): void
    {
        $spark = Sparkline::fromSeries([
            ['label' => '2026-06', 'count' => 0],
            ['label' => '2026-07', 'count' => 0],
        ]);

        $this->assertSame('4,30 116,30', $spark->points);
        $this->assertSame(30.0, $spark->lastY);
    }

    public function testSingleMonthAndEmptySeriesStillDrawALine(): void
    {
        $single = Sparkline::fromSeries([['label' => '2026-07', 'count' => 3]]);
        $this->assertSame([3, 3], $single->values);
        $this->assertSame('4,4 116,4', $single->points);

        $empty = Sparkline::fromSeries([]);
        $this->assertSame([0, 0], $empty->values);
        $this->assertSame('4,30 116,30', $empty->points);
    }

    public function testAcceptsAPlainIntegerSeries(): void
    {
        $spark = Sparkline::fromSeries([1, 2]);

        $this->assertSame([1, 2], $spark->values);
    }
}
