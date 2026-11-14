<?php

declare(strict_types=1);

namespace App\Http\Requests\Rounds;

use App\Domain\Round\Actions\CloseRound;
use Illuminate\Foundation\Http\FormRequest;

/** POST /api/rounds/{round}/close: closing publishes the round, so it takes a reason (step 4). */
final class CloseRoundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:'.CloseRound::MIN_REASON],
        ];
    }
}
