<?php

declare(strict_types=1);

use App\Domain\Export\Actions\ExpireExports;
use App\Domain\Export\Actions\GenerateExport;
use App\Domain\Export\Enums\ExportStatus;
use App\Domain\Export\Jobs\ExpireExportsJob;
use App\Domain\Export\Jobs\GenerateExportJob;
use App\Domain\Export\Models\Export;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\LgaSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\ReportingFixture;
use Tests\Support\Rows;

// Phase 6 step 0 (D-96): queued exports. Asked for from the admin area, written on the exports
// queue in chunks, downloaded through a signed link (30 minutes) by a signed-in administrator,
// deleted after 7 days. The default columns never carry assessor names, device IDs, visit GPS or
// attachment links; the option that adds them is recorded in the audit log and in the file.

const Q2_2026 = ['year' => 2026, 'quarter' => 2];

beforeEach(function (): void {
    $this->seed(LgaSeeder::class);
    $this->f = ReportingFixture::build();
    DB::table('assessments')->update([
        'assessor_name' => 'MARKER-ASSESSOR Zainab Lawal',
        'device_id' => 'MARKER-DEVICE-77',
        'gps' => json_encode(['type' => 'Point', 'coordinates' => [7.4321, 10.5123, 600]]),
    ]);
    $this->admin = activity()->withoutLogs(fn () => User::factory()->create(['name' => 'Hauwa Bello']));
    Storage::fake('exports');
});

/** Ask for an export as the administrator and return its row. */
function requestTestExport(array $body): Export
{
    $id = test()->actingAs(test()->admin)->postJson('/admin/api/exports', $body)->assertStatus(202)->json('data.export_id');

    return Export::query()->findOrFail($id);
}

/** Run the queued job the way the worker would. */
function runTestExport(Export $export): Export
{
    (new GenerateExportJob($export->id))->handle(app(GenerateExport::class));

    return $export->refresh();
}

/** @return list<list<string>> */
function exportCsvRows(Export $export): array
{
    $content = (string) Storage::disk('exports')->get((string) $export->path);
    expect(str_starts_with($content, "\u{FEFF}"))->toBeTrue();

    return array_map(str_getcsv(...), array_values(array_filter(explode("\n", trim(substr($content, 3))))));
}

function exportAudit(string $event): ?array
{
    $properties = DB::table('activity_log')->where('event', $event)->orderByDesc('id')->value('properties');

    return $properties === null ? null : json_decode((string) $properties, true);
}

it('refuses guests: no export can be asked for, followed or downloaded signed out', function (): void {
    $export = Export::query()->create(['type' => 'facility_scores', 'filter' => (object) [], 'options' => ['format' => 'csv', 'include_private' => false], 'requested_by' => $this->admin->id]);

    $this->postJson('/admin/api/exports', ['type' => 'facility_scores', 'format' => 'csv'])->assertUnauthorized();
    $this->getJson("/admin/api/exports/{$export->id}")->assertUnauthorized();
    $this->get(URL::temporarySignedRoute('admin.exports.download', now()->addMinutes(30), ['export' => $export->id], absolute: false))->assertRedirect('/login');
    expect(Export::query()->count())->toBe(1);
});

it('queues the export on the exports queue and writes nothing in the request', function (): void {
    Queue::fake();

    $export = requestTestExport(['type' => 'facility_scores', 'format' => 'csv', 'filter' => Q2_2026]);

    Queue::assertPushedOn('exports', GenerateExportJob::class, fn (GenerateExportJob $job): bool => $job->exportId === $export->id);
    expect($export->status)->toBe(ExportStatus::Queued)
        ->and($export->progress)->toBe(0)
        ->and($export->filter)->toEqual([...Q2_2026, 'metric' => 'overall'])
        ->and($export->options)->toBe(['format' => 'csv', 'include_private' => false])
        ->and(Storage::disk('exports')->allFiles())->toBe([]);
});

it('writes the export, its expiry and its file name; the default never carries assessor, device, visit GPS or source details', function (): void {
    Queue::fake();
    $export = runTestExport(requestTestExport(['type' => 'facility_scores', 'format' => 'csv', 'filter' => Q2_2026]));

    expect($export->status)->toBe(ExportStatus::Done)
        ->and($export->row_count)->toBe(7)
        ->and($export->progress)->toBe(100)
        ->and($export->expires_at?->isSameDay(now()->addDays(7)))->toBeTrue()
        ->and(basename((string) $export->path))->toMatch('/^facility_scores-\d{8}-\d{4}\.csv$/');

    $rows = exportCsvRows($export);
    expect($rows)->toHaveCount(8)->and($rows[0])->not->toContain('Assessor', 'Device ID', 'Instance ID');
    $content = (string) Storage::disk('exports')->get((string) $export->path);
    expect($content)->not->toContain('MARKER-ASSESSOR')->not->toContain('MARKER-DEVICE')->not->toContain('10.5123')->not->toContain('uuid:');
});

it('adds assessor and source details only when asked, and says so in the audit entry and the file', function (): void {
    Queue::fake();
    $assessment = (int) DB::table('assessments')->where('round_id', $this->f->q2)->value('id');
    Rows::itemResponse(['assessment_id' => $assessment, 'dimension' => 'availability', 'month_slot' => 1, 'item_code' => 'register', 'value' => 'yes']);
    $export = runTestExport(requestTestExport(['type' => 'item_responses', 'format' => 'xlsx', 'include_private' => true, 'filter' => Q2_2026]));

    expect(basename((string) $export->path))->toMatch('/^item_responses-\d{8}-\d{4}-with-assessor-details\.xlsx$/');
    $book = IOFactory::load(Storage::disk('exports')->path((string) $export->path));
    $data = $book->getSheetByName('Data')?->toArray(null, true, false);
    expect($data[0])->toContain('Assessor', 'Device ID', 'Visit latitude', 'Visit longitude', 'Instance ID', 'Link at source', 'Attachment links')
        ->and(collect($data)->flatten()->all())->toContain('MARKER-ASSESSOR Zainab Lawal', 'MARKER-DEVICE-77', 10.5123, 7.4321);

    $about = collect($book->getSheetByName('About this file')?->toArray(null, true, false))->mapWithKeys(fn (array $row): array => [(string) $row[0] => $row[1]]);
    expect($about['Personal details'])->toStartWith('Included')
        ->and($about['Rows'])->toBe(1)
        ->and($about['Generated by'])->toBe('Hauwa Bello')
        ->and($about['Filters'])->toBe('Year 2026 · Q2');

    expect(exportAudit('export_requested'))->toMatchArray(['attributes' => ['type' => 'item_responses', 'format' => 'xlsx', 'include_private' => true, 'filter' => Q2_2026]])
        ->and(exportAudit('export_completed')['attributes'])->toMatchArray(['rows' => 1, 'include_private' => true]);
    expect(DB::table('activity_log')->where('event', 'export_requested')->value('causer_id'))->toBe($this->admin->id);
});

it('refuses the privacy option for a type that has no personal details', function (): void {
    Queue::fake();
    $this->actingAs($this->admin)->postJson('/admin/api/exports', ['type' => 'lga_table', 'format' => 'csv', 'include_private' => true])
        ->assertStatus(409)->assertJsonPath('code', 'private_not_offered');
    expect(Export::query()->count())->toBe(0);
});

it('records a request without the option as not including details', function (): void {
    Queue::fake();
    requestTestExport(['type' => 'facility_scores', 'format' => 'csv']);

    expect(exportAudit('export_requested')['attributes']['include_private'])->toBeFalse();
});

it('keeps the progress on the row while it writes, chunk by chunk', function (): void {
    Queue::fake();
    config(['edqa.exports.chunk_rows' => 2]);
    $export = requestTestExport(['type' => 'facility_scores', 'format' => 'csv', 'filter' => Q2_2026]);
    $seen = [];
    Event::listen('eloquent.updated: '.Export::class, function (Export $updated) use (&$seen): void {
        $seen[] = [$updated->status->value, $updated->progress];
    });

    runTestExport($export);

    // 7 rows in chunks of 2: 2/7, 4/7, 6/7, 7/7 written, then done.
    expect($seen)->toBe([['running', 0], ['running', 28], ['running', 57], ['running', 85], ['running', 99], ['done', 100]]);
});

it('answers the progress, and once done a signed link that works 30 minutes', function (): void {
    Queue::fake();
    $export = requestTestExport(['type' => 'facility_scores', 'format' => 'csv', 'filter' => Q2_2026]);
    $this->actingAs($this->admin)->getJson("/admin/api/exports/{$export->id}")->assertOk()
        ->assertJsonPath('data.status', 'queued')->assertJsonPath('data.status_label', 'Waiting to start')->assertJsonPath('data.url', null);

    runTestExport($export);
    $url = (string) $this->actingAs($this->admin)->getJson("/admin/api/exports/{$export->id}")->assertOk()
        ->assertJsonPath('data.status', 'done')->assertJsonPath('data.progress', 100)->assertJsonPath('data.rows', 7)
        ->json('data.url');
    expect($url)->toContain('signature=');

    $response = $this->actingAs($this->admin)->get($url)->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('text/csv')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->streamedContent())->toContain('KD-F3');
    expect(exportAudit('export_downloaded')['attributes'])->toMatchArray(['include_private' => false]);

    // After 30 minutes the link no longer works; without its signature it never did.
    $this->travel(31)->minutes();
    $this->actingAs($this->admin)->get($url)->assertForbidden();
    $this->actingAs($this->admin)->get("/admin/exports/{$export->id}/download")->assertForbidden();
});

it('needs a signed-in administrator even with a valid signed link', function (): void {
    Queue::fake();
    $export = runTestExport(requestTestExport(['type' => 'facility_scores', 'format' => 'csv']));
    $url = URL::temporarySignedRoute('admin.exports.download', now()->addMinutes(30), ['export' => $export->id], absolute: false);

    auth()->logout();
    $this->get($url)->assertRedirect('/login');
});

it('marks a failed export with a plain message and keeps no partial file', function (): void {
    $export = Export::query()->create(['type' => 'facility_scores', 'filter' => ['lga' => 'NOT-AN-LGA'], 'options' => ['format' => 'csv', 'include_private' => false], 'requested_by' => $this->admin->id]);

    $export = runTestExport($export);

    expect($export->status)->toBe(ExportStatus::Failed)
        ->and($export->error)->toBe('The export could not be prepared. Try again; if it fails again, tell the technical team.')
        ->and(Storage::disk('exports')->allFiles())->toBe([])
        ->and(exportAudit('export_failed'))->not->toBeNull();
    $this->actingAs($this->admin)->getJson("/admin/api/exports/{$export->id}")->assertJsonPath('data.status', 'failed')->assertJsonPath('data.url', null);
});

it('refuses an unknown type or format, and a download of an export not ready', function (): void {
    Queue::fake();
    $this->actingAs($this->admin)->postJson('/admin/api/exports', ['type' => 'payloads', 'format' => 'csv'])->assertUnprocessable()->assertJsonValidationErrors('type');
    $this->actingAs($this->admin)->postJson('/admin/api/exports', ['type' => 'facility_scores', 'format' => 'docx'])->assertUnprocessable()->assertJsonValidationErrors('format');
    // PDF is the round report's format alone (Phase 6 step 5).
    $this->actingAs($this->admin)->postJson('/admin/api/exports', ['type' => 'facility_scores', 'format' => 'pdf'])->assertStatus(409)->assertJsonPath('code', 'wrong_format');

    $export = requestTestExport(['type' => 'facility_scores', 'format' => 'csv']);
    $this->actingAs($this->admin)->get(URL::temporarySignedRoute('admin.exports.download', now()->addMinutes(30), ['export' => $export->id], absolute: false))
        ->assertStatus(409)->assertJsonPath('code', 'not_ready');
});

it('deletes files older than 7 days and marks them expired, daily, audited', function (): void {
    Queue::fake();
    $old = runTestExport(requestTestExport(['type' => 'facility_scores', 'format' => 'csv']));
    $fresh = runTestExport(requestTestExport(['type' => 'facility_scores', 'format' => 'csv']));
    $old->update(['expires_at' => now()->subMinute()]);

    expect(app(ExpireExports::class)->execute())->toBe(1);

    expect($old->refresh()->status)->toBe(ExportStatus::Expired)
        ->and($old->path)->toBeNull()
        ->and($fresh->refresh()->status)->toBe(ExportStatus::Done)
        ->and(Storage::disk('exports')->allFiles())->toBe([(string) $fresh->path])
        ->and(exportAudit('export_expired'))->not->toBeNull();
    $this->actingAs($this->admin)->get(URL::temporarySignedRoute('admin.exports.download', now()->addMinutes(30), ['export' => $old->id], absolute: false))
        ->assertStatus(409)->assertJsonPath('code', 'expired');

    // And the job runs every day, on the low queue.
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'edqa:exports:expire');
    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('15 3 * * *')
        ->and((new ExpireExportsJob)->queue)->toBe('low');
});

it('expires a done export written more than 7 days ago even without an expiry date', function (): void {
    Queue::fake();
    $export = runTestExport(requestTestExport(['type' => 'facility_scores', 'format' => 'csv']));
    $export->forceFill(['expires_at' => null])->save();
    CarbonImmutable::setTestNow(now()->addDays(8));

    expect(app(ExpireExports::class)->execute())->toBe(1)
        ->and($export->refresh()->status)->toBe(ExportStatus::Expired);
    CarbonImmutable::setTestNow();
});

it('allows 10 exports a minute per administrator', function (): void {
    Queue::fake();
    foreach (range(1, 10) as $_) {
        $this->actingAs($this->admin)->postJson('/admin/api/exports', ['type' => 'facility_scores', 'format' => 'csv'])->assertStatus(202);
    }
    $this->actingAs($this->admin)->postJson('/admin/api/exports', ['type' => 'facility_scores', 'format' => 'csv'])->assertStatus(429);

    // Another administrator has their own ten.
    $other = activity()->withoutLogs(fn () => User::factory()->create());
    $this->actingAs($other)->postJson('/admin/api/exports', ['type' => 'facility_scores', 'format' => 'csv'])->assertStatus(202);
});
