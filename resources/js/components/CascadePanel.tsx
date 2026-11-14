import { useState } from 'react';
import Panel from '@/components/Panel';
import PanelHeader from '@/components/PanelHeader';
import StateMark from '@/components/StateMark';
import { formatCount } from '@/lib/format';
import { useTranslate } from '@/lib/i18n';
import { useApiAction } from '@/lib/useApiAction';
import type { CascadeExport } from '@/types/admin';

export type CascadePanelProps = {
    /** docs/odk-cascade-media.md's link, or null to name the file instead. */
    howUrl: string | null;
    /** A master-list change has reached the form's lists since the last export (step 5). */
    outOfDate?: boolean;
    /** After a regeneration: the page reloads the out-of-date state. */
    onRegenerated?: () => void;
};

/**
 * "Regenerate facility media file" (ARCHITECTURE.md §5.1, §9.3, D-54): POST
 * /api/facility-list/export-cascade, then the counts and a signed download (30 minutes). The same
 * file is attached to the form on both platforms (ODK Central and KoboToolbox), by hand for now,
 * and the panel says so. On the Data sources and Facility list pages; while a master-list change
 * has not been exported it says the file is out of date (step 5).
 */
export default function CascadePanel({ howUrl, outOfDate = false, onRegenerated }: CascadePanelProps) {
    const t = useTranslate();
    const action = useApiAction<{ data: CascadeExport }>();
    const [result, setResult] = useState<CascadeExport | null>(null);

    const regenerate = async () => {
        const response = await action.run('POST', '/admin/api/facility-list/export-cascade');
        if (response.ok) {
            setResult(response.data.data);
            onRegenerated?.();
        }
    };

    return (
        <Panel labelledBy="sources-cascade-title">
            <PanelHeader id="sources-cascade-title" title={t('admin.sources.cascade.title')} description={t('admin.sources.cascade.description')} />
            <div className="flex flex-col gap-3 px-5 pb-5 text-sm">
                {/* Step 5: persistent until the file is regenerated (CascadeStatusQuery). */}
                {outOfDate && (
                    <p className="notice notice-warn flex items-start gap-2 text-ink">
                        <StateMark mark="▲" className="mt-1 text-warn-fill" />
                        <span>{t('admin.sources.cascade.out_of_date')}</span>
                    </p>
                )}
                <p>
                    {howUrl === null ? (
                        t('admin.sources.cascade.how_file')
                    ) : (
                        <a href={howUrl} className="font-medium text-deep underline" target="_blank" rel="noreferrer">
                            {t('admin.sources.cascade.how')}
                        </a>
                    )}
                </p>
                <div>
                    <button
                        type="button"
                        onClick={() => void regenerate()}
                        disabled={action.pending}
                        className="btn btn-secondary min-h-11"
                    >
                        {action.pending ? t('common.ui.working') : t('admin.sources.cascade.button')}
                    </button>
                </div>
                {action.error !== null && (
                    <p role="alert" className="notice notice-bad text-bad">
                        {action.error}
                    </p>
                )}
                {result !== null && (
                    <div role="status" className="notice notice-ok">
                        <p className="text-ok">
                            {t('admin.sources.cascade.ready', { lgas: formatCount(result.lgas), wards: formatCount(result.wards), facilities: formatCount(result.facilities) })}
                        </p>
                        <a href={result.url} className="font-medium text-deep underline">
                            {t('admin.sources.cascade.download', { file: result.filename })}
                        </a>
                        <span className="block text-xs text-ink-2">{t('admin.sources.cascade.expiry')}</span>
                    </div>
                )}
                {result !== null && (
                    <div>
                        <h3 id="sources-cascade-steps" className="mb-1.5 text-base font-semibold">
                            {t('admin.sources.cascade.steps_label')}
                        </h3>
                        <ol aria-labelledby="sources-cascade-steps" className="flex list-decimal flex-col gap-1 pl-5">
                            <li>{t('admin.sources.cascade.steps.download')}</li>
                            <li>{t('admin.sources.cascade.steps.odk')}</li>
                            <li>{t('admin.sources.cascade.steps.kobo')}</li>
                        </ol>
                    </div>
                )}
            </div>
        </Panel>
    );
}
