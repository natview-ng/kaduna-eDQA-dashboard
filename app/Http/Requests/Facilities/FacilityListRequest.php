<?php

declare(strict_types=1);

namespace App\Http\Requests\Facilities;

use App\Domain\Facility\Enums\FacilityLevel;
use App\Domain\Facility\Enums\Ownership;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** GET /admin/facilities: the Facility list's search, filters and page (Phase 5 step 5). */
final class FacilityListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'lga' => ['nullable', 'string', 'exists:lgas,code'],
            'ward' => ['nullable', 'integer', 'exists:wards,id'],
            'level' => ['nullable', Rule::enum(FacilityLevel::class)],
            'ownership' => ['nullable', Rule::enum(Ownership::class)],
            'active' => ['nullable', 'in:yes,no'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{q: ?string, lga: ?string, ward: ?int, level: ?string, ownership: ?string, active: ?string, page: int} */
    public function filters(): array
    {
        $string = fn (string $key): ?string => $this->filled($key) ? $this->string($key)->toString() : null;

        return [
            'q' => $string('q'),
            'lga' => $string('lga'),
            'ward' => $this->filled('ward') ? $this->integer('ward') : null,
            'level' => $string('level'),
            'ownership' => $string('ownership'),
            'active' => $string('active'),
            'page' => max(1, $this->integer('page', 1)),
        ];
    }
}
