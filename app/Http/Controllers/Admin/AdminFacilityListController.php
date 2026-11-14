<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Facility\Models\Lga;
use App\Domain\Facility\Queries\CascadeStatusQuery;
use App\Domain\Reporting\Queries\FacilityListQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Facilities\FacilityListRequest;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Facility list page (ARCHITECTURE.md §9.3, Phase 5 step 5): the master list searched,
 * filtered and paged on the server, the 23 LGAs with their wards (for the filters, the editor and
 * ward management; the 23 themselves are fixed), and whether the facility media file is out of
 * date. The page changes the list through /api/facilities and /api/wards and reloads only what
 * changed.
 */
final class AdminFacilityListController extends Controller
{
    public function __invoke(FacilityListRequest $request, FacilityListQuery $facilities, CascadeStatusQuery $cascade): Response
    {
        return Inertia::render('admin/facilities', [
            'facilities' => fn () => $facilities->paginate($request->filters()),
            'facilityFilters' => $request->filters(),
            'lgas' => fn (): array => Lga::query()->orderBy('name')->with(['wards' => fn ($q) => $q->orderBy('name')])->get()
                ->map(fn (Lga $lga): array => [
                    'id' => $lga->id,
                    'code' => $lga->code,
                    'name' => $lga->name,
                    'wards' => $lga->wards->map(fn ($ward): array => ['id' => $ward->id, 'name' => $ward->name])->values()->all(),
                ])->values()->all(),
            'cascade' => fn (): array => $cascade->status(),
        ]);
    }
}
