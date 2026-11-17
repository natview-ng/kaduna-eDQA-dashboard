import { useEffect, useRef, useState, type FormEvent } from 'react';
import Panel from '@/components/Panel';
import { useTranslate } from '@/lib/i18n';
import type { RoundRow } from '@/types/admin';

export type RoundWindowPanelProps = {
    round: RoundRow;
    /** Field errors from the last save: window_start / window_end → message. */
    errors: Partial<Record<'window_start' | 'window_end', string>>;
    /** Opens the reasoned dialog with the new dates. */
    onSave: (start: string, end: string) => void;
    onCancel: () => void;
};

/**
 * "Edit window" for an open round: two dates, then a reason in the dialog, since
 * the window decides which visits count. The server's refusals show beside the field they concern.
 */
export default function RoundWindowPanel({ round, errors, onSave, onCancel }: RoundWindowPanelProps) {
    const t = useTranslate();
    const heading = useRef<HTMLHeadingElement>(null);
    const [start, setStart] = useState(round.window_start);
    const [end, setEnd] = useState(round.window_end);

    useEffect(() => heading.current?.focus(), []);

    const submit = (event: FormEvent) => {
        event.preventDefault();
        onSave(start, end);
    };
    const field = (name: 'window_start' | 'window_end', id: string, value: string, set: (value: string) => void) => (
        <div>
            <label htmlFor={id} className="mb-1 block text-xs font-medium text-ink-2">
                {t(`admin.rounds.create.${name}`)}
            </label>
            <input
                id={id}
                type="date"
                required
                className="control"
                value={value}
                aria-invalid={errors[name] !== undefined || undefined}
                aria-describedby={errors[name] === undefined ? undefined : `${id}-error`}
                onChange={(event) => set(event.target.value)}
            />
            {errors[name] !== undefined && (
                <p id={`${id}-error`} className="mt-1 text-xs text-bad">
                    {errors[name]}
                </p>
            )}
        </div>
    );

    return (
        <Panel labelledBy="round-window-title">
            <form noValidate onSubmit={submit} className="px-5 pt-4.5 pb-5">
                <h2 id="round-window-title" ref={heading} tabIndex={-1} className="text-md font-semibold tracking-[-0.015em] outline-offset-4">
                    {t('admin.rounds.window.title', { round: round.label })}
                </h2>
                <div className="mt-4 flex flex-wrap items-start gap-4">
                    {field('window_start', 'round-window-start', start, setStart)}
                    {field('window_end', 'round-window-end', end, setEnd)}
                    <div className="mt-5 flex gap-2">
                        <button type="submit" className="btn btn-primary">
                            {t('admin.rounds.window.save')}
                        </button>
                        <button type="button" className="btn btn-quiet" onClick={onCancel}>
                            {t('admin.rounds.window.cancel')}
                        </button>
                    </div>
                </div>
            </form>
        </Panel>
    );
}
