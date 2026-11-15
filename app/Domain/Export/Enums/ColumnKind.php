<?php

declare(strict_types=1);

namespace App\Domain\Export\Enums;

/**
 * What a column holds (Phase 6 step 1), which sets its Excel number format and width: a score at
 * full precision beside the same score as displayed (1 dp), counts, dates, and text.
 */
enum ColumnKind: string
{
    case Text = 'text';
    case Integer = 'integer';
    case Precise = 'precise';
    case Displayed = 'displayed';
    case Date = 'date';
    case DateTime = 'datetime';

    /** The Excel number format; null for text. */
    public function excelFormat(): ?string
    {
        return match ($this) {
            self::Text => null,
            self::Integer => '#,##0',
            self::Precise => '0.00########',
            self::Displayed => '0.0',
            self::Date => 'yyyy-mm-dd',
            self::DateTime => 'yyyy-mm-dd hh:mm',
        };
    }

    /** A starting width in characters; the heading's length raises it. */
    public function width(): int
    {
        return match ($this) {
            self::Text => 22,
            self::Integer => 10,
            self::Precise => 14,
            self::Displayed => 11,
            self::Date => 12,
            self::DateTime => 17,
        };
    }
}
