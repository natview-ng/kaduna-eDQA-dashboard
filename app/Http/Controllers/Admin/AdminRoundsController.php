<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Ingestion\Actions\StartManualPull;
use App\Domain\Ingestion\SourceRegistry;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Rounds\RoundsPayload;
use Illuminate\Container\Attributes\Config;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Rounds page (ARCHITECTURE.md §9.3, Phase 5 step 4): every round with its counts, the sources
 * that can be reconciled before a close (configured and switched on, with any reconcile already
 * running, so the page follows it), and the late-visit grace for the create form's hint. Closing a
 * round publishes it (D-07, D-77); the page acts through /api/rounds and reloads only `rounds`.
 */
final class AdminRoundsController extends Controller
{
    public function __invoke(RoundsPayload $rounds, SourceRegistry $registry, #[Config('edqa.rounds.window_grace_days')] int $graceDays): Response
    {
        return Inertia::render('admin/rounds', [
            'rounds' => fn (): array => $rounds->build(),
            'sources' => fn (): array => array_map(fn (string $key): array => [
                'key' => $key,
                'name' => $registry->name($key),
                'running_reconcile_run_id' => StartManualPull::running($registry->id($key), reconcile: true)?->id,
            ], $registry->enabled()),
            'graceDays' => $graceDays,
        ]);
    }
}
