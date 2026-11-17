<?php

declare(strict_types=1);

use App\Domain\Export\Models\Export;
use App\Domain\Export\Types\ExportTypes;
use App\Models\User;
use Database\Seeders\LgaSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Tests\Support\ExportRunner;
use Tests\Support\ReportingFixture;
use Tests\Support\Rows;

// D-97: The files themselves. CSV: UTF-8 with a byte-order mark, RFC 4180 quoting
// and CRLF, formula injection neutralised. Excel: a bold frozen header row with an autofilter,
// number formats and widths, and "About this file". Personal columns only when asked for; open-round
// rows marked provisional.

const Q2 = ['year' => 2026, 'quarter' => 2];

beforeEach(function (): void {
    $this->seed(LgaSeeder::class);
    $this->f = ReportingFixture::build();
    Storage::fake('exports');
    // Names a spreadsheet would run, a comma and quotes, Hausa and an apostrophe.
    DB::table('facilities')->where('id', $this->f->facilities['F1'])->update(['name' => '=HYPERLINK("http://evil.test","click")']);
    DB::table('facilities')->where('id', $this->f->facilities['F2'])->update(['name' => '+SUM(A1:A9)']);
    DB::table('facilities')->where('id', $this->f->facilities['F3'])->update(['name' => '@cmd|calc']);
    DB::table('facilities')->where('id', $this->f->facilities['F4'])->update(['name' => 'Ward "A", Zaria clinic']);
    DB::table('facilities')->where('id', $this->f->facilities['F5'])->update(['name' => "Ɗan Mahawayi Jema'a PHC"]);
    DB::table('facilities')->where('id', $this->f->facilities['F6'])->update(['name' => '-1+2']);
});

function xlsx(Export $export): Spreadsheet
{
    return IOFactory::load(Storage::disk('exports')->path((string) $export->path));
}

it('writes CSV as UTF-8 with a byte-order mark, RFC 4180 quoting and CRLF line ends', function (): void {
    $export = ExportRunner::run('facility_scores', Q2);
    $raw = ExportRunner::content($export);

    expect(substr($raw, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and(substr_count($raw, "\r\n"))->toBe(8) // the header and 7 facilities, each ended by CRLF
        ->and(str_replace("\r\n", '', $raw))->not->toContain("\n")
        // A field with a comma and quotes is quoted, its quotes doubled.
        ->and($raw)->toContain('"Ward ""A"", Zaria clinic"');

    $names = array_column(ExportRunner::csv($export)['rows'], 'Facility');
    expect($names)->toContain('Ward "A", Zaria clinic', "Ɗan Mahawayi Jema'a PHC");
});

it('neutralises formula injection in CSV, and writes such text as text in Excel', function (): void {
    $names = array_column(ExportRunner::csv(ExportRunner::run('facility_scores', Q2))['rows'], 'Facility');
    expect($names)->toContain("'=HYPERLINK(\"http://evil.test\",\"click\")", "'+SUM(A1:A9)", "'@cmd|calc", "'-1+2")
        ->not->toContain('=HYPERLINK("http://evil.test","click")');

    $sheet = xlsx(ExportRunner::run('facility_scores', Q2, 'xlsx'))->getSheetByName('Data');
    $cells = [];
    foreach ($sheet->getColumnIterator('B')->current()->getCellIterator(2) as $cell) {
        $cells[(string) $cell->getValue()] = $cell->getDataType();
    }
    expect($cells['=HYPERLINK("http://evil.test","click")'])->toBe(DataType::TYPE_STRING)
        ->and($cells['+SUM(A1:A9)'])->toBe(DataType::TYPE_STRING)
        ->and($cells['-1+2'])->toBe(DataType::TYPE_STRING);
});

it('formats Excel: a bold frozen header row with an autofilter, number formats and widths', function (): void {
    $sheet = xlsx(ExportRunner::run('facility_scores', Q2, 'xlsx'))->getSheetByName('Data');
    $headings = $sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn().'1')[0];
    $column = fn (string $heading): string => Coordinate::stringFromColumnIndex(array_search($heading, $headings, true) + 1);

    expect($sheet->getStyle('A1')->getFont()->getBold())->toBeTrue()
        ->and($sheet->getStyle($sheet->getHighestDataColumn().'1')->getFont()->getBold())->toBeTrue()
        ->and($sheet->getFreezePane())->toBe('A2')
        ->and($sheet->getAutoFilter()->getRange())->toBe('A1:'.$sheet->getHighestDataColumn().'8')
        ->and($sheet->getStyle($column('Overall (full precision)').'2')->getNumberFormat()->getFormatCode())->toBe('0.00########')
        ->and($sheet->getStyle($column('Overall (displayed)').'2')->getNumberFormat()->getFormatCode())->toBe('0.0')
        ->and($sheet->getStyle($column('Assessed on').'2')->getNumberFormat()->getFormatCode())->toBe('yyyy-mm-dd')
        ->and($sheet->getColumnDimension($column('Facility'))->getWidth())->toBeGreaterThanOrEqual(22.0)
        ->and($sheet->getColumnDimension($column('Overall (full precision)'))->getWidth())->toBeGreaterThan(14.0);

    // The full-precision value is the unrounded number; the displayed one its presentation.
    $f3 = collect($sheet->toArray(null, true, false))->first(fn (array $row): bool => $row[0] === 'KD-F3');
    expect($f3[array_search('Overall (full precision)', $headings, true)])->toEqualWithDelta(86.6666666667, 1e-9)
        ->and($f3[array_search('Overall (displayed)', $headings, true)])->toBe(86.7)
        ->and($f3[array_search('Overall band', $headings, true)])->toBe('Acceptable');
});

it('writes "About this file" with the filters, when and by whom, rule version, rounding, aggregation, sources, personal details and the published line', function (): void {
    Carbon::setTestNow('2026-11-15 09:30:00');
    $by = activity()->withoutLogs(fn () => User::factory()->create(['name' => 'Hauwa Bello']));
    $book = xlsx(ExportRunner::run('facility_scores', [...Q2, 'lga' => 'AAA', 'metric' => 'validity'], 'xlsx', by: $by));
    $about = collect($book->getSheetByName('About this file')?->toArray(null, true, false))->mapWithKeys(fn (array $row): array => [(string) $row[0] => $row[1]]);

    expect($about->all())->toBe([
        'Export' => 'Facility scores',
        'Filters' => 'Year 2026 · Q2 · LGA Alpha · Metric: Validity',
        'Generated at' => '15 Nov 2026, 09:30 WAT',
        'Generated by' => 'Hauwa Bello',
        'Rows' => 3,
        'Rule version' => 'Q2 2026: v2',
        'Rounding mode' => 'Half up to one decimal place, for display only; the full-precision columns are unrounded',
        'Aggregation method' => 'Unweighted mean of facility scores: every facility counts once, for LGA, owner and state figures',
        'Data sources' => $about['Data sources'],
        'Personal details' => 'Not included',
        'Published and provisional figures' => 'Figures from closed rounds are published; open-round figures are provisional.',
    ])->and($about['Data sources'])->toBeString()->not->toBe('');
    Carbon::setTestNow();
});

it('marks open-round rows provisional and closed-round rows published', function (): void {
    $q2 = array_unique(array_column(ExportRunner::csv(ExportRunner::run('facility_scores', Q2))['rows'], 'Status'));
    $q1 = array_unique(array_column(ExportRunner::csv(ExportRunner::run('facility_scores', ['year' => 2026, 'quarter' => 1]))['rows'], 'Status'));
    $lgas = array_unique(array_column(ExportRunner::csv(ExportRunner::run('lga_table', ['year' => 2026, 'quarter' => 1]))['rows'], 'Status'));

    expect(array_values($q2))->toBe(['Provisional — round not closed'])
        ->and(array_values($q1))->toBe(['Published'])
        ->and(array_values($lgas))->toBe(['Published']);
});

it('leaves personal columns out of item responses unless asked, and adds them when asked', function (): void {
    $assessment = (int) DB::table('assessments')->where('round_id', $this->f->q2)->where('facility_id', $this->f->facilities['F7'])->value('id');
    DB::table('assessments')->where('id', $assessment)->update(['assessor_name' => 'MARKER Zainab Lawal', 'device_id' => 'MARKER-DEVICE', 'gps' => json_encode(['type' => 'Point', 'coordinates' => [7.4321, 10.5123]])]);
    Rows::itemResponse(['assessment_id' => $assessment, 'dimension' => 'availability', 'month_slot' => 1, 'item_code' => 'register', 'value' => 'yes']);

    $default = ExportRunner::run('item_responses', Q2);
    $csv = ExportRunner::csv($default);
    expect($csv['headings'])->not->toContain('Assessor', 'Device ID', 'Visit latitude', 'Visit longitude', 'Instance ID', 'Link at source', 'Attachment links')
        ->and(ExportRunner::content($default))->not->toContain('MARKER')->not->toContain('10.5123')->not->toContain('uuid:')
        ->and($csv['rows'][0]['Source'])->toBe('ODK Central');

    $private = ExportRunner::csv(ExportRunner::run('item_responses', Q2, private: true));
    expect($private['headings'])->toContain('Assessor', 'Device ID', 'Visit latitude', 'Visit longitude', 'Instance ID', 'Link at source', 'Attachment links')
        ->and($private['rows'][0]['Assessor'])->toBe('MARKER Zainab Lawal')
        ->and($private['rows'][0]['Visit latitude'])->toBe('10.5123')
        ->and($private['rows'][0]['Instance ID'])->toStartWith('uuid:');
});

it('offers personal details only where there are some', function (string $type, bool $offers): void {
    expect(app(ExportTypes::class)->get($type)->offersPrivate())->toBe($offers);
})->with([
    ['state_summary', false], ['lga_table', false], ['facility_scores', false], ['item_responses', true],
    ['data_issues', false], ['pull_history', false], ['audit_log', false],
]);
