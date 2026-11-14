<?php

declare(strict_types=1);

use App\Domain\Facility\Models\Facility;
use App\Domain\Facility\Models\Ward;
use Database\Seeders\LgaSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Support\XlsxFixture;

// Phase 5 step 5: edqa:facilities:import-master-list (CLAUDE.md hard rule 1: reference data, not
// assessment data, console only). The workbook is facility_reconcile.py's (Phase 0), signed off.

const MATCHED_HEADER = ['Ref', 'LGA', 'Submitted code', 'Submitted name(s)', 'Submitted ward(s)', 'Submissions', 'Registry code', 'Registry name', 'Registry ward', 'Level', 'Ownership', 'Method', 'Score', 'Ward agrees', 'Other refs mapped here', 'Note'];

/** @return list<string> the Review sheet's header, as facility_reconcile.py writes it */
function reviewHeader(): array
{
    $header = ['Ref', 'LGA', 'Submitted code', 'Submitted name(s)', 'Submitted ward(s)', 'Ownership (submitted)', 'Level (submitted)', 'Submissions', 'Why review'];
    foreach ([1, 2, 3] as $n) {
        array_push($header, "Candidate {$n} code", "Candidate {$n} name", "Candidate {$n} ward", "Candidate {$n} score");
    }

    return [...$header, 'Decision', 'Map to registry code', 'Notes'];
}

/** @return list<string|null> */
function matched(string $ref, string $lga, string $code, string $name, string $ward, string $level = 'Primary', string $ownership = 'Public'): array
{
    return [$ref, $lga, '', $name, $ward, 3, $code, $name, $ward, $level, $ownership, 'code', 100, 'Yes', '', ''];
}

/**
 * @param  list<array{0: string, 1: string, 2: string}>  $candidates  code, name, ward
 * @return list<string|int|null>
 */
function review(string $ref, string $lga, string $names, string $wards, array $candidates, string $decision, string $map = '', string $level = 'Primary', string $ownership = 'Public', string $code = ''): array
{
    $row = [$ref, $lga, $code, $names, $wards, $ownership, $level, 2, 'name only'];
    foreach ([0, 1, 2] as $n) {
        $c = $candidates[$n] ?? null;
        array_push($row, $c[0] ?? '', $c[1] ?? '', $c[2] ?? '', $c === null ? '' : 88);
    }

    return [...$row, $decision, $map, ''];
}

function workbook(array $matched, array $review): string
{
    return XlsxFixture::write(sys_get_temp_dir().'/facility_reconciliation_'.uniqid().'.xlsx', [
        'Read me' => [['Facility reconciliation']],
        'Matched' => [MATCHED_HEADER, ...$matched],
        'Review' => [reviewHeader(), ...$review],
        'Unmatched' => [['Ref', 'LGA']],
    ]);
}

function signedOff(): string
{
    return workbook(
        [
            matched('S001', 'Zaria', 'KD-ZAR-0001', 'Tudun Wada Primary Health Care Centre', 'Tudun Wada'),
            matched('S002', 'Ikara', 'KD-IKR-0002', 'Ikara General Hospital', 'Ikara', 'Secondary'),
        ],
        [
            review('S003', 'Makarfi', 'Gubuchi PHC | Gubuchi Clinic', 'Gubuchi', [['KD-MKF-0003', 'Gubuchi Primary Health Care Clinic', 'Gubuchi']], 'MAP', 'KD-MKF-0003'),
            review('S004', 'Chikun', 'Kakau Daji Clinic', 'Kakau', [], 'ADD', 'KD-CHK-0101', 'Primary', 'Private'),
            review('S005', 'Chikun', 'Test entry 2019.00', 'Kakau', [], 'REJECT'),
        ],
    );
}

beforeEach(function (): void {
    $this->seed(LgaSeeder::class);
});

it('reads Matched and decided Review rows, creates wards as needed and upserts by code, audited with the file name', function (): void {
    $file = signedOff();

    $this->artisan('edqa:facilities:import-master-list', ['path' => $file])
        ->expectsOutputToContain('+ KD-ZAR-0001')
        ->expectsOutputToContain('+ KD-CHK-0101')
        ->assertSuccessful();

    expect(Facility::query()->orderBy('code')->pluck('code')->all())->toBe(['KD-CHK-0101', 'KD-IKR-0002', 'KD-MKF-0003', 'KD-ZAR-0001']);
    $gubuchi = Facility::query()->where('code', 'KD-MKF-0003')->sole();
    expect($gubuchi->name)->toBe('Gubuchi Primary Health Care Clinic')
        ->and($gubuchi->lga_id)->toBe((int) DB::table('lgas')->where('name', 'Makarfi')->value('id'))
        ->and(Ward::query()->find($gubuchi->ward_id)?->name)->toBe('Gubuchi');
    $kakau = Facility::query()->where('code', 'KD-CHK-0101')->sole();
    expect($kakau->ownership->value)->toBe('private')
        ->and($kakau->name)->toBe('Kakau Daji Clinic');
    expect(Facility::query()->where('name', 'like', '%2019.00%')->exists())->toBeFalse(); // REJECT ignored

    $reason = 'master list import '.basename($file);
    expect(DB::table('activity_log')->where('event', 'facility_imported')->where('properties->reason', $reason)->count())->toBe(4)
        ->and(DB::table('activity_log')->where('event', 'ward_created')->where('properties->reason', $reason)->count())->toBe(4);
});

it('writes nothing on --dry-run, after printing the same diff', function (): void {
    $this->artisan('edqa:facilities:import-master-list', ['path' => signedOff(), '--dry-run' => true])
        ->expectsOutputToContain('+ KD-ZAR-0001')
        ->expectsOutputToContain('--dry-run')
        ->assertSuccessful();

    expect(Facility::query()->count())->toBe(0)
        ->and(Ward::query()->count())->toBe(0)
        ->and(DB::table('activity_log')->count())->toBe(0);
});

it('is idempotent: a second run of the same workbook changes nothing', function (): void {
    $file = signedOff();
    $this->artisan('edqa:facilities:import-master-list', ['path' => $file])->assertSuccessful();
    $audit = DB::table('activity_log')->count();
    $updated = Facility::query()->pluck('updated_at', 'code')->all();

    $this->artisan('edqa:facilities:import-master-list', ['path' => $file])
        ->expectsOutputToContain('Nothing to change')
        ->assertSuccessful();

    expect(DB::table('activity_log')->count())->toBe($audit)
        ->and(Facility::query()->pluck('updated_at', 'code')->all())->toEqual($updated);
});

it('updates a changed facility by its code and says what changed', function (): void {
    $this->artisan('edqa:facilities:import-master-list', ['path' => signedOff()])->assertSuccessful();
    $renamed = workbook([matched('S001', 'Zaria', 'KD-ZAR-0001', 'Tudun Wada Comprehensive Health Centre', 'Tudun Wada', 'Secondary')], []);

    $this->artisan('edqa:facilities:import-master-list', ['path' => $renamed])
        ->expectsOutputToContain('~ KD-ZAR-0001')
        ->assertSuccessful();

    expect(Facility::query()->where('code', 'KD-ZAR-0001')->sole())
        ->name->toBe('Tudun Wada Comprehensive Health Centre')
        ->level->value->toBe('secondary');
    expect(DB::table('activity_log')->where('event', 'facility_updated')->count())->toBe(1);
});

it('refuses the whole workbook when a row names an unknown LGA, giving the sheet and line', function (): void {
    $file = workbook(
        [matched('S001', 'Zaria', 'KD-ZAR-0001', 'Tudun Wada PHC', 'Tudun Wada'), matched('S002', 'Kaduna East', 'KD-XXX-0002', 'Somewhere PHC', 'Nowhere')],
        [review('S003', 'Lagos Island', 'Island Clinic', 'Island', [], 'ADD', 'LG-0001')],
    );

    $this->artisan('edqa:facilities:import-master-list', ['path' => $file])
        ->expectsOutputToContain('Matched line 3: the LGA "Kaduna East" is not one of the 23')
        ->expectsOutputToContain('Review line 2: the LGA "Lagos Island" is not one of the 23')
        ->assertFailed();

    expect(Facility::query()->count())->toBe(0)->and(Ward::query()->count())->toBe(0);
});

it('refuses an undecided Review row and a MAP code that is not one of its candidates', function (): void {
    $file = workbook([], [
        review('S003', 'Makarfi', 'Gubuchi PHC', 'Gubuchi', [['KD-MKF-0003', 'Gubuchi PHC', 'Gubuchi']], ''),
        review('S004', 'Makarfi', 'Gubuchi PHC', 'Gubuchi', [['KD-MKF-0003', 'Gubuchi PHC', 'Gubuchi']], 'MAP', 'KD-MKF-9999'),
    ]);

    $this->artisan('edqa:facilities:import-master-list', ['path' => $file])
        ->expectsOutputToContain('Review line 2')
        ->expectsOutputToContain('Review line 3')
        ->assertFailed();
    expect(Facility::query()->count())->toBe(0);
});

it('needs --force to replace the synthetic list outside local, and then deactivates the synthetic facilities, never deleting them', function (): void {
    $lga = (int) DB::table('lgas')->where('name', 'Zaria')->value('id');
    $ward = Ward::query()->create(['lga_id' => $lga, 'name' => 'Synthetic ward', 'code' => 'SYN-ZARIA-W01']);
    Facility::factory()->create(['code' => 'SYN-ZARIA-0001', 'ward_id' => $ward->id, 'lga_id' => $lga]);
    app()->detectEnvironment(fn (): string => 'staging');

    $this->artisan('edqa:facilities:import-master-list', ['path' => signedOff()])
        ->expectsOutputToContain('--force')
        ->assertFailed();
    expect(Facility::query()->count())->toBe(1);

    $this->artisan('edqa:facilities:import-master-list', ['path' => signedOff(), '--force' => true])
        ->expectsOutputToContain('- SYN-ZARIA-0001')
        ->assertSuccessful();
    expect(Facility::query()->where('code', 'SYN-ZARIA-0001')->sole()->is_active)->toBeFalse()
        ->and(Facility::query()->where('is_active', true)->count())->toBe(4);
});

it('says plainly when the file is missing or is not the reconciliation workbook', function (): void {
    $this->artisan('edqa:facilities:import-master-list', ['path' => '/nowhere/workbook.xlsx'])->assertFailed();

    $other = XlsxFixture::write(sys_get_temp_dir().'/other_'.uniqid().'.xlsx', ['Sheet1' => [['a', 'b']]]);
    $this->artisan('edqa:facilities:import-master-list', ['path' => $other])
        ->expectsOutputToContain('Matched')
        ->assertFailed();
});
