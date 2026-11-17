<?php

declare(strict_types=1);

namespace App\Domain\Export\Enums;

use App\Support\Enums\HasLabel;

/**
 * An export's life: queued when asked for, running on the exports queue, then
 * done (the file is on the private disk) or failed; a done export becomes expired when the daily
 * retention job deletes its file after edqa.exports.retention_days.
 */
enum ExportStatus: string
{
    use HasLabel;

    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Expired = 'expired';

    public function terminal(): bool
    {
        return $this !== self::Queued && $this !== self::Running;
    }

    protected static function labelGroup(): string
    {
        return 'export_status';
    }
}
