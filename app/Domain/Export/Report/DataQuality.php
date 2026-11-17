<?php

declare(strict_types=1);

namespace App\Domain\Export\Report;

/**
 * The report's data quality note (Phase 6 step 5): how many assessments are in the figures, how
 * many submissions were kept out (quarantined: open or rejected) and why, and both by source.
 * Counts and source names only.
 */
final readonly class DataQuality
{
    /**
     * @param  list<array{code: string, name: string, count: int}>  $rules
     * @param  list<array{name: string, accepted: int, quarantined: int}>  $sources
     */
    public function __construct(
        public int $accepted,
        public int $quarantined,
        public array $rules,
        public array $sources,
    ) {}
}
