<?php

declare(strict_types=1);

namespace App\Http\Requests\Scoring;

use Illuminate\Foundation\Http\FormRequest;

/**
 * PATCH /api/scoring-rules/{draft}: { changes: { bands?, item_weights?, na_policy?, choice_map? } }.
 * Which keys and values make a valid config is the Action's and RuleConfig's to enforce (409).
 */
final class EditDraftRuleVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['changes' => ['present', 'array']];
    }

    /** @return array<string, mixed> */
    public function changes(): array
    {
        $changes = $this->input('changes', []);

        return is_array($changes) ? $changes : [];
    }
}
