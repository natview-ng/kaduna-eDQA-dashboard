import { Link } from '@inertiajs/react';
import { useState } from 'react';
import AdminTable from '@/components/AdminTable';
import type { DataTableColumn } from '@/components/DataTable';
import { formatDateTime } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import type { Paginated } from '@/types/admin';

export type AuditEntry = {
    id: number;
    at: string;
    actor: string | null;
    console: boolean;
    event: string;
    auth: boolean;
    subject: { type: string; id: number | null; label: string | null; url: string | null } | null;
    source: string | null;
    reason: string | null;
    old: Record<string, unknown>;
    new: Record<string, unknown>;
};

export type AuditLogTableProps = {
    entries: Paginated<AuditEntry>;
    onPage: (page: number) => void;
};

/** A recorded value as text: objects as JSON, nothing as "(none)". */
export function auditValue(value: unknown, empty: string): string {
    if (value === null || value === undefined || value === '') {
        return empty;
    }

    return typeof value === 'object' ? JSON.stringify(value) : String(value);
}

/** Every field recorded before or after, with both values, in the order first seen. */
export function auditChanges(old: Record<string, unknown>, next: Record<string, unknown>): { field: string; before: unknown; after: unknown }[] {
    return [...new Set([...Object.keys(old), ...Object.keys(next)])].map((field) => ({ field, before: old[field], after: next[field] }));
}

/**
 * The audit log's rows: when (WAT), who (or Console / System), what (sign-in
 * events marked as such), the subject with a link where the admin area has a page for it, the
 * reason, and the before/after values in a row that opens under the entry. Read only.
 */
export default function AuditLogTable({ entries, onPage }: AuditLogTableProps) {
    const t = useTranslate();
    const months = useTranslationList('common.months_short');
    const [open, setOpen] = useState<number[]>([]);
    const key = (name: string) => t(`admin.audit.table.${name}`);
    const eventLabel = (event: string) => {
        const label = t(`admin.audit.events.${event}`);

        return label === `admin.audit.events.${event}` ? event : label;
    };
    const columns: DataTableColumn[] = [
        { key: 'when', header: key('when') },
        { key: 'who', header: key('who') },
        { key: 'what', header: key('what') },
        { key: 'subject', header: key('subject') },
        { key: 'reason', header: key('reason') },
        { key: 'details', header: key('details') },
    ];
    const toggle = (id: number) => setOpen((current) => (current.includes(id) ? current.filter((item) => item !== id) : [...current, id]));

    return (
        <AdminTable
            caption={key('caption')}
            captionHidden
            columns={columns}
            empty={<p className="px-3 py-6 text-center text-sm text-ink-2">{key('none')}</p>}
            meta={{ page: entries.current_page, lastPage: entries.last_page, from: entries.from ?? 0, to: entries.to ?? 0, total: entries.total }}
            onPage={onPage}
            labels={{ showing: (figures) => t('admin.audit.table.showing', figures), none: key('showing_none'), nav: key('pages') }}
        >
            {entries.data.flatMap((entry) => {
                const changes = auditChanges(entry.old, entry.new);
                const expanded = open.includes(entry.id);
                const detailsId = `audit-entry-${entry.id}-changes`;
                const row = (
                    <tr key={entry.id} className="border-b border-rule align-top last:border-b-0">
                        <th scope="row" className="px-3 py-2 text-left font-normal whitespace-nowrap">
                            {months.length === 12 ? formatDateTime(entry.at, months) : entry.at}
                        </th>
                        <td className="px-3 py-2">{entry.actor ?? (entry.console ? key('console') : key('system'))}</td>
                        <td className="px-3 py-2">
                            <span className="font-medium">{eventLabel(entry.event)}</span>
                            {entry.auth && <span className="ml-2 rounded-sm bg-subtle px-1.5 py-0.5 text-2xs font-medium text-ink">{key('sign_in')}</span>}
                            {entry.source !== null && <span className="block text-xs text-ink-2">{entry.source}</span>}
                        </td>
                        <td className="px-3 py-2">
                            {entry.subject === null ? (
                                '—'
                            ) : (
                                <>
                                    <span className="block text-xs text-ink-2">{t(`admin.audit.types.${entry.subject.type}`)}</span>
                                    {entry.subject.url !== null ? (
                                        <Link href={entry.subject.url} className="font-medium text-deep underline underline-offset-2">
                                            {entry.subject.label ?? `#${entry.subject.id ?? ''}`}
                                        </Link>
                                    ) : (
                                        <span>{entry.subject.label ?? (entry.subject.id === null ? '' : `#${entry.subject.id}`)}</span>
                                    )}
                                </>
                            )}
                        </td>
                        <td className="max-w-72 px-3 py-2 text-ink-2">{entry.reason ?? key('no_reason')}</td>
                        <td className="px-3 py-2">
                            {changes.length === 0 ? (
                                <span className="text-xs text-ink-2">{key('no_changes')}</span>
                            ) : (
                                <button type="button" className="btn btn-quiet btn-sm" aria-expanded={expanded} aria-controls={detailsId} onClick={() => toggle(entry.id)}>
                                    {expanded ? key('hide') : key('show')} <span className="sr-only">{t('admin.audit.table.for', { id: entry.id })}</span>
                                </button>
                            )}
                        </td>
                    </tr>
                );

                return expanded
                    ? [
                          row,
                          <tr key={`${entry.id}-changes`} id={detailsId} className="border-b border-rule bg-paper/60">
                              <td colSpan={columns.length} className="px-5 py-3">
                                  <table className="w-full max-w-4xl text-sm">
                                      <caption className="sr-only">{t('admin.audit.table.diff_caption', { id: entry.id })}</caption>
                                      <thead>
                                          <tr>
                                              <th scope="col" className="py-1 pr-3 text-left text-xs font-medium text-ink-2">{key('field')}</th>
                                              <th scope="col" className="py-1 pr-3 text-left text-xs font-medium text-ink-2">{key('before')}</th>
                                              <th scope="col" className="py-1 text-left text-xs font-medium text-ink-2">{key('after')}</th>
                                          </tr>
                                      </thead>
                                      <tbody>
                                          {changes.map((change) => (
                                              <tr key={change.field} className="border-t border-rule">
                                                  <th scope="row" className="py-1 pr-3 text-left align-top font-normal">
                                                      <code className="text-xs">{change.field}</code>
                                                  </th>
                                                  <td className="py-1 pr-3 align-top break-all text-ink-2">{auditValue(change.before, key('empty'))}</td>
                                                  <td className="py-1 align-top break-all">{auditValue(change.after, key('empty'))}</td>
                                              </tr>
                                          ))}
                                      </tbody>
                                  </table>
                              </td>
                          </tr>,
                      ]
                    : [row];
            })}
        </AdminTable>
    );
}
