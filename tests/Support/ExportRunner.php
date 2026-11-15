<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Export\Actions\GenerateExport;
use App\Domain\Export\Enums\ExportStatus;
use App\Domain\Export\Models\Export;
use App\Domain\Export\Types\ExportTypes;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Runs an export the way the worker does (GenerateExport) and reads the file back, for the export
 * tests (Phase 6 step 1). Storage::fake('exports') first.
 */
final class ExportRunner
{
    /** @param  array<string, mixed>  $filter  as asked for, by the page's URL names */
    public static function run(string $type, array $filter, string $format = 'csv', bool $private = false, ?User $by = null): Export
    {
        $by ??= activity()->withoutLogs(fn () => User::factory()->create(['name' => 'Export Tester']));
        $export = Export::query()->create([
            'type' => $type,
            'filter' => (object) app(ExportTypes::class)->get($type)->filter($filter),
            'options' => ['format' => $format, 'include_private' => $private],
            'requested_by' => $by->id,
        ]);
        app(GenerateExport::class)->execute($export->id);
        $export->refresh();
        expect($export->status)->toBe(ExportStatus::Done, "the {$type} export did not finish: ".(string) $export->error);

        return $export;
    }

    /** The raw bytes of an export's file. */
    public static function content(Export $export): string
    {
        return (string) Storage::disk('exports')->get((string) $export->path);
    }

    /**
     * A CSV export read back with a real CSV parser: the headings and each row keyed by heading.
     *
     * @return array{headings: list<string>, rows: list<array<string, string>>}
     */
    public static function csv(Export $export): array
    {
        $content = self::content($export);
        expect(str_starts_with($content, "\u{FEFF}"))->toBeTrue('the CSV starts with a UTF-8 byte-order mark');
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, substr($content, 3));
        rewind($handle);
        $headings = fgetcsv($handle, escape: '') ?: [];
        $rows = [];
        while (($line = fgetcsv($handle, escape: '')) !== false) {
            if ($line === [null]) {
                continue;
            }
            $rows[] = array_combine($headings, array_map(fn (?string $cell): string => (string) $cell, $line));
        }
        fclose($handle);

        return ['headings' => array_map(strval(...), $headings), 'rows' => $rows];
    }
}
