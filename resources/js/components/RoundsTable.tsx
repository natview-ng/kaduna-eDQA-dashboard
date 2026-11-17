import { Link } from '@inertiajs/react';
import DataTable, { type DataTableColumn } from '@/components/DataTable';
import EmptyState from '@/components/EmptyState';
import StateMark from '@/components/StateMark';
import { formatCount, formatDate, formatDateTime } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import type { RoundRow } from '@/types/admin';

export type RoundAction = 'close' | 'reopen' | 'window' | 'rescore';

export type RoundsTableProps = {
    rounds: RoundRow[];
    onAction: (action: RoundAction, round: RoundRow) => void;
    /** A round whose action is under way: its buttons wait. */
    busy: number | null;
};

/**
 * Every round, newest first: the window, the status in words ("Open — not
 * public", "Closed — public") with a shape, accepted assessments with each source's share, what is
 * still open (with a link to those records), the rule version, who closed it and when, and the
 * last reopen reason. An open round can be closed, its window edited, or rescored; a closed one
 * reopened. Every number is the server's.
 */
export default function RoundsTable({ rounds, onAction, busy }: RoundsTableProps) {
    const t = useTranslate();
    const months = useTranslationList('common.months_short');
    const day = (value: string) => (months.length === 12 ? formatDate(value, months) : value);
    const when = (value: string) => (months.length === 12 ? formatDateTime(value, months) : value);
    const key = (name: string) => t(`admin.rounds.table.${name}`);
    const columns: DataTableColumn[] = [
        { key: 'round', header: key('round') },
        { key: 'status', header: key('status') },
        { key: 'accepted', header: key('accepted'), align: 'end' },
        { key: 'issues', header: key('issues') },
        { key: 'version', header: key('version') },
        { key: 'closed', header: key('closed') },
        { key: 'reopened', header: key('reopened') },
        { key: 'actions', header: key('actions') },
    ];

    return (
        <DataTable caption={key('caption')} captionHidden columns={columns} empty={<EmptyState title={key('none')} />}>
            {rounds.map((round) => {
                const open = round.status === 'open';
                const issues = [
                    ['quarantine', round.issues.quarantine],
                    ['deletions', round.issues.deletions],
                    ['cross_source', round.issues.cross_source],
                ] as const;
                const anyIssue = issues.some(([, count]) => count > 0);
                const forRound = (
                    <>
                        {' '}
                        <span className="sr-only">{t('admin.rounds.actions.for', { round: round.label })}</span>
                    </>
                );

                return (
                    <tr key={round.id} className="border-b border-rule align-top last:border-b-0">
                        <th scope="row" className="px-3 py-2 text-left font-normal whitespace-nowrap">
                            <span className="block font-semibold">{round.label}</span>
                            <span className="block text-xs text-ink-2">{t('admin.rounds.table.window', { start: day(round.window_start), end: day(round.window_end) })}</span>
                        </th>
                        <td className="px-3 py-2 whitespace-nowrap">
                            <span className={`inline-flex items-center gap-1.5 rounded-sm px-1.5 py-0.5 text-xs font-medium ${open ? 'bg-subtle text-ink' : 'bg-ok-bg text-ok'}`}>
                                <StateMark mark={open ? '○' : '●'} className="size-2" />
                                {t(`admin.rounds.status.${round.status}`)}
                            </span>
                        </td>
                        <td className="px-3 py-2 text-right">
                            <span className="block font-semibold tabular-nums">{formatCount(round.assessments.total)}</span>
                            {round.assessments.by_source.map((source) => (
                                <span key={source.key ?? source.name} className="block text-xs whitespace-nowrap text-ink-2">
                                    {t('admin.rounds.table.by_source', { source: source.name, count: formatCount(source.count) })}
                                </span>
                            ))}
                        </td>
                        <td className="px-3 py-2">
                            {anyIssue ? (
                                <>
                                    <ul className="text-sm">
                                        {issues
                                            .filter(([, count]) => count > 0)
                                            .map(([name, count]) => (
                                                <li key={name} className="whitespace-nowrap">
                                                    {t(`admin.rounds.table.${name}`, { count: formatCount(count) })}
                                                </li>
                                            ))}
                                    </ul>
                                    <Link href={`/admin/data-issues?round=${round.id}`} className="text-xs font-medium text-deep underline underline-offset-2">
                                        {key('review_issues')} <span className="sr-only">{t('admin.rounds.table.review_issues_sr', { round: round.label })}</span>
                                    </Link>
                                </>
                            ) : (
                                <span className="text-ink-2">{key('no_issues')}</span>
                            )}
                        </td>
                        <td className="px-3 py-2 text-sm whitespace-nowrap">
                            {round.rule_version !== null && !open
                                ? t('admin.rounds.table.pinned', { version: round.rule_version })
                                : round.reads_rule_version !== null
                                  ? t('admin.rounds.table.reads', { version: round.reads_rule_version })
                                  : key('no_version')}
                        </td>
                        <td className="px-3 py-2 text-sm">
                            {round.closed_at === null ? (
                                <span className="text-ink-2">{key('not_closed')}</span>
                            ) : (
                                <>
                                    <span className="block whitespace-nowrap">{when(round.closed_at)}</span>
                                    {round.closed_by !== null && <span className="block text-xs text-ink-2">{t('admin.rounds.table.closed_by', { name: round.closed_by })}</span>}
                                </>
                            )}
                        </td>
                        <td className="max-w-56 px-3 py-2 text-sm text-ink-2">{round.reopened_reason ?? '—'}</td>
                        <td className="px-3 py-2">
                            <div className="flex flex-wrap gap-1.5">
                                {open ? (
                                    <>
                                        <button type="button" className="btn btn-primary btn-sm" disabled={busy === round.id} onClick={() => onAction('close', round)}>
                                            {t('admin.rounds.actions.close')}
                                            {forRound}
                                        </button>
                                        <button type="button" className="btn btn-secondary btn-sm" disabled={busy === round.id} onClick={() => onAction('window', round)}>
                                            {t('admin.rounds.actions.edit_window')}
                                            {forRound}
                                        </button>
                                        <button type="button" className="btn btn-secondary btn-sm" disabled={busy === round.id} onClick={() => onAction('rescore', round)}>
                                            {t('admin.rounds.actions.rescore')}
                                            {forRound}
                                        </button>
                                    </>
                                ) : (
                                    <button type="button" className="btn btn-secondary btn-sm" disabled={busy === round.id} onClick={() => onAction('reopen', round)}>
                                        {t('admin.rounds.actions.reopen')}
                                        {forRound}
                                    </button>
                                )}
                            </div>
                        </td>
                    </tr>
                );
            })}
        </DataTable>
    );
}
