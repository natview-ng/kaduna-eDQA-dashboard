<?php

declare(strict_types=1);

use App\Domain\Round\Actions\CloseRound;
use App\Domain\Round\Actions\ReopenRound;
use App\Domain\Round\Models\Round;
use App\Models\User;
use Database\Seeders\LgaSeeder;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\ReportingFixture;

// Closing a round publishes it (D-07, D-77), reopening takes it back. An anonymous
// visitor sees a round only while it is closed, and the public cache is flushed both ways (the
// cache store is a real one here, so a stale answer would show).

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-01 08:00:00');      // the fixture's scores are computed now
    $this->seed(LgaSeeder::class);
    $this->f = ReportingFixture::build();          // Q1 2026 closed (F1 alone), Q2 2026 open (7)
    $this->admin = User::factory()->create();
});

/** What an anonymous visitor sees on Home: facilities in the hero, and "Figures last updated". */
function anonymousHome(): array
{
    $seen = [];
    test()->get('/')->assertOk()->assertInertia(function (Assert $page) use (&$seen): void {
        $props = $page->toArray()['props'];
        $seen = ['facilities' => $props['hero']['facilitiesVisited'], 'updated' => $props['figuresUpdatedAt'] ?? null];
    });

    return $seen;
}

it('shows an anonymous visitor a round only after it closes, and not after it reopens', function (): void {
    Carbon::setTestNow('2026-07-10 09:00:00');
    expect(anonymousHome()['facilities'])->toBe(1);                      // Q2 is open: not public
    expect($this->getJson('/api/public/aggregates')->json('data.summary.facilitiesVisited'))->toBe(1);

    Carbon::setTestNow('2026-07-12 15:30:00');
    app(CloseRound::class)->execute(Round::query()->findOrFail($this->f->q2), $this->admin, 'All returns are in for Q2 2026.');

    $after = anonymousHome();
    expect($after['facilities'])->toBe(7)                                 // Q2 is now the latest closed round
        ->and(Carbon::parse($after['updated'])->equalTo(Carbon::parse('2026-07-12 15:30:00')))->toBeTrue();
    expect($this->getJson('/api/public/aggregates')->json('data.summary.facilitiesVisited'))->toBe(7);

    app(ReopenRound::class)->execute(Round::query()->findOrFail($this->f->q2), $this->admin, 'A late batch from Ikara arrived.');

    expect(anonymousHome()['facilities'])->toBe(1)                        // back to Q1
        ->and($this->getJson('/api/public/aggregates')->json('data.summary.facilitiesVisited'))->toBe(1);
});

it('keeps the open round in the administrator preview throughout', function (): void {
    $this->actingAs($this->admin)->get('/')->assertInertia(fn (Assert $page) => $page->where('hero.facilitiesVisited', 7));
});
