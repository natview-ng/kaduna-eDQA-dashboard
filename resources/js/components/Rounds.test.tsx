import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import CloseRoundPanel from '@/components/CloseRoundPanel';
import CreateRoundForm from '@/components/CreateRoundForm';
import RoundsTable from '@/components/RoundsTable';
import { nextQuarter, quarterDates, windowErrorField } from '@/lib/rounds';
import AdminRounds from '@/pages/admin/rounds';
import { axeCheck, renderUi, testTranslations } from '@/test/render';
import type { RoundRow } from '@/types/admin';

const routerReload = vi.fn();
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();

    return { ...actual, router: { ...actual.router, reload: (...args: unknown[]) => routerReload(...args) } };
});

// The keys these components read, as lang/en/admin.php has them (step 4).
const translations = {
    ...testTranslations,
    common: {
        ...(testTranslations.common as Record<string, unknown>),
        months_short: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        api: { error: 'Something went wrong.', conflict: 'Refused.', invalid: 'Check the fields.', forbidden: 'Not allowed.', too_many: 'Too many requests.' },
        progress: { stage: ':label: :stage', failed: 'Could not get the progress.' },
        reason: { label: 'Reason', hint: 'At least :min characters.', remaining: ':count more characters needed.' },
        ui: { ...((testTranslations.common as Record<string, Record<string, string>>).ui ?? {}), cancel: 'Cancel', working: 'Working…' },
        error_summary_title: 'There is a problem',
    },
    admin: {
        public_impact: { label: 'Public dashboard:', text: 'this changes what the public dashboard shows.' },
        sources: { pull: { stages: { running: 'Pulling', succeeded: 'Finished', failed: 'Failed' }, counters: 'Progress so far', fetched: 'Fetched', parsed: 'Parsed', accepted: 'Accepted', flagged: 'Flagged', quarantined: 'Quarantined', deletions_detected: 'Deleted at source' } },
        rounds: {
            table: {
                title: 'Rounds', description: 'Newest first.', caption: 'Assessment rounds', round: 'Round', status: 'Status', accepted: 'Accepted assessments', issues: 'Open issues',
                version: 'Rule version', closed: 'Closed', reopened: 'Last reopened because', actions: 'Actions', window: ':start to :end', none: 'No round yet.',
                by_source: ':source :count', no_issues: 'None', quarantine: ':count in quarantine', deletions: ':count deleted at source', cross_source: ':count duplicate(s) across sources',
                review_issues: 'Review in Data issues', review_issues_sr: 'for :round', pinned: 'v:version, fixed at close', reads: 'Reads v:version', no_version: 'No version published',
                closed_by: 'by :name', not_closed: 'Not closed',
            },
            status: { open: 'Open — not public', closed: 'Closed — public' },
            actions: { close: 'Close…', reopen: 'Reopen…', edit_window: 'Edit window', rescore: 'Rescore…', for: ':round' },
            create: {
                title: 'Create a round', description: 'A round takes the visits in its window.', year: 'Year', quarter: 'Quarter', quarter_option: 'Q:quarter',
                window_start: 'Window starts', window_end: 'Window ends', grace_hint: 'Up to :days days after the quarter.', submit: 'Create round', submitting: 'Creating…', created: ':round created.',
            },
            close: {
                title: 'Close :round', intro: 'Check what is open first.', issues_title: 'Still open in :round', issues_none: 'Nothing is open in this round.',
                issues_allowed: 'You can close with open issues.', reconcile_title: 'Catch late edits first', reconcile_help: 'Reconcile compares this round with each source.',
                reconcile_all: 'Reconcile all sources now', reconcile_starting: 'Starting…', reconcile_none: 'No source is switched on.', reconcile_progress: 'Reconcile :source',
                consequence: "Closing publishes this round's figures to the public dashboard. Later submissions from either source will be held under ROUND_WINDOW.",
                consequence_issues: 'The open issues listed stay open after closing.', button: 'Close round…', confirm: 'Close and publish', cancel: 'Cancel', done: ':round is closed and public.',
            },
            reopen: { title: 'Reopen :round', consequence: 'Reopening removes this round from the public dashboard until it is closed again.', confirm: 'Reopen', done: ':round is open again.', rescoring: 'Rescoring.' },
            window: { title: 'Edit the window of :round', save: 'Save window…', cancel: 'Cancel', confirm_title: 'Change the window of :round', consequence: 'From :start to :end.', confirm: 'Save window', done: 'Window of :round saved.' },
            rescore: { title: 'Rescore :round', consequence: 'Scored again.', confirm: 'Rescore', progress: 'Rescore :round', stages: { running: 'Rescoring', succeeded: 'Finished', failed: 'Failed' }, processed: 'Assessments scored', changed: 'Scores changed' },
        },
    },
};

const round = (overrides: Partial<RoundRow> = {}): RoundRow => ({
    id: 2,
    year: 2026,
    quarter: 3,
    label: 'Q3 2026',
    window_start: '2026-07-01',
    window_end: '2026-09-30',
    status: 'open',
    closed_at: null,
    closed_by: null,
    rule_version: null,
    reads_rule_version: 1,
    reopened_reason: null,
    assessments: { total: 6, by_source: [{ key: 'kobo', name: 'KoboToolbox', count: 5 }, { key: 'odk', name: 'ODK Central', count: 1 }] },
    issues: { quarantine: 2, deletions: 1, cross_source: 0 },
    ...overrides,
});
const closedQ2 = round({ id: 1, quarter: 2, label: 'Q2 2026', window_start: '2026-04-01', window_end: '2026-06-30', status: 'closed', closed_at: '2026-07-12T14:30:00Z', closed_by: 'Hauwa Bello', rule_version: 1, assessments: { total: 0, by_source: [] }, issues: { quarantine: 0, deletions: 0, cross_source: 0 } });
const sources = [
    { key: 'odk', name: 'ODK Central', running_reconcile_run_id: null },
    { key: 'kobo', name: 'KoboToolbox', running_reconcile_run_id: null },
];

function stubFetch(handler: (url: string, init?: RequestInit) => { status?: number; body: unknown }) {
    const fetch = vi.fn(async (url: string, init?: RequestInit) => {
        const { status = 200, body } = handler(url, init);

        return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
    });
    vi.stubGlobal('fetch', fetch);

    return fetch;
}

afterEach(() => {
    vi.unstubAllGlobals();
    routerReload.mockReset();
});

describe('lib/rounds', () => {
    it("starts a window on the quarter's dates and offers the quarter after the newest round", () => {
        expect(quarterDates(2026, 4)).toEqual({ start: '2026-10-01', end: '2026-12-31' });
        expect(nextQuarter([{ year: 2026, quarter: 4 }], new Date('2026-11-14'))).toEqual({ year: 2027, quarter: 1 });
        expect(nextQuarter([], new Date('2026-11-14T12:00:00'))).toEqual({ year: 2026, quarter: 4 });
    });

    it('puts each refusal on the field it concerns', () => {
        const q3 = quarterDates(2026, 3);
        expect(windowErrorField('round_exists', '2026-07-01', q3)).toBe('quarter');
        expect(windowErrorField('window_overlap', '2026-07-01', q3)).toBe('window_start');
        expect(windowErrorField('window_outside_quarter', '2026-06-20', q3)).toBe('window_start');
        expect(windowErrorField('window_outside_quarter', '2026-07-01', q3)).toBe('window_end');
        expect(windowErrorField('window_order', '2026-07-01', q3)).toBe('window_end');
    });
});

describe('RoundsTable', () => {
    it('says each status in words, splits accepted assessments by source and links the open issues', async () => {
        const { container } = renderUi(<RoundsTable rounds={[round(), closedQ2]} onAction={() => undefined} busy={null} />, translations);

        const [, open, closed] = screen.getAllByRole('row');
        expect(open?.textContent).toContain('Open — not public');
        expect(open?.textContent).toContain('KoboToolbox 5');
        expect(open?.textContent).toContain('2 in quarantine');
        expect(open?.textContent).toContain('1 deleted at source');
        expect(open?.textContent).toContain('Reads v1');
        expect(within(open as HTMLElement).getByRole('link', { name: 'Review in Data issues for Q3 2026' }).getAttribute('href')).toBe('/admin/data-issues?round=2');
        expect(within(open as HTMLElement).getByRole('button', { name: 'Close… Q3 2026' })).toBeTruthy();

        expect(closed?.textContent).toContain('Closed — public');
        expect(closed?.textContent).toContain('v1, fixed at close');
        expect(closed?.textContent).toContain('by Hauwa Bello');
        expect(within(closed as HTMLElement).queryByRole('button', { name: /Close…/ })).toBeNull();
        expect(within(closed as HTMLElement).getByRole('button', { name: 'Reopen… Q2 2026' })).toBeTruthy();
        expect(await axeCheck(container)).toHaveNoViolations();
    });
});

describe('CreateRoundForm', () => {
    it("starts the window on the quarter's dates, follows the quarter, and is usable from the keyboard alone", async () => {
        const fetch = stubFetch(() => ({ status: 201, body: { data: { label: 'Q4 2026' }, warnings: [] } }));
        const onCreated = vi.fn();
        const { container } = renderUi(<CreateRoundForm graceDays={45} initial={{ year: 2026, quarter: 3 }} onCreated={onCreated} />, translations);
        const user = userEvent.setup();

        expect((screen.getByLabelText('Window starts') as HTMLInputElement).value).toBe('2026-07-01');
        await user.tab(); // year
        await user.tab(); // quarter
        expect(document.activeElement).toBe(screen.getByLabelText('Quarter'));
        await user.selectOptions(screen.getByLabelText('Quarter'), '4');
        expect((screen.getByLabelText('Window starts') as HTMLInputElement).value).toBe('2026-10-01');
        expect((screen.getByLabelText('Window ends') as HTMLInputElement).value).toBe('2026-12-31');

        await user.tab();
        await user.tab();
        await user.tab(); // the submit button
        expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Create round' }));
        await user.keyboard('{Enter}');

        await waitFor(() => expect(onCreated).toHaveBeenCalledWith({ label: 'Q4 2026' }, []));
        expect(JSON.parse(String((fetch.mock.calls[0] as unknown as [string, RequestInit])[1].body))).toEqual({ year: 2026, quarter: 4, window_start: '2026-10-01', window_end: '2026-12-31' });
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('shows an overlap as an error on the start date, summarised and linked', async () => {
        stubFetch(() => ({ status: 409, body: { code: 'window_overlap', message: 'The window overlaps Q2 2026.' } }));
        const { container } = renderUi(<CreateRoundForm graceDays={45} initial={{ year: 2026, quarter: 3 }} onCreated={() => undefined} />, translations);

        await userEvent.click(screen.getByRole('button', { name: 'Create round' }));

        const summary = await screen.findByRole('alert');
        expect(within(summary).getByRole('link', { name: 'The window overlaps Q2 2026.' }).getAttribute('href')).toBe('#create-round-start');
        const start = screen.getByLabelText('Window starts');
        expect(start.getAttribute('aria-invalid')).toBe('true');
        expect(document.getElementById(start.getAttribute('aria-describedby')?.split(' ')[0] ?? '')?.textContent).toBe('The window overlaps Q2 2026.');
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('shows the late-visit grace refusal on the end date', async () => {
        stubFetch(() => ({ status: 409, body: { code: 'window_outside_quarter', message: 'End by 2026-11-14.' } }));
        renderUi(<CreateRoundForm graceDays={45} initial={{ year: 2026, quarter: 3 }} onCreated={() => undefined} />, translations);

        await userEvent.click(screen.getByRole('button', { name: 'Create round' }));

        await waitFor(() => expect(screen.getByLabelText('Window ends').getAttribute('aria-invalid')).toBe('true'));
        expect(screen.getByLabelText('Window starts').getAttribute('aria-invalid')).toBeNull();
    });
});

describe('CloseRoundPanel', () => {
    it('reconciles every source, follows each run, then offers the close: keyboard only', async () => {
        const fetch = stubFetch((url) => {
            if (url.endsWith('/odk/reconcile')) {
                return { status: 202, body: { run_id: 11, already_running: false } };
            }
            if (url.endsWith('/kobo/reconcile')) {
                return { status: 202, body: { run_id: 12, already_running: false } };
            }

            return { body: { data: { run_id: Number(url.split('/').pop()), source: 'odk', status: 'succeeded', fetched: 3, parsed: 3, accepted: 2, flagged: 0, quarantined: 1, duplicates: 0, cross_source_duplicates: 0, deletions_detected: 0 } } };
        });
        const onReconciled = vi.fn();
        const onClose = vi.fn();
        const { container } = renderUi(<CloseRoundPanel round={round()} sources={sources} onClose={onClose} onCancel={() => undefined} onReconciled={onReconciled} />, translations);
        const user = userEvent.setup();

        expect(document.activeElement).toBe(screen.getByRole('heading', { name: 'Close Q3 2026' }));
        expect(screen.getByText('2 in quarantine')).toBeTruthy();
        expect(screen.getByText('You can close with open issues.')).toBeTruthy();

        await user.tab(); // Review in Data issues
        await user.tab(); // Reconcile all sources now
        expect(document.activeElement).toBe(screen.getByRole('button', { name: 'Reconcile all sources now' }));
        await user.keyboard('{Enter}');

        await waitFor(() => expect(onReconciled).toHaveBeenCalledTimes(2));
        expect(fetch.mock.calls.map(([url]) => url)).toEqual(expect.arrayContaining(['/admin/api/sources/odk/reconcile', '/admin/api/sources/kobo/reconcile', '/admin/api/pulls/11', '/admin/api/pulls/12']));
        expect(screen.getByText(/Closing publishes this round's figures/)).toBeTruthy();

        const close = screen.getByRole('button', { name: 'Close round…' });
        close.focus();
        await user.keyboard('{Enter}');
        expect(onClose).toHaveBeenCalled();
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('says when nothing is open and when no source can be reconciled', () => {
        renderUi(<CloseRoundPanel round={round({ issues: { quarantine: 0, deletions: 0, cross_source: 0 } })} sources={[]} onClose={() => undefined} onCancel={() => undefined} onReconciled={() => undefined} />, translations);

        expect(screen.getByText('Nothing is open in this round.')).toBeTruthy();
        expect(screen.getByText('No source is switched on.')).toBeTruthy();
        expect(screen.queryByRole('button', { name: 'Reconcile all sources now' })).toBeNull();
    });
});

describe('AdminRounds', () => {
    it('closes with a reason through the dialog, which states what closing publishes and the public impact', async () => {
        const fetch = stubFetch(() => ({ body: { data: { label: 'Q3 2026', window_start: '2026-07-01', window_end: '2026-09-30' } } }));
        const { container } = renderUi(<AdminRounds rounds={[round(), closedQ2]} sources={[]} graceDays={45} />, translations);
        const user = userEvent.setup();

        await user.click(screen.getByRole('button', { name: 'Close… Q3 2026' }));
        await user.click(screen.getByRole('button', { name: 'Close round…' }));

        const dialog = screen.getByRole('dialog', { name: 'Close Q3 2026' });
        expect(dialog.textContent).toContain("Closing publishes this round's figures to the public dashboard. Later submissions from either source will be held under ROUND_WINDOW.");
        expect(dialog.textContent).toContain('The open issues listed stay open after closing.');
        expect(dialog.textContent).toContain('this changes what the public dashboard shows.');
        await user.type(within(dialog).getByRole('textbox', { name: 'Reason' }), 'Every LGA has reported');
        await user.click(within(dialog).getByRole('button', { name: 'Close and publish' }));

        await waitFor(() => expect(screen.getByRole('status').textContent).toBe('Q3 2026 is closed and public.'));
        const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('/admin/api/rounds/2/close');
        expect(JSON.parse(String(init.body))).toEqual({ reason: 'Every LGA has reported' });
        expect(routerReload).toHaveBeenCalledWith({ only: ['rounds', 'sources', 'admin', 'shell'] });
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('reopens with a reason, saying it leaves the public dashboard', async () => {
        const fetch = stubFetch(() => ({ body: { data: { label: 'Q2 2026' }, rescore: null } }));
        renderUi(<AdminRounds rounds={[round(), closedQ2]} sources={[]} graceDays={45} />, translations);
        const user = userEvent.setup();

        await user.click(screen.getByRole('button', { name: 'Reopen… Q2 2026' }));
        const dialog = screen.getByRole('dialog', { name: 'Reopen Q2 2026' });
        expect(dialog.textContent).toContain('Reopening removes this round from the public dashboard until it is closed again.');
        expect(dialog.textContent).toContain('this changes what the public dashboard shows.');
        await user.type(within(dialog).getByRole('textbox', { name: 'Reason' }), 'A late batch from Ikara');
        await user.click(within(dialog).getByRole('button', { name: 'Reopen' }));

        await waitFor(() => expect(screen.getByRole('status').textContent).toBe('Q2 2026 is open again.'));
        expect(fetch.mock.calls[0]?.[0]).toBe('/admin/api/rounds/1/reopen');
    });
});
