<?php

declare(strict_types=1);

namespace App\Domain\Export\Jobs;

use App\Domain\Export\Actions\GenerateExport;
use App\Domain\Export\Enums\ExportStatus;
use App\Domain\Export\Models\Export;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Writes one export on the exports queue (DEPLOY.md: default, rescore, exports, low), never in the
 * request. Once only: a retry would write the file again; GenerateExport marks a
 * failure itself, and failed() covers a worker that dies mid-way.
 */
final class GenerateExportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** @var list<int> */
    public array $backoff = [30];

    public int $timeout = 600;

    public function __construct(
        public readonly int $exportId,
    ) {
        $this->onQueue('exports');
    }

    public function uniqueId(): string
    {
        return (string) $this->exportId;
    }

    public function handle(GenerateExport $generate): void
    {
        $generate->execute($this->exportId);
    }

    public function failed(?Throwable $exception): void
    {
        Export::query()->whereKey($this->exportId)->whereIn('status', [ExportStatus::Queued->value, ExportStatus::Running->value])
            ->update(['status' => ExportStatus::Failed->value, 'error' => (string) __('exports.errors.failed')]);
    }
}
