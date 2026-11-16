<?php

declare(strict_types=1);

namespace App\Http\Requests\Plan;

use App\Domain\Plan\Enums\PlanActionStatus;
use App\Domain\Plan\Models\PlanAction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /admin/api/plan-actions/{planAction}: any of the owner (a role, or "other" with the role or
 * unit written out: never a person's name, says the page), contact, review date, status and notes.
 * A new review date is on or after today; an unchanged one may stay in the past (the page warns).
 */
final class UpdatePlanActionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'owner_role' => ['sometimes', 'string', Rule::in([...(array) config('edqa.plan.roles'), 'other'])],
            'owner_role_other' => ['nullable', 'string', 'max:80', Rule::requiredIf(fn (): bool => $this->input('owner_role') === 'other')],
            'owner_contact' => ['sometimes', 'nullable', 'string', 'max:200'],
            'due_date' => ['sometimes', 'date_format:Y-m-d'],
            'status' => ['sometimes', Rule::enum(PlanActionStatus::class)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    /** @return list<callable(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $action = $this->route('planAction');
            $date = $this->input('due_date');
            if (! $action instanceof PlanAction || ! is_string($date) || $validator->errors()->has('due_date')) {
                return;
            }
            $timezone = (string) config('app.timezone');
            if ($date !== $action->due_date->toDateString() && CarbonImmutable::parse($date, $timezone)->lt(CarbonImmutable::today($timezone))) {
                $validator->errors()->add('due_date', (string) __('plan.admin.errors.past_date'));
            }
        }];
    }

    /** @return array{owner_role?: string, owner_role_other?: ?string, owner_contact?: ?string, due_date?: string, status?: string, notes?: ?string} */
    public function fields(): array
    {
        /** @var array{owner_role?: string, owner_role_other?: ?string, owner_contact?: ?string, due_date?: string, status?: string, notes?: ?string} $fields */
        $fields = $this->safe()->only(['owner_role', 'owner_role_other', 'owner_contact', 'due_date', 'status', 'notes']);

        return $fields;
    }
}
