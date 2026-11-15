import { useId, useState, type FormEvent } from 'react';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { BAND_KEYS, bandErrors, weightValid, type BandKey } from '@/lib/rules';
import type { RuleConfigData, ScoredItem } from '@/types/admin';

export type RuleDraftEditorProps = {
    version: number;
    config: RuleConfigData;
    items: ScoredItem[];
    pending: boolean;
    /** A server refusal (409: a config the calculator cannot apply), said under the form. */
    error: string | null;
    onSave: (changes: Pick<RuleConfigData, 'bands' | 'item_weights' | 'na_policy'>) => void;
    onDiscard: () => void;
    /** The fields differ from the saved draft: the preview waits for a save. */
    onDirtyChange: (dirty: boolean) => void;
};

const NA_POLICIES = ['exclude'] as const;

/**
 * The one draft (Phase 5 step 6): band thresholds (each the lowest score in its band, going down,
 * checked as typed), item weights (every scored item, searchable, 1 unless set), and the N/A
 * policy. Save keeps the draft; Discard asks once, in place. Weights that are 1 are not stored.
 */
export default function RuleDraftEditor({ version, config, items, pending, error, onSave, onDiscard, onDirtyChange }: RuleDraftEditorProps) {
    const t = useTranslate();
    const ids = { search: useId(), na: useId() };
    const [bands, setBands] = useState<Record<BandKey, string>>(() => ({ strong: String(config.bands.strong), acceptable: String(config.bands.acceptable), review: String(config.bands.review) }));
    const [weights, setWeights] = useState<Record<string, string>>(() => Object.fromEntries(Object.entries(config.item_weights).map(([key, weight]) => [key, String(weight)])));
    const [naPolicy, setNaPolicy] = useState(config.na_policy);
    const [query, setQuery] = useState('');
    const [confirming, setConfirming] = useState(false);
    const [touched, setTouched] = useState(false);

    const errors = bandErrors(bands);
    const badWeights = Object.entries(weights).filter(([, text]) => !weightValid(text)).map(([key]) => key);
    const shown = items.filter((item) => {
        const q = query.trim().toLowerCase();

        return q === '' || item.label.toLowerCase().includes(q) || item.code.toLowerCase().includes(q) || item.dimension.includes(q);
    });
    const dirty = (next: () => void) => {
        next();
        onDirtyChange(true);
    };

    const save = (event: FormEvent) => {
        event.preventDefault();
        setTouched(true);
        if (Object.keys(errors).length > 0 || badWeights.length > 0) {
            return;
        }
        onSave({
            bands: { strong: Number(bands.strong), acceptable: Number(bands.acceptable), review: Number(bands.review) },
            item_weights: Object.fromEntries(Object.entries(weights).filter(([, text]) => Number(text) !== 1).map(([key, text]) => [key, Number(text)])),
            na_policy: naPolicy,
        });
    };
    const bandMessage = (key: BandKey): string | null => {
        const problem = errors[key];
        if (problem === undefined) {
            return null;
        }

        return problem.kind === 'range' ? t('admin.scoring.draft.out_of_range') : t('admin.scoring.draft.not_descending', { band: t(`enums.band.${problem.above}`), value: problem.value });
    };

    return (
        <form noValidate onSubmit={save} className="flex flex-col gap-6 px-5 pt-3 pb-5">
            {touched && (Object.keys(errors).length > 0 || badWeights.length > 0) && (
                <p role="alert" className="notice notice-bad text-bad">
                    {t('admin.scoring.draft.fix')}
                </p>
            )}

            <fieldset>
                <legend className="text-sm font-semibold">{t('admin.scoring.draft.bands_legend')}</legend>
                <p id="draft-bands-help" className="mt-1 max-w-[72ch] text-sm text-ink-2">
                    {t('admin.scoring.draft.bands_help')}
                </p>
                <div className="mt-3 flex flex-wrap gap-4">
                    {BAND_KEYS.map((key) => {
                        const message = bandMessage(key);

                        return (
                            <div key={key}>
                                <label htmlFor={`draft-band-${key}`} className="mb-1 block text-xs font-medium text-ink-2">
                                    {t(`admin.scoring.draft.${key}`)}
                                </label>
                                <input
                                    id={`draft-band-${key}`}
                                    inputMode="decimal"
                                    className="control w-28 tabular-nums"
                                    value={bands[key]}
                                    aria-invalid={message !== null || undefined}
                                    aria-describedby={['draft-bands-help', message === null ? null : `draft-band-${key}-error`].filter(Boolean).join(' ')}
                                    onChange={(event) => dirty(() => setBands({ ...bands, [key]: event.target.value }))}
                                />
                                {message !== null && (
                                    <p id={`draft-band-${key}-error`} className="mt-1 max-w-48 text-xs text-bad">
                                        {message}
                                    </p>
                                )}
                            </div>
                        );
                    })}
                </div>
            </fieldset>

            <fieldset>
                <legend className="text-sm font-semibold">{t('admin.scoring.draft.weights_legend')}</legend>
                <p className="mt-1 max-w-[72ch] text-sm text-ink-2">{t('admin.scoring.draft.weights_help')}</p>
                <div className="field mt-3">
                    <label htmlFor={ids.search}>{t('admin.scoring.draft.weights_search')}</label>
                    <input id={ids.search} type="search" value={query} onChange={(event) => setQuery(event.target.value)} />
                </div>
                <p className="mt-2 text-xs text-ink-2" aria-live="polite">
                    {t('admin.scoring.draft.weights_showing', { shown: formatCount(shown.length), total: formatCount(items.length) })}
                </p>
                <div role="region" aria-label={t('admin.scoring.draft.weights_caption')} tabIndex={0} className="mt-2 max-h-96 overflow-y-auto rounded-md border border-rule">
                    <table className="w-full text-sm">
                        <caption className="sr-only">{t('admin.scoring.draft.weights_caption')}</caption>
                        <thead className="sticky top-0">
                            <tr>
                                <th scope="col" className="bg-subtle px-3 py-2 text-left text-xs font-medium text-ink-2">{t('admin.scoring.draft.item')}</th>
                                <th scope="col" className="bg-subtle px-3 py-2 text-left text-xs font-medium text-ink-2">{t('admin.scoring.draft.dimension')}</th>
                                <th scope="col" className="bg-subtle px-3 py-2 text-right text-xs font-medium text-ink-2">{t('admin.scoring.draft.weight')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {shown.map((item) => {
                                const value = weights[item.key] ?? '1';
                                const invalid = !weightValid(value);

                                return (
                                    <tr key={item.key} className="border-t border-rule">
                                        <th scope="row" className="px-3 py-1.5 text-left font-normal">
                                            {item.label}
                                            <code className="block text-2xs text-ink-2">{item.code}</code>
                                        </th>
                                        <td className="px-3 py-1.5">{t(`enums.dimension.${item.dimension}`)}</td>
                                        <td className="px-3 py-1.5 text-right">
                                            <label htmlFor={`weight-${item.key}`} className="sr-only">
                                                {t('admin.scoring.draft.weight_label', { item: item.label })}
                                            </label>
                                            <input
                                                id={`weight-${item.key}`}
                                                inputMode="decimal"
                                                className={`control w-20 text-right tabular-nums ${value !== '1' ? 'border-deep' : ''}`}
                                                value={value}
                                                aria-invalid={invalid || undefined}
                                                aria-describedby={invalid ? `weight-${item.key}-error` : undefined}
                                                onChange={(event) => dirty(() => setWeights({ ...weights, [item.key]: event.target.value }))}
                                            />
                                            {invalid && (
                                                <p id={`weight-${item.key}-error`} className="mt-1 text-xs text-bad">
                                                    {t('admin.scoring.draft.weight_invalid')}
                                                </p>
                                            )}
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </fieldset>

            <div className="flex flex-col gap-1">
                <label htmlFor={ids.na} className="text-sm font-semibold">
                    {t('admin.scoring.draft.na_legend')}
                </label>
                <select id={ids.na} className="control max-w-md" value={naPolicy} onChange={(event) => dirty(() => setNaPolicy(event.target.value))}>
                    {NA_POLICIES.map((policy) => (
                        <option key={policy} value={policy}>
                            {t(`admin.scoring.current.na_policies.${policy}`)}
                        </option>
                    ))}
                </select>
            </div>

            {error !== null && (
                <p role="alert" className="notice notice-bad text-bad">
                    {error}
                </p>
            )}

            <div className="flex flex-wrap items-center gap-2">
                <button type="submit" className="btn btn-primary" disabled={pending}>
                    {pending ? t('admin.scoring.draft.saving') : t('admin.scoring.draft.save')}
                </button>
                {confirming ? (
                    <span role="group" aria-label={t('admin.scoring.draft.discard')} className="flex flex-wrap items-center gap-2 text-sm">
                        <span>{t('admin.scoring.draft.discard_confirm', { version })}</span>
                        <button type="button" className="btn btn-danger btn-sm" onClick={onDiscard}>
                            {t('admin.scoring.draft.discard_yes')}
                        </button>
                        <button type="button" className="btn btn-quiet btn-sm" onClick={() => setConfirming(false)}>
                            {t('admin.scoring.draft.discard_no')}
                        </button>
                    </span>
                ) : (
                    <button type="button" className="btn btn-quiet" onClick={() => setConfirming(true)}>
                        {t('admin.scoring.draft.discard')}
                    </button>
                )}
            </div>
        </form>
    );
}
