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
 * Renders one PDF round report (D-101) on the exports queue, which in staging and
 * production only worker-exports serves: the one image with Chromium (DEPLOY.md §12). Same
 * lifecycle as any export (GenerateExport: running, progress, done or failed, audited); once only,
 * as a retry would render it again.
 */
final class GenerateRoundReport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

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
