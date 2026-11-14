<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facilities;

use App\Domain\Facility\Actions\SaveFacility;
use App\Domain\Facility\Actions\SetFacilityActive;
use App\Domain\Facility\Models\Facility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Facilities\FacilityReasonRequest;
use App\Http\Requests\Facilities\SaveFacilityRequest;
use App\Http\Resources\Facilities\FacilityResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/facilities (Phase 5 step 5): add a facility, edit it inline, deactivate and reactivate it
 * with a reason. Never a delete. Each change is audited by its action; a refusal is 409.
 */
final class FacilityController extends Controller
{
    public function store(SaveFacilityRequest $request, SaveFacility $save): JsonResponse
    {
        /** @var array{code: string, name: string, ward_id: int, level: string, ownership: string, lat?: float|string|null, lng?: float|string|null} $data */
        $data = $request->changes();

        return (new FacilityResource($save->create($data, self::actor($request))))->response()->setStatusCode(201);
    }

    public function update(SaveFacilityRequest $request, Facility $facility, SaveFacility $save): FacilityResource
    {
        return new FacilityResource($save->update($facility, $request->changes(), self::actor($request)));
    }

    public function deactivate(FacilityReasonRequest $request, Facility $facility, SetFacilityActive $active): FacilityResource
    {
        return new FacilityResource($active->deactivate($facility, self::actor($request), $request->string('reason')->toString()));
    }

    public function reactivate(FacilityReasonRequest $request, Facility $facility, SetFacilityActive $active): FacilityResource
    {
        return new FacilityResource($active->reactivate($facility, self::actor($request), $request->string('reason')->toString()));
    }

    private static function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}
