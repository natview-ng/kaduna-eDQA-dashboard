<?php

declare(strict_types=1);

namespace App\Domain\Export\Jobs;

use App\Domain\Export\Actions\ExpireExports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** The daily retention run (routes/console.php), on the low queue. */
final class ExpireExportsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 600];

    public int $timeout = 300;

    public function __construct()
    {
        $this->onQueue('low');
    }

    public function handle(ExpireExports $expire): void
    {
        $expire->execute();
    }

    public function failed(?Throwable $exception): void
    {
        report($exception ?? new \RuntimeException('Export retention failed.'));
    }
}
