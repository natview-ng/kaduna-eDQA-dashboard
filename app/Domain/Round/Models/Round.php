<?php

declare(strict_types=1);

namespace App\Domain\Round\Models;

use App\Domain\Assessment\Models\Assessment;
use App\Domain\Plan\Models\PlanAction;
use App\Domain\Round\Enums\RoundStatus;
use App\Domain\Round\Support\RoundLabel;
use App\Domain\Scoring\Models\ScoringRuleVersion;
use App\Domain\Validation\Models\QuarantinedRecord;
use App\Models\User;
use App\Support\Audit\Audited;
use Carbon\CarbonImmutable;
use Database\Factories\RoundFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One quarter's assessment window: open → closed (closed = published, D-07).
 *
 * @property CarbonImmutable $window_start
 * @property CarbonImmutable $window_end
 * @property RoundStatus $status
 * @property int|null $rule_version_id
 * @property CarbonImmutable|null $closed_at
 * @property int|null $closed_by
 * @property string|null $reopened_reason
 */
#[UseFactory(RoundFactory::class)]
final class Round extends Model
{
    use Audited;

    /** @use HasFactory<RoundFactory> */
    use HasFactory;

    protected $fillable = [
        'year', 'quarter', 'window_start', 'window_end', 'status', 'closed_at', 'closed_by', 'reopened_reason',
        'rule_version_id',
    ];

    /** Mirrors the column default, so a new model matches its stored row. */
    protected $attributes = ['status' => 'open'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'quarter' => 'integer',
            'window_start' => 'immutable_date',
            'window_end' => 'immutable_date',
            'status' => RoundStatus::class,
            'closed_at' => 'immutable_datetime',
            'closed_by' => 'integer',
            'rule_version_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** "Q2 2026" (RoundLabel). */
    public function label(): string
    {
        return RoundLabel::of($this->year, $this->quarter);
    }

    /**
     * The version a closed round is pinned to (D-32); null while open. Read scores through
     * RuleVersionForRound, which handles both.
     *
     * @return BelongsTo<ScoringRuleVersion, $this>
     */
    public function ruleVersion(): BelongsTo
    {
        return $this->belongsTo(ScoringRuleVersion::class, 'rule_version_id');
    }

    /**
     * The administrator who closed the round (step 4); null while open, and for rounds closed
     * before the column existed.
     *
     * @return BelongsTo<User, $this>
     */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return HasMany<Assessment, $this> */
    public function assessments(): HasMany
    {
        return $this->hasMany(Assessment::class);
    }

    /** @return HasMany<PlanAction, $this> */
    public function planActions(): HasMany
    {
        return $this->hasMany(PlanAction::class);
    }

    /** @return HasMany<QuarantinedRecord, $this> */
    public function quarantinedRecords(): HasMany
    {
        return $this->hasMany(QuarantinedRecord::class);
    }

    protected function auditedAttributes(): array
    {
        return ['year', 'quarter', 'window_start', 'window_end', 'status', 'closed_at', 'closed_by', 'reopened_reason', 'rule_version_id'];
    }
}
