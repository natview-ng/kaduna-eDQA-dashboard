<?php

declare(strict_types=1);

namespace App\Domain\Export\Models;

use App\Domain\Export\Enums\ExportStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One export asked for from the admin area (D-96). Not Audited: the job updates
 * its progress many times; the meaningful moments (requested, done or failed, downloaded,
 * expired) are recorded explicitly by the actions, with the privacy option.
 *
 * @property int $id
 * @property string $type
 * @property array<string, mixed> $filter
 * @property array{format: string, include_private: bool} $options
 * @property int $requested_by
 * @property ExportStatus $status
 * @property int $progress
 * @property int|null $row_count
 * @property string|null $path
 * @property string|null $error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property CarbonImmutable|null $expires_at
 */
final class Export extends Model
{
    protected $fillable = ['type', 'filter', 'options', 'requested_by', 'status', 'progress', 'row_count', 'path', 'error', 'expires_at'];

    /** Mirrors the column defaults, so a new model matches its stored row. */
    protected $attributes = ['status' => 'queued', 'progress' => 0];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'filter' => 'array',
            'options' => 'array',
            'requested_by' => 'integer',
            'status' => ExportStatus::class,
            'progress' => 'integer',
            'row_count' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function includesPrivate(): bool
    {
        return (bool) ($this->options['include_private'] ?? false);
    }

    public function format(): string
    {
        return (string) ($this->options['format'] ?? 'csv');
    }
}
