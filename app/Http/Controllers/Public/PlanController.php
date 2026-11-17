<?php

declare(strict_types=1);

namespace App\Http\Controllers\Public;

use App\Domain\Reporting\Aggregators\ImplementationPlanAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Http\Controllers\Controller;
use App\Http\Resources\Public\PlanResource;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Implementation plan (ARCHITECTURE.md §9.2.6), read-only: the plan from
 * ImplementationPlanAggregator, deferred. Owners, dates and statuses are edited in the admin
 * plan (AdminPlanController), and the Excel workplan is exported there.
 */
final class PlanController extends Controller
{
    public function __invoke(ReportFilter $filter, ImplementationPlanAggregator $plan): Response
    {
        return Inertia::render('dashboard/plan', [
            // Owners as roles only (D-79): PlanResource.
            'plan' => Inertia::defer(fn () => PlanResource::one($plan->plan($filter))),
        ]);
    }
}
