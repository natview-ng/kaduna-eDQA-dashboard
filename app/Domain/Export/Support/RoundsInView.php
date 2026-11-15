<?php

declare(strict_types=1);

namespace App\Domain\Export\Support;

use App\Domain\Reporting\DTOs\ReportFilter;
use App\Domain\Reporting\Support\RoundSelection;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\Exceptions\NoPublishedRuleVersion;
use App\Domain\Scoring\RuleVersionForRound;
use Illuminate\Support\Facades\DB;

/**
 * The rounds a report export covers (the same rounds as the page: RoundSelection::current), and
 * what a reader needs to know about them: whether a figure is provisional (from a round not yet
 * closed) and which scoring rule version each round is read under (D-32).
 */
final class RoundsInView
{
    public function __construct(
        private readonly RoundSelection $rounds,
        private readonly RuleVersionForRound $versions,
    ) {}

    /** @return list<Round> */
    public function of(ReportFilter $filter): array
    {
        return $this->rounds->current($filter);
    }

    /** "Provisional — round not closed" when any round in view is open, else "Published". */
    public function status(ReportFilter $filter): string
    {
        foreach ($this->of($filter) as $round) {
            if ($round->status === RoundStatus::Open) {
                return self::provisional();
            }
        }

        return (string) __('exports.status.published');
    }

    /** @return array<int, string> round id => its status in words */
    public function statusByRound(ReportFilter $filter): array
    {
        $statuses = [];
        foreach ($this->of($filter) as $round) {
            $statuses[$round->id] = $round->status === RoundStatus::Open ? self::provisional() : (string) __('exports.status.published');
        }

        return $statuses;
    }

    public static function provisional(): string
    {
        return (string) __('exports.status.provisional');
    }

    /** "Q2 2026: v2; Q1 2026: v1", the version each round in view is read under. */
    public function ruleVersions(ReportFilter $filter): string
    {
        $parts = [];
        foreach ($this->of($filter) as $round) {
            try {
                $version = DB::table('scoring_rule_versions')->where('id', $this->versions->id($round))->value('version');
            } catch (NoPublishedRuleVersion) {
                $version = null;
            }
            $parts[] = (string) __('exports.about.rule_version_round', [
                'round' => $round->label(),
                'version' => $version === null ? (string) __('exports.about.no_version') : 'v'.(int) $version,
            ]);
        }

        return $parts === [] ? (string) __('exports.about.no_rounds') : implode('; ', $parts);
    }
}
