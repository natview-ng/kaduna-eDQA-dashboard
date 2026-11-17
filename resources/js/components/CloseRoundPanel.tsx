import { Link } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import Panel from '@/components/Panel';
import ProgressPanel, { type ProgressStage } from '@/components/ProgressPanel';
import StateMark from '@/components/StateMark';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { useApiAction } from '@/lib/useApiAction';
import type { PullRun, ReconcilableSource, RoundRow } from '@/types/admin';

export type CloseRoundPanelProps = {
    round: RoundRow;
    /** Configured, switched-on sources, each with any reconcile already running. */
    sources: ReconcilableSource[];
    /** Opens the reasoned close dialog. */
    onClose: () => void;
    onCancel: () => void;
    /** A reconcile finished: the page reloads the rounds, whose open issues may have changed. */
    onReconciled: () => void;
};

const COUNTERS = ['fetched', 'parsed', 'accepted', 'flagged', 'quarantined', 'deletions_detected'] as const;

/**
 * The step before closing a round: what is still open in it (quarantine,
 * deletions at source, duplicates across sources, with a link to them), then "Reconcile all
 * sources now" so late edits at the source are caught, each source followed in its own
 * ProgressPanel, then the close itself, which asks for a reason. Open issues do not block the
 * close. Focus moves to the panel's heading when it opens.
 */
export default function CloseRoundPanel({ round, sources, onClose, onCancel, onReconciled }: CloseRoundPanelProps) {
    const t = useTranslate();
    const action = useApiAction<{ run_id: number; already_running: boolean }>();
    const heading = useRef<HTMLHeadingElement>(null);
    const [runs, setRuns] = useState<Record<string, number | null>>(() =>
        Object.fromEntries(sources.map((source) => [source.key, source.running_reconcile_run_id])),
    );
    const [finished, setFinished] = useState<number[]>([]);
    const running = Object.values(runs).some((id) => id !== null && !finished.includes(id));

    useEffect(() => heading.current?.focus(), []);

    const issues = [
        ['quarantine', round.issues.quarantine],
        ['deletions', round.issues.deletions],
        ['cross_source', round.issues.cross_source],
    ] as const;
    const anyIssue = issues.some(([, count]) => count > 0);

    const reconcileAll = async () => {
        for (const source of sources) {
            const result = await action.run('POST', `/admin/api/sources/${source.key}/reconcile`);
            if (result.ok) {
                setRuns((current) => ({ ...current, [source.key]: result.data.run_id }));
            }
        }
    };

    const stage = ({ data }: { data: PullRun }): ProgressStage => ({
        key: data.status,
        label: t(`admin.sources.pull.stages.${data.status}`),
        terminal: data.status !== 'running',
    });

    return (
        <Panel labelledBy="close-round-title" className="border-deep/40">
            <div className="px-5 pt-4.5 pb-5">
                <h2 id="close-round-title" ref={heading} tabIndex={-1} className="text-md font-semibold tracking-[-0.015em] outline-offset-4">
                    {t('admin.rounds.close.title', { round: round.label })}
                </h2>
                <p className="mt-1 max-w-[72ch] text-sm text-ink-2">{t('admin.rounds.close.intro')}</p>

                <section aria-labelledby="close-round-issues" className="mt-4">
                    <h3 id="close-round-issues" className="text-sm font-semibold">
                        {t('admin.rounds.close.issues_title', { round: round.label })}
                    </h3>
                    {anyIssue ? (
                        <>
                            <ul className="mt-1.5 flex flex-col gap-1 text-sm">
                                {issues
                                    .filter(([, count]) => count > 0)
                                    .map(([name, count]) => (
                                        <li key={name} className="flex items-center gap-2">
                                            <StateMark mark="▲" className="text-warn-fill" />
                                            {t(`admin.rounds.table.${name}`, { count: formatCount(count) })}
                                        </li>
                                    ))}
                            </ul>
                            <p className="mt-2 text-sm">
                                <Link href={`/admin/data-issues?round=${round.id}`} className="font-medium text-deep underline underline-offset-2">
                                    {t('admin.rounds.table.review_issues')}
                                </Link>{' '}
                                <span className="text-ink-2">{t('admin.rounds.close.issues_allowed')}</span>
                            </p>
                        </>
                    ) : (
                        <p className="mt-1.5 flex items-center gap-2 text-sm">
                            <StateMark mark="●" className="text-ok" />
                            {t('admin.rounds.close.issues_none')}
                        </p>
                    )}
                </section>

                <section aria-labelledby="close-round-reconcile" className="mt-5">
                    <h3 id="close-round-reconcile" className="text-sm font-semibold">
                        {t('admin.rounds.close.reconcile_title')}
                    </h3>
                    <p className="mt-1 text-sm text-ink-2">{t('admin.rounds.close.reconcile_help')}</p>
                    {sources.length === 0 ? (
                        <p className="mt-2 text-sm">{t('admin.rounds.close.reconcile_none')}</p>
                    ) : (
                        <button type="button" className="btn btn-secondary mt-2" disabled={action.pending || running} onClick={() => void reconcileAll()}>
                            {action.pending ? t('admin.rounds.close.reconcile_starting') : t('admin.rounds.close.reconcile_all')}
                        </button>
                    )}
                    {action.error !== null && (
                        <p role="alert" className="notice notice-bad mt-2 text-bad">
                            {action.error}
                        </p>
                    )}
                    <div className="mt-3 grid gap-3 lg:grid-cols-2">
                        {sources.map((source) => {
                            const runId = runs[source.key] ?? null;

                            return (
                                runId !== null && (
                                    <ProgressPanel<{ data: PullRun }>
                                        key={runId}
                                        title={t('admin.rounds.close.reconcile_progress', { source: source.name })}
                                        url={`/admin/api/pulls/${runId}`}
                                        stage={stage}
                                        onDone={() => {
                                            setFinished((current) => [...current, runId]);
                                            onReconciled();
                                        }}
                                    >
                                        {({ data }) => (
                                            <dl aria-label={t('admin.sources.pull.counters')} className="grid grid-cols-1 gap-x-6 gap-y-1 min-[420px]:grid-cols-2">
                                                {COUNTERS.map((counter) => (
                                                    <div key={counter} className="flex justify-between gap-3">
                                                        <dt>{t(`admin.sources.pull.${counter}`)}</dt>
                                                        <dd className="font-medium text-ink tabular-nums">{formatCount(data[counter])}</dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        )}
                                    </ProgressPanel>
                                )
                            );
                        })}
                    </div>
                </section>

                <div className="mt-5 border-t border-rule pt-4">
                    <p className="max-w-[72ch] text-sm">{t('admin.rounds.close.consequence')}</p>
                    <div className="mt-3 flex flex-wrap gap-2">
                        <button type="button" className="btn btn-primary" disabled={running} onClick={onClose}>
                            {t('admin.rounds.close.button')}
                        </button>
                        <button type="button" className="btn btn-quiet" onClick={onCancel}>
                            {t('admin.rounds.close.cancel')}
                        </button>
                    </div>
                </div>
            </div>
        </Panel>
    );
}
