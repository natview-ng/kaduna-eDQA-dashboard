<?php

declare(strict_types=1);

namespace App\Domain\Export\Support;

use App\Domain\Export\Models\Export;
use App\Domain\Export\Types\ExportType;
use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use App\Domain\Reporting\Enums\Metric;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The words that go with an export: a dashboard filter in plain language, and the "About this file"
 * sheet (Phase 6 step 1): what it is, its filters, when (WAT) and by whom it was generated, the
 * rule versions, rounding mode, aggregation method, data sources, whether personal details are in
 * it, and that open-round figures are provisional.
 */
final class ExportDescription
{
    public function __construct(
        private readonly SourceNames $sources,
    ) {}

    /**
     * "Year 2026 · Q3 · LGA Zaria · Primary · Public · Metric: Availability", or "Latest round".
     *
     * @param  array<string, mixed>  $filter
     */
    public static function reportFilter(array $filter): string
    {
        $parts = [];
        if (isset($filter['year'])) {
            $parts[] = (string) __('exports.filter.year', ['year' => (int) $filter['year']]);
        }
        if (isset($filter['month'])) {
            $parts[] = (string) __('reporting.months.'.(int) $filter['month']);
        } elseif (isset($filter['quarter'])) {
            $parts[] = 'Q'.(int) $filter['quarter'];
        }
        if (isset($filter['lga'])) {
            $name = DB::table('lgas')->where('code', (string) $filter['lga'])->value('name');
            $parts[] = (string) __('exports.filter.lga', ['lga' => is_string($name) ? $name : (string) $filter['lga']]);
        }
        if (isset($filter['level'])) {
            $parts[] = FacilityLevel::from((string) $filter['level'])->label();
        }
        if (isset($filter['owner'])) {
            $parts[] = Ownership::from((string) $filter['owner'])->label();
        }
        $where = $parts === [] ? (string) __('exports.filter.latest') : implode(' · ', $parts);
        $metric = Metric::tryFrom(is_string($filter['metric'] ?? null) ? $filter['metric'] : '');

        return $metric === null ? $where : $where.' · '.(string) __('exports.filter.metric', ['metric' => (string) __('exports.dimensions.'.$metric->value)]);
    }

    /** @return list<array{0: string, 1: string|int}> */
    public function about(Export $export, ExportType $type, int $rows): array
    {
        $requester = $export->requester()->value('name');

        return $this->rows($type, $export->filter, is_string($requester) ? $requester : '', $rows, $export->includesPrivate());
    }

    /**
     * "About this file" for any export, from the export plumbing or the console (the backfill
     * report written by edqa:backfill-report).
     *
     * @param  array<string, mixed>  $filter
     * @return list<array{0: string, 1: string|int}>
     */
    public function rows(ExportType $type, array $filter, string $generatedBy, int $rows, bool $private): array
    {
        $byLabel = [];
        foreach ($type->describe($filter) as [$label, $value]) {
            $byLabel[$label] = $value;
        }
        $filterLabel = (string) __('exports.about.filter');
        $versionLabel = (string) __('exports.about.rule_version');

        return [
            [(string) __('exports.about.type'), (string) __("exports.types.{$type->key()}.title")],
            [$filterLabel, $byLabel[$filterLabel] ?? (string) __('exports.filter.all')],
            [(string) __('exports.about.generated_at'), CarbonImmutable::now()->setTimezone((string) config('app.timezone'))->format('j M Y, H:i').' WAT'],
            [(string) __('exports.about.generated_by'), $generatedBy],
            [(string) __('exports.about.rows'), $rows],
            [$versionLabel, $byLabel[$versionLabel] ?? (string) __('exports.about.not_applicable')],
            [(string) __('exports.about.rounding'), (string) __('exports.about.rounding_modes.'.config('edqa.presentation.rounding'))],
            [(string) __('exports.about.aggregation'), (string) __('exports.about.aggregation_methods.'.config('edqa.aggregation.weighting'))],
            [(string) __('exports.about.sources'), $this->sources->configured()],
            // A type may say what its personal details are (the workplan: contact and notes).
            [(string) __('exports.about.private'), $private ? ($byLabel[(string) __('exports.about.private')] ?? (string) __('exports.about.private_yes')) : (string) __('exports.about.private_no')],
            [(string) __('exports.about.published'), (string) __('exports.about.published_line')],
        ];
    }
}
