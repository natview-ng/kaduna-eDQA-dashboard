import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import RoundReportPanel from '@/components/RoundReportPanel';
import { axeCheck, renderUi, testTranslations } from '@/test/render';
import type { RoundRow } from '@/types/admin';

// D-101: The PDF round report from the Rounds page. The latest closed round
// first, a draft warning for an open one, then the export plumbing asked for a PDF of that round.

const translations = {
    ...testTranslations,
    common: {
        ...(testTranslations.common as Record<string, unknown>),
        progress: { stage: ':label: :stage', failed: 'Could not get the progress.' },
        api: { error: 'Something went wrong.', conflict: 'Refused.', invalid: 'Check the fields.', forbidden: 'Not allowed.', too_many: 'Too many exports: wait a minute.' },
    },
    admin: {
        rounds: {
            report: {
                title: 'Round report (PDF)', description: 'A printable report of one round.', round: 'Round', open_option: ':round (open: draft)',
                draft: ':round is not closed. Its report is a draft: every page is marked "DRAFT — round not closed" and the file name says DRAFT.', none: 'No round yet.',
            },
        },
        exports: {
            button: 'Export…', what: 'What to export', types: { round_report: 'Round report (PDF)' }, format: 'Format', only_format: 'Format: :format', formats: { csv: 'CSV', xlsx: 'Excel', pdf: 'PDF' },
            start: 'Start export', starting: 'Starting…', progress: 'Export', percent: ':progress% done', download: 'Download :file', expiry: 'The link works for 30 minutes.',
        },
    },
};

function round(id: number, year: number, quarter: number, status: RoundRow['status']): RoundRow {
    return {
        id, year, quarter, label: `Q${quarter} ${year}`, window_start: `${year}-01-01`, window_end: `${year}-03-31`, status,
        closed_at: status === 'closed' ? `${year}-04-10T09:00:00+01:00` : null, closed_by: null, rule_version: status === 'closed' ? 1 : null, reads_rule_version: 1,
        reopened_reason: null, assessments: { total: 0, by_source: [] }, issues: { quarantine: 0, deletions: 0, cross_source: 0 },
    };
}

// Newest first, as the page lists them: Q3 is open, Q2 the latest closed.
const rounds = [round(3, 2026, 3, 'open'), round(2, 2026, 2, 'closed'), round(1, 2026, 1, 'closed')];

function stubFetch() {
    const fetch = vi.fn(async (_url: string, init?: RequestInit) =>
        init?.method === 'POST'
            ? new Response(JSON.stringify({ data: { export_id: 9 } }), { status: 202, headers: { 'Content-Type': 'application/json' } })
            : new Response(JSON.stringify({ data: { id: 9, status: 'queued', status_label: 'Waiting to start', progress: 0, rows: null, error: null, file: null, url: null } }), {
                  status: 200,
                  headers: { 'Content-Type': 'application/json' },
              }),
    );
    vi.stubGlobal('fetch', fetch);

    return fetch;
}

afterEach(() => vi.unstubAllGlobals());

describe('RoundReportPanel', () => {
    it('starts on the latest closed round and asks for its PDF', async () => {
        const fetch = stubFetch();
        const { container } = renderUi(<RoundReportPanel rounds={rounds} />, translations);

        const select = screen.getByRole('combobox', { name: 'Round' }) as HTMLSelectElement;
        expect(select.value).toBe('2');
        expect(screen.getByRole('option', { name: 'Q3 2026 (open: draft)' })).toBeTruthy();
        expect(screen.queryByText(/is not closed/)).toBeNull();
        expect(await axeCheck(container)).toHaveNoViolations();

        await userEvent.click(screen.getByRole('button', { name: 'Export…' }));
        expect(screen.getByText('Format: PDF')).toBeTruthy();
        await userEvent.click(screen.getByRole('button', { name: 'Start export' }));

        await waitFor(() => expect(fetch).toHaveBeenCalled());
        const post = fetch.mock.calls.find(([, init]) => init?.method === 'POST');
        expect(JSON.parse(String(post?.[1]?.body))).toEqual({ type: 'round_report', format: 'pdf', include_private: false, filter: { round: 2 } });
    });

    it('says an open round\'s report is a draft before it is asked for, and sends that round', async () => {
        const fetch = stubFetch();
        renderUi(<RoundReportPanel rounds={rounds} />, translations);

        await userEvent.selectOptions(screen.getByRole('combobox', { name: 'Round' }), '3');
        expect(screen.getByText(/Q3 2026 is not closed\. Its report is a draft/)).toBeTruthy();

        await userEvent.click(screen.getByRole('button', { name: 'Export…' }));
        await userEvent.click(screen.getByRole('button', { name: 'Start export' }));
        await waitFor(() => expect(fetch).toHaveBeenCalled());
        const post = fetch.mock.calls.find(([, init]) => init?.method === 'POST');
        expect(JSON.parse(String(post?.[1]?.body)).filter).toEqual({ round: 3 });
    });

    it('says so when there is no round', () => {
        renderUi(<RoundReportPanel rounds={[]} />, translations);

        expect(screen.getByText('No round yet.')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Export…' })).toBeNull();
    });
});
