<?php

declare(strict_types=1);

namespace App\Domain\Export\Support;

use App\Domain\Scoring\DTOs\ScoreValue;

/**
 * A score's cells beside Column::score(): the full-precision value, the value as the dashboard
 * shows it (rounded once, by the presentation rules, never here) and the band label as text.
 * Empty cells for no data.
 */
final class ScoreCells
{
    /** @return list<float|string|null> */
    public static function of(ScoreValue $score, bool $band = true): array
    {
        $cells = [
            $score->value,
            $score->value === null || ! is_numeric($score->display) ? null : (float) $score->display,
        ];
        if ($band) {
            $cells[] = $score->band === null ? null : $score->bandLabel;
        }

        return $cells;
    }
}
