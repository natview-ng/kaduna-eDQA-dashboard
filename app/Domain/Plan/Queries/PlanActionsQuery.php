<?php

declare(strict_types=1);

namespace App\Domain\Plan\Queries;

use App\Domain\Plan\Models\PlanAction;

/**
 * A round's stored plan rows with what only administrators see (Phase 6 step 3): the row id, the
 * owner's role key or the role written under "Other role…", the contact, the review date, the
 * status and the notes. The admin plan page lays these over ImplementationPlanAggregator's rows;
 * the public plan never reads them.
 */
final class PlanActionsQuery
{
    /**
     * @return array<string, array{id: int, owner_role: string, owner_role_other: ?string, owner_contact: ?string, due_date: string, status: string, notes: ?string}> by "lgaId|dimension"
     */
    public function forRound(int $roundId): array
    {
        $rows = [];
        foreach (PlanAction::query()->where('round_id', $roundId)->get() as $action) {
            $rows["{$action->lga_id}|{$action->dimension->value}"] = [
                'id' => $action->id,
                'owner_role' => (string) $action->owner_role,
                'owner_role_other' => $action->owner_role_other,
                'owner_contact' => $action->owner_contact,
                'due_date' => $action->due_date->toDateString(),
                'status' => $action->status->value,
                'notes' => $action->notes,
            ];
        }

        return $rows;
    }
}
