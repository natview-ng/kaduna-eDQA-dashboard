<?php

declare(strict_types=1);

use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Models\User;
use Database\Seeders\LgaSeeder;
use Database\Seeders\ScoringRuleVersionSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportingFixture;
use Tests\Support\RescoreFixture;

// The Scoring rules page (ARCHITECTURE.md §7, §9.3). A draft is edited, previewed
// (reading only), then published with a reason; open rounds are rescored, closed rounds keep their
// pinned version (D-32), so the public dashboard does not move until an open round is closed.

beforeEach(function (): void {
    $this->admin = User::factory()->create(['name' => 'Hauwa Bello']);
});

/** A draft from the current version, with these changes, through the API. */
function draftFrom(int $from, array $changes = []): int
{
    return (int) test()->actingAs(test()->admin)->postJson('/admin/api/scoring-rules', ['from' => $from, 'changes' => $changes])->assertCreated()->json('data.id');
}

it('needs a signed-in administrator', function (): void {
    $this->get('/admin/scoring-rules')->assertRedirect('/login');
    $this->patchJson('/admin/api/scoring-rules/1', ['changes' => []])->assertUnauthorized();
    $this->deleteJson('/admin/api/scoring-rules/1')->assertUnauthorized();
});

it('shows the current version with who published it and why, the settings read-only, the item catalog and the rounds', function (): void {
    $f = ReportingFixture::build();
    DB::table('activity_log')->insert([
        'log_name' => 'default', 'description' => 'published', 'event' => 'published', 'subject_type' => (new ScoringRuleVersion)->getMorphClass(), 'subject_id' => $f->v2,
        'causer_type' => $this->admin->getMorphClass(), 'causer_id' => $this->admin->id, 'properties' => json_encode(['reason' => 'October review']), 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('scoring_rule_versions')->where('id', $f->v2)->update(['published_reason' => 'October review']);

    $this->actingAs($this->admin)->get('/admin/scoring-rules')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/scoring-rules')
        ->where('versions.0.version', 2)
        ->where('versions.0.status', 'current')
        ->where('versions.0.published_by', 'Hauwa Bello')
        ->where('versions.0.published_reason', 'October review')
        ->where('versions.0.config.bands.strong', 90)
        ->where('versions.1.status', 'published')
        ->where('draft', null)
        ->where('settings.weighting', 'unweighted')
        ->where('settings.rounding', 'half_up')
        ->where('items', fn ($items): bool => collect($items)->contains(fn (array $item): bool => $item['key'] === 'availability.m__1a' && $item['label'] !== ''))
        ->where('rounds.open.0.label', 'Q2 2026')
        ->where('rounds.closed.0.label', 'Q1 2026')
        ->where('rounds.closed.0.rule_version', 1));
});

it('edits and discards the draft, audited; a published version cannot be edited or discarded', function (): void {
    $this->seed(ScoringRuleVersionSeeder::class);
    $v1 = ScoringRuleVersion::query()->where('version', 1)->sole()->id;
    $draft = draftFrom($v1);

    $this->actingAs($this->admin)->patchJson("/admin/api/scoring-rules/{$draft}", ['changes' => ['bands' => ['strong' => 85, 'acceptable' => 75, 'review' => 65]]])
        ->assertOk()->assertJsonPath('data.config.bands.strong', 85);
    $this->actingAs($this->admin)->patchJson("/admin/api/scoring-rules/{$draft}", ['changes' => ['bands' => ['strong' => 70, 'acceptable' => 75, 'review' => 65]]])
        ->assertStatus(409)->assertJsonPath('code', 'bands_not_descending');
    expect(ScoringRuleVersion::query()->findOrFail($draft)->config['bands']['strong'])->toEqual(85);

    $this->actingAs($this->admin)->patchJson("/admin/api/scoring-rules/{$v1}", ['changes' => []])->assertStatus(409)->assertJsonPath('code', 'not_a_draft');
    $this->actingAs($this->admin)->deleteJson("/admin/api/scoring-rules/{$v1}")->assertStatus(409)->assertJsonPath('code', 'not_a_draft');

    $this->actingAs($this->admin)->deleteJson("/admin/api/scoring-rules/{$draft}")->assertNoContent();
    expect(ScoringRuleVersion::query()->find($draft))->toBeNull();
    expect(DB::table('activity_log')->whereIn('event', ['draft_updated', 'draft_discarded'])->where('causer_id', $this->admin->id)->pluck('event')->all())->toBe(['draft_updated', 'draft_discarded']);

    // A new draft can start once the old one is gone.
    draftFrom($v1);
});

it('previews a draft without writing anything, naming the facilities and LGAs that change band', function (): void {
    $f = RescoreFixture::build();
    $facility = $f->facility();
    DB::table('facilities')->where('id', $facility)->update(['name' => 'Rescore Clinic']);
    $f->assessment($f->q2, $facility, default: 'yes/no');      // 50: Needs action now, Strong under the draft
    $low = $f->facility();
    $f->assessment($f->q2, $low, default: 'yes/yes');          // 100: Strong either way
    $draft = draftFrom($f->v1, ['bands' => ['strong' => 40, 'acceptable' => 30, 'review' => 20]]);

    $tables = ['assessment_scores', 'scoring_rule_versions', 'rescore_runs', 'activity_log', 'rounds', 'job_batches'];
    $before = array_map(fn (string $table): int => DB::table($table)->count(), $tables);

    $preview = $this->actingAs($this->admin)->postJson("/admin/api/scoring-rules/{$draft}/preview")->assertOk()->json('data.rounds');

    expect(array_map(fn (string $table): int => DB::table($table)->count(), $tables))->toBe($before);
    expect($preview)->toHaveCount(1)
        ->and($preview[0]['round_id'])->toBe($f->q2)
        ->and(collect($preview[0]['facilities_changing_band'])->pluck('name')->filter()->all())->not->toBeEmpty()
        ->and($preview[0]['facilities_changing_band'][0])->toHaveKeys(['code', 'name', 'before', 'after']);
});

it('publishes with a reason and rescores the open rounds only, never the closed one', function (): void {
    $f = RescoreFixture::build();
    $closed = $f->assessment($f->q1);
    RescoreFixture::scores($closed, $f->v1, 50);
    $open = $f->assessment($f->q2);
    RescoreFixture::scores($open, $f->v1, 50);
    $draft = draftFrom($f->v1, ['bands' => ['strong' => 85, 'acceptable' => 75, 'review' => 65]]);

    $this->actingAs($this->admin)->postJson("/admin/api/scoring-rules/{$draft}/publish", [])->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->actingAs($this->admin)->postJson("/admin/api/scoring-rules/{$draft}/publish", ['reason' => 'Strong lowered to 85 at the review'])->assertOk();

    expect(RescoreFixture::rows($f->q2, $draft))->not->toBeEmpty()        // open: rescored under the new version
        ->and(RescoreFixture::rows($f->q1, $draft))->toBe([]);           // closed: untouched (D-32)
    $this->actingAs($this->admin)->get('/admin/scoring-rules')->assertInertia(fn (Assert $page) => $page
        ->where('rescores.0.round_id', $f->q2)
        ->where('rescores.0.status', 'succeeded'));
});

it('leaves the public figures exactly as they were after a publish', function (): void {
    $this->seed(LgaSeeder::class);
    $f = ReportingFixture::build();                                       // Q1 2026 closed, pinned to v1
    $public = function (): array {
        $seen = [];
        $this->get('/')->assertOk()->assertInertia(function (Assert $page) use (&$seen): void {
            $hero = $page->toArray()['props']['hero'];
            $seen = [$hero['facilitiesVisited'], $hero['overall']['display'], $hero['overall']['band']];
        });

        return $seen;
    };
    $before = $public();
    $draft = draftFrom($f->v2, ['bands' => ['strong' => 60, 'acceptable' => 40, 'review' => 20]]);
    auth()->logout();

    $this->actingAs($this->admin)->postJson("/admin/api/scoring-rules/{$draft}/publish", ['reason' => 'Bands lowered for the test'])->assertOk();
    auth()->logout();

    expect($public())->toBe($before);
});
