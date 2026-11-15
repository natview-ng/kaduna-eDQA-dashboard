import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import AuditLogTable, { auditChanges, auditValue, type AuditEntry } from '@/components/AuditLogTable';
import Account from '@/pages/admin/account';
import AdminAudit from '@/pages/admin/audit';
import { axeCheck, renderUi, testTranslations } from '@/test/render';
import type { Paginated } from '@/types/admin';

const routerGet = vi.fn();
const routerReload = vi.fn();
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();

    return { ...actual, router: { ...actual.router, get: (...args: unknown[]) => routerGet(...args), reload: (...args: unknown[]) => routerReload(...args) } };
});

// The keys these pages read, as lang/en/admin.php and auth.php have them (step 7).
const translations = {
    ...testTranslations,
    common: {
        ...(testTranslations.common as Record<string, unknown>),
        months_short: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        api: { error: 'Something went wrong.', conflict: 'Refused.', invalid: 'Check the fields.', forbidden: 'Not allowed.', too_many: 'Too many requests.' },
        error_summary_title: 'There is a problem',
    },
    auth: {
        settings: { password_title: 'Change password', password_submit: 'Save password' },
        fields: { current_password: 'Current password', new_password: 'New password', confirm_password: 'Confirm new password' },
        reset: { rules: 'At least 14 characters, with upper and lower case, a number and a symbol.' },
    },
    admin: {
        audit: {
            read_only: 'The audit log is read only.',
            filters: { label: 'Filter the audit log', actor: 'Who', type: 'Subject', source: 'Source', event: 'Event', from: 'From', to: 'To', search: 'Reason contains', all: 'All', clear: 'Clear filters', announce: ':total entr(ies) found.' },
            types: { assessment: 'Assessment', quarantine: 'Quarantine', facility: 'Facility or ward', round: 'Round', rule_version: 'Rule version', user: 'Administrator', auth: 'Sign-in', source: 'Source' },
            table: {
                title: 'Entries', caption: 'Audit log entries', when: 'When (WAT)', who: 'Who', what: 'What', subject: 'Subject', reason: 'Reason', details: 'Before and after', show: 'Show changes', hide: 'Hide changes',
                for: 'for entry :id', console: 'Console', system: 'System', sign_in: 'Sign-in', no_reason: '—', no_changes: 'No field values recorded.', field: 'Field', before: 'Before', after: 'After',
                empty: '(none)', diff_caption: 'Changes in entry :id', none: 'No entry matches these filters.', showing: 'Showing :from–:to of :total entries', showing_none: 'No entries', pages: 'Audit log pages',
            },
            events: { lockout: 'Lockout', facility_deactivated: 'Facility deactivated', 'login-failed': 'Failed login' },
            export: {
                title: 'Export', description: 'A CSV of the entries matching the filters.', button: 'Export CSV', working: 'Exporting…', ready: ':rows row(s) written.', download: 'Download :file',
                queued: 'The export is being prepared: it will appear below when it is ready.', recent: 'Recent exports', recent_item: ':file (:rows rows)', expiry: 'The links work for 30 minutes.', none: 'No export yet.',
            },
        },
        account: {
            logins: { title: 'Your recent sign-ins', description: 'If you do not recognise one, change your password.', caption: 'Recent sign-ins', when: 'When (WAT)', browser: 'Browser', ip: 'Address (range)', new_device: 'New device', yes: 'Yes', no: 'No', unknown: 'Unknown', none: 'No sign-in recorded yet.' },
            unlock: { title: 'Unlocking an account', text: 'An account is unlocked from the server console, never here:' },
        },
    },
};

const entries: AuditEntry[] = [
    { id: 12, at: '2026-11-05T14:00:00+01:00', actor: 'Musa Garba', console: false, event: 'lockout', auth: true, subject: { type: 'auth', id: 3, label: null, url: null }, source: null, reason: null, old: {}, new: {} },
    {
        id: 11, at: '2026-11-02T09:15:00+01:00', actor: 'Hauwa Bello', console: false, event: 'facility_deactivated', auth: false,
        subject: { type: 'facility', id: 7, label: 'Tudun Wada PHC', url: '/admin/facilities/7' }, source: null, reason: 'Closed down by the LGA in March', old: { is_active: true }, new: { is_active: false },
    },
    { id: 10, at: '2026-11-01T02:00:00+01:00', actor: null, console: true, event: 'facility_imported', auth: false, subject: null, source: null, reason: 'master list import book.xlsx', old: {}, new: { code: 'KD-1', lat: null } },
];
const page = (rows: AuditEntry[]): Paginated<AuditEntry> => ({ data: rows, current_page: 1, last_page: 1, from: 1, to: rows.length, total: rows.length, per_page: 50 } as Paginated<AuditEntry>);
const filters = { actor: null, type: null, source: null, event: null, from: null, to: null, q: null, page: 1 };

function stubFetch(handler: () => { status?: number; body: unknown }) {
    const fetch = vi.fn(async () => {
        const { status = 200, body } = handler();

        return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
    });
    vi.stubGlobal('fetch', fetch);

    return fetch;
}

afterEach(() => {
    vi.unstubAllGlobals();
    routerGet.mockReset();
    routerReload.mockReset();
});

describe('audit helpers', () => {
    it('lists every field recorded and writes values plainly', () => {
        expect(auditChanges({ a: 1 }, { a: 2, b: 'x' })).toEqual([{ field: 'a', before: 1, after: 2 }, { field: 'b', before: undefined, after: 'x' }]);
        expect(auditValue(null, '(none)')).toBe('(none)');
        expect(auditValue({ x: 1 }, '(none)')).toBe('{"x":1}');
        expect(auditValue(false, '(none)')).toBe('false');
    });
});

describe('AuditLogTable', () => {
    it('says who did what, labels sign-in events, links the subject and opens the before/after from the keyboard', async () => {
        const { container } = renderUi(<AuditLogTable entries={page(entries)} onPage={() => undefined} />, translations);
        const user = userEvent.setup();

        const [, lockout, deactivated, imported] = screen.getAllByRole('row');
        expect(lockout?.textContent).toContain('Lockout');
        expect(lockout?.textContent).toContain('Sign-in');
        expect(within(deactivated as HTMLElement).getByRole('link', { name: 'Tudun Wada PHC' }).getAttribute('href')).toBe('/admin/facilities/7');
        expect(deactivated?.textContent).toContain('Closed down by the LGA in March');
        expect(imported?.textContent).toContain('Console');
        expect(imported?.textContent).toContain('facility_imported'); // an event without a label shows its key

        const toggle = screen.getByRole('button', { name: 'Show changes for entry 11' });
        toggle.focus();
        await user.keyboard('{Enter}');
        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        const diff = screen.getByRole('table', { name: 'Changes in entry 11' });
        expect(within(diff).getByRole('row', { name: /is_active/ }).textContent).toBe('is_activetruefalse');
        expect(await axeCheck(container)).toHaveNoViolations();

        await user.keyboard('{Enter}');
        expect(screen.queryByRole('table', { name: 'Changes in entry 11' })).toBeNull();
    });
});

describe('AdminAudit', () => {
    const props = { entries: page(entries), auditFilters: filters, actors: [{ id: 3, name: 'Musa Garba' }], events: ['facility_deactivated', 'lockout'], types: ['facility', 'round', 'auth'], sources: [{ key: 'kobo', name: 'KoboToolbox' }], exports: [] };

    it('filters on the server, keeping the filters in the URL', async () => {
        const { container } = renderUi(<AdminAudit {...props} />, translations);
        const user = userEvent.setup();

        expect(screen.getByText('The audit log is read only.')).toBeTruthy();
        await user.selectOptions(screen.getByLabelText('Who'), '3');
        expect(routerGet).toHaveBeenLastCalledWith('/admin/audit', { actor: 3 }, expect.objectContaining({ only: ['entries', 'auditFilters'] }));
        await user.selectOptions(screen.getByLabelText('Subject'), 'auth');
        expect(routerGet).toHaveBeenLastCalledWith('/admin/audit', { type: 'auth' }, expect.anything());
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('exports the filtered entries and links the file, or says it is queued', async () => {
        const fetch = stubFetch(() => ({ status: 201, body: { data: { queued: false, file: 'audit-log-20261110-080000-abcdef.csv', rows: 2, url: '/download' } } }));
        renderUi(<AdminAudit {...props} auditFilters={{ ...filters, type: 'round' }} />, translations);

        await userEvent.click(screen.getByRole('button', { name: 'Export CSV' }));

        await waitFor(() => expect(screen.getByRole('status').textContent).toContain('2 row(s) written.'));
        expect(screen.getByRole('link', { name: 'Download audit-log-20261110-080000-abcdef.csv' }).getAttribute('href')).toBe('/download');
        expect(JSON.parse(String((fetch.mock.calls[0] as unknown as [string, RequestInit])[1].body))).toEqual({ type: 'round' });

        stubFetch(() => ({ status: 202, body: { data: { queued: true } } }));
        await userEvent.click(screen.getByRole('button', { name: 'Export CSV' }));
        await waitFor(() => expect(screen.getByRole('status').textContent).toBe('The export is being prepared: it will appear below when it is ready.'));
    });
});

describe('Account', () => {
    it('lists the recent sign-ins with the address as a range and points to the console to unlock', async () => {
        const { container } = renderUi(
            <Account
                logins={[
                    { at: '2026-11-03T18:30:00+01:00', browser: 'Chrome 131 on Windows', ip_range: '102.89.34.0/24', new_device: false },
                    { at: '2026-11-01T08:00:00+01:00', browser: 'Firefox 132', ip_range: null, new_device: true },
                ]}
                unlockCommand="php artisan edqa:admin:unlock {email}"
            />,
            translations,
        );

        const table = screen.getByRole('table', { name: 'Recent sign-ins' });
        const [, latest, first] = within(table).getAllByRole('row');
        expect(latest?.textContent).toContain('102.89.34.0/24');
        expect(latest?.textContent).toContain('No');
        expect(first?.textContent).toContain('Unknown');
        expect(first?.textContent).toContain('Yes');
        expect(screen.getByText('php artisan edqa:admin:unlock {email}')).toBeTruthy();
        expect(screen.getByLabelText(/Current password/)).toBeTruthy();
        expect(await axeCheck(container)).toHaveNoViolations();
    });
});
