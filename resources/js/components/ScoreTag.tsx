import type { Band, ScoreValue } from '@/types/score';

export type ScoreTagProps = {
    score: ScoreValue;
};

// CONVENTION.md §6 band colours (D-89): Strong → ok (green), Acceptable → fine (slate), Review →
// warn, Needs action → bad. The label is always shown too: colour never carries the meaning alone
// (CLAUDE.md hard rule 11).
const TONE: Record<Band, string> = {
    strong: 'bg-ok-bg text-ok',
    acceptable: 'bg-fine-bg text-fine',
    review: 'bg-warn-bg text-warn',
    needs_action: 'bg-bad-bg text-bad',
};

// Ink, not ink-2: ink-2 on wash is about 4.3:1, under AA's 4.5:1 for this small text.
const NO_DATA_TONE = 'bg-wash text-ink';

/** A score with its band, both as text. Renders the server's values as they are. */
export default function ScoreTag({ score }: ScoreTagProps) {
    const tone = score.band === null ? NO_DATA_TONE : TONE[score.band];

    return (
        <span className={`inline-flex items-baseline gap-1.5 rounded-sm px-1.5 py-0.5 text-sm font-semibold whitespace-nowrap tabular-nums ${tone}`}>
            <span>{score.display}</span>
            <span className="text-2xs font-medium">{score.bandLabel}</span>
        </span>
    );
}
