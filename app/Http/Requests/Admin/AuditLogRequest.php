<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\Audit\AuditLogQuery;
use Illuminate\Foundation\Http\FormRequest;

/** The Audit log's filters: GET /admin/audit; its export checks the same in AuditLogExport. */
final class AuditLogRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'actor' => ['nullable', 'integer', 'exists:users,id'],
            'type' => ['nullable', 'in:'.implode(',', [...array_keys(AuditLogQuery::types()), 'auth'])],
            'source' => ['nullable', 'string', 'max:40'],
            'event' => ['nullable', 'string', 'max:80'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{actor: ?int, type: ?string, source: ?string, event: ?string, from: ?string, to: ?string, q: ?string, page: int} */
    public function filters(): array
    {
        $string = fn (string $key): ?string => $this->filled($key) ? $this->string($key)->toString() : null;

        return [
            'actor' => $this->filled('actor') ? $this->integer('actor') : null,
            'type' => $string('type'),
            'source' => $string('source'),
            'event' => $string('event'),
            'from' => $string('from'),
            'to' => $string('to'),
            'q' => $string('q'),
            'page' => max(1, $this->integer('page', 1)),
        ];
    }
}
