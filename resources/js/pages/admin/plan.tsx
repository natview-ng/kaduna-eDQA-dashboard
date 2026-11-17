import { router } from '@inertiajs/react';
import { useId, useState } from 'react';
import { useAnnounce } from '@/components/Announcer';
import ExportButton from '@/components/ExportButton';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import PlanRowEditor, { type AdminPlanRow, type PlanRole } from '@/components/PlanRowEditor';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { useApiAction } from '@/lib/useApiAction';

type PlanSummaryRow = {
    dimension: string;
    dimension_label: string;
    action: string;
    meaning: string;
    owner: string;
    count: number;
    statuses: { not_started: number; in_progress: number; done: number };
};

export type AdminPlanProps = {
    rounds: { id: number; label: string; status: 'open' | 'closed' }[];
    round: { id: number; label: string; status: 'open' | 'closed' } | null;
    plan: {
        threshold: { band: string; label: string; value: string } | null;
        rows: AdminPlanRow[];
        unsaved: number;
        summary: PlanSummaryRow[];
    } | null;
    roles: PlanRole[];
    today: string;
};

/**
 * The admin implementation plan (ARCHITECTURE.md §9.2.6): for one round, open or
 * closed, every LGA below the threshold with its recommended action and its follow-up (owner,
 * contact, review date, status, notes), saved row by row; "Generate plan for this round" stores the
 * proposed rows, leaving saved ones as they are. "Actions by type" counts by status. The public plan
 * shows the same rows for closed rounds, without the contact or the notes.
 */
export default function AdminPlan({ rounds, round, plan, roles, today }: AdminPlanProps) {
    const t = useTranslate();
    const announce = useAnnounce();
    const roundId = useId();
    const generate = useApiAction<{ data: { created: number } }>();
    const [generated, setGenerated] = useState<string | null>(null);

    if (round === null || plan === null) {
        return <p className="text-sm text-ink-2">{t('admin.plan.none')}</p>;
    }

    const run = async () => {
        setGenerated(null);
        const result = await generate.run('POST', `/admin/api/plan/${round.id}/generate`);
        if (result.ok) {
            const created = result.data.data.created;
            const text = created === 0 ? t('admin.plan.generated_none') : t('admin.plan.generated', { count: formatCount(created) });
            setGenerated(text);
            announce(text);
            router.reload({ only: ['plan'] });
        }
    };

    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-wrap items-end gap-x-4 gap-y-2">
                <div className="field">
                    <label htmlFor={roundId}>{t('admin.plan.round')}</label>
                    <select id={roundId} value={round.id} onChange={(event) => router.get('/admin/plan', { round: event.target.value }, { preserveScroll: true })}>
                        {rounds.map((option) => (
                            <option key={option.id} value={option.id}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                </div>
                <p className="text-sm text-ink-2">{t(`admin.plan.round_status.${round.status}`)}</p>
                {/* The round's workplan as Excel: to share and print. */}
                <div className="w-full sm:ml-auto sm:w-auto">
                    <ExportButton
                        types={[{ key: 'workplan', offersPrivate: true, formats: ['xlsx'], privateLabel: 'admin.exports.private_plan', privateHelp: 'admin.exports.private_plan_help' }]}
                        filter={{ round: round.id }}
                    />
                </div>
            </div>

            {plan.unsaved > 0 && (
                <div className="flex flex-wrap items-center gap-3">
                    <button type="button" className="btn btn-primary" disabled={generate.pending} onClick={() => void run()}>
                        {generate.pending ? t('admin.plan.generating') : t('admin.plan.generate')}
                    </button>
                    <p className="max-w-[72ch] text-sm text-ink-2">{t('admin.plan.generate_help', { count: formatCount(plan.unsaved) })}</p>
                </div>
            )}
            {generated !== null && (
                <p role="status" className="notice notice-ok">
                    {generated}
                </p>
            )}
            {generate.error !== null && (
                <p role="alert" className="notice notice-bad text-bad">
                    {generate.error}
                </p>
            )}

            <Panel labelledBy="plan-rows-title">
                <PanelHeader
                    id="plan-rows-title"
                    title={t('admin.plan.table.caption', { round: round.label })}
                    {...(plan.threshold === null ? {} : { description: t('admin.plan.threshold', { band: plan.threshold.label, value: plan.threshold.value }) })}
                />
                {plan.rows.length === 0 ? (
                    <p className="px-5 pb-5 text-sm text-ink-2">{t('admin.plan.empty')}</p>
                ) : (
                    <ul className="mt-2">
                        {plan.rows.map((row) => (
                            <PlanRowEditor key={`${round.id}-${row.key}-${row.id ?? 'new'}`} row={row} roles={roles} today={today} />
                        ))}
                    </ul>
                )}
            </Panel>

            <div className="grid grid-cols-1 items-start gap-5 xl:grid-cols-2">
                <Panel labelledBy="plan-summary-title">
                    <PanelHeader id="plan-summary-title" title={t('admin.plan.summary.title')} />
                    <div role="region" aria-label={t('admin.plan.summary.caption')} tabIndex={0} className="relative mt-2 overflow-x-auto px-5 pb-5">
                        <table className="w-full text-sm">
                            <caption className="sr-only">{t('admin.plan.summary.caption')}</caption>
                            <thead>
                                <tr className="text-left text-xs text-ink-2">
                                    <th scope="col" className="py-1 pr-3 font-medium">{t('admin.plan.summary.type')}</th>
                                    <th scope="col" className="py-1 pr-3 text-right font-medium">{t('admin.plan.summary.total')}</th>
                                    <th scope="col" className="py-1 pr-3 text-right font-medium">{t('enums.plan_action_status.not_started')}</th>
                                    <th scope="col" className="py-1 pr-3 text-right font-medium">{t('enums.plan_action_status.in_progress')}</th>
                                    <th scope="col" className="py-1 text-right font-medium">{t('enums.plan_action_status.done')}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {plan.summary.map((type) => (
                                    <tr key={type.dimension} className="border-t border-rule">
                                        <th scope="row" className="py-2 pr-3 text-left font-medium">{type.dimension_label}</th>
                                        <td className="py-2 pr-3 text-right tabular-nums">{formatCount(type.count)}</td>
                                        <td className="py-2 pr-3 text-right tabular-nums">{formatCount(type.statuses.not_started)}</td>
                                        <td className="py-2 pr-3 text-right tabular-nums">{formatCount(type.statuses.in_progress)}</td>
                                        <td className="py-2 text-right tabular-nums">{formatCount(type.statuses.done)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Panel>
                <Panel labelledBy="plan-meanings-title">
                    <PanelHeader id="plan-meanings-title" title={t('admin.plan.meanings.title')} />
                    <dl className="flex flex-col gap-3 px-5 pt-2 pb-5 text-sm">
                        {plan.summary.map((type) => (
                            <div key={type.dimension}>
                                <dt className="font-semibold">{type.dimension_label}: {type.action}</dt>
                                <dd className="text-ink-2">{type.meaning}</dd>
                                <dd className="text-xs text-ink-2">{t('admin.plan.meanings.default_owner', { owner: type.owner })}</dd>
                            </div>
                        ))}
                    </dl>
                </Panel>
            </div>
        </div>
    );
}
