<?php

declare(strict_types=1);

namespace App\Domain\Export\Types;

use App\Domain\Export\Support\ExportDescription;
use App\Domain\Export\Support\RoundsInView;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Enums\ReportAudience;

/**
 * The dashboard report exports (state summary, LGA table, facility scores, item responses): the
 * dashboard filter for the admin audience, so the open round counts as in the admin preview the
 * export was asked from, with its rows marked provisional.
 */
abstract class ReportExport implements ExportType
{
    public function __construct(
        protected readonly RoundsInView $rounds,
    ) {}

    public function offersPrivate(): bool
    {
        return false;
    }

    public function filter(array $input): array
    {
        return ReportFilter::fromArray($input, ReportAudience::Admin)->toQuery();
    }

    public function describe(array $filter): array
    {
        $report = $this->report($filter);

        return [
            [(string) __('exports.about.filter'), ExportDescription::reportFilter($filter)],
            [(string) __('exports.about.rule_version'), $this->rounds->ruleVersions($report)],
        ];
    }

    /** @param  array<string, mixed>  $filter */
    protected function report(array $filter): ReportFilter
    {
        return ReportFilter::fromArray($filter, ReportAudience::Admin);
    }
}
