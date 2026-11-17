<?php

declare(strict_types=1);

namespace App\Http\Controllers\Plan;

use App\Domain\Plan\Actions\GeneratePlan;
use App\Domain\Plan\Actions\UpdatePlanAction;
use App\Domain\Plan\Models\PlanAction;
use App\Domain\Round\Models\Round;
use App\Http\Controllers\Controller;
use App\Http\Requests\Plan\UpdatePlanActionRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The admin plan's writes: generate a round's plan (idempotent), and save one row.
 * Both audited; no reason asked: a plan is follow-up, not a figure.
 */
final class PlanActionController extends Controller
{
    public function generate(Request $request, Round $round, GeneratePlan $generate): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return response()->json(['data' => ['created' => $generate->execute($round, $user)]]);
    }

    public function update(UpdatePlanActionRequest $request, PlanAction $planAction, UpdatePlanAction $update): JsonResponse
    {
        $action = $update->execute($planAction, $request->fields());

        return response()->json(['data' => [
            'id' => $action->id,
            'owner_role' => $action->owner_role,
            'owner_role_other' => $action->owner_role_other,
            'owner_contact' => $action->owner_contact,
            'due_date' => $action->due_date->toDateString(),
            'status' => $action->status->value,
            'notes' => $action->notes,
        ]]);
    }
}
