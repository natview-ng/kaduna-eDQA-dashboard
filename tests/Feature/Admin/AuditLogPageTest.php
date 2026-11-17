<?php

declare(strict_types=1);

use App\Domain\Facility\Models\Facility;
use App\Domain\Round\Models\Round;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Auth\AuthAudit;
use Database\Seeders\ScoringRuleVersionSeeder;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

// D-93: The read-only audit log. With one role, administrators need to see each
// other's actions (SECURITY.md §4). Views are not audited; exports are.

beforeEach(function (): void {
    $this->seed(ScoringRuleVersionSeeder::class);
    $this->hauwa = activity()->withoutLogs(fn () => User::factory()->create(['name' => 'Hauwa Bello']));
    $this->musa = activity()->withoutLogs(fn () => User::factory()->create(['name' => 'Musa Garba']));

    Carbon::setTestNow('2026-11-02 09:15:00');
    // The fixtures' own model entries would crowd the log: created without them.
    $this->facility = activity()->withoutLogs(fn () => Facility::factory()->create(['name' => 'Tudun Wada PHC']));
    AuditLog::record($this->facility, $this->hauwa, 'facility_deactivated', old: ['is_active' => true], new: ['is_active' => false], reason: 'Closed down by the LGA in March', extra: ['cascade' => true]);
    Carbon::setTestNow('2026-11-05 14:00:00');
    $this->round = activity()->withoutLogs(fn () => Round::factory()->create(['year' => 2027, 'quarter' => 1]));
    AuditLog::record($this->round, $this->musa, 'window_updated', old: ['window_end' => '2027-03-31'], new: ['window_end' => '2027-04-15'], reason: 'Late visits from Ikara');
    AuthAudit::record('login-failed', $this->musa, ['email' => 'musa@example.test']);
    AuthAudit::record('lockout', $this->musa, ['minutes' => 15]);
    AuditLog::record(null, $this->hauwa, 'pull_requested', old: [], new: ['trigger' => 'manual', 'source' => 'kobo']);
    Carbon::setTestNow('2026-11-10 08:00:00');
});

/** The rows a GET of the page returns: their event keys, newest first. */
function auditEvents(string $query = ''): array
{
    $events = [];
    test()->actingAs(test()->hauwa)->get('/admin/audit'.($query === '' ? '' : "?{$query}"))->assertOk()->assertInertia(function (Assert $page) use (&$events): void {
        $events = array_column($page->toArray()['props']['entries']['data'], 'event');
    });

    return $events;
}

it('needs a signed-in administrator', function (): void {
    $this->get('/admin/audit')->assertRedirect('/login');
});

it('lists every entry newest first, with who, what, when, the subject, the reason and before/after', function (): void {
    $this->actingAs($this->hauwa)->get('/admin/audit')->assertInertia(fn (Assert $page) => $page
        ->component('admin/audit')
        ->where('entries.total', 5)
        ->where('entries.data.4.event', 'facility_deactivated')
        ->where('entries.data.4.actor', 'Hauwa Bello')
        ->where('entries.data.4.at', '2026-11-02T09:15:00+01:00')
        ->where('entries.data.4.subject.type', 'facility')
        ->where('entries.data.4.subject.label', 'Tudun Wada PHC')
        ->where('entries.data.4.subject.url', "/admin/facilities/{$this->facility->id}")
        ->where('entries.data.4.reason', 'Closed down by the LGA in March')
        ->where('entries.data.4.old', ['is_active' => true])
        ->where('entries.data.4.new', ['is_active' => false])
        ->where('entries.data.1.auth', true)
        ->where('entries.data.1.event', 'lockout')
        ->has('actors', 2)
        ->has('events'));
});

it('filters by actor, subject type, source, event, date range and reason', function (string $query, array $events): void {
    $query = str_replace(['{musa}', '{hauwa}'], [(string) $this->musa->id, (string) $this->hauwa->id], $query);

    expect(auditEvents($query))->toBe($events);
})->with([
    'actor' => ['actor={musa}', ['lockout', 'login-failed', 'window_updated']],
    'subject: facility' => ['type=facility', ['facility_deactivated']],
    'subject: round' => ['type=round', ['window_updated']],
    'subject: auth' => ['type=auth', ['lockout', 'login-failed']],
    'source' => ['source=kobo', ['pull_requested']],
    'event' => ['event=window_updated', ['window_updated']],
    'date range' => ['from=2026-11-01&to=2026-11-03', ['facility_deactivated']],
    'reason' => ['q=ikara', ['window_updated']],
    'together' => ['actor={hauwa}&type=facility&q=march', ['facility_deactivated']],
]);

it('does not audit a view of the log', function (): void {
    $before = DB::table('activity_log')->count();
    auditEvents();
    auditEvents('type=auth');

    expect(DB::table('activity_log')->count())->toBe($before);
});

it('has no route that changes or removes the audit log', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())->filter(fn (RoutingRoute $route): bool => str_contains($route->uri(), 'audit'));
    $writes = $routes->filter(fn (RoutingRoute $route): bool => array_diff($route->methods(), ['GET', 'HEAD']) !== [])->map(fn (RoutingRoute $route): string => $route->uri())->values()->all();
    // Its export is the audit_log export type, under /admin/api/exports: no audit route writes.
    expect($writes)->toBe([]);

    // Nothing in the app updates, deletes or truncates activity_log, by query builder, model or SQL.
    $root = realpath(__DIR__.'/../../../app');
    $offenders = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator((string) $root, FilesystemIterator::SKIP_DOTS)) as $file) {
        $source = (string) file_get_contents((string) $file);
        if (preg_match("/table\\(['\"]activity_log['\"]\\)[^;]*->(update|delete|truncate|upsert)\\(|(UPDATE|DELETE FROM|TRUNCATE)\\s+activity_log|Activity::[^;]*->(update|delete|forceDelete)\\(/i", $source) === 1) {
            $offenders[] = basename((string) $file);
        }
    }
    expect($offenders)->toBe([]);
});
