<?php

declare(strict_types=1);

namespace App\Domain\Export\Types;

use App\Domain\Export\Support\BackfillReportSheets;
use App\Domain\Ingestion\Queries\BackfillReportQuery;
use App\Domain\Ingestion\SourceRegistry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * "Backfill report" (ARCHITECTURE.md §5.6) as Excel, for one source or all, as it
 * stands now: the Data sources page's download, through the export plumbing. The same sheets as
 * edqa:backfill-report --format=xlsx (BackfillReportSheets over BackfillReportQuery).
 */
final class BackfillReportExport implements MultiSheetExport
{
    public function __construct(
        private readonly BackfillReportQuery $report,
        private readonly SourceRegistry $sources,
    ) {}

    public function key(): string
    {
        return 'backfill_report';
    }

    public function offersPrivate(): bool
    {
        return false;
    }

    public function filter(array $input): array
    {
        $data = Validator::make($input, ['source' => ['nullable', 'string', Rule::in($this->sources->keys())]])->validate();

        return isset($data['source']) ? ['source' => (string) $data['source']] : [];
    }

    public function sheets(array $filter, bool $private = false): array
    {
        return BackfillReportSheets::from($this->report->build(isset($filter['source']) ? (string) $filter['source'] : null));
    }

    public function columns(array $filter, bool $private): array
    {
        return $this->sheets($filter)[0]->columns;
    }

    public function count(array $filter): int
    {
        $rows = 0;
        foreach ($this->sheets($filter) as $sheet) {
            $rows += count($sheet->rows);
        }

        return $rows;
    }

    public function chunks(array $filter, bool $private, int $size): iterable
    {
        yield $this->sheets($filter)[0]->rows;
    }

    public function describe(array $filter): array
    {
        return [[
            (string) __('exports.about.filter'),
            isset($filter['source']) ? $this->sources->name((string) $filter['source']) : (string) __('exports.types.backfill_report.all_sources'),
        ]];
    }
}
