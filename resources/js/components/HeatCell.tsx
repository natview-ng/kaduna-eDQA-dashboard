import VisuallyHidden from '@/components/VisuallyHidden';
import { heatClass } from '@/lib/heat';
import type { ScoreValue } from '@/types/score';

export type HeatCellProps = {
    score: ScoreValue;
};

/**
 * A month cell: the server's display value on its band's tint (lib/heat), text always ink. The
 * number carries the meaning; the tint only helps the eye. A cell with no score is unshaded and
 * says so.
 */
export default function HeatCell({ score }: HeatCellProps) {
    return (
        <td className={`px-3 py-2 text-right font-medium tabular-nums ${heatClass(score.band)}`}>
            {score.value === null ? (
                <>
                    <span aria-hidden="true" className="text-ink-3">
                        {score.display}
                    </span>
                    <VisuallyHidden>{score.bandLabel}</VisuallyHidden>
                </>
            ) : (
                score.display
            )}
        </td>
    );
}
