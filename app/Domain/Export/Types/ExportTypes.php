<?php

declare(strict_types=1);

namespace App\Domain\Export\Types;

use App\Domain\Export\Exceptions\UnknownExportType;
use Illuminate\Contracts\Container\Container;

/**
 * The kinds of export there are. A new one is a class implementing ExportType
 * that reads its page's own aggregator or query, listed here, with its strings in
 * lang/en/exports.php.
 */
final class ExportTypes
{
    /** @var array<string, class-string<ExportType>> */
    private const TYPES = [
        'state_summary' => StateSummaryExport::class,
        'lga_table' => LgaTableExport::class,
        'facility_scores' => FacilityScoresExport::class,
        'item_responses' => ItemResponsesExport::class,
        'data_issues' => DataIssuesExport::class,
        'pull_history' => PullHistoryExport::class,
        'audit_log' => AuditLogExport::class,
        'backfill_report' => BackfillReportExport::class,
        'workplan' => WorkplanExport::class,
        'round_report' => RoundReportExport::class,
    ];

    public function __construct(
        private readonly Container $container,
    ) {}

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(self::TYPES);
    }

    public function get(string $key): ExportType
    {
        $class = self::TYPES[$key] ?? throw new UnknownExportType($key);

        return $this->container->make($class);
    }
}
