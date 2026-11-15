import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Announcer from '@/components/Announcer';
import CascadePanel from '@/components/CascadePanel';
import WardManager from '@/components/WardManager';
import { I18nProvider } from '@/lib/i18n';
import AdminFacilities from '@/pages/admin/facilities';
import { axeCheck, renderUi, testTranslations } from '@/test/render';
import type { FacilityListRow, LgaWithWards, Paginated } from '@/types/admin';

const routerReload = vi.fn();
const routerGet = vi.fn();
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();

    return { ...actual, router: { ...actual.router, reload: (...args: unknown[]) => routerReload(...args), get: (...args: unknown[]) => routerGet(...args) } };
});

// The keys these components read, as lang/en/admin.php has them (step 5).
const translations = {
    ...testTranslations,
    common: {
        ...(testTranslations.common as Record<string, unknown>),
        api: { error: 'Something went wrong.', conflict: 'Refused.', invalid: 'Check the fields.', forbidden: 'Not allowed.', too_many: 'Too many requests.' },
        reason: { label: 'Reason', hint: 'At least :min characters.', remaining: ':count more characters needed.' },
        ui: { ...((testTranslations.common as Record<string, Record<string, string>>).ui ?? {}), cancel: 'Cancel', working: 'Working…', previous: 'Previous', next: 'Next', page: 'Page :page' },
    },
    enums: { facility_level: { primary: 'Primary', secondary: 'Secondary', tertiary: 'Tertiary' }, ownership: { public: 'Public', private: 'Private' } },
    admin: {
        public_impact: { label: 'Public dashboard:', text: 'this changes what the public dashboard shows.' },
        sources: {
            cascade: {
                title: 'Facility media file', description: 'The form picks the facility from these lists.', button: 'Regenerate facility media file', how_file: 'See docs/odk-cascade-media.md.',
                out_of_date: 'The facility media file is out of date — regenerate it and attach it to the form on ODK Central and KoboToolbox.',
                ready: 'Ready: :lgas LGAs, :wards wards, :facilities facilities.', download: 'Download :file', expiry: 'The link works for 30 minutes.',
                steps_label: 'Next', steps: { download: 'Download it.', odk: 'Attach it on ODK Central.', kobo: 'Attach it on KoboToolbox.' },
            },
        },
        facilities: {
            fixed_lgas: 'Kaduna State has exactly 23 LGAs. They are fixed.',
            filters: { label: 'Search and filters', search: 'Search', search_placeholder: 'Name, code, ward or LGA', lga: 'LGA', ward: 'Ward', level: 'Level', ownership: 'Ownership', active: 'Status', all: 'All', active_yes: 'Active', active_no: 'Deactivated', clear: 'Clear filters', announce: ':total facility(ies) found.' },
            table: {
                title: 'Facilities', caption: 'Facility master list', code: 'Code', name: 'Name', ward: 'Ward', lga: 'LGA', level: 'Level', ownership: 'Ownership', active: 'Status', visits: 'Visits', latest: 'Latest score', actions: 'Actions',
                is_active: 'Active', is_inactive: 'Deactivated', no_score: 'No score yet', none: 'No facility matches these filters.', showing: 'Showing :from–:to of :total facilities', showing_none: 'No facilities', pages: 'Facility pages',
                edit: 'Edit', deactivate: 'Deactivate…', reactivate: 'Reactivate…',
            },
            edit: { legend: 'Edit :name', code_locked: 'The code cannot change: assessments are recorded under it.', lat: 'Latitude', lng: 'Longitude', save: 'Save', saving: 'Saving…', cancel: 'Cancel', public_impact: 'names and ward or LGA changes show on the public dashboard, for published rounds too.', saved: ':name is saved.' },
            add: { title: 'Add a facility', description: 'A new facility goes on the master list.', submit: 'Add facility', submitting: 'Adding…', added: ':name is added.', choose: 'Choose…' },
            deactivate: { title: 'Deactivate :name', consequence: 'The facility is never deleted. Its published history stays on the public dashboard.', open_round: ':count of its visits are in an open round. Future submissions for it from ODK Central or KoboToolbox will fail FACILITY_UNKNOWN.', confirm: 'Deactivate', done: ':name is deactivated.' },
            reactivate: { title: 'Reactivate :name', consequence: 'It returns to the lists.', confirm: 'Reactivate', done: ':name is active again.' },
            wards: { title: 'Wards', description: 'Add or rename wards.', lga: 'LGA', caption: 'Wards of :lga', name: 'Ward', rename: 'Rename', rename_label: 'New name for :ward', save: 'Save', cancel: 'Cancel', add_label: 'New ward in :lga', add: 'Add ward', added: 'Ward :ward is added to :lga.', renamed: 'The ward is now :ward.', none: 'This LGA has no ward yet.' },
        },
    },
};

const lgas: LgaWithWards[] = [
    { id: 1, code: 'IKR', name: 'Ikara', wards: [{ id: 11, name: 'Ikara' }] },
    { id: 2, code: 'ZAR', name: 'Zaria', wards: [{ id: 21, name: 'Tudun Wada' }, { id: 22, name: 'Kwarbai' }] },
];
const row = (overrides: Partial<FacilityListRow> = {}): FacilityListRow => ({
    id: 7, code: 'KD-ZAR-0001', name: 'Tudun Wada PHC', ward: { id: 21, name: 'Tudun Wada' }, lga: { id: 2, code: 'ZAR', name: 'Zaria' }, level: 'primary', ownership: 'public', active: true,
    lat: null, lng: null, visits: 3, open_round_visits: 1, code_locked: true,
    latest: { round: 'Q3 2026', score: { value: 69.8, display: '69.8', headline: '70', band: 'needs_action', bandLabel: 'Needs action' } },
    ...overrides,
});
const page = (rows: FacilityListRow[]): Paginated<FacilityListRow> => ({ data: rows, current_page: 1, last_page: 1, from: 1, to: rows.length, total: rows.length, per_page: 25 } as Paginated<FacilityListRow>);
const filters = { q: null, lga: null, ward: null, level: null, ownership: null, active: null, page: 1 };
const cascade = { out_of_date: false, changed_at: null, exported_at: null };

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
    routerGet.mockReset();
});

describe('AdminFacilities', () => {
    it('lists the master list with status in words and the latest score, and says the 23 LGAs are fixed', async () => {
        const { container } = renderUi(<AdminFacilities facilities={page([row(), row({ id: 8, code: 'KD-IKR-0002', name: 'Ikara Clinic', active: false, latest: null })])} facilityFilters={filters} lgas={lgas} cascade={cascade} />, translations);

        expect(screen.getByText('Kaduna State has exactly 23 LGAs. They are fixed.')).toBeTruthy();
        const [, first, second] = screen.getAllByRole('row');
        expect(first?.textContent).toContain('Active');
        expect(first?.textContent).toContain('69.8Needs action');
        expect(second?.textContent).toContain('Deactivated');
        expect(second?.textContent).toContain('No score yet');
        expect(within(second as HTMLElement).getByRole('button', { name: 'Reactivate… Ikara Clinic' })).toBeTruthy();
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('edits inline with the public-impact note, keeps a locked code read-only, and sends only what may change', async () => {
        const fetch = stubFetch(() => ({ body: { data: { name: 'Tudun Wada Clinic' } } }));
        const { container } = renderUi(<AdminFacilities facilities={page([row()])} facilityFilters={filters} lgas={lgas} cascade={cascade} />, translations);
        const user = userEvent.setup();

        await user.click(screen.getByRole('button', { name: 'Edit Tudun Wada PHC' }));
        expect(document.activeElement).toBe(document.getElementById('edit-facility-7-name'));
        expect(screen.getByText('names and ward or LGA changes show on the public dashboard, for published rounds too.')).toBeTruthy();
        const code = document.getElementById('edit-facility-7-code') as HTMLInputElement;
        expect(code.readOnly).toBe(true);
        expect(code.getAttribute('aria-describedby')).toContain('edit-facility-7-code-locked');

        const name = document.getElementById('edit-facility-7-name') as HTMLInputElement;
        await user.clear(name);
        await user.type(name, 'Tudun Wada Clinic');
        await user.selectOptions(document.getElementById('edit-facility-7-lga') as HTMLSelectElement, 'IKR');
        await user.selectOptions(document.getElementById('edit-facility-7-ward_id') as HTMLSelectElement, '11');
        await user.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(screen.getByRole('status').textContent).toBe('Tudun Wada Clinic is saved.'));
        const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('/admin/api/facilities/7');
        expect(JSON.parse(String(init.body))).toEqual({ name: 'Tudun Wada Clinic', ward_id: 11, level: 'primary', ownership: 'public', lat: null, lng: null });
        expect(routerReload).toHaveBeenCalledWith({ only: ['facilities', 'lgas', 'cascade'] });
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('puts a refused code change on the code field', async () => {
        stubFetch(() => ({ status: 409, body: { code: 'code_locked', message: 'The code cannot change: 2 assessment(s) are recorded under it.' } }));
        renderUi(<AdminFacilities facilities={page([row({ code_locked: false })])} facilityFilters={filters} lgas={lgas} cascade={cascade} />, translations);
        const user = userEvent.setup();

        await user.click(screen.getByRole('button', { name: 'Edit Tudun Wada PHC' }));
        const code = document.getElementById('edit-facility-7-code') as HTMLInputElement;
        await user.clear(code);
        await user.type(code, 'KD-ZAR-0200');
        await user.click(screen.getByRole('button', { name: 'Save' }));

        await waitFor(() => expect(code.getAttribute('aria-invalid')).toBe('true'));
        expect(document.getElementById('edit-facility-7-code-error')?.textContent).toBe('The code cannot change: 2 assessment(s) are recorded under it.');
    });

    it('warns before deactivating a facility with visits in an open round, and asks for a reason', async () => {
        const fetch = stubFetch(() => ({ body: { data: { name: 'Tudun Wada PHC' } } }));
        renderUi(<AdminFacilities facilities={page([row()])} facilityFilters={filters} lgas={lgas} cascade={cascade} />, translations);
        const user = userEvent.setup();

        await user.click(screen.getByRole('button', { name: 'Deactivate… Tudun Wada PHC' }));
        const dialog = screen.getByRole('dialog', { name: 'Deactivate Tudun Wada PHC' });
        expect(dialog.textContent).toContain('1 of its visits are in an open round. Future submissions for it from ODK Central or KoboToolbox will fail FACILITY_UNKNOWN.');
        expect(dialog.textContent).toContain('Its published history stays on the public dashboard.');
        await user.type(within(dialog).getByRole('textbox', { name: 'Reason' }), 'Closed down by the LGA');
        await user.click(within(dialog).getByRole('button', { name: 'Deactivate' }));

        await waitFor(() => expect(screen.getByRole('status').textContent).toBe('Tudun Wada PHC is deactivated.'));
        const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('/admin/api/facilities/7/deactivate');
        expect(JSON.parse(String(init.body))).toEqual({ reason: 'Closed down by the LGA' });
    });

    it('shows the out-of-date notice while the media file is stale, and reloads its state after regenerating', async () => {
        stubFetch(() => ({ status: 201, body: { data: { filename: 'cascade.zip', lgas: 23, wards: 255, facilities: 2190, url: '/download', expires_at: '2026-11-14T12:00:00Z' } } }));
        renderUi(<AdminFacilities facilities={page([row()])} facilityFilters={filters} lgas={lgas} cascade={{ ...cascade, out_of_date: true }} />, translations);

        expect(screen.getByText(/The facility media file is out of date/)).toBeTruthy();
        await userEvent.click(screen.getByRole('button', { name: 'Regenerate facility media file' }));

        await waitFor(() => expect(routerReload).toHaveBeenCalledWith({ only: ['cascade'] }));
    });

    it('keeps the regenerated file\'s download where it was asked for once the file is current again', async () => {
        // Found by the full-round e2e journey: the reload clears out_of_date, and the panel used to
        // jump to the foot of the page as a fresh copy, losing the counts and the download link.
        stubFetch(() => ({ status: 201, body: { data: { filename: 'cascade.zip', lgas: 23, wards: 255, facilities: 2190, url: '/download', expires_at: '2026-11-14T12:00:00Z' } } }));
        const props = { facilities: page([row()]), facilityFilters: filters, lgas };
        const { rerender } = renderUi(<AdminFacilities {...props} cascade={{ ...cascade, out_of_date: true }} />, translations);
        await userEvent.click(screen.getByRole('button', { name: 'Regenerate facility media file' }));
        expect(await screen.findByText('Ready: 23 LGAs, 255 wards, 2,190 facilities.')).toBeTruthy();

        rerender(
            <I18nProvider translations={translations}>
                <Announcer>
                    <AdminFacilities {...props} cascade={cascade} />
                </Announcer>
            </I18nProvider>,
        );
        expect(screen.queryByText(/The facility media file is out of date/)).toBeNull();
        expect(screen.getByText('Ready: 23 LGAs, 255 wards, 2,190 facilities.')).toBeTruthy();
        expect(screen.getByRole('link', { name: 'Download cascade.zip' })).toBeTruthy();
        expect(screen.getAllByRole('button', { name: 'Regenerate facility media file' })).toHaveLength(1);
    });

    it('searches after typing stops, keeping the filters in the URL', async () => {
        renderUi(<AdminFacilities facilities={page([row()])} facilityFilters={filters} lgas={lgas} cascade={cascade} />, translations);

        await userEvent.type(screen.getByRole('searchbox', { name: 'Search' }), 'tudun');

        await waitFor(() => expect(routerGet).toHaveBeenCalledWith('/admin/facilities', { q: 'tudun' }, expect.objectContaining({ only: ['facilities', 'facilityFilters'] })));
    });
});

describe('CascadePanel', () => {
    it('says nothing about being out of date when the file is current', () => {
        renderUi(<CascadePanel howUrl={null} />, translations);

        expect(screen.queryByText(/out of date/)).toBeNull();
    });
});

describe('WardManager', () => {
    it('adds a ward to the chosen LGA and shows a refusal beside the field, keyboard first', async () => {
        const fetch = stubFetch(() => ({ status: 409, body: { code: 'ward_exists', message: 'Zaria already has a ward called Kwarbai.' } }));
        const onChanged = vi.fn();
        const { container } = renderUi(<WardManager lgas={lgas} onChanged={onChanged} />, translations);
        const user = userEvent.setup();

        await user.selectOptions(screen.getByLabelText('LGA'), 'ZAR');
        expect(screen.getByRole('list', { name: 'Wards of Zaria' }).textContent).toContain('Kwarbai');
        const input = screen.getByLabelText('New ward in Zaria');
        await user.type(input, 'kwarbai{Enter}');

        await waitFor(() => expect(input.getAttribute('aria-invalid')).toBe('true'));
        expect(document.getElementById(input.getAttribute('aria-describedby') ?? '')?.textContent).toBe('Zaria already has a ward called Kwarbai.');
        expect(JSON.parse(String((fetch.mock.calls[0] as unknown as [string, RequestInit])[1].body))).toEqual({ lga_id: 2, name: 'kwarbai' });
        expect(onChanged).not.toHaveBeenCalled();
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('renames a ward in place', async () => {
        stubFetch(() => ({ body: { data: { id: 22, name: 'Kwarbai Sarki' } } }));
        const onChanged = vi.fn();
        renderUi(<WardManager lgas={lgas} onChanged={onChanged} />, translations);
        const user = userEvent.setup();

        await user.selectOptions(screen.getByLabelText('LGA'), 'ZAR');
        await user.click(screen.getByRole('button', { name: 'Rename Kwarbai' }));
        const input = screen.getByLabelText('New name for Kwarbai');
        expect(document.activeElement).toBe(input);
        await user.clear(input);
        await user.type(input, 'Kwarbai Sarki{Enter}');

        await waitFor(() => expect(onChanged).toHaveBeenCalledWith('The ward is now Kwarbai Sarki.'));
    });
});
