<?php

declare(strict_types=1);

use App\Domain\Facility\Models\Facility;
use App\Domain\Facility\Models\Ward;
use App\Models\User;
use Database\Seeders\LgaSeeder;
use Database\Seeders\ScoringRuleVersionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportingFixture;
use Tests\Support\Rows;

// The Facility list page (ARCHITECTURE.md §9.3) and its API. The master list is
// the validation authority: edited inline, deactivated with a reason, never deleted.

beforeEach(function (): void {
    $this->seed(LgaSeeder::class);
    $this->admin = User::factory()->create();
    $this->zaria = (int) DB::table('lgas')->where('name', 'Zaria')->value('id');
    $this->ikara = (int) DB::table('lgas')->where('name', 'Ikara')->value('id');
    $this->tudun = Ward::query()->create(['lga_id' => $this->zaria, 'name' => 'Tudun Wada', 'code' => 'ZAR-W01']);
    $this->kwari = Ward::query()->create(['lga_id' => $this->zaria, 'name' => 'Kwarbai', 'code' => 'ZAR-W02']);
    $this->ikaraWard = Ward::query()->create(['lga_id' => $this->ikara, 'name' => 'Ikara', 'code' => 'IKR-W01']);
    $this->facility = Facility::factory()->create(['code' => 'KD-ZAR-0001', 'name' => 'Tudun Wada PHC', 'ward_id' => $this->tudun->id, 'lga_id' => $this->zaria, 'level' => 'primary', 'ownership' => 'public']);
    Facility::factory()->create(['code' => 'KD-IKR-0002', 'name' => 'Ikara General Hospital', 'ward_id' => $this->ikaraWard->id, 'lga_id' => $this->ikara, 'level' => 'secondary', 'ownership' => 'public']);
});

function auditOf(Facility $facility, string $event): array
{
    $row = DB::table('activity_log')->where('subject_type', $facility->getMorphClass())->where('subject_id', $facility->id)->where('event', $event)->latest('id')->first();
    expect($row)->not->toBeNull();

    return ['causer_id' => $row->causer_id, ...json_decode((string) $row->properties, true)];
}

it('needs a signed-in administrator', function (): void {
    $this->get('/admin/facilities')->assertRedirect('/login');
    $this->patchJson("/admin/api/facilities/{$this->facility->id}", ['name' => 'x'])->assertUnauthorized();
});

it('lists the master list with search on name, code, ward and LGA, filters and server pagination', function (string $query, array $codes): void {
    parse_str($query, $params);
    $this->actingAs($this->admin)->get('/admin/facilities?'.$query)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/facilities')
        ->where('facilities.data', fn ($rows): bool => collect($rows)->pluck('code')->sort()->values()->all() === $codes)
        ->has('facilities.total')
        ->has('lgas', 23)
        ->has('cascade'));
})->with([
    'everything' => ['', ['KD-IKR-0002', 'KD-ZAR-0001']],
    'name' => ['q=general', ['KD-IKR-0002']],
    'code' => ['q=zar-0001', ['KD-ZAR-0001']],
    'ward' => ['q=tudun', ['KD-ZAR-0001']],
    'lga name' => ['q=ikara', ['KD-IKR-0002']],
    'lga filter' => ['lga=ZAR', ['KD-ZAR-0001']],
    'level filter' => ['level=secondary', ['KD-IKR-0002']],
    'inactive only' => ['active=no', []],
]);

it('shows each facility\'s visits and latest score', function (): void {
    $f = ReportingFixture::build();
    $code = (string) DB::table('facilities')->where('id', $f->facilities['F4'])->value('code');

    $this->actingAs($this->admin)->get('/admin/facilities?q='.urlencode($code))->assertInertia(fn (Assert $page) => $page
        ->where('facilities.data.0.code', $code)
        ->where('facilities.data.0.visits', 1)
        ->where('facilities.data.0.latest.round', 'Q2 2026')
        ->where('facilities.data.0.latest.score.display', '100.0'));
});

it('edits a facility inline, audited old and new; a ward in another LGA moves its LGA too', function (): void {
    $this->actingAs($this->admin)->patchJson("/admin/api/facilities/{$this->facility->id}", [
        'name' => 'Tudun Wada Primary Health Care Centre', 'ward_id' => $this->ikaraWard->id, 'level' => 'secondary', 'ownership' => 'private', 'lat' => 11.1, 'lng' => 7.7,
    ])->assertOk()->assertJsonPath('data.lga.id', $this->ikara);

    expect($this->facility->refresh())
        ->name->toBe('Tudun Wada Primary Health Care Centre')
        ->ward_id->toBe($this->ikaraWard->id)
        ->lga_id->toBe($this->ikara);
    $entry = auditOf($this->facility, 'facility_updated');
    expect($entry['causer_id'])->toBe($this->admin->id)
        ->and($entry['old'])->toMatchArray(['name' => 'Tudun Wada PHC', 'ward_id' => $this->tudun->id, 'lga_id' => $this->zaria, 'level' => 'primary', 'ownership' => 'public'])
        ->and($entry['attributes'])->toMatchArray(['name' => 'Tudun Wada Primary Health Care Centre', 'ward_id' => $this->ikaraWard->id, 'lga_id' => $this->ikara, 'level' => 'secondary', 'ownership' => 'private'])
        ->and($entry['cascade'])->toBeTrue();
});

it('keeps the code once the facility has assessments, and lets it change before', function (): void {
    $this->actingAs($this->admin)->patchJson("/admin/api/facilities/{$this->facility->id}", ['code' => 'KD-ZAR-0100'])
        ->assertOk()->assertJsonPath('data.code', 'KD-ZAR-0100');

    Rows::assessment(['facility_id' => $this->facility->id]);
    $this->actingAs($this->admin)->patchJson("/admin/api/facilities/{$this->facility->id}", ['code' => 'KD-ZAR-0200'])
        ->assertConflict()->assertJsonPath('code', 'code_locked');
    expect($this->facility->refresh()->code)->toBe('KD-ZAR-0100');
});

it('adds a facility, refusing a code already in use', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/api/facilities', ['code' => 'KD-ZAR-0003', 'name' => 'Kwarbai Clinic', 'ward_id' => $this->kwari->id, 'level' => 'primary', 'ownership' => 'private'])
        ->assertCreated()->assertJsonPath('data.lga.id', $this->zaria);
    expect(auditOf(Facility::query()->where('code', 'KD-ZAR-0003')->sole(), 'facility_created')['causer_id'])->toBe($this->admin->id);

    $this->actingAs($this->admin)->postJson('/admin/api/facilities', ['code' => 'KD-ZAR-0003', 'name' => 'Again', 'ward_id' => $this->kwari->id, 'level' => 'primary', 'ownership' => 'private'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');
});

it('deactivates with a reason, never deleting, and warns when an open round has its visits', function (): void {
    $this->seed(ScoringRuleVersionSeeder::class);
    $open = Rows::round(['status' => 'open']);
    Rows::assessment(['facility_id' => $this->facility->id, 'round_id' => $open]);

    $this->actingAs($this->admin)->get('/admin/facilities?q=zar-0001')->assertInertia(fn (Assert $page) => $page
        ->where('facilities.data.0.open_round_visits', 1));

    $this->actingAs($this->admin)->postJson("/admin/api/facilities/{$this->facility->id}/deactivate", [])
        ->assertUnprocessable()->assertJsonValidationErrors('reason');
    $this->actingAs($this->admin)->postJson("/admin/api/facilities/{$this->facility->id}/deactivate", ['reason' => 'Closed down by the LGA in March'])
        ->assertOk()->assertJsonPath('data.active', false);

    expect(Facility::query()->find($this->facility->id))->not->toBeNull()->is_active->toBeFalse()
        ->and(auditOf($this->facility, 'facility_deactivated')['reason'])->toBe('Closed down by the LGA in March');
    $this->actingAs($this->admin)->postJson("/admin/api/facilities/{$this->facility->id}/deactivate", ['reason' => 'Closed down by the LGA in March'])
        ->assertConflict()->assertJsonPath('code', 'already_inactive');
    $this->actingAs($this->admin)->deleteJson("/admin/api/facilities/{$this->facility->id}")->assertStatus(405);
});

it('keeps a deactivated facility\'s published history on the public dashboard', function (): void {
    $f = ReportingFixture::build(); // Q1 2026 closed: F1 alone
    DB::table('facilities')->where('id', $f->facilities['F1'])->update(['is_active' => false]);

    $this->get('/')->assertInertia(fn (Assert $page) => $page->where('hero.facilitiesVisited', 1));
});

it('says the facility media file is out of date after a cascade change, until it is regenerated', function (): void {
    Storage::fake('exports');
    $cascade = fn () => $this->actingAs($this->admin)->get('/admin/facilities')->viewData('page')['props']['cascade'];

    $this->actingAs($this->admin)->postJson('/admin/api/facility-list/export-cascade')->assertCreated();
    expect($cascade()['out_of_date'])->toBeFalse();

    // A level change does not reach the form's lists: still current.
    $this->actingAs($this->admin)->patchJson("/admin/api/facilities/{$this->facility->id}", ['level' => 'tertiary'])->assertOk();
    expect($cascade()['out_of_date'])->toBeFalse();

    $this->actingAs($this->admin)->patchJson("/admin/api/facilities/{$this->facility->id}", ['name' => 'Tudun Wada Clinic'])->assertOk();
    expect($cascade()['out_of_date'])->toBeTrue();

    $this->actingAs($this->admin)->postJson('/admin/api/facility-list/export-cascade')->assertCreated();
    expect($cascade()['out_of_date'])->toBeFalse();

    $this->actingAs($this->admin)->postJson('/admin/api/wards', ['lga_id' => $this->zaria, 'name' => 'Muchia'])->assertCreated();
    expect($cascade()['out_of_date'])->toBeTrue();
});

it('adds and renames wards within an LGA, audited; the 23 LGAs have no edit route', function (): void {
    $this->actingAs($this->admin)->postJson('/admin/api/wards', ['lga_id' => $this->zaria, 'name' => 'Muchia'])->assertCreated()->assertJsonPath('data.name', 'Muchia');
    $ward = Ward::query()->where('name', 'Muchia')->sole();
    $this->actingAs($this->admin)->postJson('/admin/api/wards', ['lga_id' => $this->zaria, 'name' => 'muchia'])->assertConflict()->assertJsonPath('code', 'ward_exists');

    $this->actingAs($this->admin)->patchJson("/admin/api/wards/{$ward->id}", ['name' => 'Muchia Sarki'])->assertOk();
    expect($ward->refresh()->name)->toBe('Muchia Sarki');
    expect(DB::table('activity_log')->where('subject_type', $ward->getMorphClass())->where('subject_id', $ward->id)->whereIn('event', ['ward_created', 'ward_renamed'])->where('causer_id', $this->admin->id)->count())->toBe(2);

    $this->actingAs($this->admin)->postJson('/admin/api/lgas', ['name' => 'Kaduna East'])->assertNotFound();
    expect(DB::table('lgas')->count())->toBe(23);
});
