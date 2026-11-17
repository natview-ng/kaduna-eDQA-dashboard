<?php

declare(strict_types=1);

use App\Domain\Export\Actions\GenerateExport;
use App\Domain\Export\Enums\ExportStatus;
use App\Domain\Export\Jobs\GenerateRoundReport;
use App\Domain\Export\Models\Export;
use App\Domain\Export\Report\RoundReportBuilder;
use App\Domain\Export\Report\RoundReportView;
use App\Domain\Export\Types\ExportTypes;
use App\Domain\Reporting\Aggregators\DimensionAggregator;
use App\Domain\Reporting\Aggregators\ImplementationPlanAggregator;
use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\Aggregators\MonthSlotAggregator;
use App\Domain\Reporting\Aggregators\OwnerSplitAggregator;
use App\Domain\Reporting\Aggregators\QuarterTrendAggregator;
use App\Domain\Reporting\Aggregators\StateSummaryAggregator;
use App\Domain\Reporting\Aggregators\WeakestLgasAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Enums\ReportAudience;
use App\Domain\Round\Models\Round;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Spatie\Browsershot\Browsershot;
use Spatie\LaravelPdf\Facades\Pdf;
use Tests\Support\ReportingFixture;
use Tests\Support\Rows;

// Phase 6 step 5 (D-101): the PDF round report. Its HTML (what Chromium is given) is checked here
// against the aggregators, for personal fields and for the draft watermark; Chromium itself is
// faked (Pdf::fake()). The real render, read back with pdftotext, is the pdf group
// (RoundReportPdfTest, `make pdf`). ReportingFixture: Q1 2026 closed (F1 only), Q2 2026 open.

beforeEach(function (): void {
    $this->f = ReportingFixture::build();
    Storage::fake('exports');
    // Personal details that must never reach the report.
    DB::table('assessments')->update(['assessor_name' => 'MARKER-ASSESSOR']);
    Rows::planAction(['round_id' => $this->f->q2, 'lga_id' => $this->f->lgas['BBB'], 'dimension' => 'availability', 'status' => 'in_progress',
        'owner_contact' => 'MARKER-CONTACT 0803 555 0101', 'notes' => 'MARKER-NOTES the PHC in-charge']);
    $submission = Rows::submission(['instance_id' => 'uuid:MARKER-INSTANCE', 'external_id' => 'uuid:MARKER-EXTERNAL']);
    Rows::quarantined(['round_id' => $this->f->q2, 'submission_id' => $submission, 'facility_ref' => 'MARKER-FACREF', 'lga_ref' => 'MARKER-LGAREF']);
    Rows::quarantined(['round_id' => $this->f->q2, 'status' => 'resolved', 'resolution' => 'rejected',
        'failures' => json_encode([['code' => 'SCORE_RANGE', 'severity' => 'hard', 'detail' => 'x'], ['code' => 'LGA_UNKNOWN', 'severity' => 'hard', 'detail' => 'y']])]);
    // Reprocessed: in the figures now, so not counted as quarantined.
    Rows::quarantined(['round_id' => $this->f->q2, 'status' => 'resolved', 'resolution' => 'reprocessed']);
});

function reportHtml(int $roundId): string
{
    return app(RoundReportView::class)->html(app(RoundReportBuilder::class)->build(Round::query()->findOrFail($roundId)));
}

/** The text of the element marked data-figure="$name". */
function reportFigure(string $html, string $name): ?string
{
    $dom = new DOMDocument;
    @$dom->loadHTML($html, LIBXML_NOERROR);
    $node = (new DOMXPath($dom))->query("//*[@data-figure='{$name}']")->item(0);

    return $node === null ? null : trim(preg_replace('/\s+/', ' ', $node->textContent) ?? '');
}

it('shows the aggregators\' own figures for a closed round, as the public dashboard reads it', function (): void {
    $html = reportHtml($this->f->q1);
    $filter = new ReportFilter(year: 2026, quarter: 1, audience: ReportAudience::Public);
    $summary = app(StateSummaryAggregator::class)->summary($filter);
    $dimensions = app(DimensionAggregator::class)->dimensions($filter);
    $owners = app(OwnerSplitAggregator::class)->split($filter);

    expect(reportFigure($html, 'overall'))->toBe($summary->overall->headline)
        ->and(reportFigure($html, 'visited'))->toBe((string) $summary->facilitiesVisited)
        ->and(reportFigure($html, 'lgas'))->toBe((string) $summary->lgasCovered)
        ->and(reportFigure($html, 'strong'))->toBe((string) $summary->facilitiesStrong)
        ->and(reportFigure($html, 'weak'))->toBe((string) $summary->facilitiesWeak);
    foreach (['availability', 'consistency', 'validity', 'overall'] as $key) {
        expect(reportFigure($html, "dimension-{$key}"))->toBe($dimensions->{$key}->display)
            ->and(reportFigure($html, "public-{$key}"))->toStartWith($owners->public->{$key}->display);
    }
    // F1 scored 10 everywhere in Q1: one public facility, Needs action.
    expect($summary->overall->headline)->toBe('10')
        ->and(reportFigure($html, 'public-facilities'))->toBe('1')
        ->and($html)->not->toContain('DRAFT')
        ->and($html)->toContain('Published (round closed)');
});

it('shows every section\'s figures from its aggregator for an open round, read as the admin preview reads it', function (): void {
    $html = reportHtml($this->f->q2);
    $filter = new ReportFilter(year: 2026, quarter: 2, audience: ReportAudience::Admin);

    $summary = app(StateSummaryAggregator::class)->summary($filter);
    expect(reportFigure($html, 'overall'))->toBe($summary->overall->headline)
        ->and(reportFigure($html, 'visited'))->toBe('7');

    $owners = app(OwnerSplitAggregator::class)->split($filter);
    foreach (['public', 'private'] as $side) {
        expect(reportFigure($html, "{$side}-facilities"))->toBe((string) $owners->{$side}->facilities);
        foreach (['availability', 'consistency', 'validity', 'overall'] as $key) {
            expect(reportFigure($html, "{$side}-{$key}"))->toBe($owners->{$side}->{$key}->display.' '.$owners->{$side}->{$key}->bandLabel);
        }
    }

    // Quality by quarter: 2026 up to Q2; both rounds, each under its own rule version.
    $trend = app(QuarterTrendAggregator::class)->trend($filter);
    expect($trend)->toHaveCount(2);
    foreach ($trend as $point) {
        expect(reportFigure($html, "trend-{$point->roundId}-overall"))->toBe($point->overall->display);
    }

    $months = app(MonthSlotAggregator::class)->series($filter);
    expect($months)->toHaveCount(3);
    foreach ($months as $point) {
        foreach (['availability', 'consistency', 'validity', 'overall'] as $key) {
            expect(reportFigure($html, "month-{$point->slot}-{$key}"))->toBe($point->{$key}->display);
        }
    }

    foreach (app(LgaTableAggregator::class)->rows($filter) as $lga) {
        expect($html)->toContain('>'.$lga->name.'<');
        foreach (['availability', 'consistency', 'validity', 'm1', 'm2', 'm3', 'overall'] as $key) {
            expect(reportFigure($html, "lga-{$lga->code}-{$key}"))->toBe($lga->{$key}->display);
        }
    }
    foreach (app(WeakestLgasAggregator::class)->weakest($filter) as $lga) {
        expect(reportFigure($html, "weakest-{$lga->code}"))->toBe($lga->overall->display);
    }

    $plan = app(ImplementationPlanAggregator::class)->plan($filter);
    foreach ($plan->summary as $type) {
        expect(reportFigure($html, "plan-{$type->dimension->value}"))->toBe((string) $type->count);
    }
    // Bravo's stored action: its owner role and status, never its contact or notes.
    expect($html)->toContain('In progress')->toContain('LGA M&amp;E officer');
});

it('counts accepted and quarantined submissions, by rule and by source, open or rejected only', function (): void {
    $html = reportHtml($this->f->q2);

    expect(reportFigure($html, 'accepted'))->toBe('7')
        ->and(reportFigure($html, 'quarantined'))->toBe('2')
        // The rejected record fails two rules: counted under both, once in the total.
        ->and(reportFigure($html, 'rule-LGA_UNKNOWN'))->toBe('2')
        ->and(reportFigure($html, 'rule-SCORE_RANGE'))->toBe('1')
        ->and($html)->toContain((string) __('validation_rules.LGA_UNKNOWN.name'));
});

it('holds nothing personal: no assessor, facility name or code, source ID, contact or note', function (): void {
    foreach ([$this->f->q1, $this->f->q2] as $round) {
        $html = reportHtml($round);
        foreach (['MARKER', 'Facility F', 'KD-F', 'uuid:', 'Assessor', 'Ward F'] as $private) {
            expect($html)->not->toContain($private);
        }
    }
});

it('marks an open round DRAFT on every page, as a fixed watermark and in the running header', function (): void {
    $round = Round::query()->findOrFail($this->f->q2);
    $report = app(RoundReportBuilder::class)->build($round);
    $view = app(RoundReportView::class);
    $html = $view->html($report);

    expect($report->draft)->toBeTrue()
        ->and($html)->toContain('<div class="watermark" aria-hidden="true">DRAFT — round not closed</div>')
        ->and($html)->toMatch('/\.watermark \{\s*position: fixed;/')
        ->and($view->header($report))->toContain('DRAFT — round not closed')
        ->and($view->footer($report))->toContain('<span class="pageNumber"></span>')->toContain('<span class="totalPages"></span>');
    expect($view->header(app(RoundReportBuilder::class)->build(Round::query()->findOrFail($this->f->q1))))->not->toContain('DRAFT');
});

it('is print HTML: A4 portrait, tokens from app.css, the self-hosted font inline, table headers that repeat, a chart and a table for each figure', function (): void {
    $html = reportHtml($this->f->q2);

    expect($html)->toContain('<html lang="en">')
        ->toContain('<title>Kaduna State eDQA round report, Q2 2026</title>')
        ->toContain('@page { size: A4 portrait; }')
        ->toContain('--color-series-availability: #0d4b44;')
        ->toContain('--color-ink: #17302b;')
        ->toContain("font-family: 'IBM Plex Sans'; font-style: normal; font-weight: 600; src: url(data:font/woff2;base64,")
        ->toContain('thead { display: table-header-group; }')
        ->toContain('Support: HSDF')
        ->toContain('Closed rounds only are published.')
        // Nothing for Chromium to fetch: no script, no link, no remote URL.
        ->not->toContain('<script')
        ->not->toContain('<link')
        ->not->toMatch('/(src|href)="https?:/');

    // Five charts, each with its table (hard rule 11).
    expect(substr_count($html, '<svg class="chart"'))->toBe(5);
    foreach (['dimensions', 'owners', 'trend', 'months', 'lgas', 'weakest', 'plan-summary', 'quality-sources'] as $table) {
        expect($html)->toContain("data-table=\"{$table}\"");
    }
});

it('takes a round by id, by year and quarter, or the latest closed round; names an open round\'s file DRAFT', function (): void {
    $type = app(ExportTypes::class)->get('round_report');

    expect($type->filter(['round' => $this->f->q2]))->toBe(['round' => $this->f->q2])
        ->and($type->filter(['year' => 2026, 'quarter' => 2]))->toBe(['round' => $this->f->q2])
        // Closed rounds by default.
        ->and($type->filter([]))->toBe(['round' => $this->f->q1])
        ->and($type->fileName(['round' => $this->f->q1]))->toBe('round-report-2026-Q1')
        ->and($type->fileName(['round' => $this->f->q2]))->toBe('round-report-2026-Q2-DRAFT');
    expect(fn () => $type->filter(['round' => 999999]))->toThrow(ValidationException::class);

    DB::table('rounds')->update(['status' => 'open', 'rule_version_id' => null]);
    expect(fn () => $type->filter([]))->toThrow(ValidationException::class);
});

it('is queued as GenerateRoundReport on the exports queue, as a PDF only, and audited', function (): void {
    Queue::fake();
    $admin = activity()->withoutLogs(fn () => User::factory()->create());

    $id = $this->actingAs($admin)->postJson('/admin/api/exports', ['type' => 'round_report', 'format' => 'pdf', 'filter' => ['round' => $this->f->q2]])
        ->assertStatus(202)->json('data.export_id');
    Queue::assertPushedOn('exports', GenerateRoundReport::class, fn (GenerateRoundReport $job): bool => $job->exportId === $id);
    expect(json_decode((string) DB::table('activity_log')->where('event', 'export_requested')->value('properties'), true)['attributes'])
        ->toMatchArray(['type' => 'round_report', 'format' => 'pdf', 'include_private' => false, 'filter' => ['round' => $this->f->q2]]);

    $this->actingAs($admin)->postJson('/admin/api/exports', ['type' => 'round_report', 'format' => 'xlsx', 'filter' => ['round' => $this->f->q2]])->assertStatus(409)->assertJsonPath('code', 'wrong_format');
    $this->actingAs($admin)->postJson('/admin/api/exports', ['type' => 'round_report', 'format' => 'pdf', 'include_private' => true])->assertStatus(409)->assertJsonPath('code', 'private_not_offered');
});

it('renders the report HTML with Chromium (no sandbox, the configured path, never a URL), tagged and titled, then offers it for download', function (): void {
    Pdf::fake();
    config(['edqa.pdf.chrome_path' => '/opt/test/chromium']);
    $admin = activity()->withoutLogs(fn () => User::factory()->create());
    $export = Export::query()->create(['type' => 'round_report', 'filter' => ['round' => $this->f->q2], 'options' => ['format' => 'pdf', 'include_private' => false], 'requested_by' => $admin->id]);

    (new GenerateRoundReport($export->id))->handle(app(GenerateExport::class));
    $export->refresh();

    expect($export->status)->toBe(ExportStatus::Done)
        ->and($export->path)->toBe("{$export->id}/round-report-2026-Q2-DRAFT.pdf")
        ->and($export->progress)->toBe(100);
    Pdf::assertSaved(fn ($pdf, string $path): bool => str_ends_with($path, "/{$export->id}/round-report-2026-Q2-DRAFT.pdf"));
    Pdf::assertSee(['Q2 2026', 'DRAFT — round not closed', 'Alpha', 'Bravo', 'Charlie']);
    Pdf::assertDontSee(['MARKER', 'Facility F1']);
    // The callback's set-up (the driver's own options, tagged and A4, are checked with pdfinfo: RoundReportPdfTest).
    Pdf::assertBrowsershot(function (Browsershot $browsershot): bool {
        $command = $browsershot->createPdfCommand('/tmp/x.pdf');

        return str_starts_with($command['url'], 'file://')
            && in_array('--no-sandbox', $command['options']['args'], true)
            && $command['options']['executablePath'] === '/opt/test/chromium'
            && ($command['options']['disableJavascript'] ?? false) === true;
    });
    $completed = json_decode((string) DB::table('activity_log')->where('event', 'export_completed')->value('properties'), true)['attributes'];
    expect($completed)->toMatchArray(['type' => 'round_report', 'file' => 'round-report-2026-Q2-DRAFT.pdf']);

    // The worker's file, as Chromium would have written it, through the signed download.
    Storage::disk('exports')->put((string) $export->path, "%PDF-1.7\n");
    $url = URL::temporarySignedRoute('admin.exports.download', now()->addMinutes(30), ['export' => $export->id], absolute: false);
    $response = $this->actingAs($admin)->get($url)->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('application/pdf')
        ->and((string) $response->headers->get('Content-Disposition'))->toContain('round-report-2026-Q2-DRAFT.pdf');
});

it('names a closed round\'s file without DRAFT', function (): void {
    Pdf::fake();
    $admin = activity()->withoutLogs(fn () => User::factory()->create());
    $export = Export::query()->create(['type' => 'round_report', 'filter' => ['round' => $this->f->q1], 'options' => ['format' => 'pdf', 'include_private' => false], 'requested_by' => $admin->id]);

    app(GenerateExport::class)->execute($export->id);

    expect($export->refresh()->path)->toBe("{$export->id}/round-report-2026-Q1.pdf");
    Pdf::assertDontSee('DRAFT');
});
