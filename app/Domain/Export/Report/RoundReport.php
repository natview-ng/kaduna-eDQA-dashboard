<?php

declare(strict_types=1);

namespace App\Domain\Export\Report;

use App\Domain\Reporting\DTOs\DimensionScores;
use App\Domain\Reporting\DTOs\LgaRow;
use App\Domain\Reporting\DTOs\LgaScore;
use App\Domain\Reporting\DTOs\MonthSlotPoint;
use App\Domain\Reporting\DTOs\OwnerSplit;
use App\Domain\Reporting\DTOs\Plan;
use App\Domain\Reporting\DTOs\StateSummary;
use App\Domain\Reporting\DTOs\TrendPoint;

/**
 * Everything the PDF round report shows (D-101), each section straight from the
 * dashboard's own aggregator for the round, so the report and the dashboard cannot disagree. A
 * closed round is read as the public reads it; an open one (a draft) as the admin preview does.
 * Nothing personal: no assessor, facility name, source ID or contact.
 */
final readonly class RoundReport
{
    /**
     * @param  list<TrendPoint>  $trend  the round's year, up to and including the round
     * @param  list<MonthSlotPoint>  $months  the round's three month slots
     * @param  list<LgaRow>  $lgas  all 23, by name
     * @param  list<LgaScore>  $weakest
     * @param  list<array{label: string, value: string}>  $method
     */
    public function __construct(
        public int $roundId,
        public string $round,
        public string $generatedOn,
        public bool $draft,
        public StateSummary $summary,
        public DimensionScores $dimensions,
        public OwnerSplit $owners,
        public array $trend,
        public ?string $trendTakeaway,
        public array $months,
        public array $lgas,
        public array $weakest,
        public Plan $plan,
        public DataQuality $quality,
        public array $method,
    ) {}

    /** "Kaduna State eDQA round report, Q2 2026", the PDF's title metadata and <title>. */
    public function title(): string
    {
        return (string) __('report.title', ['round' => $this->round]);
    }
}
