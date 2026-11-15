<?php

declare(strict_types=1);

namespace App\Domain\Reporting\DTOs;

use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use App\Domain\Scoring\DTOs\ScoreValue;

/** One assessment's figures for an export (AssessmentExportQuery). No private detail. */
final readonly class AssessmentExportRow
{
    public function __construct(
        public int $assessmentId,
        public int $submissionId,
        public int $sourceId,
        public string $status,
        public string $round,
        public string $lgaCode,
        public string $lga,
        public string $ward,
        public string $facilityCode,
        public string $facility,
        public FacilityLevel $level,
        public Ownership $ownership,
        public string $visitedOn,
        public ScoreValue $availability,
        public ScoreValue $consistency,
        public ScoreValue $validity,
        public ScoreValue $overall,
    ) {}
}
