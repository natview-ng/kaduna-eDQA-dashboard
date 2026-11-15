<?php

declare(strict_types=1);

use App\Domain\Export\Actions\GenerateExport;
use App\Domain\Export\Enums\ExportStatus;
use App\Domain\Export\Models\Export;
use App\Models\User;
use Database\Seeders\LgaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ReportingFixture;
use Tests\Support\Rows;

// ARCHITECTURE.md §10: a 10,000-row CSV export in under 30 s, queued with progress: the facility
// scores export, every page of the Facility view (Phase 6 step 1).
// Run with `make test-slow`; the default suite leaves the slow group out.

const EXPORT_PERF_ROWS = 10_000;

it('writes a 10,000-row facility scores export in under 30 seconds', function (): void {
    $this->seed(LgaSeeder::class);
    $f = ReportingFixture::build();
    Storage::fake('exports');
    $lga = Rows::lga(['code' => 'PRF', 'name' => 'Perf LGA']);
    $ward = Rows::ward(['lga_id' => $lga]);
    $source = Rows::sourceId();
    $now = now();

    // 10,000 more facilities, each with an accepted assessment in the open Q2 2026 and nine scores.
    foreach (array_chunk(range(1, EXPORT_PERF_ROWS), 1000) as $chunk) {
        DB::table('facilities')->insert(array_map(fn (int $i): array => ['code' => "PERF-{$i}", 'name' => "Perf facility {$i}", 'lga_id' => $lga, 'ward_id' => $ward,
            'level' => 'primary', 'ownership' => $i % 10 === 0 ? 'private' : 'public', 'is_active' => true, 'created_at' => $now, 'updated_at' => $now], $chunk));
        DB::table('submissions')->insert(array_map(fn (int $i): array => ['source_id' => $source, 'external_id' => "uuid:perf-{$i}", 'instance_id' => "uuid:perf-{$i}",
            'form_ref' => 'dqa', 'form_version' => '2026.1', 'payload' => '{}', 'submitted_at' => $now, 'received_at' => $now, 'status' => 'received'], $chunk));
        $facilities = DB::table('facilities')->whereIn('code', array_map(fn (int $i): string => "PERF-{$i}", $chunk))->pluck('id', 'code');
        $submissions = DB::table('submissions')->whereIn('instance_id', array_map(fn (int $i): string => "uuid:perf-{$i}", $chunk))->pluck('id', 'instance_id');
        DB::table('assessments')->insert(array_map(fn (int $i): array => ['round_id' => $f->q2, 'facility_id' => $facilities["PERF-{$i}"], 'submission_id' => $submissions["uuid:perf-{$i}"],
            'assessor_name' => 'Assessor', 'started_at' => '2026-05-04 09:00:00+01', 'ended_at' => '2026-05-04 10:00:00+01', 'status' => 'accepted', 'created_at' => $now, 'updated_at' => $now], $chunk));
        $assessments = DB::table('assessments')->whereIn('facility_id', $facilities->values())->pluck('id');
        $scores = [];
        foreach ($assessments as $assessment) {
            foreach (['availability', 'consistency', 'validity'] as $dimension) {
                foreach ([1, 2, 3] as $slot) {
                    $scores[] = ['assessment_id' => $assessment, 'dimension' => $dimension, 'month_slot' => $slot, 'score' => 60 + ($assessment % 40), 'rule_version_id' => $f->v2, 'computed_at' => $now];
                }
            }
        }
        foreach (array_chunk($scores, 3000) as $part) {
            DB::table('assessment_scores')->insert($part);
        }
    }
    DB::statement('ANALYZE facilities, assessments, assessment_scores');

    $admin = activity()->withoutLogs(fn () => User::factory()->create());
    $export = Export::query()->create(['type' => 'facility_scores', 'filter' => ['year' => 2026, 'quarter' => 2, 'metric' => 'overall'], 'options' => ['format' => 'csv', 'include_private' => false], 'requested_by' => $admin->id]);

    $started = hrtime(true);
    app(GenerateExport::class)->execute($export->id);
    $seconds = (hrtime(true) - $started) / 1e9;
    $export->refresh();

    fwrite(STDERR, sprintf("\n  Facility scores export of %d rows as CSV: %.1f s\n", (int) $export->row_count, $seconds));

    expect($export->status)->toBe(ExportStatus::Done)
        ->and($export->row_count)->toBe(EXPORT_PERF_ROWS + 7)
        ->and($seconds)->toBeLessThan(30.0);
})->group('slow');
