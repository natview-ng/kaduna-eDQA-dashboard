<?php

declare(strict_types=1);

namespace App\Domain\Plan\Actions;

use App\Domain\Plan\Models\PlanAction;
use App\Domain\Reporting\Aggregators\ImplementationPlanAggregator;
use App\Domain\Reporting\DTOs\PlanRow;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Enums\ReportAudience;
use App\Domain\Round\Models\Round;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * "Generate plan for this round": a plan_actions row for every LGA the plan
 * proposes an action for and that has none yet (ImplementationPlanAggregator's generated rows: below
 * the threshold, its weakest dimension, the standard action and the dimension's default role),
 * reviewed edqa.plan.review_days after today. Idempotent: an LGA with a stored row keeps it, and a
 * second run creates nothing. Works on closed rounds too: a plan is follow-up, not a figure. Each row
 * is audited as created; the run as plan_generated.
 */
final class GeneratePlan
{
    public function __construct(
        private readonly ImplementationPlanAggregator $plan,
    ) {}

    /** @return int how many rows were created */
    public function execute(Round $round, User $actor): int
    {
        $plan = $this->plan->plan(new ReportFilter(year: $round->year, quarter: $round->quarter, audience: ReportAudience::Admin));
        $review = CarbonImmutable::today()->addDays((int) config('edqa.plan.review_days'));
        $roles = (array) config('edqa.plan.default_owners');

        return DB::transaction(function () use ($plan, $round, $actor, $review, $roles): int {
            $created = 0;
            foreach (array_filter($plan->actions, fn (PlanRow $row): bool => ! $row->stored) as $row) {
                $action = PlanAction::query()->firstOrCreate(
                    ['round_id' => $round->id, 'lga_id' => $row->lgaId, 'dimension' => $row->dimension->value],
                    ['action_text' => $row->action, 'owner_role' => (string) ($roles[$row->dimension->value] ?? 'lga_me_officer'), 'due_date' => $review->toDateString(), 'status' => 'not_started'],
                );
                if ($action->wasRecentlyCreated) {
                    $created++;
                }
            }
            AuditLog::record($round, $actor, 'plan_generated', old: [], new: ['created' => $created]);

            return $created;
        });
    }
}
