<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

use App\Domain\Scoring\Enums\Dimension;

/**
 * "Actions by type" and "What each action means" (§9.2.6): one per dimension, in fixed order, with
 * how many actions of that type there are and how many stand at each status (Phase 6 step 3).
 */
final readonly class PlanSummary
{
    /** @param  array{not_started: int, in_progress: int, done: int}  $statuses */
    public function __construct(
        public Dimension $dimension,
        public string $action,
        public string $meaning,
        public string $owner,
        public int $count,
        public array $statuses = ['not_started' => 0, 'in_progress' => 0, 'done' => 0],
    ) {}
}
