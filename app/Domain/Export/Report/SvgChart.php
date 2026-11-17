<?php

declare(strict_types=1);

namespace App\Domain\Export\Report;

/**
 * Inline SVG charts for the PDF round report (D-101): drawn on the server, so the
 * PDF never waits on JavaScript or hydration. Three kinds: horizontal bars, grouped bars and a
 * line. Scores only, so every axis runs 0 to 100. Colour comes from CSS classes the report view
 * maps to the design tokens (series-availability, series-overall, pub, priv…), never from hex
 * here. Each chart is role="img" with a <title>; the view puts the same figures in a table beside
 * it (hard rule 11), so the chart is never the only way to read a number. Values are positioned
 * from the full-precision score; the text drawn is the displayed one, as the dashboard shows it.
 */
final class SvgChart
{
    private const GRID = [0, 25, 50, 75, 100];

    /**
     * One bar per row, label on the left, displayed value at the bar's end. A dashed marker at
     * $target (the Strong line) when given.
     *
     * @param  list<array{label: string, value: ?float, display: string, class: string}>  $rows
     */
    public static function bars(string $title, array $rows, ?float $target = null, int $width = 640): string
    {
        $labelWidth = (int) round($width * 0.27);
        $valueWidth = 40;
        $rowHeight = 22;
        $top = 6;
        $plot = $width - $labelWidth - $valueWidth;
        $height = $top + count($rows) * $rowHeight + 22;
        $x = fn (float $v): float => $labelWidth + $plot * max(0.0, min(100.0, $v)) / 100;

        $svg = self::grid($x, $top, $top + count($rows) * $rowHeight, vertical: true);
        foreach ($rows as $i => $row) {
            $y = $top + $i * $rowHeight;
            $mid = $y + $rowHeight / 2;
            $svg .= self::text($labelWidth - 8, $mid + 4, $row['label'], 'label', 'end');
            if ($row['value'] === null) {
                $svg .= self::text($labelWidth + 4, $mid + 4, $row['display'], 'value muted');

                continue;
            }
            $end = $x($row['value']);
            $svg .= sprintf('<rect class="%s" x="%s" y="%s" width="%s" height="%s" rx="2"/>', self::e($row['class']), $labelWidth, self::n($y + 4), self::n(max(0.5, $end - $labelWidth)), $rowHeight - 8);
            $svg .= self::text($end + 5, $mid + 4, $row['display'], 'value');
        }
        if ($target !== null) {
            $svg .= sprintf('<line class="target" x1="%1$s" x2="%1$s" y1="%2$s" y2="%3$s"/>', self::n($x($target)), $top - 4, $top + count($rows) * $rowHeight + 2);
        }

        return self::wrap($title, $width, $height, $svg);
    }

    /**
     * Vertical bars side by side for each group (a month, a dimension), one per series.
     *
     * @param  list<string>  $groups
     * @param  list<array{name: string, class: string, values: list<?float>, displays: list<string>}>  $series
     */
    public static function groupedBars(string $title, array $groups, array $series, int $width = 640, int $height = 230): string
    {
        $left = 34;
        $top = 14;
        $bottom = $height - 26;
        $plot = $width - $left - 8;
        $y = fn (float $v): float => $bottom - ($bottom - $top) * max(0.0, min(100.0, $v)) / 100;
        $groupWidth = $groups === [] ? $plot : $plot / count($groups);
        $barWidth = min(34.0, ($groupWidth - 18) / max(1, count($series)));

        $svg = self::grid($y, $left, $width - 8, vertical: false);
        foreach ($groups as $g => $group) {
            $start = $left + $g * $groupWidth + ($groupWidth - $barWidth * count($series)) / 2;
            foreach ($series as $s => $line) {
                $value = $line['values'][$g] ?? null;
                $bx = $start + $s * $barWidth;
                if ($value === null) {
                    $svg .= self::text($bx + $barWidth / 2, $bottom - 4, '—', 'value muted', 'middle');

                    continue;
                }
                $by = $y($value);
                $svg .= sprintf('<rect class="%s" x="%s" y="%s" width="%s" height="%s"/>', self::e($line['class']), self::n($bx + 1), self::n($by), self::n($barWidth - 2), self::n(max(0.5, $bottom - $by)));
                $svg .= self::text($bx + $barWidth / 2, $by - 3, $line['displays'][$g] ?? '', 'value small', 'middle');
            }
            $svg .= self::text($left + $g * $groupWidth + $groupWidth / 2, $bottom + 16, $group, 'label', 'middle');
        }

        return self::wrap($title, $width, $height, $svg);
    }

    /**
     * One line per series across the points (rounds), with a dot and the displayed value at each.
     *
     * @param  list<string>  $labels
     * @param  list<array{name: string, class: string, values: list<?float>, displays: list<string>}>  $series
     */
    public static function line(string $title, array $labels, array $series, int $width = 640, int $height = 220): string
    {
        $left = 34;
        $top = 16;
        $bottom = $height - 26;
        $inset = 40;
        $plot = $width - $left - 8 - 2 * $inset;
        $y = fn (float $v): float => $bottom - ($bottom - $top) * max(0.0, min(100.0, $v)) / 100;
        $x = fn (int $i): float => $left + $inset + (count($labels) <= 1 ? $plot / 2 : $plot * $i / (count($labels) - 1));

        $svg = self::grid($y, $left, $width - 8, vertical: false);
        foreach ($labels as $i => $label) {
            $svg .= self::text($x($i), $bottom + 16, $label, 'label', 'middle');
        }
        foreach ($series as $s => $line) {
            $points = [];
            foreach ($labels as $i => $label) {
                $value = $line['values'][$i] ?? null;
                if ($value === null) {
                    // A gap: the line does not pretend to know a round with no data.
                    $svg .= self::polyline($points, $line['class']);
                    $points = [];

                    continue;
                }
                $points[] = [$x($i), $y($value)];
            }
            $svg .= self::polyline($points, $line['class']);
            foreach ($labels as $i => $label) {
                $value = $line['values'][$i] ?? null;
                if ($value !== null) {
                    $svg .= sprintf('<circle class="dot %s" cx="%s" cy="%s" r="3"/>', self::e($line['class']), self::n($x($i)), self::n($y($value)));
                    // Labels alternate above and below by series so two close lines stay legible.
                    $svg .= self::text($x($i) + 6, $y($value) + ($s % 2 === 0 ? -5 : 12), $line['displays'][$i] ?? '', 'value small');
                }
            }
        }

        return self::wrap($title, $width, $height, $svg);
    }

    /** @param  callable(float): float  $at */
    private static function grid(callable $at, float $from, float $to, bool $vertical): string
    {
        $svg = '';
        foreach (self::GRID as $tick) {
            $p = self::n($at((float) $tick));
            $svg .= $vertical
                ? sprintf('<line class="grid" x1="%1$s" x2="%1$s" y1="%2$s" y2="%3$s"/>', $p, self::n($from - 2), self::n($to + 2)).self::text((float) $p, $to + 15, (string) $tick, 'axis', 'middle')
                : sprintf('<line class="grid" x1="%2$s" x2="%3$s" y1="%1$s" y2="%1$s"/>', $p, self::n($from), self::n($to)).self::text($from - 6, (float) $p + 3, (string) $tick, 'axis', 'end');
        }

        return $svg;
    }

    /** @param  list<array{0: float, 1: float}>  $points */
    private static function polyline(array $points, string $class): string
    {
        if (count($points) < 2) {
            return '';
        }

        return sprintf('<polyline class="line %s" points="%s"/>', self::e($class), implode(' ', array_map(fn (array $p): string => self::n($p[0]).','.self::n($p[1]), $points)));
    }

    private static function text(float $x, float $y, string $text, string $class, string $anchor = 'start'): string
    {
        return sprintf('<text class="%s" x="%s" y="%s" text-anchor="%s">%s</text>', self::e($class), self::n($x), self::n($y), $anchor, self::e($text));
    }

    private static function wrap(string $title, int $width, int $height, string $body): string
    {
        $id = 'chart-'.substr(md5($title.$body), 0, 10);

        return sprintf(
            '<svg class="chart" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %1$d %2$d" width="100%%" role="img" aria-labelledby="%3$s"><title id="%3$s">%4$s</title>%5$s</svg>',
            $width, $height, $id, self::e($title), $body,
        );
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
