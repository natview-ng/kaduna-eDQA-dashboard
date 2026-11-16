<?php

declare(strict_types=1);

namespace App\Http\Controllers\Plan;

use App\Domain\Round\Models\Round;
use App\Http\Controllers\Controller;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin implementation plan (/admin/plan?round=, Phase 6 step 3, ARCHITECTURE.md §9.2.6): the
 * plan for one round, the latest by default, open or closed. Every LGA below the threshold with its
 * stored row (owner, contact, review date, status, notes: each saved inline and audited), or the row
 * the plan proposes until "Generate plan for this round" stores it.
 */
final class AdminPlanController extends Controller
{
    public function __invoke(Request $request, AdminPlanPayload $payload): Response
    {
        $rounds = Round::query()->orderByDesc('year')->orderByDesc('quarter')->get();
        $asked = $request->integer('round');
        $round = $rounds->firstWhere('id', $asked) ?? $rounds->first();

        return Inertia::render('admin/plan', [
            'rounds' => $rounds->map(fn (Round $r): array => ['id' => $r->id, 'label' => $r->label(), 'status' => $r->status->value])->values()->all(),
            'round' => $round === null ? null : ['id' => $round->id, 'label' => $round->label(), 'status' => $round->status->value],
            'plan' => $round === null ? null : $payload->build($round),
            'roles' => array_map(fn (string $role): array => ['key' => $role, 'label' => (string) __("plan.owners.{$role}")], (array) config('edqa.plan.roles')),
            'today' => CarbonImmutable::today((string) config('app.timezone'))->toDateString(),
        ]);
    }
}
