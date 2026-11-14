<?php

declare(strict_types=1);

namespace App\Domain\Facility\Import;

use App\Domain\Facility\Actions\SaveFacility;
use App\Domain\Facility\Actions\SaveWard;
use App\Domain\Facility\Actions\SetFacilityActive;
use App\Domain\Facility\Models\Facility;
use App\Domain\Facility\Models\Lga;
use App\Domain\Facility\Models\Ward;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The facility master list from the client's signed-off reconciliation workbook (D-91,
 * facility_reconcile.py's output): reference data, not assessment data, so it does not conflict
 * with source-only ingestion (CLAUDE.md hard rule 1). Console only.
 *
 * Rows read: every Matched row (a registry match, so MAP, unless a Decision column says REJECT),
 * and Review rows decided MAP (to one of the row's candidates) or ADD (a real facility the
 * registry lacks). REJECT rows are skipped; an undecided or malformed row, an LGA that is not one
 * of the 23, or a code given two different ways refuses the whole workbook, every problem with
 * its sheet and line. Wards are created as needed; facilities are upserted by code. A plan is
 * built first (the diff the command prints), then applied in one transaction, each change audited
 * with the reason "master list import {file}". Replacing the synthetic list (SYN- codes)
 * deactivates the synthetic facilities the workbook does not have: never a delete.
 */
final class MasterListImport
{
    public const SYNTHETIC_PREFIX = 'SYN-';

    public function __construct(
        private readonly SaveFacility $facilities,
        private readonly SaveWard $wards,
        private readonly SetFacilityActive $active,
    ) {}

    /**
     * @return array{rows: list<array{sheet: string, line: int, code: string, name: string, lga_id: int, lga: string, ward: string, level: string, ownership: string}>, problems: list<string>, skipped: int}
     */
    public function read(string $path): array
    {
        $book = XlsxSheets::open($path);
        foreach (['Matched', 'Review'] as $sheet) {
            if (! in_array($sheet, $book->names(), true)) {
                throw new RuntimeException((string) __('facilities.import.no_sheet', ['sheet' => $sheet]));
            }
        }
        /** @var array<string, array{id: int, name: string}> $lgas lower(name) and lower(code) => LGA */
        $lgas = [];
        foreach (Lga::query()->get(['id', 'name', 'code']) as $lga) {
            $lgas[mb_strtolower($lga->name)] = $lgas[mb_strtolower($lga->code)] = ['id' => $lga->id, 'name' => $lga->name];
        }

        $rows = [];
        $problems = [];
        $skipped = 0;
        foreach (['Matched', 'Review'] as $sheet) {
            $lines = $book->rows($sheet);
            $header = array_shift($lines);
            $col = $this->columns($sheet, $header['cells'] ?? []);
            foreach ($lines as $line) {
                $cell = fn (string $name): string => isset($col[$name]) ? ($line['cells'][$col[$name]] ?? '') : '';
                $decision = strtoupper($cell('Decision'));
                if ($sheet === 'Matched' && $decision === '') {
                    $decision = 'MAP';
                }
                $problem = fn (string $key, array $replace = []): string => (string) __('facilities.import.line', ['sheet' => $sheet, 'line' => $line['line'], 'problem' => __("facilities.import.{$key}", $replace)]);

                if ($decision === 'REJECT') {
                    $skipped++;

                    continue;
                }
                if ($decision === '') {
                    $problems[] = $problem('no_decision');

                    continue;
                }
                if (! in_array($decision, ['MAP', 'ADD'], true)) {
                    $problems[] = $problem('bad_decision', ['decision' => $decision]);

                    continue;
                }

                $lga = $lgas[mb_strtolower($cell('LGA'))] ?? null;
                if ($lga === null) {
                    $problems[] = $problem('unknown_lga', ['lga' => $cell('LGA')]);

                    continue;
                }

                if ($sheet === 'Matched') {
                    [$code, $name, $ward, $level, $ownership] = [$cell('Registry code'), $cell('Registry name'), $cell('Registry ward'), $cell('Level'), $cell('Ownership')];
                } elseif ($decision === 'MAP') {
                    $code = $cell('Map to registry code');
                    $candidate = null;
                    foreach ([1, 2, 3] as $n) {
                        if ($code !== '' && strcasecmp($cell("Candidate {$n} code"), $code) === 0) {
                            $candidate = $n;
                        }
                    }
                    if ($code !== '' && $candidate === null) {
                        $problems[] = $problem('not_a_candidate', ['code' => $code]);

                        continue;
                    }
                    [$name, $ward] = $candidate === null ? ['', ''] : [$cell("Candidate {$candidate} name"), $cell("Candidate {$candidate} ward")];
                    [$level, $ownership] = [self::first($cell('Level (submitted)')), self::first($cell('Ownership (submitted)'))];
                } else {
                    $code = $cell('Map to registry code') !== '' ? $cell('Map to registry code') : $cell('Submitted code');
                    [$name, $ward] = [self::first($cell('Submitted name(s)')), self::first($cell('Submitted ward(s)'))];
                    [$level, $ownership] = [self::first($cell('Level (submitted)')), self::first($cell('Ownership (submitted)'))];
                }

                $levelValue = self::level($level);
                $ownershipValue = self::ownership($ownership);
                $found = match (true) {
                    $code === '' => $problem('no_code'),
                    $name === '' => $problem('no_name'),
                    $ward === '' => $problem('no_ward'),
                    $levelValue === null => $problem('bad_level', ['level' => $level]),
                    $ownershipValue === null => $problem('bad_ownership', ['ownership' => $ownership]),
                    default => null,
                };
                if ($found !== null) {
                    $problems[] = $found;

                    continue;
                }
                $rows[] = ['sheet' => $sheet, 'line' => $line['line'], 'code' => $code, 'name' => $name, 'lga_id' => $lga['id'], 'lga' => $lga['name'], 'ward' => $ward, 'level' => (string) $levelValue, 'ownership' => (string) $ownershipValue];
            }
        }

        // One code, one facility: several refs mapped to the same registry code are one row when
        // they agree, and a refusal when they do not.
        $byCode = [];
        foreach ($rows as $row) {
            $key = mb_strtoupper($row['code']);
            $seen = $byCode[$key] ?? null;
            if ($seen === null) {
                $byCode[$key] = $row;
            } elseif ([$seen['name'], $seen['lga_id'], mb_strtolower($seen['ward']), $seen['level'], $seen['ownership']] !== [$row['name'], $row['lga_id'], mb_strtolower($row['ward']), $row['level'], $row['ownership']]) {
                $problems[] = (string) __('facilities.import.line', ['sheet' => $row['sheet'], 'line' => $row['line'], 'problem' => __('facilities.import.conflict', ['code' => $row['code'], 'other' => "{$seen['sheet']} line {$seen['line']}"])]);
            }
        }

        return ['rows' => array_values($byCode), 'problems' => $problems, 'skipped' => $skipped];
    }

    /**
     * What applying the rows would change.
     *
     * @param  list<array{sheet: string, line: int, code: string, name: string, lga_id: int, lga: string, ward: string, level: string, ownership: string}>  $rows
     * @return array{add: list<array<string, mixed>>, change: list<array{facility: Facility, row: array<string, mixed>, changes: array<string, array{0: string, 1: string}>}>, unchanged: int, wards: list<array{lga_id: int, lga: string, name: string}>, deactivate: list<Facility>}
     */
    public function diff(array $rows): array
    {
        $existing = Facility::query()->with('ward:id,name')->get()->keyBy(fn (Facility $f): string => mb_strtoupper($f->code));
        $wards = Ward::query()->get(['id', 'lga_id', 'name'])->keyBy(fn (Ward $w): string => $w->lga_id.'|'.mb_strtolower($w->name));

        $diff = ['add' => [], 'change' => [], 'unchanged' => 0, 'wards' => [], 'deactivate' => []];
        foreach ($rows as $row) {
            $wardKey = $row['lga_id'].'|'.mb_strtolower($row['ward']);
            if (! $wards->has($wardKey) && ! in_array($wardKey, array_map(fn (array $w): string => $w['lga_id'].'|'.mb_strtolower($w['name']), $diff['wards']), true)) {
                $diff['wards'][] = ['lga_id' => $row['lga_id'], 'lga' => $row['lga'], 'name' => $row['ward']];
            }
            $facility = $existing->get(mb_strtoupper($row['code']));
            if (! $facility instanceof Facility) {
                $diff['add'][] = $row;

                continue;
            }
            // A ward is the same ward whatever its case, in the same LGA.
            $sameWard = $facility->lga_id === $row['lga_id'] && mb_strtolower((string) $facility->ward?->name) === mb_strtolower($row['ward']);
            $changes = array_filter([
                'name' => [$facility->name, $row['name']],
                'ward' => $sameWard ? null : [(string) $facility->ward?->name, $row['ward'].' ('.$row['lga'].')'],
                'level' => [$facility->level->value, $row['level']],
                'ownership' => [$facility->ownership->value, $row['ownership']],
            ], fn (?array $pair): bool => $pair !== null && $pair[0] !== $pair[1]);
            if ($changes === []) {
                $diff['unchanged']++;
            } else {
                $diff['change'][] = ['facility' => $facility, 'row' => $row, 'changes' => $changes];
            }
        }

        $codes = array_map(fn (array $row): string => mb_strtoupper($row['code']), $rows);
        $diff['deactivate'] = $existing->filter(fn (Facility $f, string $code): bool => $f->is_active && str_starts_with($code, self::SYNTHETIC_PREFIX) && ! in_array($code, $codes, true))->values()->all();

        return $diff;
    }

    /**
     * Applies a diff in one transaction, every change audited with $reason (no causer: the console).
     *
     * @param  array{add: list<array<string, mixed>>, change: list<array{facility: Facility, row: array<string, mixed>, changes: array<string, array{0: string, 1: string}>}>, unchanged: int, wards: list<array{lga_id: int, lga: string, name: string}>, deactivate: list<Facility>}  $diff
     */
    public function apply(array $diff, string $reason): void
    {
        DB::transaction(function () use ($diff, $reason): void {
            foreach ($diff['wards'] as $ward) {
                $this->wards->create($ward['lga_id'], $ward['name'], null, $reason);
            }
            $wardIds = Ward::query()->get(['id', 'lga_id', 'name'])->mapWithKeys(fn (Ward $w): array => [$w->lga_id.'|'.mb_strtolower($w->name) => $w->id]);
            $ward = fn (array $row): int => (int) $wardIds->get($row['lga_id'].'|'.mb_strtolower((string) $row['ward']));

            foreach ($diff['add'] as $row) {
                $this->facilities->create([
                    'code' => (string) $row['code'], 'name' => (string) $row['name'], 'ward_id' => $ward($row), 'level' => (string) $row['level'], 'ownership' => (string) $row['ownership'],
                ], null, $reason, 'facility_imported');
            }
            foreach ($diff['change'] as $change) {
                $row = $change['row'];
                $this->facilities->update($change['facility'], ['name' => (string) $row['name'], 'ward_id' => $ward($row), 'level' => (string) $row['level'], 'ownership' => (string) $row['ownership']], null, $reason);
            }
            foreach ($diff['deactivate'] as $facility) {
                $this->active->deactivate($facility, null, $reason);
            }
        });
    }

    /**
     * @param  array<int, string>  $header
     * @return array<string, int>
     */
    private function columns(string $sheet, array $header): array
    {
        $columns = array_flip(array_map(fn (string $h): string => trim($h), $header));
        $needed = $sheet === 'Matched'
            ? ['LGA', 'Registry code', 'Registry name', 'Registry ward', 'Level', 'Ownership']
            : ['LGA', 'Submitted code', 'Submitted name(s)', 'Submitted ward(s)', 'Ownership (submitted)', 'Level (submitted)', 'Decision', 'Map to registry code'];
        foreach ($needed as $column) {
            if (! isset($columns[$column])) {
                throw new RuntimeException((string) __('facilities.import.no_column', ['sheet' => $sheet, 'column' => $column]));
            }
        }

        return $columns;
    }

    /** The first of facility_reconcile.py's " | "-joined values (most frequent first). */
    private static function first(string $joined): string
    {
        return trim(explode(' | ', $joined)[0]);
    }

    /**
     * Level and ownership are taken as written (any case), never guessed from a near word: they
     * decide the public/private split and the level filter, so anything else is a row for the
     * client to correct in the workbook (CLAUDE.md: do not guess what changes a number).
     */
    private static function level(string $value): ?string
    {
        $value = mb_strtolower(trim($value));

        return in_array($value, ['primary', 'secondary', 'tertiary'], true) ? $value : null;
    }

    private static function ownership(string $value): ?string
    {
        $value = mb_strtolower(trim($value));

        return in_array($value, ['public', 'private'], true) ? $value : null;
    }
}
