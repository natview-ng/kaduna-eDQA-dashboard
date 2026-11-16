<?php

declare(strict_types=1);

namespace App\Http\Controllers\Plan;

use App\Domain\Plan\Queries\PlanActionsQuery;
use App\Domain\Reporting\Aggregators\ImplementationPlanAggregator;
use App\Domain\Reporting\DTOs\PlanRow;
use App\Domain\Reporting\DTOs\PlanSummary;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Enums\ReportAudience;
use App\Domain\Round\Models\Round;
use App\Http\Resources\Public\ScoreValueResource;

/**
 * The admin plan page's data for one round (Phase 6 step 3): ImplementationPlanAggregator's rows for
 * the admin audience (the round may be open), each stored row with what only administrators see laid
 * over it (PlanActionsQuery: id, role key, other role, contact, notes), and the summary by type and
 * status. A generated row has no id: "Generate plan for this round" stores it.
 */
final class AdminPlanPayload
{
    public function __construct(
        private readonly ImplementationPlanAggregator $plan,
        private readonly PlanActionsQuery $stored,
    ) {}

    /** @return array<string, mixed> */
    public function build(Round $round): array
    {
        $plan = $this->plan->plan(new ReportFilter(year: $round->year, quarter: $round->quarter, audience: ReportAudience::Admin));
        $stored = $this->stored->forRound($round->id);

        return [
            'threshold' => $plan->threshold,
            'rows' => array_map(function (PlanRow $row) use ($stored): array {
                $saved = $stored["{$row->lgaId}|{$row->dimension->value}"] ?? null;

                return [
                    'key' => "{$row->lgaId}-{$row->dimension->value}",
                    'id' => $saved['id'] ?? null,
                    'lga' => ['id' => $row->lgaId, 'code' => $row->code, 'name' => $row->name],
                    'overall' => ScoreValueResource::one($row->overall),
                    'dimension' => $row->dimension->value,
                    'dimension_label' => $row->dimension->label(),
                    'score' => ScoreValueResource::one($row->score),
                    'action' => $row->action,
                    'owner' => $row->owner,
                    'owner_role' => $saved['owner_role'] ?? (string) config("edqa.plan.default_owners.{$row->dimension->value}"),
                    'owner_role_other' => $saved['owner_role_other'] ?? null,
                    'owner_contact' => $saved['owner_contact'] ?? null,
                    'due_date' => $saved['due_date'] ?? null,
                    'status' => $saved['status'] ?? $row->status->value,
                    'notes' => $saved['notes'] ?? null,
                ];
            }, $plan->actions),
            'unsaved' => count(array_filter($plan->actions, fn (PlanRow $row): bool => ! $row->stored)),
            'summary' => array_map(fn (PlanSummary $summary): array => [
                'dimension' => $summary->dimension->value,
                'dimension_label' => $summary->dimension->label(),
                'action' => $summary->action,
                'meaning' => $summary->meaning,
                'owner' => $summary->owner,
                'count' => $summary->count,
                'statuses' => $summary->statuses,
            ], $plan->summary),
        ];
    }
}
