<?php

declare(strict_types=1);

use App\Domain\Plan\Models\PlanAction;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportingFixture;
use Tests\Support\Rows;

// Phase 6 step 3: the implementation plan, editable in the admin area and read-only publicly.
// ReportingFixture: Q2 2026 open (Alpha 78.9 → consistency, Bravo 76.0 → availability, Charlie 81.0
// above Acceptable), Q1 2026 closed (Alpha only, 10 → its weakest).

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-16 10:00:00');
    $this->f = ReportingFixture::build();
    $this->admin = activity()->withoutLogs(fn () => User::factory()->create(['name' => 'Hauwa Bello']));
});

/** The admin plan page's props for a round. @return array<string, mixed> */
function adminPlan(object $test, ?int $round = null): array
{
    $props = [];
    $test->actingAs($test->admin)->get('/admin/plan'.($round === null ? '' : "?round={$round}"))->assertOk()
        ->assertInertia(function (Assert $page) use (&$props): void {
            $page->component('admin/plan');
            $props = $page->toArray()['props'];
        });

    return $props;
}

function planRow(array $props, string $lga): array
{
    return collect($props['plan']['rows'])->first(fn (array $row): bool => $row['lga']['name'] === $lga) ?? throw new RuntimeException("No plan row for {$lga}");
}

it('needs a signed-in administrator', function (): void {
    $this->get('/admin/plan')->assertRedirect('/login');
    $this->postJson("/admin/api/plan/{$this->f->q2}/generate")->assertUnauthorized();
    $this->patchJson('/admin/api/plan-actions/1', ['status' => 'done'])->assertUnauthorized();
});

it('lists every LGA below the threshold for the round, from the aggregator, the latest round by default', function (): void {
    $props = adminPlan($this);

    expect($props['round'])->toMatchArray(['id' => $this->f->q2, 'label' => 'Q2 2026', 'status' => 'open'])
        ->and(array_map(fn (array $r): array => [$r['lga']['name'], $r['dimension']], $props['plan']['rows']))->toBe([['Bravo', 'availability'], ['Alpha', 'consistency']])
        ->and($props['plan']['unsaved'])->toBe(2)
        ->and(planRow($props, 'Bravo'))->toMatchArray(['id' => null, 'owner_role' => 'lga_me_officer', 'status' => 'not_started', 'due_date' => null])
        ->and(array_column($props['roles'], 'key'))->toBe(['lga_me_officer', 'state_me_team', 'programme_manager', 'state_hmis_team', 'lga_phc_coordinator'])
        ->and($props['today'])->toBe('2026-11-16');

    $closed = adminPlan($this, $this->f->q1);
    expect($closed['round']['status'])->toBe('closed')->and(array_column(array_column($closed['plan']['rows'], 'lga'), 'name'))->toBe(['Alpha']);
});

it('generates the plan idempotently: rows for the LGAs without one, audited, never touching a saved row', function (): void {
    $alpha = $this->f->lgas['AAA'];
    Rows::planAction(['round_id' => $this->f->q2, 'lga_id' => $alpha, 'dimension' => 'consistency', 'owner_role' => 'state_hmis_team', 'status' => 'in_progress', 'due_date' => '2026-12-01']);

    $this->actingAs($this->admin)->postJson("/admin/api/plan/{$this->f->q2}/generate")->assertOk()->assertJsonPath('data.created', 1);
    $this->actingAs($this->admin)->postJson("/admin/api/plan/{$this->f->q2}/generate")->assertOk()->assertJsonPath('data.created', 0);

    $rows = DB::table('plan_actions')->where('round_id', $this->f->q2)->orderBy('lga_id')->get();
    expect($rows)->toHaveCount(2);
    $bravo = $rows->firstWhere('lga_id', $this->f->lgas['BBB']);
    expect($bravo->dimension)->toBe('availability')
        ->and($bravo->owner_role)->toBe('lga_me_officer')
        ->and($bravo->status)->toBe('not_started')
        ->and($bravo->due_date)->toBe('2026-12-16')
        ->and($bravo->action_text)->toBe('Restock HMIS tools and confirm the current register set is in place')
        ->and($rows->firstWhere('lga_id', $alpha)->owner_role)->toBe('state_hmis_team');

    expect(DB::table('activity_log')->where('event', 'plan_generated')->count())->toBe(2)
        ->and(DB::table('activity_log')->where('event', 'created')->where('subject_type', (new PlanAction)->getMorphClass())->count())->toBe(1)
        ->and(adminPlan($this)['plan']['unsaved'])->toBe(0);
});

it('generates and edits the plan of a closed round too: a plan is follow-up, not a figure', function (): void {
    $this->actingAs($this->admin)->postJson("/admin/api/plan/{$this->f->q1}/generate")->assertOk()->assertJsonPath('data.created', 1);
    $id = (int) DB::table('plan_actions')->where('round_id', $this->f->q1)->value('id');

    $this->actingAs($this->admin)->patchJson("/admin/api/plan-actions/{$id}", ['status' => 'done'])->assertOk()->assertJsonPath('data.status', 'done');
});

it('saves each field inline, audited old and new, with no reason asked', function (): void {
    $this->actingAs($this->admin)->postJson("/admin/api/plan/{$this->f->q2}/generate")->assertOk();
    $action = PlanAction::query()->where('round_id', $this->f->q2)->where('lga_id', $this->f->lgas['BBB'])->firstOrFail();

    $this->actingAs($this->admin)->patchJson("/admin/api/plan-actions/{$action->id}", [
        'owner_role' => 'other', 'owner_role_other' => '  Zonal M&E unit ', 'owner_contact' => 'Dr A. Bello, 0803 000 0000',
        'due_date' => '2027-01-15', 'status' => 'in_progress', 'notes' => 'Visited on 3 March; the register was missing.',
    ])->assertOk()->assertJsonPath('data.owner_role_other', 'Zonal M&E unit');

    $entry = DB::table('activity_log')->where('event', 'updated')->where('subject_type', $action->getMorphClass())->where('subject_id', $action->id)->latest('id')->first();
    $properties = json_decode((string) $entry->properties, true);
    expect((int) $entry->causer_id)->toBe($this->admin->id)
        ->and($properties['old'])->toMatchArray(['owner_role' => 'lga_me_officer', 'owner_role_other' => null, 'status' => 'not_started', 'owner_contact' => null, 'notes' => null])
        ->and($properties['attributes'])->toMatchArray(['owner_role' => 'other', 'owner_role_other' => 'Zonal M&E unit', 'status' => 'in_progress', 'owner_contact' => 'Dr A. Bello, 0803 000 0000', 'notes' => 'Visited on 3 March; the register was missing.']);

    // Back to a listed role: the written role goes.
    $this->actingAs($this->admin)->patchJson("/admin/api/plan-actions/{$action->id}", ['owner_role' => 'state_hmis_team'])->assertOk()->assertJsonPath('data.owner_role_other', null);
    expect(planRow(adminPlan($this), 'Bravo'))->toMatchArray(['owner_role' => 'state_hmis_team', 'owner' => 'State HMIS team', 'status' => 'in_progress', 'due_date' => '2027-01-15', 'owner_contact' => 'Dr A. Bello, 0803 000 0000']);
});

it('refuses a new review date before today, an "Other role…" left blank, and a role not on the list', function (): void {
    $this->actingAs($this->admin)->postJson("/admin/api/plan/{$this->f->q2}/generate")->assertOk();
    $id = (int) DB::table('plan_actions')->where('round_id', $this->f->q2)->value('id');

    $this->actingAs($this->admin)->patchJson("/admin/api/plan-actions/{$id}", ['due_date' => '2026-11-15'])->assertUnprocessable()
        ->assertJsonPath('errors.due_date.0', 'Choose a review date on or after today.');
    $this->actingAs($this->admin)->patchJson("/admin/api/plan-actions/{$id}", ['due_date' => '2026-11-16'])->assertOk();
    $this->actingAs($this->admin)->patchJson("/admin/api/plan-actions/{$id}", ['owner_role' => 'other', 'owner_role_other' => ''])->assertUnprocessable()->assertJsonValidationErrors('owner_role_other');
    $this->actingAs($this->admin)->patchJson("/admin/api/plan-actions/{$id}", ['owner_role' => 'Dr A. Bello'])->assertUnprocessable()->assertJsonValidationErrors('owner_role');
    $this->actingAs($this->admin)->patchJson("/admin/api/plan-actions/{$id}", ['status' => 'abandoned'])->assertUnprocessable()->assertJsonValidationErrors('status');
});

it('keeps a review date that has since passed when other fields are saved', function (): void {
    $id = Rows::planAction(['round_id' => $this->f->q1, 'lga_id' => $this->f->lgas['AAA'], 'dimension' => 'availability', 'due_date' => '2026-05-01']);

    $this->actingAs($this->admin)->patchJson("/admin/api/plan-actions/{$id}", ['due_date' => '2026-05-01', 'status' => 'done'])->assertOk()->assertJsonPath('data.due_date', '2026-05-01');
});

it('shows the stored owner role and status on the public plan, for closed rounds only, never the contact or notes', function (): void {
    Rows::planAction(['round_id' => $this->f->q1, 'lga_id' => $this->f->lgas['AAA'], 'dimension' => 'availability', 'owner_role' => 'other', 'owner_role_other' => 'Zonal M&E unit',
        'status' => 'in_progress', 'due_date' => '2027-01-15', 'owner_contact' => 'MARKER-CONTACT Dr Bello', 'notes' => 'MARKER-NOTES register missing']);
    Rows::planAction(['round_id' => $this->f->q2, 'lga_id' => $this->f->lgas['BBB'], 'dimension' => 'availability', 'owner_role' => 'state_hmis_team', 'status' => 'done', 'due_date' => '2027-01-15', 'owner_contact' => 'MARKER-CONTACT open', 'notes' => 'MARKER-NOTES open']);
    // Q1 is the only closed round, so the public plan is Q1's.
    DB::table('rounds')->where('id', $this->f->q1)->update(['closed_at' => now()->subMonth()]);

    $first = $this->get('/plan');
    $version = (string) ($first->viewData('page')['version'] ?? '');
    $partial = $this->get('/plan', ['X-Inertia' => 'true', 'X-Inertia-Version' => $version, 'X-Inertia-Partial-Component' => 'dashboard/plan', 'X-Inertia-Partial-Data' => 'plan']);
    $plan = $partial->json('props.plan');

    expect($plan['round'])->toBe('Q1 2026')
        ->and($plan['actions'])->toHaveCount(1)
        ->and($plan['actions'][0])->toMatchArray(['name' => 'Alpha', 'owner' => 'Zonal M&E unit', 'status' => 'in_progress', 'reviewDate' => '2027-01-15', 'stored' => true])
        ->and($plan['summary'][0]['statuses'])->toBe(['not_started' => 0, 'in_progress' => 1, 'done' => 0]);
    $body = (string) $first->getContent().(string) $partial->getContent();
    expect($body)->not->toContain('MARKER-CONTACT')->not->toContain('MARKER-NOTES')->not->toContain('State HMIS team');
});
