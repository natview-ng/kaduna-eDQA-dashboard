<?php

declare(strict_types=1);

use App\Domain\Export\Enums\ExportStatus;
use Tests\Support\Rows;

// Phase 6 step 0: the exports table. A queued export moves queued → running → done or failed, and
// a done one becomes expired when the retention job deletes its file (7 days).

it('accepts every ExportStatus the app defines', function (ExportStatus $status): void {
    $done = in_array($status, [ExportStatus::Done], true);
    expect(Rows::export(['status' => $status->value, 'path' => $done ? 'exports/x.csv' : null, 'row_count' => $done ? 3 : null, 'progress' => $done ? 100 : 0]))->toBeInt();
})->with(fn (): array => ExportStatus::cases());

it('rejects a status the app does not define', function (): void {
    expectCheckViolation(fn () => Rows::export(['status' => 'cancelled']), 'exports_status_valid');
});

it('rejects progress outside 0 to 100', function (int $progress): void {
    expectCheckViolation(fn () => Rows::export(['progress' => $progress]), 'exports_progress_valid');
})->with([-1, 101]);

it('rejects a negative row count', function (): void {
    expectCheckViolation(fn () => Rows::export(['row_count' => -1]), 'exports_row_count_valid');
});

it('rejects a done export without its file', function (): void {
    expectCheckViolation(fn () => Rows::export(['status' => 'done', 'path' => null, 'row_count' => 1, 'progress' => 100]), 'exports_done_has_file');
});

it('rejects an expired export that still names a file', function (): void {
    expectCheckViolation(fn () => Rows::export(['status' => 'expired', 'path' => 'exports/x.csv']), 'exports_expired_has_no_file');
});

it('rejects options that are not an object', function (): void {
    expectCheckViolation(fn () => Rows::export(['options' => '[]']), 'exports_options_object');
});

it('rejects a filter that is not an object', function (): void {
    expectCheckViolation(fn () => Rows::export(['filter' => '"2026"']), 'exports_filter_object');
});
