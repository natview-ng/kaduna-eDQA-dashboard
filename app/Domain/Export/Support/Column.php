<?php

declare(strict_types=1);

namespace App\Domain\Export\Support;

use App\Domain\Export\Enums\ColumnKind;

/** One column of an export: its heading and what it holds. */
final readonly class Column
{
    public function __construct(
        public string $heading,
        public ColumnKind $kind = ColumnKind::Text,
    ) {}

    public static function text(string $heading): self
    {
        return new self($heading);
    }

    public static function integer(string $heading): self
    {
        return new self($heading, ColumnKind::Integer);
    }

    public static function dateTime(string $heading): self
    {
        return new self($heading, ColumnKind::DateTime);
    }

    /**
     * A score as three columns, side by side: full precision, as displayed (1 dp), and its band.
     *
     * @return list<self>
     */
    public static function score(string $name, bool $band = true): array
    {
        return [
            new self((string) __('exports.columns.precise', ['name' => $name]), ColumnKind::Precise),
            new self((string) __('exports.columns.displayed', ['name' => $name]), ColumnKind::Displayed),
            ...($band ? [new self((string) __('exports.columns.band', ['name' => $name]))] : []),
        ];
    }

    public function width(): int
    {
        return max($this->kind->width(), mb_strlen($this->heading) + 2);
    }
}
