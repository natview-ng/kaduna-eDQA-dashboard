<?php

declare(strict_types=1);

namespace App\Support\Export;

/**
 * A text cell a spreadsheet would read as a formula (=, +, -, @, tab, carriage return at the
 * start) is written with a leading apostrophe, so opening an export never runs anything (CSV
 * injection). Numbers are left as they are. Shared by every CSV the portal writes.
 */
final class SpreadsheetCell
{
    public static function safe(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
