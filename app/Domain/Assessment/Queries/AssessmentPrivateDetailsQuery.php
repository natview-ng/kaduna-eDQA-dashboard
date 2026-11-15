<?php

declare(strict_types=1);

namespace App\Domain\Assessment\Queries;

use App\Domain\Assessment\Models\Assessment;

/**
 * The assessor, device and visit GPS of assessments (SECURITY.md §3.1: never public). Read only
 * for an export whose requester chose "Include assessor and source details" (Phase 6 step 0), one
 * query per chunk; nothing else in an export ever selects these columns.
 */
final class AssessmentPrivateDetailsQuery
{
    /**
     * @param  list<int>  $assessmentIds
     * @return array<int, array{assessor: string, device: ?string, latitude: ?float, longitude: ?float}> by assessment id
     */
    public function forAssessments(array $assessmentIds): array
    {
        if ($assessmentIds === []) {
            return [];
        }
        $details = [];
        foreach (Assessment::query()->whereIn('id', $assessmentIds)->get(['id', 'assessor_name', 'device_id', 'gps']) as $assessment) {
            [$latitude, $longitude] = self::point($assessment->getAttribute('gps'));
            $details[(int) $assessment->id] = [
                'assessor' => (string) $assessment->assessor_name,
                'device' => is_string($assessment->device_id) ? $assessment->device_id : null,
                'latitude' => $latitude,
                'longitude' => $longitude,
            ];
        }

        return $details;
    }

    /**
     * A GeoJSON point is [longitude, latitude, altitude?].
     *
     * @return array{0: ?float, 1: ?float} latitude, longitude
     */
    private static function point(mixed $gps): array
    {
        if (is_string($gps)) {
            $gps = json_decode($gps, true);
        }
        $coordinates = is_array($gps) && is_array($gps['coordinates'] ?? null) ? $gps['coordinates'] : null;
        if ($coordinates === null || ! is_numeric($coordinates[0] ?? null) || ! is_numeric($coordinates[1] ?? null)) {
            return [null, null];
        }

        return [(float) $coordinates[1], (float) $coordinates[0]];
    }
}
