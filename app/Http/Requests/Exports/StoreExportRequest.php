<?php

declare(strict_types=1);

namespace App\Http\Requests\Exports;

use App\Domain\Export\Enums\ExportFormat;
use App\Domain\Export\Types\ExportTypes;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Enums\ReportAudience;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /admin/api/exports: the type, the format, whether to include assessor and source details,
 * and the dashboard filter by the same names as the dashboard URL (ARCHITECTURE.md §8), so a view
 * can be exported as it is shown. The filter is checked by ReportFilter itself.
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

    public function reportFilter(): ReportFilter
    {
        /** @var array<string, mixed> $filter */
        $filter = (array) $this->input('filter', []);

        return ReportFilter::fromArray($filter, ReportAudience::Admin);
    }
}
