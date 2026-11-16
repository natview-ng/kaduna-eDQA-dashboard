<?php

declare(strict_types=1);

namespace App\Domain\Plan\Actions;

use App\Domain\Plan\Models\PlanAction;

/**
 * Saves one plan row from the admin plan page (Phase 6 step 3): its owner (a role, or "Other role…"
 * written out), contact and notes (admin-only), review date and status. No reason needed: a plan is
 * follow-up, not a figure, and stays editable for closed rounds. The model's audit records each
 * change, old and new, with who made it (PlanAction is Audited).
 */
final class UpdatePlanAction
{
    /**
     * @param  array{owner_role?: string, owner_role_other?: ?string, owner_contact?: ?string, due_date?: string, status?: string, notes?: ?string}  $fields
     */
    public function execute(PlanAction $action, array $fields): PlanAction
    {
        if (array_key_exists('owner_role', $fields) && $fields['owner_role'] !== 'other') {
            $fields['owner_role_other'] = null;
        }
        foreach (['owner_role_other', 'owner_contact', 'notes'] as $text) {
            if (array_key_exists($text, $fields)) {
                $value = trim((string) $fields[$text]);
                $fields[$text] = $value === '' ? null : $value;
            }
        }
        $action->fill($fields)->save();

        return $action->refresh();
    }
}
