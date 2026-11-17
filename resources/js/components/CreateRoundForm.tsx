import { useState, type FormEvent } from 'react';
import ErrorSummary from '@/components/ErrorSummary';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import { useTranslate } from '@/lib/i18n';
import { quarterDates, windowErrorField } from '@/lib/rounds';
import { useApiAction } from '@/lib/useApiAction';
import type { RoundRow } from '@/types/admin';

export type CreateRoundFormProps = {
    /** The late-visit grace (edqa.rounds.window_grace_days), for the hint. */
    graceDays: number;
    /** The first year and quarter offered: the quarter after the newest round, or the current one. */
    initial: { year: number; quarter: number };
    onCreated: (round: RoundRow | { label: string }, warnings: string[]) => void;
};

type Field = 'year' | 'quarter' | 'window_start' | 'window_end';
const ID: Record<Field, string> = { year: 'create-round-year', quarter: 'create-round-quarter', window_start: 'create-round-start', window_end: 'create-round-end' };

/**
 * "Create a round": year, quarter and a window that starts as the quarter's own
 * dates and follows the quarter until the administrator edits it. The server checks the window;
 * its refusals (the round exists, the window overlaps another round or passes the late-visit
 * grace) come back as field errors, summarised at the top and repeated beside each field.
 */
export default function CreateRoundForm({ graceDays, initial, onCreated }: CreateRoundFormProps) {
    const t = useTranslate();
    const action = useApiAction<{ data: { label: string }; warnings: { code: string; message: string }[] }>();
    const [year, setYear] = useState(String(initial.year));
    const [quarter, setQuarter] = useState(String(initial.quarter));
    const [dates, setDates] = useState(() => quarterDates(initial.year, initial.quarter));
    const [edited, setEdited] = useState(false);
    const [errors, setErrors] = useState<Partial<Record<Field, string>>>({});

    const followQuarter = (nextYear: string, nextQuarter: string) => {
        if (!edited && /^\d{4}$/.test(nextYear)) {
            setDates(quarterDates(Number(nextYear), Number(nextQuarter)));
        }
    };

    const submit = async (event: FormEvent) => {
        event.preventDefault();
        setErrors({});
        const result = await action.run('POST', '/admin/api/rounds', { year: Number(year), quarter: Number(quarter), window_start: dates.start, window_end: dates.end });
        if (result.ok) {
            onCreated(result.data.data, result.data.warnings.map((warning) => warning.message));
            setEdited(false);

            return;
        }
        const fields = result.fieldErrors as Partial<Record<Field, string>>;
        if (result.code !== null) {
            const field = windowErrorField(result.code, dates.start, quarterDates(Number(year), Number(quarter)));
            setErrors({ ...fields, [field]: result.message });
        } else {
            setErrors(fields);
        }
    };

    const summary = Object.fromEntries(Object.entries(errors).map(([field, message]) => [ID[field as Field], message]));
    const described = (field: Field) => (errors[field] === undefined ? undefined : `${ID[field]}-error`);
    const fieldError = (field: Field) =>
        errors[field] !== undefined && (
            <p id={`${ID[field]}-error`} className="mt-1 text-xs text-bad">
                {errors[field]}
            </p>
        );
    const label = 'mb-1 block text-xs font-medium text-ink-2';

    return (
        <Panel labelledBy="create-round-title">
            <PanelHeader id="create-round-title" title={t('admin.rounds.create.title')} description={t('admin.rounds.create.description')} />
            <form noValidate onSubmit={(event) => void submit(event)} className="px-5 pt-4 pb-5">
                <ErrorSummary errors={summary} />
                <div className="flex flex-wrap items-start gap-4">
                    <div>
                        <label htmlFor={ID.year} className={label}>
                            {t('admin.rounds.create.year')}
                        </label>
                        <input
                            id={ID.year}
                            type="number"
                            inputMode="numeric"
                            min={2000}
                            max={2100}
                            required
                            className="control w-28"
                            value={year}
                            aria-invalid={errors.year !== undefined || undefined}
                            aria-describedby={described('year')}
                            onChange={(event) => {
                                setYear(event.target.value);
                                followQuarter(event.target.value, quarter);
                            }}
                        />
                        {fieldError('year')}
                    </div>
                    <div>
                        <label htmlFor={ID.quarter} className={label}>
                            {t('admin.rounds.create.quarter')}
                        </label>
                        <select
                            id={ID.quarter}
                            className="control w-28"
                            value={quarter}
                            aria-invalid={errors.quarter !== undefined || undefined}
                            aria-describedby={described('quarter')}
                            onChange={(event) => {
                                setQuarter(event.target.value);
                                followQuarter(year, event.target.value);
                            }}
                        >
                            {[1, 2, 3, 4].map((q) => (
                                <option key={q} value={String(q)}>
                                    {t('admin.rounds.create.quarter_option', { quarter: q })}
                                </option>
                            ))}
                        </select>
                        {fieldError('quarter')}
                    </div>
                    <div>
                        <label htmlFor={ID.window_start} className={label}>
                            {t('admin.rounds.create.window_start')}
                        </label>
                        <input
                            id={ID.window_start}
                            type="date"
                            required
                            className="control"
                            value={dates.start}
                            aria-invalid={errors.window_start !== undefined || undefined}
                            aria-describedby={[described('window_start'), 'create-round-hint'].filter(Boolean).join(' ')}
                            onChange={(event) => {
                                setEdited(true);
                                setDates({ ...dates, start: event.target.value });
                            }}
                        />
                        {fieldError('window_start')}
                    </div>
                    <div>
                        <label htmlFor={ID.window_end} className={label}>
                            {t('admin.rounds.create.window_end')}
                        </label>
                        <input
                            id={ID.window_end}
                            type="date"
                            required
                            className="control"
                            value={dates.end}
                            aria-invalid={errors.window_end !== undefined || undefined}
                            aria-describedby={[described('window_end'), 'create-round-hint'].filter(Boolean).join(' ')}
                            onChange={(event) => {
                                setEdited(true);
                                setDates({ ...dates, end: event.target.value });
                            }}
                        />
                        {fieldError('window_end')}
                    </div>
                    <button type="submit" className="btn btn-primary mt-5" disabled={action.pending}>
                        {action.pending ? t('admin.rounds.create.submitting') : t('admin.rounds.create.submit')}
                    </button>
                </div>
                <p id="create-round-hint" className="mt-3 text-xs text-ink-2">
                    {t('admin.rounds.create.grace_hint', { days: graceDays })}
                </p>
                {action.error !== null && Object.keys(errors).length === 0 && (
                    <p role="alert" className="notice notice-bad mt-3 text-bad">
                        {action.error}
                    </p>
                )}
            </form>
        </Panel>
    );
}
