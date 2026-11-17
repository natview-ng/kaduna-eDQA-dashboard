<?php

declare(strict_types=1);

namespace App\Http\Requests\Facilities;

use App\Domain\Facility\Actions\SetFacilityActive;
use Illuminate\Foundation\Http\FormRequest;

/** Deactivate or reactivate a facility: a reason of at least 10 characters. */
final class FacilityReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:'.SetFacilityActive::MIN_REASON]];
    }
}
