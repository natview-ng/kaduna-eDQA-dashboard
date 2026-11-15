<?php

declare(strict_types=1);

namespace App\Domain\Scoring\Actions;

use App\Domain\Scoring\DTOs\RuleConfig;
use App\Domain\Scoring\Exceptions\RuleVersionRefused;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Saving and discarding the one draft (Phase 5 step 6). Save replaces the top-level config keys
 * given (bands, item_weights, na_policy, choice_map), each whole, as CreateDraftRuleVersion does;
 * the result must be a config the calculator can apply (InvalidRuleConfig, 409, otherwise).
 * Discard removes the draft: nothing was ever scored under it (a preview scores in memory only,
 * and only a published version is rescored), and the audit entry keeps its config. A published
 * version is never edited or removed.
 */
final class EditDraftRuleVersion
{
    /** @param  array<string, mixed>  $changes */
    public function save(ScoringRuleVersion $draft, array $changes, User $actor): ScoringRuleVersion
    {
        foreach (array_keys($changes) as $key) {
            if (! in_array($key, RuleConfig::KEYS, true)) {
                throw RuleVersionRefused::unknownConfigKey((string) $key);
            }
        }

        return DB::transaction(function () use ($draft, $changes, $actor): ScoringRuleVersion {
            $draft = $this->lockedDraft($draft);
            $config = RuleConfig::fromArray(array_replace($draft->config, $changes))->toArray();
            if ($config === RuleConfig::fromArray($draft->config)->toArray()) {
                return $draft;
            }

            $old = $draft->config;
            activity()->withoutLogs(fn () => $draft->update(['config' => $config]));
            AuditLog::record($draft, $actor, 'draft_updated', old: ['config' => $old], new: ['config' => $config]);

            return $draft;
        });
    }

    public function discard(ScoringRuleVersion $draft, User $actor): void
    {
        DB::transaction(function () use ($draft, $actor): void {
            $draft = $this->lockedDraft($draft);
            AuditLog::record($draft, $actor, 'draft_discarded', old: ['version' => $draft->version, 'config' => $draft->config], new: []);
            activity()->withoutLogs(fn () => $draft->delete());
        });
    }

    private function lockedDraft(ScoringRuleVersion $version): ScoringRuleVersion
    {
        $version = ScoringRuleVersion::query()->lockForUpdate()->findOrFail($version->id);
        if ($version->published_at !== null) {
            throw RuleVersionRefused::notADraft();
        }

        return $version;
    }
}
