<?php

declare(strict_types=1);

namespace App\Http\Resources\Exports;

use App\Domain\Export\Enums\ExportStatus;
use App\Domain\Export\Models\Export;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\URL;

/**
 * An export's state for the progress panel (GET /admin/api/exports/{id}): status in words,
 * progress, rows, and once done a signed download link that works edqa.exports.link_minutes (30).
 *
 * @mixin Export
 */
final class ExportResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $done = $this->status === ExportStatus::Done && $this->path !== null;
        $minutes = (int) config('edqa.exports.link_minutes');

        return [
            'id' => $this->id,
            'type' => $this->type,
            'format' => $this->format(),
            'include_private' => $this->includesPrivate(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'progress' => $this->progress,
            'rows' => $this->row_count,
            'error' => $this->status === ExportStatus::Failed ? $this->error : null,
            'file' => $done ? basename((string) $this->path) : null,
            'url' => $done ? URL::temporarySignedRoute('admin.exports.download', now()->addMinutes($minutes), ['export' => $this->id], absolute: false) : null,
            'url_expires_at' => $done ? now()->addMinutes($minutes)->toIso8601String() : null,
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
