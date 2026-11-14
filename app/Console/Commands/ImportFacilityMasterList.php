<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Facility\Import\MasterListImport;
use App\Domain\Facility\Models\Facility;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Loads the facility master list from the client's signed-off reconciliation workbook (D-91):
 * reference data, not assessment data, so it does not conflict with source-only ingestion
 * (CLAUDE.md hard rule 1). There is no upload route: the file is read from the server's disk.
 *
 * It prints what would change first (added, changed, synthetic facilities deactivated, wards
 * created); --dry-run stops there and writes nothing. Any bad row refuses the whole workbook with
 * its sheet and line. Outside local, replacing the synthetic list needs --force. Every change is
 * audited with the reason "master list import {filename}".
 */
final class ImportFacilityMasterList extends Command
{
    protected $signature = 'edqa:facilities:import-master-list
        {path : The signed-off reconciliation workbook (.xlsx, facility_reconcile.py\'s output)}
        {--dry-run : Print what would change; write nothing}
        {--force : Replace the synthetic facility list outside local}';

    protected $description = 'Load the facility master list from the signed-off reconciliation workbook (console only)';

    public function handle(MasterListImport $import): int
    {
        $path = (string) $this->argument('path');
        $file = basename($path);
        if (! is_file($path)) {
            $this->error((string) __('facilities.import.no_file', ['file' => $path]));

            return self::FAILURE;
        }

        try {
            $read = $import->read($path);
        } catch (RuntimeException $e) {
            $message = str_starts_with($e->getMessage(), 'Not a readable') || str_starts_with($e->getMessage(), 'Unreadable')
                ? (string) __('facilities.import.not_xlsx', ['file' => $file])
                : $e->getMessage();
            $this->error($message);

            return self::FAILURE;
        }

        if ($read['problems'] !== []) {
            foreach ($read['problems'] as $problem) {
                $this->line("  {$problem}");
            }
            $this->error((string) __('facilities.import.refused'));

            return self::FAILURE;
        }

        $diff = $import->diff($read['rows']);
        $synthetic = Facility::query()->where('is_active', true)->where('code', 'like', MasterListImport::SYNTHETIC_PREFIX.'%')->exists();
        if ($synthetic && ! app()->environment('local') && ! (bool) $this->option('force') && ! (bool) $this->option('dry-run')) {
            $this->error((string) __('facilities.import.synthetic_needs_force', ['env' => app()->environment()]));

            return self::FAILURE;
        }

        $this->print($diff, $read['skipped'], $file);
        if ($diff['add'] === [] && $diff['change'] === [] && $diff['deactivate'] === [] && $diff['wards'] === []) {
            $this->info((string) __('facilities.import.nothing', ['file' => $file]));

            return self::SUCCESS;
        }
        if ((bool) $this->option('dry-run')) {
            $this->warn((string) __('facilities.import.dry_run'));

            return self::SUCCESS;
        }

        $reason = (string) __('facilities.import.reason', ['file' => $file]);
        $import->apply($diff, $reason);
        $this->info((string) __('facilities.import.done', [
            'added' => count($diff['add']), 'changed' => count($diff['change']), 'deactivated' => count($diff['deactivate']), 'wards' => count($diff['wards']), 'reason' => $reason,
        ]));

        return self::SUCCESS;
    }

    /** @param  array{add: list<array<string, mixed>>, change: list<array{facility: Facility, row: array<string, mixed>, changes: array<string, array{0: string, 1: string}>}>, unchanged: int, wards: list<array{lga_id: int, lga: string, name: string}>, deactivate: list<Facility>}  $diff */
    private function print(array $diff, int $skipped, string $file): void
    {
        $this->line((string) __('facilities.import.diff_title', ['file' => $file]));
        foreach ($diff['wards'] as $ward) {
            $this->line('  '.__('facilities.import.new_ward', ['ward' => $ward['name'], 'lga' => $ward['lga']]));
        }
        foreach ($diff['add'] as $row) {
            $this->line('  '.__('facilities.import.add', ['code' => (string) $row['code'], 'name' => (string) $row['name'], 'lga' => (string) $row['lga'], 'ward' => (string) $row['ward']]));
        }
        foreach ($diff['change'] as $change) {
            $changes = implode('; ', array_map(fn (string $field, array $pair): string => "{$field}: \"{$pair[0]}\" → \"{$pair[1]}\"", array_keys($change['changes']), $change['changes']));
            $this->line('  '.__('facilities.import.change', ['code' => $change['facility']->code, 'changes' => $changes]));
        }
        foreach ($diff['deactivate'] as $facility) {
            $this->line('  '.__('facilities.import.deactivate', ['code' => $facility->code, 'name' => $facility->name]));
        }
        $this->line('  '.__('facilities.import.unchanged', ['count' => $diff['unchanged']]));
        if ($skipped > 0) {
            $this->line('  '.__('facilities.import.skipped', ['count' => $skipped]));
        }
    }
}
