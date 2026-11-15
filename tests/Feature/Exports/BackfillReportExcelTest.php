<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\LgaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\ExportRunner;
use Tests\Support\Rows;

// Phase 6 step 2: the backfill report as Excel (edqa:backfill-report --format=xlsx, and the Data
// sources page's download through the export plumbing). Every sheet reconciles with counts of the
// tables themselves, and nothing personal is in it, though the payloads and assessments carry an
// assessor, a device and GPS.

beforeEach(function (): void {
    $this->seed(LgaSeeder::class);
    Storage::fake('exports');
    $this->odk = Rows::sourceId('odk');
    $this->kobo = Rows::sourceId('kobo');
    $this->q1 = Rows::round(['year' => 2026, 'quarter' => 1, 'window_start' => '2026-01-01', 'window_end' => '2026-03-31']);
    $this->q2 = Rows::round(['year' => 2026, 'quarter' => 2, 'window_start' => '2026-04-01', 'window_end' => '2026-06-30']);
    $personal = ['assessor' => 'MARKER-ASSESSOR Zainab Lawal', 'device_id' => 'MARKER-DEVICE-77', 'gps' => '10.5123 7.4321'];

    $submission = fn (int $source, string $status, string $version = '2026.1'): int => Rows::submission(['source_id' => $source, 'status' => $status, 'form_version' => $version, 'payload' => json_encode($personal)]);
    $assessed = function (int $source, int $round, string $status) use ($submission): void {
        Rows::assessment(['submission_id' => $submission($source, $status), 'round_id' => $round, 'status' => $status,
            'assessor_name' => 'MARKER-ASSESSOR Zainab Lawal', 'device_id' => 'MARKER-DEVICE-77', 'gps' => json_encode(['type' => 'Point', 'coordinates' => [7.4321, 10.5123]])]);
    };
    $held = function (int $source, ?int $round, array $failures, string $version = '2026.1', ?string $facility = null, ?string $lga = null, string $startedAt = '2026-05-04T09:00:00+01:00', string $status = 'quarantined') use ($submission, $personal): void {
        Rows::quarantined(['submission_id' => $submission($source, $status, $version), 'source_id' => $source, 'round_id' => $round,
            'facility_ref' => $facility, 'lga_ref' => $lga, 'payload' => json_encode($personal),
            'parsed' => json_encode(['started_at' => $startedAt, 'facility_ref' => $facility, 'assessor' => 'MARKER-ASSESSOR Zainab Lawal']),
            'failures' => json_encode($failures), 'status' => $status === 'rejected' ? 'resolved' : 'open', 'resolution' => $status === 'rejected' ? 'rejected' : null]);
    };
    $hard = fn (string $code, string $detail): array => ['code' => $code, 'severity' => 'hard', 'detail' => $detail, 'field' => 'x'];

    foreach ([[$this->odk, $this->q1, 'accepted'], [$this->odk, $this->q1, 'accepted'], [$this->odk, $this->q2, 'flagged'], [$this->kobo, $this->q2, 'accepted'], [$this->kobo, $this->q2, 'accepted']] as [$source, $round, $status]) {
        $assessed($source, $round, $status);
    }
    $held($this->odk, $this->q2, [$hard('FACILITY_UNKNOWN', "facility 'KD-GONE-1' is not on the master list")], facility: 'KD-GONE-1', lga: 'Zaria');
    $held($this->odk, $this->q2, [$hard('FACILITY_UNKNOWN', "facility 'KD-GONE-1' is not on the master list"), $hard('LGA_UNKNOWN', 'lga was blank')], version: '2025.2', facility: 'KD-GONE-1', lga: 'Zaria');
    $held($this->kobo, null, [$hard('ROUND_WINDOW', "visit date 2023-07-01 is not inside any round's window")], startedAt: '2023-07-01T10:00:00+01:00');
    $held($this->kobo, $this->q2, [$hard('DUPLICATE_ASSESSMENT', 'Q2 2026 already recorded for facility KD-1 (instance uuid:a from ODK Central; this one from KoboToolbox)')], facility: 'KD-1');
    $held($this->odk, $this->q1, [$hard('FACILITY_UNKNOWN', "facility '' blank")], facility: '', status: 'rejected');
    Rows::pullRun(['source_id' => $this->kobo, 'cross_source_duplicates' => 4]);
    Rows::pullRun(['source_id' => $this->odk, 'cross_source_duplicates' => 1]);
});

/** @return array<string, list<list<mixed>>> each sheet's rows (header first), by title */
function backfillSheets(Spreadsheet $book): array
{
    $sheets = [];
    foreach ($book->getAllSheets() as $sheet) {
        $sheets[$sheet->getTitle()] = $sheet->toArray(null, true, false);
    }

    return $sheets;
}

/** A sheet's rows keyed by heading. @return list<array<string, mixed>> */
function keyed(array $rows): array
{
    $headings = array_shift($rows);

    return array_map(fn (array $row): array => array_combine($headings, $row), $rows);
}

it('writes the workbook from the command, every sheet reconciling with the tables', function (): void {
    $this->artisan('edqa:backfill-report', ['--format' => 'xlsx'])->expectsOutputToContain('backfill-report-')->assertSuccessful();
    $file = collect(Storage::disk('exports')->files())->first(fn (string $f): bool => str_ends_with($f, '.xlsx'));
    expect($file)->toMatch('/^backfill-report-\d{8}-\d{6}\.xlsx$/');
    $sheets = backfillSheets(IOFactory::load(Storage::disk('exports')->path((string) $file)));

    expect(array_keys($sheets))->toBe(['Summary', 'By rule × year', 'By rule × form version', 'Examples', 'Unmatched facilities', 'About this file']);

    // Summary: per source × round, each figure counted again here from submissions and quarantine.
    $summary = collect(keyed($sheets['Summary']));
    $row = fn (string $source, string $round): array => $summary->first(fn (array $r): bool => $r['Source'] === $source && $r['Round'] === $round) ?? throw new RuntimeException("No row {$source} {$round}");
    $count = fn (int $source, ?int $round, string $status): int => DB::table('submissions as s')->leftJoin('assessments as a', 'a.submission_id', '=', 's.id')
        ->leftJoin('quarantined_records as q', 'q.submission_id', '=', 's.id')->where('s.source_id', $source)->where('s.status', $status)
        ->whereRaw('COALESCE(a.round_id, q.round_id) IS NOT DISTINCT FROM ?', [$round])->count();
    foreach ([['ODK Central', $this->odk, 'Q1 2026', $this->q1], ['ODK Central', $this->odk, 'Q2 2026', $this->q2], ['KoboToolbox', $this->kobo, 'Q2 2026', $this->q2], ['KoboToolbox', $this->kobo, 'No round (outside every window, or not processed)', null]] as [$name, $source, $label, $round]) {
        $r = $row($name, $label);
        foreach (['Accepted' => 'accepted', 'Flagged' => 'flagged', 'Quarantined' => 'quarantined', 'Rejected' => 'rejected'] as $heading => $status) {
            expect($r[$heading])->toBe($count($source, $round, $status), "{$name} {$label} {$heading}");
        }
        expect($r['Pulled'])->toBe($r['Accepted'] + $r['Flagged'] + $r['Quarantined'] + $r['Rejected'] + $r['Not processed']);
    }
    expect($row('KoboToolbox', 'Q2 2026')['Duplicates (held)'])->toBe(1)
        ->and($row('ODK Central', 'Q2 2026')['Duplicates (held)'])->toBe(0)
        ->and($row('KoboToolbox', 'Total')['Copies from the other source (not stored)'])->toBe(4)
        ->and($row('ODK Central', 'Total')['Copies from the other source (not stored)'])->toBe(1)
        ->and($row('All sources', 'Grand total')['Pulled'])->toBe(DB::table('submissions')->where('status', '<>', 'superseded')->count())
        ->and($row('ODK Central', 'Total')['Pulled'])->toBe(DB::table('submissions')->where('source_id', $this->odk)->count());

    // By rule × year and × form version: the open records under each hard rule.
    $byYear = collect(keyed($sheets['By rule × year']));
    $byVersion = collect(keyed($sheets['By rule × form version']));
    $open = fn (int $source, string $code): int => DB::table('quarantined_records')->where('source_id', $source)->where('status', 'open')
        ->whereRaw('failures @> ?::jsonb', [json_encode([['code' => $code]])])->count();
    expect($byYear->first(fn (array $r): bool => $r['Source'] === 'ODK Central' && $r['Rule code'] === 'FACILITY_UNKNOWN')['Records'])->toBe($open($this->odk, 'FACILITY_UNKNOWN'))
        ->and($byYear->first(fn (array $r): bool => $r['Rule code'] === 'ROUND_WINDOW')['Year'])->toBe(2023)
        ->and($byYear->first(fn (array $r): bool => $r['Rule code'] === 'FACILITY_UNKNOWN')['Rule'])->toBe('Unknown facility')
        ->and($byVersion->filter(fn (array $r): bool => $r['Rule code'] === 'FACILITY_UNKNOWN')->sum('Records'))->toBe($open($this->odk, 'FACILITY_UNKNOWN'))
        ->and($byVersion->pluck('Form version')->unique()->sort()->values()->all())->toBe(['2025.2', '2026.1']);

    // Examples: the commonest details per hard rule.
    $examples = collect(keyed($sheets['Examples']));
    expect($examples->first(fn (array $r): bool => $r['Rule code'] === 'FACILITY_UNKNOWN'))->toMatchArray(['Detail' => "facility 'KD-GONE-1' is not on the master list", 'Records' => 2]);

    // Unmatched facilities: open FACILITY_UNKNOWN records by the facility as sent.
    $unmatched = keyed($sheets['Unmatched facilities']);
    expect($unmatched)->toHaveCount(1)
        ->and($unmatched[0])->toMatchArray(['Source' => 'ODK Central', 'Facility as sent' => 'KD-GONE-1', 'LGA as sent' => 'Zaria', 'Records' => 2, 'Rounds' => '2026 Q2']);

    $about = collect($sheets['About this file'])->mapWithKeys(fn (array $r): array => [(string) $r[0] => $r[1]]);
    expect($about['Export'])->toBe('Backfill report')->and($about['Filters'])->toBe('All sources')
        ->and($about['Generated by'])->toBe('Console')->and($about['Personal details'])->toBe('Not included')
        ->and($about['Rows'])->toBe(array_sum(array_map(fn (string $title): int => count($sheets[$title]) - 1, ['Summary', 'By rule × year', 'By rule × form version', 'Examples', 'Unmatched facilities'])));
});

it('carries no personal field: no assessor, device, GPS or attachment, in any sheet', function (): void {
    $this->artisan('edqa:backfill-report', ['--format' => 'xlsx'])->assertSuccessful();
    $file = (string) collect(Storage::disk('exports')->files())->first(fn (string $f): bool => str_ends_with($f, '.xlsx'));
    $cells = collect(backfillSheets(IOFactory::load(Storage::disk('exports')->path($file))))->flatten()->filter()->map(fn (mixed $v): string => (string) $v);

    expect($cells->filter(fn (string $v): bool => str_contains($v, 'MARKER') || str_contains($v, '10.5123') || str_contains($v, 'attachments/'))->all())->toBe([])
        ->and($cells->filter(fn (string $v): bool => in_array($v, ['Assessor', 'Device ID', 'Visit latitude', 'Visit longitude', 'Attachment links'], true))->all())->toBe([]);
});

it('limits the report to one source, from the command and from the Data sources download', function (): void {
    $this->artisan('edqa:backfill-report', ['--format' => 'xlsx', '--source' => 'kobo'])->assertSuccessful();
    $file = (string) collect(Storage::disk('exports')->files())->first(fn (string $f): bool => str_ends_with($f, '.xlsx'));
    $summary = keyed(backfillSheets(IOFactory::load(Storage::disk('exports')->path($file)))['Summary']);
    expect(collect($summary)->pluck('Source')->unique()->values()->all())->toBe(['KoboToolbox', 'All sources']);

    $export = ExportRunner::run('backfill_report', ['source' => 'kobo'], 'xlsx');
    $sheets = backfillSheets(IOFactory::load(Storage::disk('exports')->path((string) $export->path)));
    expect(collect(keyed($sheets['Summary']))->pluck('Source')->unique()->values()->all())->toBe(['KoboToolbox', 'All sources'])
        ->and(keyed($sheets['Unmatched facilities']))->toBe([])
        ->and(collect($sheets['About this file'])->mapWithKeys(fn (array $r): array => [(string) $r[0] => $r[1]])['Filters'])->toBe('KoboToolbox');
});

it('downloads through the export plumbing as Excel only, audited, and refuses CSV', function (): void {
    Queue::fake();
    $admin = activity()->withoutLogs(fn () => User::factory()->create());

    $this->actingAs($admin)->postJson('/admin/api/exports', ['type' => 'backfill_report', 'format' => 'csv', 'filter' => ['source' => 'odk']])
        ->assertStatus(409)->assertJsonPath('code', 'excel_only');
    $this->actingAs($admin)->postJson('/admin/api/exports', ['type' => 'backfill_report', 'format' => 'xlsx', 'filter' => ['source' => 'nope']])
        ->assertUnprocessable();
    $this->actingAs($admin)->postJson('/admin/api/exports', ['type' => 'backfill_report', 'format' => 'xlsx', 'filter' => ['source' => 'odk']])->assertStatus(202);

    expect(json_decode((string) DB::table('activity_log')->where('event', 'export_requested')->value('properties'), true)['attributes'])
        ->toMatchArray(['type' => 'backfill_report', 'format' => 'xlsx', 'include_private' => false, 'filter' => ['source' => 'odk']]);
});

it('still writes Markdown and CSV, and refuses a format it does not know', function (): void {
    $this->artisan('edqa:backfill-report', ['--format' => 'md'])->assertSuccessful();
    $this->artisan('edqa:backfill-report', ['--format' => 'pdf'])->expectsOutputToContain('md, csv, xlsx')->assertFailed();
    $this->artisan('edqa:backfill-report', ['--format' => 'xlsx', '--source' => 'nope'])->assertFailed();
});
