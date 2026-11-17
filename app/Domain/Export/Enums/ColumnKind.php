<?php

declare(strict_types=1);

namespace App\Domain\Export\Enums;

/**
 * What a column holds, which sets its Excel number format and width: a score at
 * full precision beside the same score as displayed (1 dp), counts, dates, and text.
 */
enum ColumnKind: string
{
    case Text = 'text';
    case Integer = 'integer';
    case Precise = 'precise';
    case Displayed = 'displayed';
    case Date = 'date';
    /** A date spelt out for people (the workplan: "16 Nov 2026"). */
    case DateLong = 'date_long';
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
            self::DateLong => 'dd mmm yyyy',
            self::DateTime => 'yyyy-mm-dd hh:mm',
        };
    }

    /** A starting width in characters; the heading's length raises it. */
    /** A date column: its Y-m-d text is written to Excel as a real date, so the format applies. */
    public function isDate(): bool
    {
        return $this === self::Date || $this === self::DateLong;
    }

    public function width(): int
    {
        return match ($this) {
            self::Text => 22,
            self::Integer => 10,
            self::Precise => 14,
            self::Displayed => 11,
            self::Date => 12,
            self::DateLong => 13,
            self::DateTime => 17,
        };
    }
}
