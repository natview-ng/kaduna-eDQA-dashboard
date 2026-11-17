<?php

declare(strict_types=1);

namespace App\Http\Controllers\Scoring;

use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\CurrentRuleVersion;
use App\Domain\Scoring\Exceptions\NoPublishedRuleVersion;
use App\Domain\Scoring\Models\RescoreRun;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * The Scoring rules page's props: every version (newest first) with who
 * published it, when and why (from the audit log's `published` entry), the draft if there is one,
 * the item catalog the weights table lists (every scored item in the field maps, with the form's
 * wording), the open and closed rounds (closed ones keep their pinned version, D-32), and each open
 * round's latest rescore under the current version, so the page can follow a publish to its end.
 */
final class ScoringRulesPayload
{
    public function __construct(
        private readonly CurrentRuleVersion $current,
    ) {}

    /** @return list<array<string, mixed>> */
    public function versions(): array
    {
        $versions = ScoringRuleVersion::query()->orderByDesc('version')->get();
        $currentId = $this->currentId();
        $publishers = Activity::query()
            ->where('event', 'published')
            ->where('subject_type', (new ScoringRuleVersion)->getMorphClass())
            ->whereIn('subject_id', $versions->modelKeys())
            ->get(['subject_id', 'causer_id'])
            ->pluck('causer_id', 'subject_id');
        $names = User::query()->whereIn('id', [...$publishers->filter()->values()->all(), ...$versions->pluck('created_by')->all()])->pluck('name', 'id');

        return $versions->map(fn (ScoringRuleVersion $version): array => [
            'id' => $version->id,
            'version' => $version->version,
            'status' => match (true) {
                $version->published_at === null => 'draft',
                $version->id === $currentId => 'current',
                default => 'published',
            },
            'config' => $version->config,
            'created_by' => $names->get($version->created_by),
            'created_at' => $version->created_at?->toIso8601String(),
            'published_at' => $version->published_at?->toIso8601String(),
            'published_by' => $names->get($publishers->get($version->id)),
            'published_reason' => $version->published_reason,
        ])->values()->all();
    }

    /**
     * Every scored item across the field maps (config/edqa_item_rules.php), once, with the form's
     * wording (lang/en/items.php); the weights table lists them all, weight 1 unless set.
     *
     * @return list<array{key: string, dimension: string, code: string, label: string}>
     */
    public function items(): array
    {
        $items = [];
        foreach ((array) config('edqa_item_rules', []) as $map) {
            foreach ((array) $map as $rule) {
                if (! is_array($rule) || ! isset($rule['dimension'], $rule['code'])) {
                    continue;
                }
                $key = $rule['dimension'].'.'.$rule['code'];
                $label = __("items.{$rule['dimension']}.{$rule['code']}");
                $items[$key] ??= ['key' => $key, 'dimension' => (string) $rule['dimension'], 'code' => (string) $rule['code'], 'label' => is_string($label) && $label !== "items.{$key}" ? $label : (string) $rule['code']];
            }
        }

        return array_values($items);
    }

    /** @return array{open: list<array{id: int, label: string}>, closed: list<array{id: int, label: string, rule_version: ?int}>} */
    public function rounds(): array
    {
        $rounds = Round::query()->with('ruleVersion:id,version')->orderByDesc('year')->orderByDesc('quarter')->get();

        return [
            'open' => $rounds->where('status', RoundStatus::Open)->map(fn (Round $round): array => ['id' => $round->id, 'label' => $round->label()])->values()->all(),
            'closed' => $rounds->where('status', RoundStatus::Closed)->map(fn (Round $round): array => ['id' => $round->id, 'label' => $round->label(), 'rule_version' => $round->ruleVersion?->version])->values()->all(),
        ];
    }

    /**
     * Each open round's latest rescore under the current version: what a publish started.
     *
     * @return list<array{round_id: int, batch_id: ?string, status: string}>
     */
    public function rescores(): array
    {
        $currentId = $this->currentId();
        if ($currentId === null) {
            return [];
        }
        $open = Round::query()->where('status', RoundStatus::Open)->pluck('id')->all();

        return RescoreRun::query()->where('rule_version_id', $currentId)->whereIn('round_id', $open)->orderByDesc('id')->get()
            ->unique('round_id')
            ->map(fn (RescoreRun $run): array => ['round_id' => $run->round_id, 'batch_id' => $run->batch_id, 'status' => $run->status->value])
            ->values()->all();
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
