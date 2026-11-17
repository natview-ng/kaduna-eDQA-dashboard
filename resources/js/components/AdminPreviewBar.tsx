import { usePage } from '@inertiajs/react';
import ExportButton, { type ExportChoice } from '@/components/ExportButton';
import { useTranslate } from '@/lib/i18n';
import type { Filters } from '@/types/shell';

/** What each dashboard page exports: the figures it shows, from the same aggregators. */
const EXPORTS: Record<string, ExportChoice[]> = {
    // The round report: the round in view (year and quarter), else the latest closed round.
    'dashboard/home': [
        { key: 'state_summary', offersPrivate: false },
        { key: 'round_report', offersPrivate: false, formats: ['pdf'] },
    ],
    'dashboard/facility': [
        { key: 'facility_scores', offersPrivate: false },
        { key: 'item_responses', offersPrivate: true },
    ],
    'dashboard/lga': [{ key: 'lga_table', offersPrivate: false }],
    'dashboard/monitor': [{ key: 'state_summary', offersPrivate: false }],
    'dashboard/visuals': [
        { key: 'state_summary', offersPrivate: false },
        { key: 'lga_table', offersPrivate: false },
    ],
    'dashboard/plan': [
        { key: 'workplan', offersPrivate: true, formats: ['xlsx'], privateLabel: 'admin.exports.private_plan', privateHelp: 'admin.exports.private_plan_help' },
        { key: 'lga_table', offersPrivate: false },
    ],
};

/**
 * Shown above the public pages to a signed-in administrator only (ARCHITECTURE.md §9.0, D-77):
 * these figures include the open round, which visitors do not see. A labelled region before <main>,
 * with the way back to the admin area and the export of what the page shows (D-80:
 * exports exist in the admin area and here, never on the public site).
 */
export default function AdminPreviewBar() {
    const t = useTranslate();
    const page = usePage();
    const filters = (page.props as { filters?: Filters }).filters;
    const types = EXPORTS[page.component] ?? [];
    // The facility view's metric is in its URL (?metric=), as the export takes it.
    const metric = new URLSearchParams(page.url.split('?')[1] ?? '').get('metric');

    return (
        <section aria-label={t('admin.preview.label')} className="border-b border-warn-fill/40 bg-warn-bg px-4 py-2 text-sm text-ink rail:px-7">
            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                <p className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span className="font-semibold">{t('admin.preview.text')}</span>
                    <span aria-hidden="true">·</span>
                    <a href="/admin" className="font-medium text-deep underline underline-offset-2">
                        {t('admin.preview.back')}
                    </a>
                </p>
                {filters !== undefined && types.length > 0 && (
                    <div className="w-full sm:ml-auto sm:w-auto">
                        <ExportButton types={types} filter={{ ...filters, metric }} />
                    </div>
                )}
            </div>
        </section>
    );
}
