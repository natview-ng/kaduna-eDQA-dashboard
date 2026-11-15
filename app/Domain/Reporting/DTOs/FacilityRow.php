<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use App\Domain\Scoring\DTOs\ScoreValue;

/**
 * One row of the facility view (§9.2.2). m1–m3 and score follow the metric switch; overall is
 * always the overall (the band tag). The three dimensions and the round id come along for the
 * facility scores export (Phase 6 step 1: "current metric + all metrics", provisional rows).
 */
final readonly class FacilityRow
{
    public function __construct(
        public int $facilityId,
        public string $code,
        public string $name,
        public string $lga,
        public string $ward,
        public Ownership $ownership,
        public FacilityLevel $level,
        public string $roundLabel,
        public string $assessedOn,
        public ScoreValue $m1,
        public ScoreValue $m2,
        public ScoreValue $m3,
        public ScoreValue $score,
        public ScoreValue $overall,
        public ScoreValue $availability,
        public ScoreValue $consistency,
        public ScoreValue $validity,
        public ?int $roundId,
    ) {}
}
