<?php

declare(strict_types=1);

namespace App\Domain\Export\Types;

use App\Domain\Export\Support\Column;
use App\Domain\Export\Support\RoundsInView;
use App\Domain\Export\Support\ScoreCells;
use App\Domain\Reporting\Aggregators\LgaTableAggregator;
use App\Domain\Reporting\DTOs\LgaRow;

/**
 * "LGA table" (the LGA view's table): one row per LGA in view, its facilities, the three
 * dimensions, the three months and overall with its status label, from LgaTableAggregator.
 */
final class LgaTableExport extends ReportExport
{
    private const SCORES = ['availability', 'consistency', 'validity', 'm1', 'm2', 'm3', 'overall'];

    public function __construct(
        RoundsInView $rounds,
        private readonly LgaTableAggregator $lgas,
    ) {
        parent::__construct($rounds);
    }

    public function key(): string
    {
        return 'lga_table';
    }

    public function columns(array $filter, bool $private): array
    {
        $c = fn (string $key): string => (string) __("exports.types.lga_table.columns.{$key}");
        $scores = [];
        foreach (self::SCORES as $score) {
            array_push($scores, ...Column::score($c($score)));
        }

        return [Column::text($c('lga_code')), Column::text($c('lga')), Column::integer($c('facilities')), ...$scores, Column::text($c('status'))];
    }

    public function count(array $filter): int
    {
        return count($this->lgas->rows($this->report($filter)));
    }

    public function chunks(array $filter, bool $private, int $size): iterable
    {
        $report = $this->report($filter);
        $status = $this->rounds->status($report);

        yield array_map(function (LgaRow $row) use ($status): array {
            $cells = [$row->code, $row->name, $row->facilities];
            foreach (self::SCORES as $score) {
                array_push($cells, ...ScoreCells::of($row->{$score}));
            }
            $cells[] = $status;

            return $cells;
        }, $this->lgas->rows($report));
    }
}
