import StatusLabel from '@/components/StatusLabel';
import type { Band, ScoreValue } from '@/types/score';

export type DimensionBarProps = {
    label: string;
    /** One line under the name: what the dimension asks. */
    help: string;
    score: ScoreValue;
    /** The Strong threshold, where the mark sits; null hides it. */
    strongAt: number | null;
};

// Strong deep teal, Acceptable slate, Review warn-fill, Needs action bad (CONVENTION.md §6). The
// colour only repeats the band, which is also written beside the value.
const FILL: Record<Band, string> = {
    strong: 'bg-deep',
    acceptable: 'bg-fine',
    review: 'bg-warn-fill',
    needs_action: 'bg-bad',
};

/**
 * One dimension in the hero: name, help, a bar to its value with the Strong mark, and the value
 * with its band in words. The bar and mark are decorative (aria-hidden): the list item's text
 * carries everything. Width is geometry from ScoreValue.value; nothing is rounded or banded here.
 */
export default function DimensionBar({ label, help, score, strongAt }: DimensionBarProps) {
    const width = score.value === null ? 0 : Math.min(100, Math.max(0, score.value));

    return (
        <li className="grid grid-cols-[minmax(0,96px)_1fr] items-center gap-x-2.5 gap-y-2 py-3 rail:grid-cols-[180px_1fr_auto] rail:gap-x-5">
            <span className="text-sm leading-tight font-medium">
                {label}
                <span className="mt-0.5 block text-xs font-normal text-ink-2">{help}</span>
            </span>
            <span aria-hidden="true" className="relative h-2 rounded-full bg-subtle">
                {score.band !== null && <span className={`block h-full rounded-full motion-safe:transition-[width] motion-safe:duration-500 motion-safe:ease-out ${FILL[score.band]}`} style={{ width: `${width}%` }} />}
                {strongAt !== null && <span className="absolute -inset-y-1.5 w-0.5 rounded-full bg-ink" style={{ left: `${strongAt}%` }} />}
            </span>
            <span className="col-span-2 flex items-center justify-end gap-2 rail:col-span-1 rail:min-w-[150px]">
                <span className="text-md font-semibold tabular-nums">{score.display}</span>
                <StatusLabel band={score.band} label={score.bandLabel} />
            </span>
        </li>
    );
}
