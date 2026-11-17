<?php

declare(strict_types=1);

namespace App\Domain\Export\Types;

use App\Domain\Export\Enums\ExportFormat;
use App\Domain\Export\Report\PdfRenderer;
use App\Domain\Export\Report\PdfText;
use App\Domain\Export\Report\RoundReportBuilder;
use App\Domain\Export\Report\RoundReportView;
use App\Domain\Export\Support\RoundsInView;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Round report" (Phase 6 step 5, D-101): one round as a PDF to print and circulate. Cover, state
 * headline and the three dimensions, public vs private, quality by quarter, month by month, the
 * LGA table, weakest LGAs, the implementation plan summary, the data quality note (counts only)
 * and the method. The figures are RoundReportBuilder's, read from the dashboard's aggregators.
 *
 * A closed round by default: from the Rounds page by id, from the admin preview by its year and
 * quarter, else the latest closed round. An open round can be asked for by name; it is a draft,
 * watermarked "DRAFT — round not closed" on every page and named "…-DRAFT.pdf".
 */
final class RoundReportExport implements DocumentExport
{
    public function __construct(
        private readonly RoundReportBuilder $builder,
        private readonly RoundReportView $view,
        private readonly PdfRenderer $renderer,
        private readonly PdfText $reader,
        private readonly RoundsInView $rounds,
    ) {}

    public function key(): string
    {
        return 'round_report';
    }

    public function offersPrivate(): bool
    {
        return false;
    }

    public function format(): ExportFormat
    {
        return ExportFormat::Pdf;
    }

    public function filter(array $input): array
    {
        $data = Validator::make($input, [
            'round' => ['nullable', 'integer', Rule::exists('rounds', 'id')],
            'year' => ['nullable', 'integer'],
            'quarter' => ['nullable', 'integer', 'between:1,4'],
        ])->validate();
        if (isset($data['round'])) {
            return ['round' => (int) $data['round']];
        }
        $round = isset($data['year'], $data['quarter'])
            ? Round::query()->where('year', $data['year'])->where('quarter', $data['quarter'])->first()
            : Round::query()->where('status', RoundStatus::Closed)->orderByDesc('year')->orderByDesc('quarter')->first();
        if ($round === null) {
            throw ValidationException::withMessages(['round' => (string) __('exports.types.round_report.no_round')]);
        }

        return ['round' => $round->id];
    }

    public function fileName(array $filter): string
    {
        $round = $this->round($filter);
        $name = (string) __('report.file', ['year' => $round->year, 'quarter' => $round->quarter]);

        return $round->status === RoundStatus::Open ? $name.'-DRAFT' : $name;
    }

    public function write(array $filter, string $path, Closure $progress): int
    {
        $report = $this->builder->build($this->round($filter));
        $progress(40);
        $this->renderer->save($this->view->html($report), $path, $report->title(), $this->view->header($report), $this->view->footer($report));
        $progress(95);

        return $this->reader->pages($path);
    }

    /** No rows: a document. */
    public function columns(array $filter, bool $private): array
    {
        return [];
    }

    /** One document. */
    public function count(array $filter): int
    {
        return 1;
    }

    public function chunks(array $filter, bool $private, int $size): iterable
    {
        return [];
    }

    public function describe(array $filter): array
    {
        $round = Round::query()->find((int) ($filter['round'] ?? 0));
        if ($round === null) {
            return [];
        }

        return [
            [(string) __('exports.about.filter'), (string) __('exports.types.workplan.round', [
                'round' => $round->label(),
                'status' => $round->status === RoundStatus::Open ? RoundsInView::provisional() : (string) __('exports.status.published'),
            ])],
            [(string) __('exports.about.rule_version'), $this->rounds->ruleVersions(RoundReportBuilder::filterFor($round))],
        ];
    }

    /** @param  array<string, mixed>  $filter */
    private function round(array $filter): Round
    {
        return Round::query()->findOrFail((int) $filter['round']);
    }
}
