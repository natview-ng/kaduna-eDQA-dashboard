<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Support;

use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\Enums\Dimension;

/**
 * An item's result as the facility drawer shows it and ScoreCalculator counts it: N/A when not
 * applicable or when the round's choice map says so, else the mapped pass or fail; null only when
 * no rule version can be read. Shared by the drawer and the item responses export,
 * so the two never disagree.
 */
final class ItemResult
{
    public static function of(string $value, bool $applicable, ?RuleConfig $rules): ?string
    {
        if (! $applicable) {
            return 'na';
        }
        $outcome = $rules?->outcome($value);

        return in_array($outcome, ['pass', 'fail', 'na'], true) ? $outcome : null;
    }

    /**
     * One item from its stored checks. Usually one check: its result. Several checks under one item
     * (the Kobo form's consistency items, D-67): the number passed as text ("2"), or N/A when none
     * applies; null if any cannot be read.
     *
     * @param  list<array{0: string, 1: bool}>  $checks  value, applicable
     * @return array{result: ?string, checks: array{passed: int, total: int}|null}
     */
    public static function ofChecks(array $checks, ?RuleConfig $rules): array
    {
        $results = array_map(fn (array $check): ?string => self::of($check[0], $check[1], $rules), $checks);
        $single = count($checks) === 1;
        $passed = count(array_filter($results, fn (?string $r): bool => $r === 'pass'));

        return [
            'result' => match (true) {
                $single => $results[0],
                in_array(null, $results, true) => null,
                array_filter($results, fn (?string $r): bool => $r !== 'na') === [] => 'na',
                default => (string) $passed,
            },
            'checks' => $single ? null : ['passed' => $passed, 'total' => count($checks)],
        ];
    }

    /** lang/en/items.php, when the form's labels are known (Q-02); null rather than the key. */
    public static function label(Dimension $dimension, string $code): ?string
    {
        $key = "items.{$dimension->value}.{$code}";
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : null;
    }
}
