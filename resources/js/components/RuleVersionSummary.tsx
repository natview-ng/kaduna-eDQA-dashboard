import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import { formatDateTime, formatThreshold } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import type { RuleVersionRow } from '@/types/admin';

export type RuleVersionSummaryProps = {
    current: RuleVersionRow | null;
    settings: { weighting: string; rounding: string };
};

/**
 * The current published version (Phase 5 step 6): who published it, when and why; its bands,
 * N/A policy and choice map; and the two settings that are configuration, not a rule version,
 * shown read-only: weighting and display rounding (with what Q-09 decided rounding means).
 */
export default function RuleVersionSummary({ current, settings }: RuleVersionSummaryProps) {
    const t = useTranslate();
    const months = useTranslationList('common.months_short');
    if (current === null) {
        return (
            <Panel labelledBy="rules-current-title">
                <PanelHeader id="rules-current-title" title={t('admin.scoring.current.none')} />
                <div className="pb-5" />
            </Panel>
        );
    }
    const { bands } = current.config;
    const when = current.published_at === null || months.length !== 12 ? '' : formatDateTime(current.published_at, months);
    const rows: [string, string][] = [
        [t('enums.band.strong'), `≥ ${formatThreshold(bands.strong)}`],
        [t('enums.band.acceptable'), `≥ ${formatThreshold(bands.acceptable)}`],
        [t('enums.band.review'), `≥ ${formatThreshold(bands.review)}`],
        [t('enums.band.needs_action'), `< ${formatThreshold(bands.review)}`],
    ];
    const term = 'text-xs font-medium text-ink-2';

    return (
        <Panel labelledBy="rules-current-title">
            <PanelHeader
                id="rules-current-title"
                title={t('admin.scoring.current.title', { version: current.version })}
                description={current.published_by === null ? t('admin.scoring.current.published_unknown', { when }) : t('admin.scoring.current.published', { when, name: current.published_by })}
            />
            <div className="grid gap-6 px-5 pt-3 pb-5 lg:grid-cols-3">
                <div>
                    {current.published_reason !== null && <p className="mb-3 text-sm">{t('admin.scoring.current.reason', { reason: current.published_reason })}</p>}
                    <table className="w-full text-sm">
                        <caption className="mb-1 text-left text-sm font-semibold">{t('admin.scoring.current.bands')}</caption>
                        <thead>
                            <tr className="border-b border-rule">
                                <th scope="col" className="py-1.5 text-left text-xs font-medium text-ink-2">{t('admin.scoring.current.band')}</th>
                                <th scope="col" className="py-1.5 text-right text-xs font-medium text-ink-2">{t('admin.scoring.current.from')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map(([band, range]) => (
                                <tr key={band} className="border-b border-rule last:border-b-0">
                                    <th scope="row" className="py-1.5 text-left font-normal">{band}</th>
                                    <td className="py-1.5 text-right tabular-nums">{range}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="flex flex-col gap-3 text-sm">
                <dl className="flex flex-col gap-3">
                    <div>
                        <dt className={term}>{t('admin.scoring.current.weighting')}</dt>
                        <dd className="m-0">{t(`admin.scoring.current.weighting_${settings.weighting}`)}</dd>
                    </div>
                    <div>
                        <dt className={term}>{t('admin.scoring.current.rounding')}</dt>
                        <dd className="m-0">{t(`admin.scoring.current.rounding_${settings.rounding}`)}</dd>
                    </div>
                    <div>
                        <dt className={term}>{t('admin.scoring.current.na_policy')}</dt>
                        <dd className="m-0">{t(`admin.scoring.current.na_policies.${current.config.na_policy}`)}</dd>
                    </div>
                </dl>
                <p className="text-xs text-ink-2">{t('admin.scoring.current.rounding_note')}</p>
                </div>
                <table className="w-full self-start text-sm">
                    <caption className="mb-1 text-left text-sm font-semibold">{t('admin.scoring.current.choice_map')}</caption>
                    <thead>
                        <tr className="border-b border-rule">
                            <th scope="col" className="py-1.5 text-left text-xs font-medium text-ink-2">{t('admin.scoring.current.choice')}</th>
                            <th scope="col" className="py-1.5 text-left text-xs font-medium text-ink-2">{t('admin.scoring.current.outcome')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {Object.entries(current.config.choice_map).map(([choice, outcome]) => (
                            <tr key={choice} className="border-b border-rule last:border-b-0">
                                <th scope="row" className="py-1.5 text-left font-normal">
                                    <code className="text-xs">{choice}</code>
                                </th>
                                <td className="py-1.5">{t(`admin.scoring.current.outcomes.${outcome}`)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </Panel>
    );
}
