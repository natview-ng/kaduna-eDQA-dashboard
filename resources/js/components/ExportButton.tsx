import { useId, useState, type FormEvent } from 'react';
import ProgressPanel from '@/components/ProgressPanel';
import { useTranslate } from '@/lib/i18n';
import { useApiAction } from '@/lib/useApiAction';

export type ExportStatus = {
    id: number;
    status: 'queued' | 'running' | 'done' | 'failed' | 'expired';
    status_label: string;
    progress: number;
    rows: number | null;
    error: string | null;
    file: string | null;
    url: string | null;
};

export type ExportButtonProps = {
    /** The export type: "assessments". */
    type: string;
    /** The dashboard filter in view, by the dashboard's URL names (ARCHITECTURE.md §8). */
    filter: Record<string, string | number | null>;
    /** Whether "Include assessor and source details" applies to this type. */
    offersPrivate: boolean;
};

/**
 * Export (Phase 6 step 0, D-96): admin pages and the admin preview bar only, never the public site
 * (D-80). Choose CSV or Excel and, where it applies, whether to include assessor and source
 * details; the export is queued, its progress followed (ProgressPanel), and a download link
 * offered when it is ready. The link works 30 minutes; the file is deleted after 7 days.
 */
export default function ExportButton({ type, filter, offersPrivate }: ExportButtonProps) {
    const t = useTranslate();
    const id = useId();
    const action = useApiAction<{ data: { export_id: number } }>();
    const [open, setOpen] = useState(false);
    const [format, setFormat] = useState<'csv' | 'xlsx'>('csv');
    const [includePrivate, setIncludePrivate] = useState(false);
    const [exportId, setExportId] = useState<number | null>(null);
    const [error, setError] = useState<string | null>(null);

    const start = async (event: FormEvent) => {
        event.preventDefault();
        setError(null);
        const query = Object.fromEntries(Object.entries(filter).filter(([, value]) => value !== null && value !== ''));
        const result = await action.run('POST', '/admin/api/exports', { type, format, include_private: offersPrivate && includePrivate, filter: query });
        if (result.ok) {
            setExportId(result.data.data.export_id);
        } else {
            setError(result.message);
        }
    };

    return (
        <div className="w-full">
            <button type="button" className="btn btn-secondary btn-sm" aria-expanded={open} aria-controls={`${id}-panel`} onClick={() => setOpen(!open)}>
                {t('admin.exports.button')}
            </button>
            {open && (
                <div id={`${id}-panel`} className="mt-2 max-w-xl rounded-md border border-rule bg-panel px-4 py-3 text-ink">
                    {exportId === null ? (
                        <form onSubmit={(event) => void start(event)} className="flex flex-col gap-3">
                            <fieldset>
                                <legend className="text-sm font-medium">{t('admin.exports.format')}</legend>
                                <div className="mt-1.5 flex gap-4">
                                    {(['csv', 'xlsx'] as const).map((value) => (
                                        <label key={value} className="inline-flex items-center gap-1.5 text-sm">
                                            <input type="radio" name={`${id}-format`} value={value} checked={format === value} onChange={() => setFormat(value)} />
                                            {t(`admin.exports.formats.${value}`)}
                                        </label>
                                    ))}
                                </div>
                            </fieldset>
                            {offersPrivate && (
                                <div>
                                    <label className="inline-flex items-start gap-2 text-sm">
                                        <input type="checkbox" className="mt-1" checked={includePrivate} aria-describedby={`${id}-private-help`} onChange={(event) => setIncludePrivate(event.target.checked)} />
                                        <span className="font-medium">{t('admin.exports.private')}</span>
                                    </label>
                                    <p id={`${id}-private-help`} className="mt-1 pl-6 text-xs text-ink-2">
                                        {t('admin.exports.private_help')}
                                    </p>
                                </div>
                            )}
                            {error !== null && (
                                <p role="alert" className="text-sm text-bad">
                                    {error}
                                </p>
                            )}
                            <div>
                                <button type="submit" className="btn btn-primary btn-sm" disabled={action.pending}>
                                    {action.pending ? t('admin.exports.starting') : t('admin.exports.start')}
                                </button>
                            </div>
                        </form>
                    ) : (
                        <ProgressPanel<{ data: ExportStatus }>
                            title={t('admin.exports.progress')}
                            url={`/admin/api/exports/${exportId}`}
                            stage={({ data }) => ({ key: data.status, label: data.status_label, terminal: data.status !== 'queued' && data.status !== 'running' })}
                        >
                            {({ data }) => (
                                <>
                                    {(data.status === 'queued' || data.status === 'running') && <p>{t('admin.exports.percent', { progress: String(data.progress) })}</p>}
                                    {data.status === 'done' && data.url !== null && (
                                        <p className="flex flex-col gap-1">
                                            <a href={data.url} className="font-medium text-deep underline underline-offset-2">
                                                {t('admin.exports.download', { file: data.file ?? '' })}
                                            </a>
                                            <span className="text-xs">{t('admin.exports.expiry')}</span>
                                        </p>
                                    )}
                                    {data.status === 'failed' && <p className="text-bad">{data.error}</p>}
                                </>
                            )}
                        </ProgressPanel>
                    )}
                </div>
            )}
        </div>
    );
}
