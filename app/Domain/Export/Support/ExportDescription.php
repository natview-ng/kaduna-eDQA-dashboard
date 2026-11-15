<?php

declare(strict_types=1);

namespace App\Domain\Export\Support;

use App\Domain\Export\Models\Export;
use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The words that go with an export: its filter in plain language and the "About this export"
 * rows (Excel), including whether assessor and source details are in it.
 */
final class ExportDescription
{
    /** "Year 2026 · Q3 · LGA Zaria · Primary · Public", or "Latest round" with no filter. */
    public static function filter(Export $export): string
    {
        $filter = $export->filter;
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

        return $parts === [] ? (string) __('exports.filter.latest') : implode(' · ', $parts);
    }

    /** @return list<array{0: string, 1: string|int}> */
    public static function about(Export $export, int $rows): array
    {
        $requester = $export->requester()->value('name');
        $at = ($export->created_at ?? CarbonImmutable::now())->setTimezone((string) config('app.timezone'));

        return [
            [(string) __('exports.about.type'), (string) __("exports.types.{$export->type}.title")],
            [(string) __('exports.about.filter'), self::filter($export)],
            [(string) __('exports.about.requested_by'), is_string($requester) ? $requester : ''],
            [(string) __('exports.about.requested_at'), $at->format('j M Y, H:i').' WAT'],
            [(string) __('exports.about.rows'), $rows],
            [(string) __('exports.about.private'), (string) __($export->includesPrivate() ? 'exports.about.private_yes' : 'exports.about.private_no')],
            [(string) __('exports.about.scores'), (string) __('exports.about.scores_note')],
        ];
    }
}
