<?php

namespace App\Dashboard;

/**
 * Turns a small integer series into the two strings the KPI sparkline needs:
 * a polyline for the stroke and a closed path for the fill. Kept in PHP because
 * doing this arithmetic in Twig is unreadable — the SVG lives in the template.
 *
 * Coordinates are in the viewBox the template declares (120 × 34).
 */
final readonly class Sparkline
{
    private const WIDTH = 120.0;
    private const HEIGHT = 34.0;
    private const PAD_X = 4.0;
    private const PAD_Y = 4.0;

    /** @param list<int> $values */
    private function __construct(
        public array $values,
        public string $points,
        public string $areaPath,
        public float $lastX,
        public float $lastY,
    ) {
    }

    /** @param list<array{label:string, count:int}>|list<int> $series */
    public static function fromSeries(array $series): self
    {
        $values = array_map(
            static fn ($item) => (int) (is_array($item) ? $item['count'] : $item),
            $series,
        );

        if (count($values) < 2) {
            // A single point cannot draw a line; render a flat baseline instead.
            $values = [...$values, ...array_fill(0, 2 - count($values), $values[0] ?? 0)];
        }

        $max = max($values);
        $span = self::WIDTH - 2 * self::PAD_X;
        $height = self::HEIGHT - 2 * self::PAD_Y;
        $step = $span / (count($values) - 1);

        $coords = [];
        foreach ($values as $i => $value) {
            $x = self::PAD_X + $i * $step;
            // All-zero series sits on the baseline rather than dividing by zero.
            $y = self::PAD_Y + ($max > 0 ? (1 - $value / $max) * $height : $height);
            $coords[] = [round($x, 1), round($y, 1)];
        }

        $points = implode(' ', array_map(static fn (array $c) => $c[0].','.$c[1], $coords));
        $first = $coords[0];
        $last = $coords[count($coords) - 1];

        $areaPath = sprintf(
            'M%s L%s,%s L%s,%s Z',
            $points,
            $last[0],
            self::HEIGHT,
            $first[0],
            self::HEIGHT,
        );

        return new self($values, $points, $areaPath, $last[0], $last[1]);
    }
}
