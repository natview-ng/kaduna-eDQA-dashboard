import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import RuleVersionHistory from '@/components/RuleVersionHistory';
import { bandErrors, configChanges } from '@/lib/rules';
import AdminScoringRules from '@/pages/admin/scoring-rules';
import { axeCheck, renderUi, testTranslations } from '@/test/render';
import type { RoundPreviewData, RuleVersionRow } from '@/types/admin';
import type { ScoreValue } from '@/types/score';

const routerReload = vi.fn();
vi.mock('@inertiajs/react', async (importOriginal) => {
    const actual = await importOriginal<typeof import('@inertiajs/react')>();

    return { ...actual, router: { ...actual.router, reload: (...args: unknown[]) => routerReload(...args) } };
});

// The keys these components read, as lang/en/admin.php has them (step 6).
const translations = {
    ...testTranslations,
    common: {
        ...(testTranslations.common as Record<string, unknown>),
        months_short: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'],
        api: { error: 'Something went wrong.', conflict: 'Refused.', invalid: 'Check the fields.', forbidden: 'Not allowed.', too_many: 'Too many requests.' },
        progress: { stage: ':label: :stage', failed: 'Could not get the progress.' },
        reason: { label: 'Reason', hint: 'At least :min characters.', remaining: ':count more characters needed.' },
        ui: { ...((testTranslations.common as Record<string, Record<string, string>>).ui ?? {}), cancel: 'Cancel', working: 'Working…' },
    },
    enums: { band: { strong: 'Strong', acceptable: 'Acceptable', review: 'Review', needs_action: 'Needs action' }, dimension: { availability: 'Availability', consistency: 'Consistency', validity: 'Validity' } },
    admin: {
        public_impact: { label: 'Public dashboard:', text: 'this changes what the public dashboard shows.' },
        scoring: {
            current: {
                title: 'Current rule version: v:version', none: 'No rule version is published yet.', published: 'Published :when by :name', published_unknown: 'Published :when', reason: 'Reason: :reason',
                bands: 'Bands', band: 'Band', from: 'From', weighting: 'Weighting', weighting_unweighted: 'Unweighted: every facility counts once.', rounding: 'Display rounding',
                rounding_half_up: 'Half up: 73.65 shows as 73.7.', rounding_note: 'Rounding is only for display (Q-09). Shown read-only.', choice_map: 'Choice map', choice: 'Answer on the form', outcome: 'Counts as',
                outcomes: { pass: 'Pass', fail: 'Fail', na: 'N/A (left out of the score)' }, na_policy: 'N/A policy', na_policies: { exclude: 'Exclude: an N/A item counts in neither.' },
            },
            draft: {
                title: 'Draft: v:version', none_title: 'Draft', none: 'There is no draft.', start: 'Start a draft from v:version', starting: 'Starting…', bands_legend: 'Band thresholds',
                bands_help: 'Each number is the lowest score in its band. They must go down.', strong: 'Strong from', acceptable: 'Acceptable from', review: 'Review from',
                not_descending: 'Must be below :band (:value).', out_of_range: 'Must be a number from 0 to 100.', weights_legend: 'Item weights', weights_help: 'Every item weighs 1 unless set here.',
                weights_search: 'Search items', weights_caption: 'Item weights', item: 'Item', dimension: 'Dimension', weight: 'Weight', weight_label: 'Weight of :item', weight_invalid: 'Must be a number above 0.',
                weights_showing: 'Showing :shown of :total items', na_legend: 'N/A policy', save: 'Save draft', saving: 'Saving…', saved: 'Draft v:version saved.', discard: 'Discard draft',
                discard_confirm: 'Discard draft v:version?', discard_yes: 'Yes, discard', discard_no: 'Keep the draft', discarded: 'Draft discarded.', fix: 'Fix the fields marked before saving.',
            },
            preview: {
                title: 'Impact of the draft', description: 'Scored again in memory. Nothing is saved.', button: 'Preview impact', running: 'Scoring in memory…', unsaved: 'Save the draft to preview it.',
                round: ':round (open)', summary: ':facilities facility(ies) and :lgas LGA(s) change band; :assessments assessment(s) read.', skipped: ':count could not be scored.', state: 'State overall',
                arrow: ':before → :after', facilities: 'Facilities changing band in :round', lgas: 'LGAs changing band in :round', facility: 'Facility', lga: 'LGA', before: 'Now', after: 'Under the draft',
                none_changing: 'No facility or LGA changes band.', no_open: 'No round is open.', closed_title: 'Closed rounds: not affected', closed_item: ':round keeps v:version', closed_none: 'No round is closed yet.',
                public_note: 'The public dashboard only changes when an open round is closed.',
            },
            publish: {
                button: 'Publish draft…', needs_preview: 'Preview the impact before publishing.', title: 'Publish v:version',
                round_summary: ':round: :facilities facility(ies) and :lgas LGA(s) change band; state overall :before → :after.', no_open: 'No round is open.',
                closed: 'Closed rounds keep their version. The public dashboard only changes when an open round is closed.', confirm: 'Publish', done: 'v:version is published. The open rounds are being rescored.',
                progress: 'Rescore :round', queued: ':round: rescore queued…', stages: { running: 'Rescoring', succeeded: 'Finished', failed: 'Failed' }, processed: 'Assessments scored',
            },
            history: {
                title: 'Version history', caption: 'Rule versions', version: 'Version', status: 'Status', published: 'Published', by: 'By', reason: 'Reason',
                statuses: { draft: 'Draft', current: 'Current', published: 'Superseded' }, not_published: 'Not published', diff_title: 'Compare two versions', diff_from: 'From', diff_to: 'To',
                diff_version: 'v:version', diff_none: 'These two versions are the same.', diff_band: ':band from :from to :to', diff_weight: 'Weight of :item from :from to :to', diff_na: 'N/A policy from :from to :to',
                diff_choice: 'Answer ":choice" from :from to :to', diff_missing: 'none',
            },
        },
    },
};

const config = { bands: { strong: 90, acceptable: 80, review: 70 }, item_weights: {}, na_policy: 'exclude', choice_map: { yes: 'pass', no: 'fail', na: 'na' } };
const v1: RuleVersionRow = { id: 1, version: 1, status: 'current', config, created_by: 'Seeder', created_at: null, published_at: '2026-09-01T10:00:00Z', published_by: 'Hauwa Bello', published_reason: 'First version' };
const draft: RuleVersionRow = { ...v1, id: 2, version: 2, status: 'draft', config: { ...config, bands: { strong: 85, acceptable: 75, review: 65 } }, published_at: null, published_by: null, published_reason: null };
const items = [
    { key: 'availability.m__1a', dimension: 'availability', code: 'm__1a', label: '1a. Is the NHMIS summary form available?' },
    { key: 'consistency.c__2a', dimension: 'consistency', code: 'c__2a', label: '2a. Does the register agree?' },
];
const rounds = { open: [{ id: 5, label: 'Q3 2026' }], closed: [{ id: 4, label: 'Q2 2026', rule_version: 1 }] };
const settings = { weighting: 'unweighted', rounding: 'half_up' };
const score = (display: string, band: ScoreValue['band'], bandLabel: string): ScoreValue => ({ value: Number(display), display, headline: display, band, bandLabel });
const previewRound: RoundPreviewData = {
    round_id: 5, year: 2026, quarter: 3, assessments: 6, skipped: 0,
    facilities_changing_band: [{ facility_id: 9, code: 'KD-IKR-0002', name: 'Ikara PHC', lga_id: 1, before: score('86.0', 'acceptable', 'Acceptable'), after: score('86.0', 'strong', 'Strong') }],
    lgas_changing_band: [],
    facilities_changing_count: 1,
    lgas_changing_count: 0,
    state_overall_before: score('86.1', 'acceptable', 'Acceptable'),
    state_overall_after: score('86.1', 'strong', 'Strong'),
};

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

describe('lib/rules', () => {
    it('checks the thresholds go down, each from 0 to 100, reporting the order on the lower band', () => {
        expect(bandErrors({ strong: '90', acceptable: '80', review: '70' })).toEqual({});
        expect(bandErrors({ strong: '85', acceptable: '88', review: '70' })).toEqual({ acceptable: { kind: 'order', above: 'strong', value: '85' } });
        expect(bandErrors({ strong: '101', acceptable: '', review: 'x' })).toEqual({ strong: { kind: 'range' }, acceptable: { kind: 'range' }, review: { kind: 'range' } });
    });

    it('lists the differences between two configs', () => {
        expect(configChanges(config, { ...config, bands: { strong: 85, acceptable: 80, review: 70 }, item_weights: { 'availability.m__1a': 2 } })).toEqual([
            { kind: 'band', band: 'strong', from: 90, to: 85 },
            { kind: 'weight', item: 'availability.m__1a', from: null, to: 2 },
        ]);
    });
});

describe('AdminScoringRules', () => {
    it('shows the current version, who published it and why, and the settings read-only', async () => {
        const { container } = renderUi(<AdminScoringRules versions={[v1]} draft={null} items={items} rounds={rounds} rescores={[]} settings={settings} />, translations);

        expect(screen.getByRole('heading', { name: 'Current rule version: v1' })).toBeTruthy();
        expect(screen.getByText(/by Hauwa Bello/)).toBeTruthy();
        expect(screen.getByText('Reason: First version')).toBeTruthy();
        expect(screen.getByText('Half up: 73.65 shows as 73.7.')).toBeTruthy();
        expect(screen.getByRole('table', { name: 'Bands' }).textContent).toContain('≥ 90');
        expect(screen.getByRole('button', { name: 'Start a draft from v1' })).toBeTruthy();
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('checks the bands as they are typed and saves nothing while they are wrong', async () => {
        const fetch = stubFetch(() => ({ body: { data: draft } }));
        const { container } = renderUi(<AdminScoringRules versions={[draft, v1]} draft={draft} items={items} rounds={rounds} rescores={[]} settings={settings} />, translations);
        const user = userEvent.setup();

        const acceptable = screen.getByLabelText('Acceptable from');
        await user.clear(acceptable);
        await user.type(acceptable, '88');
        expect(acceptable.getAttribute('aria-invalid')).toBe('true');
        expect(document.getElementById('draft-band-acceptable-error')?.textContent).toBe('Must be below Strong (85).');
        await user.click(screen.getByRole('button', { name: 'Save draft' }));

        expect(screen.getByRole('alert').textContent).toBe('Fix the fields marked before saving.');
        expect(fetch).not.toHaveBeenCalled();
        expect(await axeCheck(container)).toHaveNoViolations();
    });

    it('searches the item weights and saves only weights that are not 1', async () => {
        const fetch = stubFetch(() => ({ body: { data: draft } }));
        renderUi(<AdminScoringRules versions={[draft, v1]} draft={draft} items={items} rounds={rounds} rescores={[]} settings={settings} />, translations);
        const user = userEvent.setup();

        await user.type(screen.getByRole('searchbox', { name: 'Search items' }), 'register');
        expect(screen.queryByLabelText('Weight of 1a. Is the NHMIS summary form available?')).toBeNull();
        const weight = screen.getByLabelText('Weight of 2a. Does the register agree?');
        await user.clear(weight);
        await user.type(weight, '2');
        await user.click(screen.getByRole('button', { name: 'Save draft' }));

        await waitFor(() => expect(fetch).toHaveBeenCalled());
        const [url, init] = fetch.mock.calls[0] as unknown as [string, RequestInit];
        expect(url).toBe('/admin/api/scoring-rules/2');
        expect(JSON.parse(String(init.body))).toEqual({ changes: { bands: { strong: 85, acceptable: 75, review: 65 }, item_weights: { 'consistency.c__2a': 2 }, na_policy: 'exclude' } });
    });

    it('previews the impact, lists closed rounds as unaffected, then publishes with a reason and follows the rescores', async () => {
        const fetch = stubFetch((url) => (url.endsWith('/preview') ? { body: { data: { rounds: [previewRound] } } } : { body: { data: { ...draft, status: 'current' } } }));
        const { container, rerender } = renderUi(<AdminScoringRules versions={[draft, v1]} draft={draft} items={items} rounds={rounds} rescores={[]} settings={settings} />, translations);
        const user = userEvent.setup();

        expect((screen.getByRole('button', { name: 'Publish draft…' }) as HTMLButtonElement).disabled).toBe(true);
        await user.click(screen.getByRole('button', { name: 'Preview impact' }));

        const table = await screen.findByRole('table', { name: 'Facilities changing band in Q3 2026' });
        expect(table.textContent).toContain('Ikara PHC');
        expect(table.textContent).toContain('Acceptable');
        expect(table.textContent).toContain('Strong');
        expect(screen.getByText('1 facility(ies) and 0 LGA(s) change band; 6 assessment(s) read.')).toBeTruthy();
        expect(screen.getByText('Q2 2026 keeps v1')).toBeTruthy();
        expect(screen.getByText('The public dashboard only changes when an open round is closed.')).toBeTruthy();
        expect(await axeCheck(container)).toHaveNoViolations();

        await user.click(screen.getByRole('button', { name: 'Publish draft…' }));
        const dialog = screen.getByRole('dialog', { name: 'Publish v2' });
        expect(dialog.textContent).toContain('Q3 2026: 1 facility(ies) and 0 LGA(s) change band; state overall 86.1 → 86.1.');
        expect(dialog.textContent).toContain('Closed rounds keep their version.');
        await user.type(within(dialog).getByRole('textbox', { name: 'Reason' }), 'Strong lowered to 85 at review');
        await user.click(within(dialog).getByRole('button', { name: 'Publish' }));

        await waitFor(() => expect(screen.getByRole('status').textContent).toBe('v2 is published. The open rounds are being rescored.'));
        const publish = fetch.mock.calls.find(([url]) => String(url).endsWith('/publish')) as unknown as [string, RequestInit];
        expect(JSON.parse(String(publish[1].body))).toEqual({ reason: 'Strong lowered to 85 at review' });
        expect(screen.getByText('Q3 2026: rescore queued…')).toBeTruthy();

        // The reload brings the started rescore: it is followed in its own progress panel.
        rerender(<AdminScoringRules versions={[{ ...draft, status: 'current' }, { ...v1, status: 'published' }]} draft={null} items={items} rounds={rounds} rescores={[{ round_id: 5, batch_id: 'batch-1', status: 'running' }]} settings={settings} />);
        await waitFor(() => expect(fetch.mock.calls.some(([url]) => url === '/admin/api/rescores/batch-1')).toBe(true));
    });
});

describe('RuleVersionHistory', () => {
    it('lists every version and describes the difference between two of them', async () => {
        const v2 = { ...v1, id: 2, version: 2, status: 'current' as const, config: { ...config, bands: { strong: 85, acceptable: 80, review: 70 } }, published_reason: 'Strong lowered' };
        const { container } = renderUi(<RuleVersionHistory versions={[v2, { ...v1, status: 'published' }]} items={items} />, translations);

        expect(screen.getByRole('table', { name: 'Rule versions' }).textContent).toContain('Superseded');
        expect(screen.getByText('Strong from 90 to 85')).toBeTruthy();
        await userEvent.selectOptions(screen.getByLabelText('From'), '2');
        expect(screen.getByText('These two versions are the same.')).toBeTruthy();
        expect(await axeCheck(container)).toHaveNoViolations();
    });
});
