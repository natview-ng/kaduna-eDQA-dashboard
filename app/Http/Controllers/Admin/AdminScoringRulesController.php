<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Scoring\ScoringRulesPayload;
use Illuminate\Container\Attributes\Config;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Scoring rules page (ARCHITECTURE.md §7, §9.3): the current version and the
 * draft, the item catalog, the rounds a publish would and would not touch (D-32), the rescores a
 * publish started, and the two settings the page shows read-only (they are config, not a rule
 * version: weighting and display rounding, Q-09). The page acts through /api/scoring-rules.
 */
final class AdminScoringRulesController extends Controller
{
    public function __invoke(
        ScoringRulesPayload $payload,
        #[Config('edqa.aggregation.weighting')] string $weighting,
        #[Config('edqa.presentation.rounding')] string $rounding,
    ): Response {
        return Inertia::render('admin/scoring-rules', [
            'versions' => fn (): array => $payload->versions(),
            'draft' => fn (): ?array => collect($payload->versions())->firstWhere('status', 'draft'),
            'items' => fn (): array => $payload->items(),
            'rounds' => fn (): array => $payload->rounds(),
            'rescores' => fn (): array => $payload->rescores(),
            'settings' => ['weighting' => $weighting, 'rounding' => $rounding],
        ]);
    }
}
