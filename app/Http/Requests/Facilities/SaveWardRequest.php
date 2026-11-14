<?php

declare(strict_types=1);

namespace App\Http\Requests\Facilities;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/wards (an LGA and a name) and PATCH /api/wards/{ward} (a new name). */
final class SaveWardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lga_id' => [$this->route('ward') === null ? 'required' : 'prohibited', 'integer', 'exists:lgas,id'],
            'name' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }
}
