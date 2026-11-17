<?php

declare(strict_types=1);

use App\Domain\Export\Models\Export;
use App\Domain\Export\Report\PdfText;
use App\Domain\Reporting\Aggregators\DimensionAggregator;
use App\Domain\Reporting\Aggregators\StateSummaryAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Enums\ReportAudience;
use Database\Seeders\LgaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ExportRunner;
use Tests\Support\ReportingFixture;
use Tests\Support\Rows;

// D-101: The PDF round report rendered for real by Chromium and read back with
// pdftotext. Needs the worker image's tools, so it is the pdf group, outside the default suite:
// `make pdf` runs it (and edqa:pdf-smoke) in the worker container. The 23 real LGAs; facilities
// in four of them. Q1 2026 closed, Q2 2026 open. Elsewhere (the app and CI images, which have
// neither Chromium nor pdftotext) the tests skip themselves.

beforeEach(function (): void {
    if (! is_executable((string) config('edqa.pdf.chrome_path')) || ! is_executable((string) config('edqa.pdf.pdftotext'))) {
        $this->markTestSkipped('Chromium and pdftotext are in the worker image only: run make pdf.');
    }
    $this->seed(LgaSeeder::class);
    Storage::fake('exports');
    $config = json_encode(ReportingFixture::CONFIG);
    $v1 = Rows::ruleVersion(['version' => 1, 'config' => $config, 'published_at' => now()->subYear()]);
    Rows::ruleVersion(['version' => 2, 'config' => $config, 'published_at' => now()]);
    $this->q1 = Rows::round(['year' => 2026, 'quarter' => 1, 'window_start' => '2026-01-01', 'window_end' => '2026-03-31', 'status' => 'closed', 'rule_version_id' => $v1]);
    $this->q2 = Rows::round(['year' => 2026, 'quarter' => 2, 'window_start' => '2026-04-01', 'window_end' => '2026-06-30']);
    $versions = [$this->q1 => $v1, $this->q2 => (int) DB::table('scoring_rule_versions')->where('version', 2)->value('id')];

    $lgas = DB::table('lgas')->orderBy('name')->pluck('id', 'name');
    $plan = [
        ['Chikun', 'public', [95, 92, 98], [91, 90, 93], [96, 97, 99]],
        ['Ikara', 'private', [88, 84, 80], [70, 72, 75], [85, 86, 87]],
        ['Makarfi', 'public', [60, 62, 58], [55, 50, 48], [70, 68, 71]],
        ['Zaria', 'public', [80, 85, 90], [82, 84, 86], [79, 81, 83]],
    ];
    foreach ($plan as $i => [$lga, $owner, $a, $c, $v]) {
        $facility = Rows::facility(['code' => "KD-PDF{$i}", 'name' => "Facility PDF{$i}", 'lga_id' => $lgas[$lga], 'ownership' => $owner, 'level' => 'primary',
            'ward_id' => Rows::ward(['lga_id' => $lgas[$lga], 'name' => "Ward PDF{$i}"])]);
        foreach ([$this->q1 => '2026-02-10 09:00:00+01', $this->q2 => '2026-05-10 09:00:00+01'] as $round => $started) {
            $assessment = Rows::assessment(['round_id' => $round, 'facility_id' => $facility, 'started_at' => $started, 'ended_at' => $started, 'assessor_name' => 'MARKER-ASSESSOR']);
            ReportingFixture::scores($assessment, $versions[$round], $a, $c, $v);
        }
    }
});

/** @return list<string> the PDF's pages, as pdftotext reads them */
function pdfPages(Export $export, bool $raw = false): array
{
    $text = app(PdfText::class)->text(Storage::disk('exports')->path((string) $export->path), $raw);

    return array_values(array_filter(explode("\f", $text), fn (string $page): bool => trim($page) !== ''));
}

/** Collapses pdftotext's spacing so a phrase reads as one line. */
function pdfFlat(string $text): string
{
    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

it('renders a closed round to a tagged A4 PDF with its title, the headline and every one of the 23 LGAs, and no DRAFT', function (): void {
    $export = ExportRunner::run('round_report', ['round' => $this->q1], 'pdf');
    $path = Storage::disk('exports')->path((string) $export->path);
    $text = pdfFlat(implode("\n", pdfPages($export)));
    $filter = new ReportFilter(year: 2026, quarter: 1, audience: ReportAudience::Public);
    $summary = app(StateSummaryAggregator::class)->summary($filter);
    $overall = app(DimensionAggregator::class)->dimensions($filter)->overall;

    expect(basename($path))->toBe('round-report-2026-Q1.pdf')
        ->and(file_get_contents($path, length: 5))->toBe('%PDF-')
        ->and($export->row_count)->toBeGreaterThanOrEqual(4);

    expect($text)->toContain('Kaduna State eDQA')
        ->toContain('Support: HSDF')
        // The headline: its tile (the tiles read column by column) and its row in the dimensions table.
        ->toContain('State headline Average quality score')
        ->toContain('Facilities visited ')
        ->toContain('Overall '.$overall->display.' '.$overall->bandLabel)
        ->toContain(' '.$summary->overall->headline.' ')
        ->toContain('Closed rounds only are published.')
        ->toContain('Page 1 of')
        ->not->toContain('DRAFT')
        ->not->toContain('MARKER')
        ->not->toContain('Facility PDF');
    $lgas = DB::table('lgas')->orderBy('name')->pluck('name');
    expect($lgas)->toHaveCount(23);
    foreach ($lgas as $name) {
        expect($text)->toContain($name);
    }

    $info = Process::run(['pdfinfo', $path])->output();
    expect($info)->toMatch('/^Tagged:\s+yes$/m')
        ->toMatch('/^Title:\s+Kaduna State eDQA round report, Q1 2026$/m')
        ->toMatch('/^Page size:\s+595\.\d+ x 841\.\d+ pts \(A4\)$/m');
})->group('pdf');

it('watermarks an open round DRAFT on every page and names the file DRAFT', function (): void {
    $export = ExportRunner::run('round_report', ['round' => $this->q2], 'pdf');
    $pages = pdfPages($export, raw: true);

    expect(basename((string) $export->path))->toBe('round-report-2026-Q2-DRAFT.pdf')
        ->and(count($pages))->toBeGreaterThanOrEqual(4);
    foreach ($pages as $i => $page) {
        // Twice on every page: the diagonal watermark (position: fixed, read whole in content-stream
        // order: -raw) and the running header.
        expect(substr_count(pdfFlat($page), 'DRAFT — round not closed'))->toBeGreaterThanOrEqual(2, 'page '.($i + 1).' has the watermark and the header');
    }
})->group('pdf');

it('passes edqa:pdf-smoke', function (): void {
    $this->artisan('edqa:pdf-smoke')->expectsOutputToContain('PDF smoke test passed')->assertSuccessful();
})->group('pdf');
