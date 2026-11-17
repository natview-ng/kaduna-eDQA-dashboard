<?php

declare(strict_types=1);

use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Models\User;
use Database\Seeders\ScoringRuleVersionSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Rows;

// The Rounds page (ARCHITECTURE.md §9.3). Every count on it is the server's.

beforeEach(function (): void {
    $this->seed(ScoringRuleVersionSeeder::class);
    $this->admin = User::factory()->create(['name' => 'Hauwa Bello']);
    $this->v1 = ScoringRuleVersion::query()->where('version', 1)->sole();
});

it('needs a signed-in administrator', function (): void {
    $this->get('/admin/rounds')->assertRedirect('/login');
});

it('lists every round newest first: status, window, accepted per source, open issues, version, who closed it', function (): void {
    $q1 = Round::factory()->closed()->create(['year' => 2027, 'quarter' => 1, 'closed_by' => $this->admin->id]);
    $q2 = Round::factory()->create(['year' => 2027, 'quarter' => 2, 'reopened_reason' => 'Late returns from Zaria']);
    $odk = Rows::sourceId('odk');
    $kobo = Rows::sourceId('kobo');

    // Accepted and flagged count; withdrawn does not.
    $facility = Rows::facility(['code' => 'FAC-X1']);
    $deleted = Rows::submission(['source_id' => $odk, 'deleted_at_source' => now()]);
    Rows::assessment(['round_id' => $q2->id, 'facility_id' => $facility, 'submission_id' => Rows::submission(['source_id' => $odk])]);
    Rows::assessment(['round_id' => $q2->id, 'submission_id' => $deleted]);
    Rows::assessment(['round_id' => $q2->id, 'status' => 'flagged', 'submission_id' => Rows::submission(['source_id' => $kobo])]);
    Rows::assessment(['round_id' => $q2->id, 'status' => 'withdrawn', 'submission_id' => Rows::submission(['source_id' => $kobo])]);

    // Two open quarantine records, one of them a duplicate of the ODK visit sent through Kobo;
    // a resolved one does not count.
    Rows::quarantined(['round_id' => $q2->id]);
    Rows::quarantined([
        'round_id' => $q2->id,
        'submission_id' => Rows::submission(['source_id' => $kobo]),
        'facility_ref' => 'fac-x1 ',
        'failures' => json_encode([['code' => 'DUPLICATE_ASSESSMENT', 'severity' => 'hard', 'detail' => 'already assessed']]),
    ]);
    Rows::quarantined(['round_id' => $q2->id, 'status' => 'resolved']);

    $this->actingAs($this->admin)->get('/admin/rounds')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/rounds')
        ->has('rounds', 2)
        ->where('rounds.0.label', 'Q2 2027')
        ->where('rounds.0.status', 'open')
        ->where('rounds.0.window_start', '2027-04-01')
        ->where('rounds.0.window_end', '2027-06-30')
        ->where('rounds.0.assessments.total', 3)
        ->where('rounds.0.assessments.by_source', [
            ['key' => 'odk', 'name' => app(SourceRegistry::class)->name('odk'), 'count' => 2],
            ['key' => 'kobo', 'name' => app(SourceRegistry::class)->name('kobo'), 'count' => 1],
        ])
        ->where('rounds.0.issues', ['quarantine' => 2, 'deletions' => 1, 'cross_source' => 1])
        ->where('rounds.0.rule_version', null)
        ->where('rounds.0.reads_rule_version', 1)
        ->where('rounds.0.closed_by', null)
        ->where('rounds.0.reopened_reason', 'Late returns from Zaria')
        ->where('rounds.1.label', 'Q1 2027')
        ->where('rounds.1.status', 'closed')
        ->where('rounds.1.rule_version', 1)
        ->where('rounds.1.closed_by', 'Hauwa Bello')
        ->where('rounds.1.assessments.total', 0)
        ->where('rounds.1.issues', ['quarantine' => 0, 'deletions' => 0, 'cross_source' => 0])
        ->has('graceDays')
        ->has('sources'));

    expect($q1->refresh()->closed_by)->toBe($this->admin->id);
});

it('reads the rounds with a fixed number of queries, however many there are', function (): void {
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get('/admin/rounds')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    $round = fn (int $quarter) => tap(Round::factory()->create(['year' => 2028, 'quarter' => $quarter]), function (Round $round): void {
        Rows::assessment(['round_id' => $round->id]);
        Rows::quarantined(['round_id' => $round->id]);
    });

    $round(1);
    $queries();            // the first request also warms the session and the shell's caches
    $one = $queries();
    $round(2);
    $round(3);
    $round(4);

    expect($queries())->toBe($one);
});
