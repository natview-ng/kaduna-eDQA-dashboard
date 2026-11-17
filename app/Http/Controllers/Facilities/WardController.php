<?php

declare(strict_types=1);

namespace App\Http\Controllers\Facilities;

use App\Domain\Facility\Actions\SaveWard;
use App\Domain\Facility\Models\Ward;
use App\Http\Controllers\Controller;
use App\Http\Requests\Facilities\SaveWardRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * /api/wards: add a ward to one of the 23 LGAs, or rename one, audited. There
 * is no LGA route: the 23 are fixed (hard rule 9).
 */
final class WardController extends Controller
{
    public function store(SaveWardRequest $request, SaveWard $save): JsonResponse
    {
        $ward = $save->create($request->integer('lga_id'), $request->string('name')->toString(), self::actor($request));

        return response()->json(['data' => ['id' => $ward->id, 'lga_id' => $ward->lga_id, 'name' => $ward->name, 'code' => $ward->code]], 201);
    }

    public function update(SaveWardRequest $request, Ward $ward, SaveWard $save): JsonResponse
    {
        $ward = $save->rename($ward, $request->string('name')->toString(), self::actor($request));

        return response()->json(['data' => ['id' => $ward->id, 'lga_id' => $ward->lga_id, 'name' => $ward->name, 'code' => $ward->code]]);
    }

    private static function actor(Request $request): User
    {
        /** @var User $actor */
        $actor = $request->user();

        return $actor;
    }
}
