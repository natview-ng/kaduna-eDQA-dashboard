import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import ExportButton from '@/components/ExportButton';
import { axeCheck, renderUi, testTranslations } from '@/test/render';

// D-96: The ExportButton. Format, the privacy option where it applies, then the
// queued export followed to its download link.

const translations = {
    ...testTranslations,
    common: {
        ...(testTranslations.common as Record<string, unknown>),
        loading: 'Loading…',
        progress: { stage: ':label: :stage', failed: 'Could not get the progress.' },
        api: { error: 'Something went wrong.', conflict: 'Refused.', invalid: 'Check the fields.', forbidden: 'Not allowed.', too_many: 'Too many exports: wait a minute.' },
    },
    admin: {
        exports: {
            button: 'Export…', what: 'What to export', types: { facility_scores: 'Facility scores', item_responses: 'Item responses', lga_table: 'LGA table', backfill_report: 'Backfill report (Excel)', workplan: 'Implementation workplan (Excel)' }, format: 'Format', only_format: 'Format: :format',
            private_plan: 'Include private plan fields', private_plan_help: 'Adds each row\'s contact and notes.', formats: { csv: 'CSV', xlsx: 'Excel' },
            private: 'Include assessor and source details', private_help: 'Adds assessor names, device IDs, visit GPS, source links and attachment links.',
            start: 'Start export', starting: 'Starting…', progress: 'Export', percent: ':progress% done', download: 'Download :file', expiry: 'The link works for 30 minutes.',
        },
    },
};

const done = { id: 5, status: 'done', status_label: 'Ready', progress: 100, rows: 7, error: null, file: 'assessments-20261115-0930.csv', url: '/admin/exports/5/download?signature=abc' };

function stubFetch(post: { status: number; body: unknown }) {
    const fetch = vi.fn(async (url: string, init?: RequestInit) => {
        if (init?.method === 'POST') {
            return new Response(JSON.stringify(post.body), { status: post.status, headers: { 'Content-Type': 'application/json' } });
        }

        return new Response(JSON.stringify({ data: done }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    });
    vi.stubGlobal('fetch', fetch);

    return fetch;
}

afterEach(() => vi.unstubAllGlobals());

const filter = { year: 2026, quarter: 2, month: null, lga: 'ZAR', level: null, owner: null };

describe('ExportButton', () => {
    it('asks for the export with the format, the privacy option and the filter in view, then offers the download', async () => {
        const fetch = stubFetch({ status: 202, body: { data: { export_id: 5 } } });
        const { container } = renderUi(<ExportButton types={[{ key: 'item_responses', offersPrivate: true }]} filter={filter} />, translations);
        const toggle = screen.getByRole('button', { name: 'Export…' });

        await userEvent.click(toggle);
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        expect((screen.getByRole('radio', { name: 'CSV' }) as HTMLInputElement).checked).toBe(true);
        const option = screen.getByRole('checkbox', { name: 'Include assessor and source details' });
        expect((option as HTMLInputElement).checked).toBe(false);
        expect(option.getAttribute('aria-describedby')).toBeTruthy();
        expect(await axeCheck(container)).toHaveNoViolations();

        await userEvent.click(screen.getByRole('radio', { name: 'Excel' }));
        await userEvent.click(option);
        await userEvent.click(screen.getByRole('button', { name: 'Start export' }));

        const post = fetch.mock.calls.find(([, init]) => init?.method === 'POST');
        expect(post?.[0]).toBe('/admin/api/exports');
        expect(JSON.parse(String(post?.[1]?.body))).toEqual({ type: 'item_responses', format: 'xlsx', include_private: true, filter: { year: 2026, quarter: 2, lga: 'ZAR' } });

        const link = await screen.findByRole('link', { name: 'Download assessments-20261115-0930.csv' });
        expect(link.getAttribute('href')).toBe(done.url);
        expect(screen.getByText('The link works for 30 minutes.')).toBeTruthy();
        expect(screen.getByRole('region', { name: 'Export' }).textContent).toContain('Ready');
    });

    it('never sends the privacy option for a type that has none, and shows no checkbox', async () => {
        const fetch = stubFetch({ status: 202, body: { data: { export_id: 5 } } });
        renderUi(<ExportButton types={[{ key: 'lga_table', offersPrivate: false }]} filter={filter} />, translations);

        await userEvent.click(screen.getByRole('button', { name: 'Export…' }));
        expect(screen.queryByRole('checkbox')).toBeNull();
        await userEvent.click(screen.getByRole('button', { name: 'Start export' }));

        await waitFor(() => expect(fetch).toHaveBeenCalled());
        const post = fetch.mock.calls.find(([, init]) => init?.method === 'POST');
        expect(JSON.parse(String(post?.[1]?.body)).include_private).toBe(false);
    });

    it('offers a choice when a page has several exports, and the privacy option only for the type that has one', async () => {
        const fetch = stubFetch({ status: 202, body: { data: { export_id: 5 } } });
        renderUi(<ExportButton types={[{ key: 'facility_scores', offersPrivate: false }, { key: 'item_responses', offersPrivate: true }]} filter={filter} />, translations);

        await userEvent.click(screen.getByRole('button', { name: 'Export…' }));
        expect(screen.getByRole('group', { name: 'What to export' })).toBeTruthy();
        expect((screen.getByRole('radio', { name: 'Facility scores' }) as HTMLInputElement).checked).toBe(true);
        expect(screen.queryByRole('checkbox')).toBeNull();

        await userEvent.click(screen.getByRole('radio', { name: 'Item responses' }));
        expect(screen.getByRole('checkbox', { name: 'Include assessor and source details' })).toBeTruthy();
        await userEvent.click(screen.getByRole('radio', { name: 'Facility scores' }));
        await userEvent.click(screen.getByRole('button', { name: 'Start export' }));

        await waitFor(() => expect(fetch).toHaveBeenCalled());
        const post = fetch.mock.calls.find(([, init]) => init?.method === 'POST');
        expect(JSON.parse(String(post?.[1]?.body))).toMatchObject({ type: 'facility_scores', include_private: false });
    });

    it('offers only Excel for a workbook of several sheets, and sends it', async () => {
        const fetch = stubFetch({ status: 202, body: { data: { export_id: 5 } } });
        renderUi(<ExportButton types={[{ key: 'backfill_report', offersPrivate: false, formats: ['xlsx'] }]} filter={{ source: 'kobo' }} />, translations);

        await userEvent.click(screen.getByRole('button', { name: 'Export…' }));
        expect(screen.queryByRole('radio')).toBeNull();
        expect(screen.getByText('Format: Excel')).toBeTruthy();
        await userEvent.click(screen.getByRole('button', { name: 'Start export' }));

        await waitFor(() => expect(fetch).toHaveBeenCalled());
        const post = fetch.mock.calls.find(([, init]) => init?.method === 'POST');
        expect(JSON.parse(String(post?.[1]?.body))).toEqual({ type: 'backfill_report', format: 'xlsx', include_private: false, filter: { source: 'kobo' } });
    });

    it('words the privacy option the type\'s own way: the workplan\'s private plan fields', async () => {
        const fetch = stubFetch({ status: 202, body: { data: { export_id: 5 } } });
        renderUi(
            <ExportButton
                types={[{ key: 'workplan', offersPrivate: true, formats: ['xlsx'], privateLabel: 'admin.exports.private_plan', privateHelp: 'admin.exports.private_plan_help' }]}
                filter={{ round: 7 }}
            />,
            translations,
        );

        await userEvent.click(screen.getByRole('button', { name: 'Export…' }));
        expect(screen.queryByRole('checkbox', { name: 'Include assessor and source details' })).toBeNull();
        await userEvent.click(screen.getByRole('checkbox', { name: 'Include private plan fields' }));
        expect(screen.getByText('Adds each row\'s contact and notes.')).toBeTruthy();
        await userEvent.click(screen.getByRole('button', { name: 'Start export' }));

        await waitFor(() => expect(fetch).toHaveBeenCalled());
        const post = fetch.mock.calls.find(([, init]) => init?.method === 'POST');
        expect(JSON.parse(String(post?.[1]?.body))).toEqual({ type: 'workplan', format: 'xlsx', include_private: true, filter: { round: 7 } });
    });

    it('says why an export was refused, in words, and stays on the form', async () => {
        stubFetch({ status: 429, body: { message: 'Too Many Attempts.' } });
        renderUi(<ExportButton types={[{ key: 'item_responses', offersPrivate: true }]} filter={filter} />, translations);

        await userEvent.click(screen.getByRole('button', { name: 'Export…' }));
        await userEvent.click(screen.getByRole('button', { name: 'Start export' }));

        expect((await screen.findByRole('alert')).textContent).toBe('Too many exports: wait a minute.');
        expect(screen.getByRole('button', { name: 'Start export' })).toBeTruthy();
    });
});
