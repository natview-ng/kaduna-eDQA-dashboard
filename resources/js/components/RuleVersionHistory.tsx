import { useId, useState } from 'react';
import Labelled from '@/components/Labelled';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import { formatDateTime, formatThreshold } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import { configChanges, type ConfigChange } from '@/lib/rules';
import type { RuleVersionRow, ScoredItem } from '@/types/admin';

export type RuleVersionHistoryProps = {
    versions: RuleVersionRow[];
    items: ScoredItem[];
};

/**
 * Every rule version, newest first (Phase 5 step 6): status, when and by whom it was published,
 * and why; then any two versions compared, change by change (bands, weights, N/A policy, choice
 * map), in words.
 */
export default function RuleVersionHistory({ versions, items }: RuleVersionHistoryProps) {
    const t = useTranslate();
    const months = useTranslationList('common.months_short');
    const ids = { from: useId(), to: useId() };
    const [from, setFrom] = useState(versions[1]?.id ?? versions[0]?.id ?? 0);
    const [to, setTo] = useState(versions[0]?.id ?? 0);
    const a = versions.find((version) => version.id === from);
    const b = versions.find((version) => version.id === to);
    const changes = a !== undefined && b !== undefined ? configChanges(a.config, b.config) : [];
    const itemLabel = (key: string) => items.find((item) => item.key === key)?.label ?? key;
    const none = t('admin.scoring.history.diff_missing');
    const head = 'bg-subtle px-3 py-2 text-left text-xs font-medium text-ink-2';

    const describe = (change: ConfigChange): string => {
        switch (change.kind) {
            case 'band':
                return t('admin.scoring.history.diff_band', { band: t(`enums.band.${change.band}`), from: formatThreshold(change.from), to: formatThreshold(change.to) });
            case 'weight':
                return t('admin.scoring.history.diff_weight', { item: itemLabel(change.item), from: change.from === null ? '1' : String(change.from), to: change.to === null ? '1' : String(change.to) });
            case 'na':
                return t('admin.scoring.history.diff_na', { from: change.from, to: change.to });
            case 'choice':
                return t('admin.scoring.history.diff_choice', { choice: change.choice, from: change.from ?? none, to: change.to ?? none });
        }
    };

    return (
        <Panel labelledBy="rules-history-title">
            <PanelHeader id="rules-history-title" title={t('admin.scoring.history.title')} />
            {/* Focusable, so a keyboard user can scroll it sideways on a phone (axe: scrollable-region-focusable). */}
            <div role="region" aria-label={t('admin.scoring.history.caption')} tabIndex={0} className="relative mt-3 overflow-x-auto">
                <table className="w-full text-sm">
                    <caption className="sr-only">{t('admin.scoring.history.caption')}</caption>
                    <thead>
                        <tr>
                            <th scope="col" className={`${head} pl-5`}>{t('admin.scoring.history.version')}</th>
                            <th scope="col" className={head}>{t('admin.scoring.history.status')}</th>
                            <th scope="col" className={head}>{t('admin.scoring.history.published')}</th>
                            <th scope="col" className={head}>{t('admin.scoring.history.by')}</th>
                            <th scope="col" className={`${head} pr-5`}>{t('admin.scoring.history.reason')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {versions.map((version) => (
                            <tr key={version.id} className="border-t border-rule">
                                <th scope="row" className="py-2 pr-3 pl-5 text-left font-semibold">v{version.version}</th>
                                <td className="px-3 py-2">{t(`admin.scoring.history.statuses.${version.status}`)}</td>
                                <td className="px-3 py-2 whitespace-nowrap">
                                    {version.published_at === null || months.length !== 12 ? t('admin.scoring.history.not_published') : formatDateTime(version.published_at, months)}
                                </td>
                                <td className="px-3 py-2">{version.published_by ?? '—'}</td>
                                <td className="max-w-80 py-2 pr-5 pl-3 text-ink-2">{version.published_reason ?? '—'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {versions.length > 1 && (
                <section aria-labelledby="rules-diff-title" className="border-t border-rule px-5 pt-4 pb-5">
                    <h3 id="rules-diff-title" className="text-sm font-semibold">
                        {t('admin.scoring.history.diff_title')}
                    </h3>
                    <div className="mt-2 flex flex-wrap gap-2">
                        <Labelled inline id={ids.from} label={t('admin.scoring.history.diff_from')}>
                            <select id={ids.from} value={String(from)} onChange={(event) => setFrom(Number(event.target.value))}>
                                {versions.map((version) => (
                                    <option key={version.id} value={String(version.id)}>
                                        {t('admin.scoring.history.diff_version', { version: version.version })}
                                    </option>
                                ))}
                            </select>
                        </Labelled>
                        <Labelled inline id={ids.to} label={t('admin.scoring.history.diff_to')}>
                            <select id={ids.to} value={String(to)} onChange={(event) => setTo(Number(event.target.value))}>
                                {versions.map((version) => (
                                    <option key={version.id} value={String(version.id)}>
                                        {t('admin.scoring.history.diff_version', { version: version.version })}
                                    </option>
                                ))}
                            </select>
                        </Labelled>
                    </div>
                    <div aria-live="polite" className="mt-3 text-sm">
                        {changes.length === 0 ? (
                            <p className="text-ink-2">{t('admin.scoring.history.diff_none')}</p>
                        ) : (
                            <ul className="flex list-disc flex-col gap-1 pl-5">
                                {changes.map((change, index) => (
                                    <li key={index}>{describe(change)}</li>
                                ))}
                            </ul>
                        )}
                    </div>
                </section>
            )}
        </Panel>
    );
}
