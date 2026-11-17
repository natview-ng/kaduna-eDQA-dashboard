<?php

declare(strict_types=1);

namespace App\Domain\Export\Types;

use App\Domain\Export\Enums\ColumnKind;
use App\Domain\Export\Support\Column;
use App\Domain\Export\Support\RoundsInView;
use App\Domain\Export\Support\ScoreCells;
use App\Domain\Export\Support\Sheet;
use App\Domain\Plan\Enums\PlanActionStatus;
use App\Domain\Plan\Queries\PlanActionsQuery;
use App\Domain\Reporting\Aggregators\ImplementationPlanAggregator;
use App\Domain\Reporting\DTOs\PlanRow;
use App\Domain\Reporting\DTOs\PlanSummary;
use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Enums\ReportAudience;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Workplan" (D-100): a round's implementation plan as an Excel workbook to share
 * and print. Plan (LGA, overall, weakest dimension and its score, the recommended action, owner
 * role, review date, status; the contact and notes only with "Include private plan fields"),
 * Summary (actions by type × status), Reference (what each action means), About this file. The rows
 * are ImplementationPlanAggregator's, as on the admin plan page; the private fields come from
 * PlanActionsQuery, read only when asked for. The Status column offers the three statuses as a
 * dropdown; the Plan sheet prints A4 landscape with its header row on every page.
 */
final class WorkplanExport implements MultiSheetExport
{
    public function __construct(
        private readonly ImplementationPlanAggregator $plan,
        private readonly PlanActionsQuery $stored,
        private readonly RoundsInView $rounds,
    ) {}

    public function key(): string
    {
        return 'workplan';
    }

    public function offersPrivate(): bool
    {
        return true;
    }

    /**
     * A round: by id (the admin plan page), or the plan's round for a dashboard filter (the admin
     * preview of the public plan: the latest round in view).
     */
    public function filter(array $input): array
    {
        $data = Validator::make($input, ['round' => ['nullable', 'integer', Rule::exists('rounds', 'id')]])->validate();
        if (isset($data['round'])) {
            return ['round' => (int) $data['round']];
        }
        $rounds = $this->rounds->of(ReportFilter::fromArray($input, ReportAudience::Admin));
        $round = $rounds === [] ? null : $rounds[count($rounds) - 1];
        if ($round === null) {
            throw ValidationException::withMessages(['round' => (string) __('exports.types.workplan.no_round')]);
        }

        return ['round' => $round->id];
    }

    public function sheets(array $filter, bool $private = false): array
    {
        $round = Round::query()->findOrFail((int) $filter['round']);
        $plan = $this->plan->plan(new ReportFilter(year: $round->year, quarter: $round->quarter, audience: ReportAudience::Admin));
        $saved = $private ? $this->stored->forRound($round->id) : [];
        $c = fn (string $key): string => (string) __("exports.types.workplan.columns.{$key}");
        $s = fn (string $key): string => (string) __("exports.types.workplan.sheets.{$key}");
        $statuses = array_map(fn (PlanActionStatus $status): string => $status->label(), PlanActionStatus::cases());

        $columns = [
            Column::text($c('lga')), ...Column::score($c('overall')), Column::text($c('weakest')), ...Column::score($c('score')),
            Column::text($c('action')), Column::text($c('owner')), new Column($c('review'), ColumnKind::DateLong), Column::text($c('status')),
        ];
        if ($private) {
            $columns[] = Column::text($c('contact'));
            $columns[] = Column::text($c('notes'));
        }
        // LGA, overall ×3, weakest, score ×3, action, owner, review, then status (0-based 11).
        $statusIndex = 11;

        $rows = array_map(function (PlanRow $row) use ($private, $saved): array {
            $cells = [
                $row->name, ...ScoreCells::of($row->overall), $row->dimension->label(), ...ScoreCells::of($row->score),
                $row->action, $row->owner, $row->reviewDate, $row->status->label(),
            ];
            if ($private) {
                $stored = $saved["{$row->lgaId}|{$row->dimension->value}"] ?? null;
                $cells[] = $stored['owner_contact'] ?? null;
                $cells[] = $stored['notes'] ?? null;
            }

            return $cells;
        }, $plan->actions);

        return [
            new Sheet($s('plan'), $columns, $rows, dropdowns: [$statusIndex => $statuses], printLandscape: true),
            new Sheet($s('summary'), [
                Column::text($c('type')), Column::text($c('action')), Column::integer($c('total')),
                ...array_map(fn (string $label): Column => Column::integer($label), $statuses),
            ], array_map(fn (PlanSummary $type): array => [
                $type->dimension->label(), $type->action, $type->count,
                $type->statuses['not_started'], $type->statuses['in_progress'], $type->statuses['done'],
            ], $plan->summary)),
            new Sheet($s('reference'), [Column::text($c('type')), Column::text($c('action')), Column::text($c('meaning')), Column::text($c('usual_owner'))],
                array_map(fn (PlanSummary $type): array => [$type->dimension->label(), $type->action, $type->meaning, $type->owner], $plan->summary)),
        ];
    }

    public function columns(array $filter, bool $private): array
    {
        return $this->sheets($filter, $private)[0]->columns;
    }

    public function count(array $filter): int
    {
        $rows = 0;
        foreach ($this->sheets($filter) as $sheet) {
            $rows += count($sheet->rows);
        }

        return $rows;
    }

    public function chunks(array $filter, bool $private, int $size): iterable
    {
        yield $this->sheets($filter, $private)[0]->rows;
    }

    public function describe(array $filter): array
    {
        $round = Round::query()->find((int) ($filter['round'] ?? 0));
        if ($round === null) {
            return [];
        }
        $report = new ReportFilter(year: $round->year, quarter: $round->quarter, audience: ReportAudience::Admin);

        return [
            [(string) __('exports.about.filter'), (string) __('exports.types.workplan.round', [
                'round' => $round->label(),
                'status' => $round->status === RoundStatus::Open ? RoundsInView::provisional() : (string) __('exports.status.published'),
            ])],
            [(string) __('exports.about.rule_version'), $this->rounds->ruleVersions($report)],
            // Said only when the private plan fields are included.
            [(string) __('exports.about.private'), (string) __('exports.types.workplan.private_yes')],
        ];
    }
}
