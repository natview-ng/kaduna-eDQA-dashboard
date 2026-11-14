import { router } from '@inertiajs/react';
import { useState } from 'react';
import { useAnnounce } from '@/components/Announcer';
import CloseRoundPanel from '@/components/CloseRoundPanel';
import ConfirmWithReasonDialog, { type ConfirmOutcome } from '@/components/ConfirmWithReasonDialog';
import CreateRoundForm from '@/components/CreateRoundForm';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import ProgressPanel, { type ProgressStage } from '@/components/ProgressPanel';
import RoundsTable, { type RoundAction } from '@/components/RoundsTable';
import RoundWindowPanel from '@/components/RoundWindowPanel';
import { formatCount, formatDate } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import { nextQuarter, quarterDates, windowErrorField } from '@/lib/rounds';
import { useApiAction } from '@/lib/useApiAction';
import type { ReconcilableSource, RescoreRunData, RoundRow } from '@/types/admin';

export type AdminRoundsProps = {
    rounds: RoundRow[];
    sources: ReconcilableSource[];
    graceDays: number;
};

type Pending = {
    title: string;
    consequence: string;
    confirm: string;
    danger: boolean;
    publicImpact: boolean;
    send: (reason: string) => Promise<ConfirmOutcome>;
};
type WindowErrors = Partial<Record<'window_start' | 'window_end', string>>;

/**
 * The Rounds page (ARCHITECTURE.md §3, §9.3; Phase 5 step 4). Every round with its window,
 * status in words, accepted assessments per source, open issues, rule version, who closed it and
 * why it was last reopened. Closing publishes a round to the public dashboard (D-07, D-77), so it
 * goes through a close panel (what is still open, "Reconcile all sources now") and a reasoned
 * dialog with the public-impact note; reopening takes it back, also reasoned. An open round's
 * window can be edited and the round rescored, each with a reason. Create a round below the list.
 * Every action reloads only `rounds` (and the shell) and says what happened.
 */
export default function AdminRounds({ rounds, sources, graceDays }: AdminRoundsProps) {
    const t = useTranslate();
    const announce = useAnnounce();
    const months = useTranslationList('common.months_short');
    const day = (value: string) => (months.length === 12 ? formatDate(value, months) : value);
    const api = useApiAction<{ data: { label: string; window_start: string; window_end: string }; rescore?: { batch_id: string } | null }>();
    const rescoreApi = useApiAction<{ data: RescoreRunData }>();

    const [pending, setPending] = useState<Pending | null>(null);
    const [closing, setClosing] = useState<RoundRow | null>(null);
    const [editing, setEditing] = useState<RoundRow | null>(null);
    const [windowErrors, setWindowErrors] = useState<WindowErrors>({});
    const [rescore, setRescore] = useState<{ round: RoundRow; batchId: string } | null>(null);
    const [outcome, setOutcome] = useState<string | null>(null);

    const reload = () => router.reload({ only: ['rounds', 'sources', 'admin', 'shell'] });
    const done = (text: string) => {
        setOutcome(text);
        announce(text);
        reload();
    };
    const fail = (message: string, fieldError: string | null = null): ConfirmOutcome => ({ ok: false, message, fieldError });

    const close = (round: RoundRow) =>
        setPending({
            title: t('admin.rounds.close.title', { round: round.label }),
            consequence: [
                t('admin.rounds.close.consequence'),
                ...(round.issues.quarantine > 0 || round.issues.deletions > 0 || round.issues.cross_source > 0 ? [t('admin.rounds.close.consequence_issues')] : []),
            ].join(' '),
            confirm: t('admin.rounds.close.confirm'),
            danger: false,
            publicImpact: true,
            send: async (reason) => {
                const result = await api.run('POST', `/admin/api/rounds/${round.id}/close`, { reason });
                if (!result.ok) {
                    return fail(result.message, result.fieldErrors.reason ?? null);
                }
                setClosing(null);
                done(t('admin.rounds.close.done', { round: round.label }));

                return { ok: true };
            },
        });

    const reopen = (round: RoundRow) =>
        setPending({
            title: t('admin.rounds.reopen.title', { round: round.label }),
            consequence: t('admin.rounds.reopen.consequence'),
            confirm: t('admin.rounds.reopen.confirm'),
            danger: true,
            publicImpact: true,
            send: async (reason) => {
                const result = await api.run('POST', `/admin/api/rounds/${round.id}/reopen`, { reason });
                if (!result.ok) {
                    return fail(result.message, result.fieldErrors.reason ?? null);
                }
                const batch = result.data.rescore?.batch_id ?? null;
                if (batch !== null) {
                    setRescore({ round, batchId: batch });
                }
                done([t('admin.rounds.reopen.done', { round: round.label }), ...(batch === null ? [] : [t('admin.rounds.reopen.rescoring')])].join(' '));

                return { ok: true };
            },
        });

    const saveWindow = (round: RoundRow, start: string, end: string) =>
        setPending({
            title: t('admin.rounds.window.confirm_title', { round: round.label }),
            consequence: t('admin.rounds.window.consequence', { start: day(start), end: day(end) }),
            confirm: t('admin.rounds.window.confirm'),
            danger: false,
            publicImpact: false,
            send: async (reason) => {
                setWindowErrors({});
                const result = await api.run('PATCH', `/admin/api/rounds/${round.id}`, { window_start: start, window_end: end, reason });
                if (!result.ok) {
                    const fields: WindowErrors = {
                        ...(result.fieldErrors.window_start === undefined ? {} : { window_start: result.fieldErrors.window_start }),
                        ...(result.fieldErrors.window_end === undefined ? {} : { window_end: result.fieldErrors.window_end }),
                    };
                    if (result.code !== null) {
                        const field = windowErrorField(result.code, start, quarterDates(round.year, round.quarter));
                        fields[field === 'quarter' ? 'window_start' : field] = result.message;
                    }
                    setWindowErrors(fields);

                    return fail(result.message, result.fieldErrors.reason ?? null);
                }
                setEditing(null);
                done(t('admin.rounds.window.done', { round: round.label, start: day(start), end: day(end) }));

                return { ok: true };
            },
        });

    const startRescore = (round: RoundRow) =>
        setPending({
            title: t('admin.rounds.rescore.title', { round: round.label }),
            consequence: t('admin.rounds.rescore.consequence'),
            confirm: t('admin.rounds.rescore.confirm'),
            danger: false,
            publicImpact: false,
            send: async (reason) => {
                const result = await rescoreApi.run('POST', `/admin/api/rounds/${round.id}/rescore`, { reason });
                if (!result.ok) {
                    return fail(result.message, result.fieldErrors.reason ?? null);
                }
                setRescore({ round, batchId: result.data.data.batch_id });

                return { ok: true };
            },
        });

    const onAction = (action: RoundAction, round: RoundRow) => {
        setOutcome(null);
        if (action === 'close') {
            setEditing(null);
            setClosing(round);
        } else if (action === 'window') {
            setClosing(null);
            setWindowErrors({});
            setEditing(round);
        } else if (action === 'reopen') {
            reopen(round);
        } else {
            startRescore(round);
        }
    };

    const rescoreStage = ({ data }: { data: RescoreRunData }): ProgressStage => ({
        key: data.status,
        label: t(`admin.rounds.rescore.stages.${data.status}`),
        terminal: data.status !== 'running',
    });

    return (
        <div className="flex flex-col gap-5">
            {outcome !== null && (
                <p role="status" className="notice notice-ok">
                    {outcome}
                </p>
            )}

            <Panel labelledBy="rounds-title">
                <PanelHeader id="rounds-title" title={t('admin.rounds.table.title')} description={t('admin.rounds.table.description')} />
                <div className="mt-3">
                    <RoundsTable rounds={rounds} onAction={onAction} busy={api.pending ? (closing?.id ?? editing?.id ?? null) : null} />
                </div>
            </Panel>

            {closing !== null && (
                <CloseRoundPanel
                    // A reload brings fresh counts: follow the round by id.
                    round={rounds.find((round) => round.id === closing.id) ?? closing}
                    sources={sources}
                    onClose={() => close(rounds.find((round) => round.id === closing.id) ?? closing)}
                    onCancel={() => setClosing(null)}
                    onReconciled={reload}
                />
            )}

            {editing !== null && (
                <RoundWindowPanel round={editing} errors={windowErrors} onSave={(start, end) => saveWindow(editing, start, end)} onCancel={() => setEditing(null)} />
            )}

            {rescore !== null && (
                <Panel labelledBy="round-rescore-title">
                    <PanelHeader id="round-rescore-title" title={t('admin.rounds.rescore.progress', { round: rescore.round.label })} />
                    <div className="px-5 pt-3 pb-5">
                        <ProgressPanel<{ data: RescoreRunData }>
                            key={rescore.batchId}
                            title={t('admin.rounds.rescore.progress', { round: rescore.round.label })}
                            url={`/admin/api/rescores/${rescore.batchId}`}
                            stage={rescoreStage}
                            onDone={reload}
                        >
                            {({ data }) => (
                                <dl className="grid grid-cols-1 gap-x-6 gap-y-1 min-[420px]:grid-cols-2">
                                    <div className="flex justify-between gap-3">
                                        <dt>{t('admin.rounds.rescore.processed')}</dt>
                                        <dd className="font-medium text-ink tabular-nums">
                                            {formatCount(data.assessments.processed)} / {formatCount(data.assessments.total)}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between gap-3">
                                        <dt>{t('admin.rounds.rescore.changed')}</dt>
                                        <dd className="font-medium text-ink tabular-nums">{formatCount(data.changed_scores)}</dd>
                                    </div>
                                </dl>
                            )}
                        </ProgressPanel>
                    </div>
                </Panel>
            )}

            <CreateRoundForm
                graceDays={graceDays}
                initial={nextQuarter(rounds, new Date())}
                onCreated={(round, warnings) => done([t('admin.rounds.create.created', { round: round.label }), ...warnings].join(' '))}
            />

            <ConfirmWithReasonDialog
                open={pending !== null}
                title={pending?.title ?? ''}
                consequence={pending?.consequence ?? ''}
                confirmLabel={pending?.confirm ?? ''}
                danger={pending?.danger ?? false}
                publicImpact={pending?.publicImpact ?? false}
                onConfirm={(reason) => (pending === null ? Promise.resolve({ ok: true }) : pending.send(reason))}
                onClose={() => setPending(null)}
            />
        </div>
    );
}
