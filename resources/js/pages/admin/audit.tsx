import { router } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
import { useAnnounce } from '@/components/Announcer';
import AuditLogTable, { type AuditEntry } from '@/components/AuditLogTable';
import Labelled from '@/components/Labelled';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import { formatCount, formatDateTime } from '@/lib/format';
import { useTranslate, useTranslationList } from '@/lib/i18n';
import { useApiAction } from '@/lib/useApiAction';
import type { Paginated } from '@/types/admin';

export type AuditFilters = { actor: number | null; type: string | null; source: string | null; event: string | null; from: string | null; to: string | null; q: string | null; page: number };

export type AdminAuditProps = {
    entries: Paginated<AuditEntry>;
    auditFilters: AuditFilters;
    actors: { id: number; name: string }[];
    events: string[];
    types: string[];
    sources: { key: string; name: string }[];
    exports: { file: string; rows: number; at: string; url: string }[];
};

type ExportResult = { queued: boolean; file?: string; rows?: number; url?: string };

const DEBOUNCE_MS = 300;

/**
 * The Audit log page (SECURITY.md §4, Phase 5 step 7, D-93): every audited write and sign-in
 * event, filtered on the server (who, subject, source, event, dates, reason), 50 a page. Read
 * only: nothing can be changed or removed, and viewing is not recorded. Export writes a CSV of the
 * filtered entries (queued over 5,000 rows); every export is audited.
 */
export default function AdminAudit({ entries, auditFilters: filters, actors, events, types, sources, exports }: AdminAuditProps) {
    const t = useTranslate();
    const announce = useAnnounce();
    const months = useTranslationList('common.months_short');
    const ids = { actor: useId(), type: useId(), source: useId(), event: useId(), from: useId(), to: useId(), q: useId() };
    const api = useApiAction<{ data: ExportResult }>();
    const [q, setQ] = useState(filters.q ?? '');
    const [result, setResult] = useState<ExportResult | null>(null);
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

    const exportCsv = async () => {
        setResult(null);
        const body = Object.fromEntries(Object.entries(filters).filter(([key, value]) => key !== 'page' && value !== null && value !== ''));
        const response = await api.run('POST', '/admin/api/audit/export', body);
        if (response.ok) {
            setResult(response.data.data);
            if (!response.data.data.queued) {
                router.reload({ only: ['exports'] });
            }
        }
    };
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
                <PanelHeader
                    id="audit-export-title"
                    title={t('admin.audit.export.title')}
                    description={t('admin.audit.export.description')}
                    actions={
                        <button type="button" className="btn btn-secondary self-center" disabled={api.pending} onClick={() => void exportCsv()}>
                            {api.pending ? t('admin.audit.export.working') : t('admin.audit.export.button')}
                        </button>
                    }
                />
                <div className="flex flex-col gap-3 px-5 pt-2 pb-5 text-sm">
                    {api.error !== null && (
                        <p role="alert" className="notice notice-bad text-bad">
                            {api.error}
                        </p>
                    )}
                    {result !== null && (
                        <div role="status" className="notice notice-ok">
                            {result.queued ? (
                                <p>{t('admin.audit.export.queued')}</p>
                            ) : (
                                <>
                                    <p>{t('admin.audit.export.ready', { rows: formatCount(result.rows ?? 0) })}</p>
                                    {result.url !== undefined && (
                                        <a href={result.url} className="font-medium text-deep underline underline-offset-2">
                                            {t('admin.audit.export.download', { file: result.file ?? '' })}
                                        </a>
                                    )}
                                </>
                            )}
                        </div>
                    )}
                    <section aria-labelledby="audit-exports-recent">
                        <h3 id="audit-exports-recent" className="font-semibold">
                            {t('admin.audit.export.recent')}
                        </h3>
                        {exports.length === 0 ? (
                            <p className="mt-1 text-ink-2">{t('admin.audit.export.none')}</p>
                        ) : (
                            <>
                                <ul className="mt-1 flex flex-col gap-1">
                                    {exports.map((file) => (
                                        <li key={file.file}>
                                            <a href={file.url} className="text-deep underline underline-offset-2">
                                                {t('admin.audit.export.recent_item', { file: file.file, rows: formatCount(file.rows) })}
                                            </a>
                                            <span className="ml-2 text-xs text-ink-2">{months.length === 12 ? formatDateTime(file.at, months) : file.at}</span>
                                        </li>
                                    ))}
                                </ul>
                                <p className="mt-1 text-xs text-ink-2">{t('admin.audit.export.expiry')}</p>
                            </>
                        )}
                    </section>
                </div>
            </Panel>
        </div>
    );
}
