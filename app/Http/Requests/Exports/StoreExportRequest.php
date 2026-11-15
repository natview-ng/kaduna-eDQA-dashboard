<?php

declare(strict_types=1);

namespace App\Http\Requests\Exports;

use App\Domain\Export\Enums\ExportFormat;
use App\Domain\Export\Types\ExportTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /admin/api/exports: the type, the format, whether to include assessor and source details,
 * and the page's filter by its URL names (ARCHITECTURE.md §8), so a view can be exported as it is
 * shown. The export type checks its own filter (RequestExport).
 */
final class StoreExportRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(app(ExportTypes::class)->keys())],
            'format' => ['required', Rule::enum(ExportFormat::class)],
            'include_private' => ['sometimes', 'boolean'],
            'filter' => ['sometimes', 'array'],
        ];
    }

    public function exportFormat(): ExportFormat
    {
        return ExportFormat::from($this->string('format')->toString());
    }

    public function includePrivate(): bool
    {
        return $this->boolean('include_private');
    }

    /** @return array<string, mixed> */
    public function exportFilter(): array
    {
        /** @var array<string, mixed> $filter */
        $filter = (array) $this->input('filter', []);

        return $filter;
    }
}
