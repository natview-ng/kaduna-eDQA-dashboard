import { router } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import { useAnnounce } from '@/components/Announcer';
import AuditLogTable, { type AuditEntry } from '@/components/AuditLogTable';
import ExportButton from '@/components/ExportButton';
import Labelled from '@/components/Labelled';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import type { Paginated } from '@/types/admin';

export type AuditFilters = { actor: number | null; type: string | null; source: string | null; event: string | null; from: string | null; to: string | null; q: string | null; page: number };

export type AdminAuditProps = {
    entries: Paginated<AuditEntry>;
    auditFilters: AuditFilters;
    actors: { id: number; name: string }[];
    events: string[];
    types: string[];
    sources: { key: string; name: string }[];
};

const DEBOUNCE_MS = 300;

/**
 * The Audit log page (SECURITY.md §4, D-93): every audited write and sign-in
 * event, filtered on the server (who, subject, source, event, dates, reason), 50 a page. Read
 * only: nothing can be changed or removed, and viewing is not recorded. Export goes through the export
 * plumbing: the filtered entries as CSV or Excel, queued, every export audited.
 */
export default function AdminAudit({ entries, auditFilters: filters, actors, events, types, sources }: AdminAuditProps) {
    const t = useTranslate();
    const announce = useAnnounce();
    const ids = { actor: useId(), type: useId(), source: useId(), event: useId(), from: useId(), to: useId(), q: useId() };
    const [q, setQ] = useState(filters.q ?? '');
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const anySet = [filters.actor, filters.type, filters.source, filters.event, filters.from, filters.to, filters.q].some((value) => value !== null && value !== '');

    useEffect(() => () => {
        if (timer.current !== null) {
            clearTimeout(timer.current);
        }
    }, []);

    const visit = (next: Partial<AuditFilters>) => {
        const query = { ...filters, ...next };
        router.get('/admin/audit', Object.fromEntries(Object.entries(query).filter(([key, value]) => value !== null && value !== '' && !(key === 'page' && value === 1))), {
            only: ['entries', 'auditFilters'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: (page) => announce(t('admin.audit.filters.announce', { total: formatCount((page.props.entries as Paginated<unknown> | undefined)?.total ?? 0) })),
        });
    };
    const search = (value: string) => {
        setQ(value);
        if (timer.current !== null) {
            clearTimeout(timer.current);
        }
        timer.current = setTimeout(() => visit({ q: value === '' ? null : value, page: 1 }), DEBOUNCE_MS);
    };
    const eventLabel = (event: string) => {
        const label = t(`admin.audit.events.${event}`);

        return label === `admin.audit.events.${event}` ? event : label;
    };

    // The export takes the filters in view, by the page's URL names; never the page number.
    const exportFilter = Object.fromEntries(Object.entries(filters).filter(([key]) => key !== 'page')) as Record<string, string | number | null>;
    const all = <option value="">{t('admin.audit.filters.all')}</option>;

    return (
        <div className="flex flex-col gap-5">
            <p className="text-sm text-ink-2">{t('admin.audit.read_only')}</p>

            <Panel labelledBy="audit-entries-title">
                <PanelHeader id="audit-entries-title" title={t('admin.audit.table.title')} />
                <form role="search" aria-label={t('admin.audit.filters.label')} className="flex flex-wrap items-center gap-2 px-5 pt-3 pb-4" onSubmit={(event) => event.preventDefault()}>
                    <Labelled inline id={ids.actor} label={t('admin.audit.filters.actor')}>
                        <select id={ids.actor} value={filters.actor === null ? '' : String(filters.actor)} onChange={(event) => visit({ actor: event.target.value === '' ? null : Number(event.target.value), page: 1 })}>
                            {all}
                            {actors.map((actor) => (
                                <option key={actor.id} value={String(actor.id)}>
                                    {actor.name}
                                </option>
                            ))}
                        </select>
                    </Labelled>
                    <Labelled inline id={ids.type} label={t('admin.audit.filters.type')}>
                        <select id={ids.type} value={filters.type ?? ''} onChange={(event) => visit({ type: event.target.value || null, page: 1 })}>
                            {all}
                            {types.map((type) => (
                                <option key={type} value={type}>
                                    {t(`admin.audit.types.${type}`)}
                                </option>
                            ))}
                        </select>
                    </Labelled>
                    <Labelled inline id={ids.source} label={t('admin.audit.filters.source')}>
                        <select id={ids.source} value={filters.source ?? ''} onChange={(event) => visit({ source: event.target.value || null, page: 1 })}>
                            {all}
                            {sources.map((source) => (
                                <option key={source.key} value={source.key}>
                                    {source.name}
                                </option>
                            ))}
                        </select>
                    </Labelled>
                    <Labelled inline id={ids.event} label={t('admin.audit.filters.event')}>
                        <select id={ids.event} value={filters.event ?? ''} onChange={(event) => visit({ event: event.target.value || null, page: 1 })}>
                            {all}
                            {events.map((event) => (
                                <option key={event} value={event}>
                                    {eventLabel(event)}
                                </option>
                            ))}
                        </select>
                    </Labelled>
                    <div className="field">
                        <label htmlFor={ids.from}>{t('admin.audit.filters.from')}</label>
                        <input id={ids.from} type="date" value={filters.from ?? ''} max={filters.to ?? undefined} onChange={(event) => visit({ from: event.target.value || null, page: 1 })} />
                    </div>
                    <div className="field">
                        <label htmlFor={ids.to}>{t('admin.audit.filters.to')}</label>
                        <input id={ids.to} type="date" value={filters.to ?? ''} min={filters.from ?? undefined} onChange={(event) => visit({ to: event.target.value || null, page: 1 })} />
                    </div>
                    <div className="field min-w-56">
                        <label htmlFor={ids.q}>{t('admin.audit.filters.search')}</label>
                        <input id={ids.q} type="search" value={q} onChange={(event) => search(event.target.value)} />
                    </div>
                    {anySet && (
                        <button
                            type="button"
                            className="btn btn-quiet btn-sm"
                            onClick={() => {
                                setQ('');
                                visit({ actor: null, type: null, source: null, event: null, from: null, to: null, q: null, page: 1 });
                            }}
                        >
                            {t('admin.audit.filters.clear')}
                        </button>
                    )}
                </form>
                <AuditLogTable entries={entries} onPage={(page) => visit({ page })} />
            </Panel>

            <Panel labelledBy="audit-export-title">
                <PanelHeader id="audit-export-title" title={t('admin.audit.export.title')} description={t('admin.audit.export.description')} />
                <div className="px-5 pt-2 pb-5">
                    <ExportButton types={[{ key: 'audit_log', offersPrivate: false }]} filter={exportFilter} />
                </div>
            </Panel>
        </div>
    );
}
