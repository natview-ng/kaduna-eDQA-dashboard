<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rounds;

use App\Domain\Ingestion\SourceRegistry;
use App\Domain\Reporting\DTOs\SourceCount;
use App\Domain\Reporting\Queries\RoundAssessmentCountsQuery;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Domain\Scoring\Exceptions\NoPublishedRuleVersion;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Queries\QuarantineQuery;

/**
 * The Rounds page's rows (step 4), newest first: window, status, accepted assessments per source,
 * open issues, the rule version pinned at close and the one read now (D-32), who closed it and
 * when, and the last reopen reason. Every count comes from a query class (Reporting for
 * assessments, QuarantineQuery for issues); this only puts them together and names the sources.
 * A fixed number of queries however many rounds there are.
 */
final class RoundsPayload
{
    public function __construct(
        private readonly RoundAssessmentCountsQuery $assessments,
        private readonly QuarantineQuery $quarantine,
        private readonly SourceRegistry $sources,
        private readonly CurrentRuleVersion $current,
    ) {}

    /**
     * @return list<array{id: int, year: int, quarter: int, label: string, window_start: string, window_end: string, status: string, closed_at: ?string, closed_by: ?string, rule_version: ?int, reads_rule_version: ?int, reopened_reason: ?string, assessments: array{total: int, by_source: list<array{key: ?string, name: string, count: int}>}, issues: array{quarantine: int, deletions: int, cross_source: int}}>
     */
    public function build(): array
    {
        $rounds = Round::query()->with('closedBy:id,name')->orderByDesc('year')->orderByDesc('quarter')->get();
        $ids = $rounds->modelKeys();
        $counts = $this->assessments->bySource($ids);
        $issues = $this->quarantine->issueCountsByRound($ids);
        /** @var array<int, int> $versions rule version id => its number */
        $versions = ScoringRuleVersion::query()->pluck('version', 'id')->map(fn (mixed $v): int => (int) $v)->all();
        $currentId = $this->currentId();

        return $rounds->map(function (Round $round) use ($counts, $issues, $versions, $currentId): array {
            $count = $counts[$round->id] ?? ['total' => 0, 'bySource' => []];
            $readsId = $round->status === RoundStatus::Closed ? $round->rule_version_id : $currentId;

            return [
                'id' => $round->id,
                'year' => $round->year,
                'quarter' => $round->quarter,
                'label' => $round->label(),
                'window_start' => $round->window_start->toDateString(),
                'window_end' => $round->window_end->toDateString(),
                'status' => $round->status->value,
                'closed_at' => $round->closed_at?->toIso8601String(),
                'closed_by' => $round->closedBy?->name,
                'rule_version' => $round->rule_version_id === null ? null : ($versions[$round->rule_version_id] ?? null),
                'reads_rule_version' => $readsId === null ? null : ($versions[$readsId] ?? null),
                'reopened_reason' => $round->reopened_reason,
                'assessments' => ['total' => $count['total'], 'by_source' => array_map(fn (SourceCount $source): array => $this->named($source), $count['bySource'])],
                'issues' => $issues[$round->id] ?? ['quarantine' => 0, 'deletions' => 0, 'cross_source' => 0],
            ];
        })->values()->all();
    }

    /** @return array{key: ?string, name: string, count: int} */
    private function named(SourceCount $count): array
    {
        $key = $this->sources->keyOf($count->sourceId);

        return ['key' => $key, 'name' => $key === null ? '' : $this->sources->name($key), 'count' => $count->assessments];
    }

    private function currentId(): ?int
    {
        try {
            return $this->current->id();
        } catch (NoPublishedRuleVersion) {
            return null;
        }
    }
}
