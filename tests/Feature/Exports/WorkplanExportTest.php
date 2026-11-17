<?php

declare(strict_types=1);

use App\Domain\Export\Models\Export;
use App\Domain\Export\Types\ExportTypes;
use App\Domain\Reporting\Aggregators\ImplementationPlanAggregator;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Enums\ReportAudience;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataValidation;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use Tests\Support\ExportRunner;
use Tests\Support\ReportingFixture;
use Tests\Support\Rows;

// D-100: The implementation workplan as Excel. ReportingFixture's Q2 2026: Bravo
// (availability) and Alpha (consistency) get actions; Alpha's is saved, with a contact and notes.

beforeEach(function (): void {
    $this->f = ReportingFixture::build();
    Storage::fake('exports');
    Rows::planAction(['round_id' => $this->f->q2, 'lga_id' => $this->f->lgas['AAA'], 'dimension' => 'consistency', 'owner_role' => 'other', 'owner_role_other' => 'Zonal M&E unit',
        'status' => 'in_progress', 'due_date' => '2027-01-15', 'owner_contact' => 'MARKER-CONTACT Dr Bello 0803', 'notes' => 'MARKER-NOTES register missing at the PHC']);
});

function workplanBook(Export $export): Spreadsheet
{
    return IOFactory::load(Storage::disk('exports')->path((string) $export->path));
}

/** A sheet's rows keyed by heading. @return list<array<string, mixed>> */
function workplanRows(Spreadsheet $book, string $sheet): array
{
    $rows = $book->getSheetByName($sheet)?->toArray(null, true, false) ?? [];
    $headings = array_shift($rows);

    return array_map(fn (array $row): array => array_combine($headings, $row), $rows);
}

it('holds the plan, its summary by type and status, what each action means, and About this file', function (): void {
    $book = workplanBook(ExportRunner::run('workplan', ['round' => $this->f->q2], 'xlsx'));
    $plan = app(ImplementationPlanAggregator::class)->plan(new ReportFilter(year: 2026, quarter: 2, audience: ReportAudience::Admin));

    expect(array_map(fn ($s): string => $s->getTitle(), $book->getAllSheets()))->toBe(['Plan', 'Summary', 'Reference', 'About this file']);

    $rows = workplanRows($book, 'Plan');
    expect($rows)->toHaveCount(count($plan->actions));
    foreach ($plan->actions as $i => $action) {
        $row = $rows[$i];
        expect($row['LGA'])->toBe($action->name)
            ->and($row['Overall (displayed)'])->toBe((float) $action->overall->display)
            ->and(abs($row['Overall (full precision)'] - $action->overall->value))->toBeLessThan(1e-9)
            ->and($row['Weakest dimension'])->toBe($action->dimension->label())
            ->and($row['Score (displayed)'])->toBe((float) $action->score->display)
            ->and($row['Recommended action'])->toBe($action->action)
            ->and($row['Owner role'])->toBe($action->owner)
            ->and($row['Status'])->toBe($action->status->label())
            ->and($row['Review date'] === null ? null : ExcelDate::excelToDateTimeObject($row['Review date'])->format('Y-m-d'))->toBe($action->reviewDate);
    }
    expect(collect($rows)->firstWhere('LGA', 'Alpha'))->toMatchArray(['Owner role' => 'Zonal M&E unit', 'Status' => 'In progress']);

    $summary = collect(workplanRows($book, 'Summary'))->keyBy('Type');
    foreach ($plan->summary as $type) {
        expect($summary[$type->dimension->label()])->toMatchArray([
            'Actions' => $type->count, 'Not started' => $type->statuses['not_started'], 'In progress' => $type->statuses['in_progress'], 'Done' => $type->statuses['done'],
        ]);
    }
    expect(collect(workplanRows($book, 'Reference'))->firstWhere('Type', 'Availability'))
        ->toMatchArray(['What it means' => 'The register or tool was not there to check.', 'Usually owned by' => 'LGA M&E officer']);

    $about = collect($book->getSheetByName('About this file')?->toArray(null, true, false))->mapWithKeys(fn (array $r): array => [(string) $r[0] => $r[1]]);
    expect($about['Export'])->toBe('Implementation workplan')
        ->and($about['Filters'])->toBe('Q2 2026 (Provisional — round not closed)')
        ->and($about['Personal details'])->toBe('Not included');
});

it('leaves the contact and notes out by default, and adds them, said in the file and the audit log, when asked', function (): void {
    $default = ExportRunner::run('workplan', ['round' => $this->f->q2], 'xlsx');
    $cells = collect(workplanBook($default)->getSheetByName('Plan')?->toArray())->flatten()->filter()->map(fn (mixed $v): string => (string) $v);
    expect($cells->contains(fn (string $v): bool => str_contains($v, 'MARKER')))->toBeFalse()
        ->and(array_keys(workplanRows(workplanBook($default), 'Plan')[0]))->not->toContain('Contact (private)', 'Notes (private)');

    $private = workplanBook(ExportRunner::run('workplan', ['round' => $this->f->q2], 'xlsx', private: true));
    expect(collect(workplanRows($private, 'Plan'))->firstWhere('LGA', 'Alpha'))
        ->toMatchArray(['Contact (private)' => 'MARKER-CONTACT Dr Bello 0803', 'Notes (private)' => 'MARKER-NOTES register missing at the PHC'])
        ->and(collect($private->getSheetByName('About this file')?->toArray())->mapWithKeys(fn (array $r): array => [(string) $r[0] => $r[1]])['Personal details'])
        ->toStartWith('Included: each row\'s contact and notes');

    Queue::fake();
    $admin = activity()->withoutLogs(fn () => User::factory()->create());
    $this->actingAs($admin)->postJson('/admin/api/exports', ['type' => 'workplan', 'format' => 'xlsx', 'include_private' => true, 'filter' => ['round' => $this->f->q2]])->assertStatus(202);
    $this->actingAs($admin)->postJson('/admin/api/exports', ['type' => 'workplan', 'format' => 'csv', 'filter' => ['round' => $this->f->q2]])->assertStatus(409)->assertJsonPath('code', 'excel_only');
    expect(json_decode((string) DB::table('activity_log')->where('event', 'export_requested')->value('properties'), true)['attributes'])
        ->toMatchArray(['type' => 'workplan', 'include_private' => true, 'filter' => ['round' => $this->f->q2]]);
});

it('offers the statuses as a dropdown, dates as dd mmm yyyy, and prints A4 landscape with the header on every page', function (): void {
    $sheet = workplanBook(ExportRunner::run('workplan', ['round' => $this->f->q2], 'xlsx'))->getSheetByName('Plan');
    $headings = $sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn().'1')[0];
    $letter = fn (string $heading): string => Coordinate::stringFromColumnIndex(array_search($heading, $headings, true) + 1);

    $status = $letter('Status');
    foreach ([2, 3] as $row) {
        $validation = $sheet->getCell("{$status}{$row}")->getDataValidation();
        expect($validation->getType())->toBe(DataValidation::TYPE_LIST)
            ->and($validation->getFormula1())->toBe('"Not started,In progress,Done"')
            ->and($validation->getShowDropDown())->toBeTrue();
    }

    $review = $letter('Review date');
    $alpha = array_search('Alpha', array_column($sheet->toArray(null, true, false), 0), true) + 1;
    expect($sheet->getStyle("{$review}{$alpha}")->getNumberFormat()->getFormatCode())->toBe('dd mmm yyyy')
        ->and($sheet->getCell("{$review}{$alpha}")->getFormattedValue())->toBe('15 Jan 2027');

    $setup = $sheet->getPageSetup();
    expect($setup->getOrientation())->toBe(PageSetup::ORIENTATION_LANDSCAPE)
        ->and($setup->getPaperSize())->toBe(PageSetup::PAPERSIZE_A4)
        ->and($setup->isRowsToRepeatAtTopSet())->toBeTrue()
        ->and($setup->getRowsToRepeatAtTop())->toBe([1, 1])
        ->and($sheet->getFreezePane())->toBe('A2')
        ->and($sheet->getStyle('A1')->getFont()->getBold())->toBeTrue();
});

it('takes a round by id, or the plan\'s round for a dashboard filter (the admin preview of the public plan)', function (): void {
    $type = app(ExportTypes::class)->get('workplan');

    expect($type->filter(['round' => $this->f->q1]))->toBe(['round' => $this->f->q1])
        ->and($type->filter(['year' => 2026, 'quarter' => 1]))->toBe(['round' => $this->f->q1])
        ->and($type->filter([]))->toBe(['round' => $this->f->q2]);
    expect(fn () => $type->filter(['round' => 999999]))->toThrow(ValidationException::class);

    $closed = workplanBook(ExportRunner::run('workplan', ['year' => 2026, 'quarter' => 1], 'xlsx'));
    expect(collect($closed->getSheetByName('About this file')?->toArray())->mapWithKeys(fn (array $r): array => [(string) $r[0] => $r[1]])['Filters'])->toBe('Q1 2026 (Published)')
        ->and(array_column(workplanRows($closed, 'Plan'), 'LGA'))->toBe(['Alpha']);
});
