<?php

declare(strict_types=1);

use App\Domain\Facility\Models\Facility;
use App\Domain\Ingestion\Queries\PullHistoryQuery;
use App\Domain\Reporting\Aggregators\CoverageAggregator;
use App\Domain\Reporting\Aggregators\DimensionAggregator;
use App\Domain\Reporting\Aggregators\FacilityDetailAggregator;
use App\Domain\Reporting\Aggregators\FacilityScoresQuery;
use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\Aggregators\OwnerSplitAggregator;
use App\Domain\Reporting\Aggregators\StateSummaryAggregator;
use App\Domain\Reporting\DTOs\FacilitySort;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Enums\Metric;
use App\Domain\Reporting\Enums\ReportAudience;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\DTOs\ScoreValue;
use App\Domain\Validation\Queries\QuarantineQuery;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Audit\AuditLogQuery;
use Database\Seeders\LgaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ExportRunner;
use Tests\Support\ReportingFixture;
use Tests\Support\Rows;

// Phase 6 step 1 (D-97): every export mirrors a page and reads its numbers from that page's own
// aggregator or query. For each type and five filter combinations, every exported number equals
// the aggregator's value: the full-precision column the value itself, the displayed column its
// display, the band its band label. Q2 2026 is open (its rows are provisional), Q1 2026 closed.

/** The dashboard filters for the report types: no filter (the latest round), a quarter, an LGA, a year with an owner, a month with a level. */
const REPORT_FILTERS = [
    'latest round' => [[]],
    'Q2 2026' => [['year' => 2026, 'quarter' => 2]],
    'Q2 2026, LGA Alpha' => [['year' => 2026, 'quarter' => 2, 'lga' => 'AAA']],
    '2026, private' => [['year' => 2026, 'owner' => 'private']],
    'May 2026, primary' => [['year' => 2026, 'month' => 5, 'level' => 'primary']],
];

beforeEach(function (): void {
    $this->seed(LgaSeeder::class);
    $this->f = ReportingFixture::build();
    Storage::fake('exports');

    // Item responses for three of the Q2 assessments and F1's Q1 one: a yes, a no, an N/A, and an
    // item made of three checks (D-67).
    foreach (['F1', 'F3', 'F6'] as $name) {
        $assessment = (int) DB::table('assessments')->where('round_id', $this->f->q2)->where('facility_id', $this->f->facilities[$name])->value('id');
        foreach (['availability', 'consistency', 'validity'] as $dimension) {
            foreach ([1, 2, 3] as $slot) {
                Rows::itemResponse(['assessment_id' => $assessment, 'dimension' => $dimension, 'month_slot' => $slot, 'item_code' => 'register', 'value' => 'yes']);
                Rows::itemResponse(['assessment_id' => $assessment, 'dimension' => $dimension, 'month_slot' => $slot, 'item_code' => 'summary', 'value' => 'no']);
                Rows::itemResponse(['assessment_id' => $assessment, 'dimension' => $dimension, 'month_slot' => $slot, 'item_code' => 'dhis', 'value' => 'na', 'is_applicable' => false]);
            }
        }
        foreach (['yes', 'no', 'yes'] as $value) {
            Rows::itemResponse(['assessment_id' => $assessment, 'dimension' => 'consistency', 'month_slot' => 2, 'item_code' => 'clients', 'value' => $value]);
        }
    }
});

/** @param  array<string, mixed>  $filter */
function adminFilter(array $filter): ReportFilter
{
    return ReportFilter::fromArray($filter, ReportAudience::Admin);
}

/** A full-precision cell equals the value; the displayed cell its display; the band its label. */
function expectScore(array $row, string $name, ScoreValue $score, bool $band = true): void
{
    $precise = $row["{$name} (full precision)"];
    if ($score->value === null) {
        expect($precise)->toBe('', "{$name} has no data")->and($row["{$name} (displayed)"])->toBe('');
    } else {
        expect(abs((float) $precise - $score->value))->toBeLessThan(1e-9, "{$name}: {$precise} vs {$score->value}")
            ->and($row["{$name} (displayed)"])->toBe($score->display, "{$name} displayed");
    }
    if ($band) {
        expect($row["{$name} band"])->toBe($score->band === null ? '' : $score->bandLabel, "{$name} band");
    }
}

/** The status the export should give rows from these rounds. */
function statusOf(array $roundIds): string
{
    return Round::query()->whereIn('id', $roundIds)->where('status', 'open')->exists() ? 'Provisional — round not closed' : 'Published';
}

it('reconciles the state summary with its four aggregators', function (array $filter): void {
    $rows = collect(ExportRunner::csv(ExportRunner::run('state_summary', $filter))['rows']);
    $report = adminFilter($filter);
    $find = fn (string $section, string $measure, string $group): array => $rows->first(fn (array $r): bool => $r['Section'] === $section && $r['Measure'] === $measure && $r['Group'] === $group)
        ?? throw new RuntimeException("No row {$section} / {$measure} / {$group}");

    $summary = app(StateSummaryAggregator::class)->summary($report);
    $average = $find('Headline', 'Average quality score', 'Kaduna State');
    expectScore($average, 'Score', $summary->overall);
    expect((int) $average['Facilities'])->toBe($summary->facilitiesVisited)
        ->and((int) $find('Headline', 'Local governments', 'Kaduna State')['Facilities'])->toBe($summary->lgasCovered);

    $dimensions = app(DimensionAggregator::class)->dimensions($report);
    foreach (['Availability' => $dimensions->availability, 'Consistency' => $dimensions->consistency, 'Validity' => $dimensions->validity, 'Overall' => $dimensions->overall] as $label => $score) {
        expectScore($find('The three dimensions', $label, 'Kaduna State'), 'Score', $score);
    }

    $split = app(OwnerSplitAggregator::class)->split($report);
    foreach (['Public' => $split->public, 'Private' => $split->private] as $owner => $group) {
        foreach (['Overall' => $group->overall, 'Availability' => $group->availability, 'Consistency' => $group->consistency, 'Validity' => $group->validity] as $label => $score) {
            $row = $find('Public and private', $label, $owner);
            expectScore($row, 'Score', $score);
            expect((int) $row['Facilities'])->toBe($group->facilities);
        }
    }

    $coverage = app(CoverageAggregator::class)->coverage($report);
    foreach ($coverage->perLga as $lga) {
        expect((int) $find('Coverage by LGA', 'Facilities visited', $lga->name)['Facilities'])->toBe($lga->facilities);
    }
    $mean = $find('Coverage by LGA', 'Mean facilities visited per LGA', 'Kaduna State');
    expect(abs((float) $mean['Score (full precision)'] - $coverage->stateMean))->toBeLessThan(1e-9);
    foreach ($coverage->byLevel as $level) {
        $row = $find('Coverage by level', 'Overall', $level->level->label());
        expectScore($row, 'Score', $level->overall);
        expect((int) $row['Facilities'])->toBe($level->facilities);
    }

    $status = statusOf(array_map(fn (Round $r): int => $r->id, app(RoundSelection::class)->current($report)));
    expect($rows->pluck('Status')->unique()->values()->all())->toBe([$status]);
})->with(REPORT_FILTERS);

it('reconciles the LGA table with LgaTableAggregator', function (array $filter): void {
    $rows = collect(ExportRunner::csv(ExportRunner::run('lga_table', $filter))['rows'])->keyBy('LGA code');
    $expected = app(LgaTableAggregator::class)->rows(adminFilter($filter));

    expect($rows)->toHaveCount(count($expected));
    foreach ($expected as $lga) {
        $row = $rows[$lga->code];
        expect($row['LGA'])->toBe($lga->name)->and((int) $row['Facilities'])->toBe($lga->facilities);
        foreach (['Availability' => $lga->availability, 'Consistency' => $lga->consistency, 'Validity' => $lga->validity, 'Month 1' => $lga->m1, 'Month 2' => $lga->m2, 'Month 3' => $lga->m3, 'Overall' => $lga->overall] as $name => $score) {
            expectScore($row, $name, $score);
        }
    }
})->with(REPORT_FILTERS);

it('reconciles facility scores, every page and every metric, with FacilityScoresQuery', function (array $filter, string $metric): void {
    $rows = ExportRunner::csv(ExportRunner::run('facility_scores', [...$filter, 'metric' => $metric]))['rows'];
    $report = adminFilter($filter);
    $label = ucfirst($metric);
    // The page, one row at a time across every page, so the export is checked against pagination too.
    $page = fn (Metric $m): array => app(FacilityScoresQuery::class)->paginate($report, FacilitySort::default(Metric::from($metric)), 1, 2)->total === 0 ? [] : array_merge(...array_map(
        fn (int $p): array => app(FacilityScoresQuery::class)->paginate($report, new FacilitySort($m, 'score', 'asc'), $p, 2)->rows,
        range(1, max(1, app(FacilityScoresQuery::class)->paginate($report, FacilitySort::default($m), 1, 2)->lastPage)),
    ));
    $inView = $page(Metric::from($metric));

    expect(array_column($rows, 'Facility code'))->toBe(array_map(fn ($r): string => $r->code, $inView));
    $byCode = collect($rows)->keyBy('Facility code');
    foreach ($inView as $facility) {
        $row = $byCode[$facility->code];
        expect($row['Round'])->toBe($facility->roundLabel)->and($row['Assessed on'])->toBe($facility->assessedOn)->and($row['Source'])->toBe('ODK Central')
            ->and($row['Status'])->toBe(statusOf([(int) $facility->roundId]));
        expectScore($row, "{$label}, month 1", $facility->m1);
        expectScore($row, "{$label}, month 2", $facility->m2);
        expectScore($row, "{$label}, month 3", $facility->m3);
        expectScore($row, "{$label} score", $facility->score);
        expectScore($row, 'Overall', $facility->overall);
    }
    // And every metric's score is that metric's page column.
    foreach (Metric::cases() as $each) {
        foreach ($page($each) as $facility) {
            expectScore($byCode[$facility->code], ucfirst($each->value), $facility->score);
        }
    }
})->with(REPORT_FILTERS)->with(['overall', 'consistency']);

it('reconciles item responses with the facility drawer', function (array $filter): void {
    $rows = collect(ExportRunner::csv(ExportRunner::run('item_responses', $filter))['rows']);
    $report = adminFilter($filter);
    $rounds = app(RoundSelection::class)->current($report);
    $drawer = app(FacilityDetailAggregator::class);

    $expected = 0;
    foreach ($rounds as $round) {
        foreach (['F1', 'F3', 'F6'] as $name) {
            $facility = Facility::query()->findOrFail($this->f->facilities[$name]);
            $inFilter = DB::table('facilities')->where('id', $facility->id)
                ->when($report->lga !== null, fn ($q) => $q->whereIn('lga_id', DB::table('lgas')->where('code', $report->lga)->select('id')))
                ->when($report->level !== null, fn ($q) => $q->where('level', $report->level->value))
                ->when($report->owner !== null, fn ($q) => $q->where('ownership', $report->owner->value))->exists();
            if (! $inFilter) {
                continue;
            }
            foreach ($drawer->forFacility($facility, $round, $report)->items as $group) {
                if ($report->monthSlot() !== null && $group['slot'] !== $report->monthSlot()) {
                    continue;
                }
                foreach ($group['items'] as $item) {
                    $row = $rows->first(fn (array $r): bool => $r['Facility code'] === $facility->code && $r['Round'] === $round->label()
                        && $r['Dimension'] === $group['dimension_label'] && (int) $r['Month slot'] === $group['slot'] && $r['Item code'] === $item['code']);
                    expect($row)->not->toBeNull("{$facility->code} {$round->label()} {$group['dimension']} {$group['slot']} {$item['code']}");
                    expect($row['Result'])->toBe(match ($item['result']) {
                        'pass' => 'Pass', 'fail' => 'Fail', 'na' => 'N/A', null => '', default => $item['result'],
                    })->and($row['Checks passed'])->toBe((string) ($item['checks']['passed'] ?? ''))
                        ->and($row['Checks'])->toBe((string) ($item['checks']['total'] ?? ''))
                        ->and($row['Source'])->toBe('ODK Central')
                        ->and($row['Status'])->toBe(statusOf([$round->id]));
                    $expected++;
                }
            }
        }
    }
    expect($rows)->toHaveCount($expected);
})->with(REPORT_FILTERS);

it('reconciles data issues with QuarantineQuery, rule codes and details, with a source column', function (array $filter, array $query): void {
    $kobo = Rows::sourceId('kobo');
    Rows::quarantined(['round_id' => $this->f->q2, 'lga_ref' => 'AAA', 'failures' => json_encode([['code' => 'LGA_UNKNOWN', 'detail' => 'lga was blank', 'field' => 'lga'], ['code' => 'FACILITY_UNKNOWN', 'detail' => "facility 'X' is not on the master list", 'field' => 'facility']])]);
    Rows::quarantined(['round_id' => $this->f->q2, 'lga_ref' => 'Bravo', 'source_id' => $kobo, 'failures' => json_encode([['code' => 'ROUND_WINDOW', 'detail' => 'visit date 2023-07-01 is not inside any round', 'field' => 'visit']])]);
    Rows::quarantined(['round_id' => $this->f->q1, 'status' => 'resolved', 'resolution' => 'rejected', 'resolved_at' => now(), 'failures' => json_encode([['code' => 'ROUND_WINDOW', 'detail' => 'too early', 'field' => 'visit']])]);

    if (isset($query['source_id'])) {
        $query['source_id'] = Rows::sourceId((string) $query['source_id']);
    }
    $rows = ExportRunner::csv(ExportRunner::run('data_issues', $filter))['rows'];
    $expected = app(QuarantineQuery::class)->paginate($query)->items();

    expect(array_map(fn (array $r): int => (int) $r['Record'], $rows))->toBe(array_map(fn ($record): int => (int) $record->id, $expected));
    foreach ($expected as $i => $record) {
        expect($rows[$i]['Instance ID'])->toBe($record->instance_id)
            ->and($rows[$i]['Rule codes'])->toBe(implode('; ', array_column($record->failures, 'code')))
            ->and($rows[$i]['Details'])->toBe(implode('; ', array_column($record->failures, 'detail')))
            ->and($rows[$i]['Source'])->toBe((int) $record->source_id === $kobo ? 'KoboToolbox' : 'ODK Central');
    }
})->with([
    'everything' => [[], []],
    'open' => [['state' => 'open'], ['state' => 'open']],
    'one rule' => [['rule' => 'ROUND_WINDOW'], ['rule' => 'ROUND_WINDOW']],
    'KoboToolbox' => [['source' => 'kobo'], ['source_id' => 'kobo']],
    'an LGA' => [['lga' => 'AAA', 'state' => 'open'], ['lga' => 'AAA', 'state' => 'open']],
]);

it('reconciles pull history with PullHistoryQuery, with a source column', function (array $filter): void {
    $odk = Rows::sourceId('odk');
    $kobo = Rows::sourceId('kobo');
    Rows::pullRun(['source_id' => $odk, 'trigger_type' => 'manual', 'started_at' => '2026-05-10 09:00:00+01', 'finished_at' => '2026-05-10 09:02:05+01', 'outcome' => 'succeeded', 'fetched' => 40, 'parsed' => 40, 'accepted' => 35, 'flagged' => 2, 'quarantined' => 3]);
    Rows::pullRun(['source_id' => $kobo, 'trigger_type' => 'scheduled', 'started_at' => '2026-05-11 10:00:00+01', 'finished_at' => '2026-05-11 10:00:30+01', 'outcome' => 'failed', 'error' => 'HTTP 401']);
    Rows::pullRun(['source_id' => $kobo, 'trigger_type' => 'reconcile', 'started_at' => '2026-02-01 02:30:00+01', 'finished_at' => '2026-02-01 02:31:00+01', 'outcome' => 'succeeded', 'fetched' => 7, 'duplicates' => 7]);

    $rows = ExportRunner::csv(ExportRunner::run('pull_history', $filter))['rows'];
    $query = array_intersect_key($filter, array_flip(['source', 'trigger', 'outcome']));
    if (isset($filter['year'])) {
        $query += ['from' => '2026-04-01', 'to' => '2026-06-30'];
    }
    $expected = app(PullHistoryQuery::class)->paginate($query)->items();

    expect($rows)->toHaveCount(count($expected));
    foreach ($expected as $i => $run) {
        expect((int) $rows[$i]['Run'])->toBe($run['id'])
            ->and($rows[$i]['Source'])->toBe($run['source']['name'] ?? 'Unknown source');
        foreach (['Fetched' => 'fetched', 'Parsed' => 'parsed', 'Accepted' => 'accepted', 'Flagged' => 'flagged', 'Quarantined' => 'quarantined', 'Already stored' => 'duplicates', 'From another source' => 'cross_source_duplicates', 'Deleted at source' => 'deletions_detected'] as $heading => $key) {
            expect((int) $rows[$i][$heading])->toBe($run[$key], "{$heading} of run {$run['id']}");
        }
        expect($rows[$i]['Duration (seconds)'])->toBe($run['duration'] === null ? '' : (string) ($run['duration']['minutes'] * 60 + $run['duration']['seconds']));
    }
})->with([
    'everything' => [[]],
    'ODK Central' => [['source' => 'odk']],
    'failed' => [['outcome' => 'failed']],
    'scheduled' => [['trigger' => 'scheduled']],
    'Q2 2026' => [['year' => 2026, 'quarter' => 2]],
]);

it('reconciles the audit log with AuditLogQuery', function (array $filter): void {
    $hauwa = activity()->withoutLogs(fn () => User::factory()->create(['name' => 'Hauwa Bello']));
    $round = Round::query()->findOrFail($this->f->q2);
    AuditLog::record($round, $hauwa, 'window_updated', old: ['window_end' => '2026-06-30'], new: ['window_end' => '2026-07-10'], reason: 'Late visits from Ikara');
    AuditLog::record(null, $hauwa, 'pull_requested', old: [], new: ['trigger' => 'manual', 'source' => 'kobo']);
    AuditLog::record($round, null, 'closed', old: [], new: [], reason: 'Ready to publish');
    $filter = str_replace('{hauwa}', (string) $hauwa->id, json_encode($filter, JSON_THROW_ON_ERROR));
    $filter = json_decode($filter, true);
    if (isset($filter['actor'])) {
        $filter['actor'] = (int) $filter['actor'];
    }

    // Read before the export, which adds its own entry when it finishes.
    $expected = app(AuditLogQuery::class)->paginate($filter)->items();
    $rows = ExportRunner::csv(ExportRunner::run('audit_log', $filter))['rows'];

    expect($rows)->toHaveCount(count($expected));
    foreach ($expected as $i => $entry) {
        expect($rows[$i]['Who'])->toBe($entry['actor'] ?? ($entry['console'] ? 'Console' : 'System'))
            ->and($rows[$i]['Reason'])->toBe((string) ($entry['reason'] ?? ''))
            ->and($rows[$i]['Source'])->toBe((string) ($entry['source'] ?? ''));
    }
})->with([
    'everything' => [[]],
    'one administrator' => [['actor' => '{hauwa}']],
    'rounds' => [['type' => 'round']],
    'KoboToolbox' => [['source' => 'kobo']],
    'reason' => [['q' => 'ikara']],
]);
