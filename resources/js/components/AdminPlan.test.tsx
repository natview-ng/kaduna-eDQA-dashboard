import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { AdminPlanRow } from '@/components/PlanRowEditor';
import AdminPlan, { type AdminPlanProps } from '@/pages/admin/plan';
import { axeCheck, renderUi, testTranslations } from '@/test/render';

// Phase 6 step 3: the admin implementation plan, keyboard first and axe clean.

const routerReload = vi.fn();
const routerGet = vi.fn();
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();

    return { ...actual, router: { ...actual.router, reload: (...args: unknown[]) => routerReload(...args), get: (...args: unknown[]) => routerGet(...args) } };
});

const translations = {
    ...testTranslations,
    common: {
        ...(testTranslations.common as Record<string, unknown>),
        api: { error: 'Something went wrong.', conflict: 'Refused.', invalid: 'Check the fields.', forbidden: 'Not allowed.', too_many: 'Too many requests.' },
    },
    enums: { plan_action_status: { not_started: 'Not started', in_progress: 'In progress', done: 'Done' } },
    admin: {
        plan: {
            round: 'Round', round_status: { open: 'Open — the plan can be drafted now', closed: 'Closed — plans stay editable' }, none: 'No round yet.',
            threshold: 'LGAs with an overall below :band (:value) get an action.', generate: 'Generate plan for this round', generating: 'Generating…',
            generate_help: ':count LGA(s) have a proposed action that is not saved yet.', generated: ':count plan row(s) created.', generated_none: 'Every LGA already has a saved row.',
            empty: 'No LGA is below the threshold.',
            table: { caption: 'Implementation plan for :round', lga: 'LGA', overall: 'Overall', weakest: 'Weakest dimension', action: 'Recommended action', follow_up: 'Follow-up' },
            unsaved: 'Proposed: generate the plan to edit this row.', owner: 'Owner', owner_for: 'Owner for :lga', other: 'Other role…', other_label: 'Other role or unit',
            owner_hint: 'A role or unit, not a person\'s name. This shows on the public plan.', contact: 'Contact (admin only)', contact_hint: 'Optional. Never shown publicly.',
            review: 'Review date', review_past: 'This review date has passed.', status: 'Status', notes: 'Notes (admin only)', save: 'Save', saving: 'Saving…', saved: 'Saved: :lga, :dimension.',
            summary: { title: 'Actions by type', caption: 'Actions by type and status', type: 'Type', total: 'Actions' },
            meanings: { title: 'What each action means', default_owner: 'Usually owned by: :owner' },
        },
    },
};

const score = (value: number, display: string) => ({ value, display, headline: display.split('.')[0] ?? display, band: 'review' as const, bandLabel: 'Review' });
const row = (overrides: Partial<AdminPlanRow> = {}): AdminPlanRow => ({
    key: '2-availability', id: 41, lga: { id: 2, code: 'BBB', name: 'Bravo' }, overall: score(76, '76.0'), dimension: 'availability', dimension_label: 'Availability',
    score: score(70, '70.0'), action: 'Restock HMIS tools', owner: 'LGA M&E officer', owner_role: 'lga_me_officer', owner_role_other: null,
    owner_contact: null, due_date: '2026-12-16', status: 'not_started', notes: null, ...overrides,
});
const props = (rows: AdminPlanRow[], unsaved = 0): AdminPlanProps => ({
    rounds: [{ id: 7, label: 'Q2 2026', status: 'open' }, { id: 6, label: 'Q1 2026', status: 'closed' }],
    round: { id: 7, label: 'Q2 2026', status: 'open' },
    plan: {
        threshold: { band: 'acceptable', label: 'Acceptable', value: '80' },
        rows,
        unsaved,
        summary: [{ dimension: 'availability', dimension_label: 'Availability', action: 'Restock HMIS tools', meaning: 'The register was not there.', owner: 'LGA M&E officer', count: 1, statuses: { not_started: 0, in_progress: 1, done: 0 } }],
    },
    roles: [{ key: 'lga_me_officer', label: 'LGA M&E officer' }, { key: 'state_hmis_team', label: 'State HMIS team' }],
    today: '2026-11-16',
});

function stubFetch(status: number, body: unknown) {
    const fetch = vi.fn(async () => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }));
    vi.stubGlobal('fetch', fetch);

    return fetch;
}

afterEach(() => {
    vi.unstubAllGlobals();
    routerReload.mockReset();
    routerGet.mockReset();
});

describe('AdminPlan', () => {
    it('lists the rows with their follow-up, the summary by status and what each action means; axe clean', async () => {
        const { container } = renderUi(<AdminPlan {...props([row(), row({ key: '1-consistency', id: null, lga: { id: 1, code: 'AAA', name: 'Alpha' }, dimension: 'consistency', dimension_label: 'Consistency' })], 1)} />, translations);

        expect(screen.getByRole('heading', { name: 'Implementation plan for Q2 2026' })).toBeTruthy();
        const form = screen.getByRole('form', { name: 'Owner for Bravo, Availability' });
        expect((within(form).getByLabelText('Owner') as HTMLSelectElement).value).toBe('lga_me_officer');
        expect(within(form).getByLabelText('Contact (admin only)')).toBeTruthy();
        expect(within(form).getByText('A role or unit, not a person\'s name. This shows on the public plan.')).toBeTruthy();
        // A proposed row cannot be edited until the plan is generated.
        expect(screen.queryByRole('form', { name: 'Owner for Alpha, Consistency' })).toBeNull();
        expect(screen.getByText(/Proposed: generate the plan to edit this row\./)).toBeTruthy();
        const summary = screen.getByRole('table', { name: 'Actions by type and status' });
        expect(within(summary).getByRole('row', { name: /Availability/ }).textContent).toBe('Availability1010');
        expect(screen.getByText('Usually owned by: LGA M&E officer')).toBeTruthy();
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('edits a row from the keyboard alone: "Other role…" written out, a status, then Save sends only what changed', async () => {
        const fetch = stubFetch(200, { data: { id: 41, owner_role: 'other', owner_role_other: 'Zonal M&E unit', owner_contact: null, due_date: '2026-12-16', status: 'in_progress', notes: null } });
        renderUi(<AdminPlan {...props([row()])} />, translations);
        const user = userEvent.setup();
        const form = screen.getByRole('form', { name: 'Owner for Bravo, Availability' });

        within(form).getByLabelText('Owner').focus();
        await user.keyboard('{End}'); // the last option: Other role…
        await user.selectOptions(within(form).getByLabelText('Owner'), 'other');
        await user.tab();
        expect(document.activeElement).toBe(within(form).getByLabelText('Other role or unit'));
        await user.keyboard('Zonal M&E unit');
        within(form).getByLabelText('Status').focus();
        await user.selectOptions(within(form).getByLabelText('Status'), 'in_progress');
        within(form).getByRole('button', { name: 'Save' }).focus();
        await user.keyboard('{Enter}');

        await waitFor(() => expect(within(form).getByRole('status').textContent).toBe('Saved: Bravo, Availability.'));
        const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('/admin/api/plan-actions/41');
        expect(init.method).toBe('PATCH');
        expect(JSON.parse(String(init.body))).toEqual({ owner_role: 'other', owner_role_other: 'Zonal M&E unit', status: 'in_progress' });
    });

    it('warns in words when a review date has passed, and shows the server\'s refusal beside the field', async () => {
        stubFetch(422, { message: 'Invalid.', errors: { due_date: ['Choose a review date on or after today.'] } });
        renderUi(<AdminPlan {...props([row({ due_date: '2026-05-01' })])} />, translations);
        const form = screen.getByRole('form', { name: 'Owner for Bravo, Availability' });

        expect(within(form).getByText('This review date has passed.')).toBeTruthy();
        expect(within(form).getByLabelText('Review date').getAttribute('aria-describedby')).toContain('review-past');
        // A passed date left as it is does not stop the other fields being saved: no min until it changes.
        expect(within(form).getByLabelText('Review date').getAttribute('min')).toBeNull();

        fireEvent.change(within(form).getByLabelText('Review date'), { target: { value: '2026-11-15' } });
        expect(within(form).getByLabelText('Review date').getAttribute('min')).toBe('2026-11-16');
        await userEvent.click(within(form).getByRole('button', { name: 'Save' }));
        expect(await within(form).findByText('Choose a review date on or after today.')).toBeTruthy();
    });

    it('generates the plan for the round and reloads it', async () => {
        const fetch = stubFetch(200, { data: { created: 1 } });
        renderUi(<AdminPlan {...props([row({ id: null })], 1)} />, translations);

        screen.getByRole('button', { name: 'Generate plan for this round' }).focus();
        await userEvent.keyboard('{Enter}');

        await waitFor(() => expect(screen.getByRole('status').textContent).toBe('1 plan row(s) created.'));
        expect((fetch.mock.calls[0] as unknown as [string, RequestInit])[0]).toBe('/admin/api/plan/7/generate');
        expect(routerReload).toHaveBeenCalledWith({ only: ['plan'] });
    });

    it('changes round through the URL', async () => {
        renderUi(<AdminPlan {...props([row()])} />, translations);
        await userEvent.selectOptions(screen.getByLabelText('Round'), '6');
        expect(routerGet).toHaveBeenCalledWith('/admin/plan', { round: '6' }, expect.anything());
    });
});
