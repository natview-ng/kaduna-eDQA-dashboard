<?php

declare(strict_types=1);

namespace App\Domain\Round\Actions;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Reporting\Actions\RefreshRoundAggregates;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Events\RoundClosed;
use App\Domain\Round\Exceptions\RoundActionRefused;
use App\Domain\Round\Models\Round;
use App\Domain\Scoring\RuleVersionForRound;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * "Close round" (ARCHITECTURE.md §3, D-32): pins the version the round is scored under (the
 * current published one, as it is open), then refreshes round_aggregates so the closed round's
 * figures are a snapshot, and dispatches RoundClosed. From now on ROUND_WINDOW quarantines its
 * submissions ("round closed") and rescore refuses it.
 *
 * Refused while any accepted or flagged assessment has no scores under that version (a rescore
 * still running, or one that could not score it): a round is never pinned to scores it lacks.
 *
 * Closing publishes the round (D-07, D-77), so it takes a reason like every other change to what
 * the public sees (step 4), audited with the administrator, who is kept in closed_by. Open issues
 * (quarantine, deletions at source) do not block it: the reason is where the administrator says why.
 */
final class CloseRound
{
    public function __construct(
        private readonly RuleVersionForRound $versions,
        private readonly RefreshRoundAggregates $refresh,
    ) {}

    public const MIN_REASON = 10;

    public function execute(Round $round, User $actor, string $reason): Round
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < self::MIN_REASON) {
            throw RoundActionRefused::reasonTooShort();
        }

        $round = DB::transaction(function () use ($round, $actor, $reason): Round {
            $round = Round::query()->lockForUpdate()->findOrFail($round->id);
            if ($round->status === RoundStatus::Closed) {
                throw RoundActionRefused::alreadyClosed();
            }

            $versionId = $this->versions->id($round);
            $unscored = Assessment::query()
                ->where('round_id', $round->id)
                ->whereIn('status', ['accepted', 'flagged'])
                ->whereDoesntHave('scores', fn ($q) => $q->where('rule_version_id', $versionId))
                ->pluck('id');
            if ($unscored->isNotEmpty()) {
                throw RoundActionRefused::notFullyScored($unscored->count());
            }

            $old = ['status' => $round->status->value, 'rule_version_id' => $round->rule_version_id, 'closed_at' => $round->closed_at?->toIso8601String()];
            $closedAt = now();
            activity()->withoutLogs(fn () => $round->update(['status' => RoundStatus::Closed, 'rule_version_id' => $versionId, 'closed_at' => $closedAt, 'closed_by' => $actor->id]));

            AuditLog::record($round, $actor, 'closed',
                old: $old,
                new: ['status' => RoundStatus::Closed->value, 'rule_version_id' => $versionId, 'closed_at' => $closedAt->toIso8601String()],
                reason: $reason,
            );

            RoundClosed::dispatch($round->id, $versionId);

            return $round;
        });

        // The snapshot, once the close is committed.
        $this->refresh->execute($round->id);

        return $round;
    }
}
