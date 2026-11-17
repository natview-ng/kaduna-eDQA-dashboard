import { expect, test, type APIRequestContext, type Browser, type Locator, type Page } from '@playwright/test';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { E2E_EMAIL, E2E_PASSWORD } from './support/credentials';

// One administrator runs a whole round, keyboard first, from an empty quarter
// to a published one. `make e2e-round` sets it up on its own database (edqa_e2e, so the
// developer's data is never touched): the demo seed with Q2 2026 closed in July, and both mocks
// holding 300 submissions for Q3 2026 (ODK Central 100, KoboToolbox 200; 20 broken, 3 visits on
// both tools, 2 to be edited at source). It runs in two parts, because two things must happen at
// the sources in between:
//   part 1 (E2E_FULL_ROUND=1): a. the visitor's view; b. sign in; c. create the round and
//     regenerate the media file; d. Pull all.
//   at the sources: one accepted Kobo visit is deleted (and the reconcile finds it), and the two
//     staged ODK edits become visible.
//   part 2 (E2E_FULL_ROUND=2): e. Data issues; f. correct an assessment; g. edit and deactivate
//     a facility; h. publish a band change; i. reconcile and close; j. the audit log; l.
//     export the closed round from the admin area and download each file; k. log out, and the
//     visitor's view again, with the leak checks and no way to an export.
// The screenshots for docs/admin-guide.md are taken on the way.

const part = process.env.E2E_FULL_ROUND ?? '';
const ROUND = 'Q3 2026';
const STATE = 'storage/e2e/full-round.json';
const NOTES = 'storage/e2e/full-round-notes.json';
const SHOTS = 'docs/images/admin-guide';
const DOWNLOADS = 'storage/e2e/downloads';

/** The mocks' assessors (App\Support\OdkMock\MockSubmissionGenerator::ASSESSORS): never public. */
const ASSESSORS = ['Hauwa Ibrahim', 'Musa Abdullahi', 'Grace Danjuma', 'Aisha Bello', 'John Gambo', 'Fatima Yusuf', 'Peter Auta', 'Zainab Lawal', 'Daniel Kure', 'Halima Sani', 'Samuel Bako', 'Rahila Ishaya', 'Ibrahim Tanko', 'Esther Musa', 'Yakubu Garba'];

/** Keys that never appear in public output (SECURITY.md §3.1; tests/Feature/Public/LeakTest.php). */
const FORBIDDEN_KEYS = [
    'assessor', 'assessor_name', 'device_id', 'submitter', 'instance_id', 'external_id', 'submission_id',
    'form_version', 'form_ref', 'payload', 'attachments', 'flags', 'activity', 'source', 'source_url',
    'quarantine', 'quarantined', 'failures', 'duplicate_of', 'deletion', 'gps', 'lat', 'lng', 'email',
    'owner_contact', 'reason', 'causer', 'corrected', 'shell', 'admin',
];

const PUBLIC_PAGES = [
    { path: '/', heading: 'Home' },
    { path: '/facilities', heading: 'Facility view' },
    { path: '/lgas', heading: 'LGA view' },
    { path: '/monitor', heading: 'Monitor' },
    { path: '/visuals', heading: 'Visuals' },
    { path: '/plan', heading: 'Implementation plan' },
];

const REASONS = {
    nullLga: 'Assessor confirmed the LGA by phone',
    reject: 'LGA typed wrongly; the assessor sent the visit again at the source',
    roundWindow: 'Visits dated three years early: test entries from the training day',
    duplicate: 'The KoboToolbox copy is the one the supervisor checked',
    withdraw: 'Deleted on KoboToolbox: the LGA confirmed this visit never happened',
    correct: 'Supervisor re-checked the register at the facility',
    deactivate: 'Closed down by the LGA health department in October',
    publish: 'Strong lowered to 85 for the 2026 reporting year',
    close: 'All issues reviewed; Q3 2026 ready to publish',
};

type ExportNote = { label: string; file: string; href: string; id: number; seconds: number };
type Notes = { updatedAt: string | null; correctedFacility?: number; correctedName?: string; exports?: ExportNote[] };
type Held = { id: number; instance_id: string; facility_ref: string | null; lga_ref: string | null; failures: { code: string; detail: string }[] };

// ---------------------------------------------------------------- helpers

/** Press Tab from where focus is now until the target has it: no mouse. */
async function tabTo(page: Page, target: Locator, max = 400): Promise<void> {
    const focused = () => target.evaluate((el) => el === document.activeElement).catch(() => false);
    for (let i = 0; i < max; i += 1) {
        if (await focused()) {
            return;
        }
        await page.keyboard.press('Tab');
    }
    if (!(await focused())) {
        throw new Error('The target was never reached with Tab.');
    }
}

/** Tab from the top of the page to the target. */
async function tabFromTop(page: Page, target: Locator, max = 400): Promise<void> {
    await page.locator('body').focus();
    await tabTo(page, target, max);
}

/** Tab to the control, press Enter. */
async function press(page: Page, target: Locator, fromTop = true): Promise<void> {
    await expect(target).toBeVisible();
    if (fromTop) {
        await tabFromTop(page, target);
    } else {
        await tabTo(page, target);
    }
    await page.keyboard.press('Enter');
}

/**
 * Focus the control directly, then press Enter: only past the scoring draft's item-weights table,
 * whose ~1,200 fields take minutes to Tab through (an accessibility finding of this journey,
 * ARCHITECTURE.md §11.4 Q-34). Still activated from the keyboard.
 */
async function focusAndPress(page: Page, target: Locator): Promise<void> {
    await expect(target).toBeEnabled();
    await target.focus();
    await page.keyboard.press('Enter');
}

/** A reasoned confirmation (ConfirmWithReasonDialog): the reason field has focus on open. */
async function confirmWithReason(page: Page, dialogName: string | RegExp, reason: string, confirm: string): Promise<void> {
    const dialog = page.getByRole('dialog', { name: dialogName });
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel('Reason')).toBeFocused();
    await page.keyboard.type(reason);
    await tabTo(page, dialog.getByRole('button', { name: confirm, exact: true }));
    await page.keyboard.press('Enter');
    await expect(dialog).toBeHidden({ timeout: 60_000 });
}

/** Choose an option of a native select with the arrow keys only, until it holds the value. */
async function chooseWithArrows(page: Page, select: Locator, value: string): Promise<void> {
    const values = await select.locator('option').evaluateAll((options) => options.map((option) => (option as HTMLOptionElement).value));
    const target = values.indexOf(value);
    expect(target, `the select offers ${value}`).toBeGreaterThanOrEqual(0);
    await tabTo(page, select);
    const now = values.indexOf(await select.inputValue());
    const key = target > now ? 'ArrowDown' : 'ArrowUp';
    for (let i = 0; i < Math.abs(target - now); i += 1) {
        await page.keyboard.press(key);
    }
    await expect(select).toHaveValue(value);
}

async function heading(page: Page, name: string | RegExp): Promise<void> {
    await expect(page.getByRole('heading', { level: 1, name })).toBeVisible({ timeout: 90_000 });
    await expect(page.locator('[aria-busy="true"]')).toHaveCount(0, { timeout: 90_000 });
}

async function shot(page: Page, name: string): Promise<void> {
    mkdirSync(SHOTS, { recursive: true });
    await page.screenshot({ path: `${SHOTS}/${name}.png`, fullPage: false });
}

function notes(): Notes {
    return JSON.parse(readFileSync(NOTES, 'utf8')) as Notes;
}

function saveNotes(next: Notes): void {
    mkdirSync('storage/e2e', { recursive: true });
    writeFileSync(NOTES, JSON.stringify(next));
}

/** Open quarantined records of a rule, from the admin API (the signed-in session). */
async function held(page: Page, rule: string): Promise<Held[]> {
    const response = await page.request.get(`/admin/api/quarantine?rule=${rule}&status=open&group=flat`, { headers: { Accept: 'application/json' } });
    expect(response.status()).toBe(200);

    return ((await response.json()) as { data: Held[] }).data;
}

function row(page: Page, instance: string): Locator {
    return page.getByRole('row').filter({ has: page.getByRole('rowheader').filter({ hasText: instance }) });
}

/** The Inertia page object in a full HTML response (Inertia 3's script tag, or the older attribute). */
function inertiaPage(body: string): Record<string, unknown> | null {
    const script = body.match(/<script[^>]*data-page[^>]*>([\s\S]*?)<\/script>/);
    if (script?.[1] !== undefined) {
        return JSON.parse(script[1]) as Record<string, unknown>;
    }
    const attribute = body.match(/data-page="([^"]*)"/);
    if (attribute?.[1] !== undefined) {
        const decoded = attribute[1].replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&amp;/g, '&');

        return JSON.parse(decoded) as Record<string, unknown>;
    }

    return null;
}

/** Every key at any depth, outside the UI strings. */
function keysOf(value: unknown): string[] {
    if (value === null || typeof value !== 'object') {
        return [];
    }
    const keys: string[] = [];
    for (const [key, inner] of Object.entries(value as Record<string, unknown>)) {
        if (key === 'translations') {
            continue;
        }
        if (!Array.isArray(value)) {
            keys.push(key);
        }
        keys.push(...keysOf(inner));
    }

    return keys;
}

/** The leak checks (SECURITY.md §3.1) on one public response, as an anonymous visitor sees it. */
function assertNothingLeaks(label: string, body: string, data: unknown): void {
    for (const name of ASSESSORS) {
        expect(body.includes(name), `${label} names the assessor ${name}`).toBe(false);
    }
    expect(/uuid:[A-Za-z0-9-]{4,}/.test(body), `${label} contains an instance ID`).toBe(false);
    expect(body.includes(E2E_EMAIL), `${label} contains the admin's email`).toBe(false);
    expect(/[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+\.[A-Za-z]{2,}/.test(body), `${label} contains an email address`).toBe(false);
    const found = [...new Set(keysOf(data))].filter((key) => FORBIDDEN_KEYS.includes(key));
    expect(found, `${label} has forbidden keys`).toEqual([]);
}

/** The rounds a visitor can choose on a public page, from its own props. */
async function publicRounds(request: APIRequestContext, path = '/'): Promise<{ year: number; quarter: number }[]> {
    const response = await request.get(path);
    expect(response.status()).toBe(200);
    const page = inertiaPage(await response.text());
    const props = (page?.props ?? {}) as { filterOptions?: { rounds?: { year: number; quarter: number }[] } };

    return props.filterOptions?.rounds ?? [];
}

/** As an anonymous visitor, in a context of its own: Q3 2026 is nowhere on the public dashboard. */
async function openRoundIsNotPublic(browser: Browser): Promise<void> {
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } });
    const visitor = await context.newPage();
    await visitor.goto('/');
    await heading(visitor, 'Home');
    await expect(visitor.getByRole('region', { name: 'Admin preview' })).toHaveCount(0);
    expect(await publicRounds(visitor.request)).not.toContainEqual({ year: 2026, quarter: 3 });
    await expect(visitor.getByRole('main')).not.toContainText(ROUND);
    // Asking for it by URL sends the visitor back to the published figures (D-77).
    await visitor.goto('/?year=2026&quarter=3');
    await heading(visitor, 'Home');
    expect(new URL(visitor.url()).search).not.toContain('quarter=3');
    await expect(visitor.getByRole('main')).not.toContainText(ROUND);
    await context.close();
}

/** The "Figures last updated" date in the public footer, as its machine-readable value. */
async function figuresUpdatedAt(page: Page): Promise<string | null> {
    const time = page.getByRole('contentinfo').locator('time');

    return (await time.count()) === 0 ? null : time.first().getAttribute('datetime');
}

// ---------------------------------------------------------------- part 1: a–d

test.describe('Full round, part 1: before the pull to after it', () => {
    test.skip(part !== '1', 'Run with make e2e-round');
    test.use({ storageState: { cookies: [], origins: [] } });

    test('a–d: the visitor\'s view, sign in, create the round, the media file, Pull all', async ({ page, browser }) => {
        test.setTimeout(900_000);

        // a. An anonymous visitor: the latest published round is Q2 2026; Q3 2026 is not there.
        await page.goto('/');
        await heading(page, 'Home');
        await expect(page.getByRole('region', { name: 'Admin preview' })).toHaveCount(0);
        const hrefs = await page.locator('a[href]').evaluateAll((links) => links.map((link) => link.getAttribute('href') ?? ''));
        expect(hrefs.filter((href) => /^\/(login|admin)(\/|$|\?)/.test(href))).toEqual([]);
        expect(await publicRounds(page.request)).toContainEqual({ year: 2026, quarter: 2 });
        await openRoundIsNotPublic(browser);
        const updatedAt = await figuresUpdatedAt(page);
        expect(updatedAt).not.toBeNull();
        saveNotes({ updatedAt });
        await shot(page, 'public-home-before');

        // b. Type /login (no link to it anywhere public), sign in with email and password.
        await page.goto('/login');
        await expect(page.getByLabel('Email address')).toBeVisible({ timeout: 90_000 });
        await shot(page, 'login');
        await tabFromTop(page, page.getByLabel('Email address'));
        await page.keyboard.type(E2E_EMAIL);
        await tabTo(page, page.getByLabel('Password', { exact: true }));
        await page.keyboard.type(E2E_PASSWORD);
        await page.keyboard.press('Enter');
        await expect(page).toHaveURL(/\/admin\/sources/, { timeout: 180_000 });
        await heading(page, 'Data sources');

        // c. Create the round: the form offers the quarter after the last one, with its dates.
        await page.goto('/admin/rounds');
        await heading(page, 'Rounds');
        await expect(page.getByLabel('Year')).toHaveValue('2026');
        await expect(page.getByLabel('Quarter')).toHaveValue('3');
        await expect(page.getByLabel('Window starts')).toHaveValue('2026-07-01');
        await expect(page.getByLabel('Window ends')).toHaveValue('2026-09-30');
        await press(page, page.getByRole('button', { name: 'Create round' }));
        await expect(page.getByRole('status').filter({ hasText: `${ROUND} created. It is open, and not public until it is closed.` })).toBeVisible({ timeout: 60_000 });
        await expect(page.getByRole('row').filter({ hasText: ROUND })).toContainText('Open — not public');
        await shot(page, 'rounds');

        // c. Regenerate the facility media file (it is attached to the form on both platforms by hand).
        await page.goto('/admin/sources');
        await heading(page, 'Data sources');
        await press(page, page.getByRole('button', { name: 'Regenerate facility media file' }));
        await expect(page.getByText(/^Ready: 23 LGAs, [\d,]+ wards, [\d,]+ facilities\.$/)).toBeVisible({ timeout: 120_000 });

        // d. Pull all, and watch both progress panels to the end.
        for (const name of ['ODK Central', 'KoboToolbox']) {
            await expect(page.getByRole('region', { name, exact: true })).toContainText('Connected');
        }
        await press(page, page.getByRole('button', { name: 'Pull all' }));
        await expect(page.getByRole('status').filter({ hasText: /^Pulls started: / })).toBeVisible();
        for (const name of ['ODK Central', 'KoboToolbox']) {
            const progress = page.getByRole('region', { name: `Pull from ${name}`, exact: true });
            await expect(progress).toBeVisible();
            await expect(progress.getByText('Finished')).toBeVisible({ timeout: 600_000 });
            await expect(progress.getByRole('term').filter({ hasText: 'Quarantined' })).toBeVisible();
        }
        await shot(page, 'data-sources');
        const history = page.getByRole('region', { name: 'Pull history' });
        for (const name of ['ODK Central', 'KoboToolbox']) {
            await expect(history.getByRole('row').filter({ hasText: name }).filter({ hasText: 'Succeeded' }).first()).toBeVisible();
        }

        // The round now holds data, and is still not public.
        await openRoundIsNotPublic(browser);
        await page.context().storageState({ path: STATE });
    });
});

// ---------------------------------------------------------------- part 2: e–k

test.describe('Full round, part 2: Data issues to the published round', () => {
    test.skip(part !== '2', 'Run with make e2e-round');
    test.use({ storageState: STATE });
    test.describe.configure({ mode: 'serial' });

    test('e. Data issues: correct, reject, bulk-resolve, a duplicate across sources, a deletion at source', async ({ page }) => {
        test.setTimeout(900_000);

        // The null LGA: the record whose LGA came blank. Its facility code names the LGA.
        const nullLga = (await held(page, 'LGA_UNKNOWN')).find((record) => (record.lga_ref ?? '') === '');
        expect(nullLga, 'a record held for a blank LGA').toBeDefined();
        const record = nullLga as Held;
        await page.goto('/admin/data-issues?rule=LGA_UNKNOWN');
        await heading(page, 'Data issues');
        await shot(page, 'data-issues');
        await press(page, row(page, record.instance_id).getByRole('button', { name: 'Correct & reprocess' }));
        const form = page.getByRole('form', { name: `Correct ${record.instance_id}` });
        await expect(form).toBeVisible();
        await expect(form.getByText('As sent: (empty)').first()).toBeVisible();
        const lga = form.getByLabel('LGA', { exact: true });
        const key = (record.facility_ref ?? '').split('-')[1] ?? '';
        const options = await lga.locator('option').evaluateAll((list) => list.map((option) => ({ value: (option as HTMLOptionElement).value, label: option.textContent ?? '' })));
        const target = options.find((option) => option.label.replace(/[^A-Za-z]/g, '').toUpperCase() === key);
        expect(target, `an LGA matching ${record.facility_ref}`).toBeDefined();
        await chooseWithArrows(page, lga, (target as { value: string }).value);
        await shot(page, 'data-issues-correct');
        await press(page, form.getByRole('button', { name: 'Review and confirm' }), false);
        await confirmWithReason(page, 'Correct and reprocess this submission?', REASONS.nullLga, 'Correct and reprocess');
        await expect(page.getByRole('status').filter({ hasText: /Accepted/ })).toBeVisible({ timeout: 60_000 });
        await expect(row(page, record.instance_id)).toHaveCount(0);

        // Reject one, with a reason: an LGA name that is not one of the 23.
        const unknown = (await held(page, 'LGA_UNKNOWN')).find((other) => (other.lga_ref ?? '') !== '');
        expect(unknown, 'a record held for an unrecognised LGA').toBeDefined();
        await page.goto('/admin/data-issues?rule=LGA_UNKNOWN');
        await heading(page, 'Data issues');
        await press(page, row(page, (unknown as Held).instance_id).getByRole('button', { name: 'Reject' }));
        await confirmWithReason(page, 'Reject this submission?', REASONS.reject, 'Reject');
        await expect(row(page, (unknown as Held).instance_id)).toHaveCount(0);

        // Bulk-resolve ROUND_WINDOW: every visit dated outside the round, selected and rejected at once.
        const wrongQuarter = await held(page, 'ROUND_WINDOW');
        expect(wrongQuarter.length).toBeGreaterThanOrEqual(2);
        await page.goto('/admin/data-issues?rule=ROUND_WINDOW');
        await heading(page, 'Data issues');
        await page.locator('body').focus();
        for (const other of wrongQuarter) {
            await tabTo(page, page.getByRole('checkbox', { name: `Select ${other.instance_id}` }));
            await page.keyboard.press('Space');
        }
        const bar = page.getByRole('group', { name: 'Bulk actions' });
        await expect(bar).toContainText(`${wrongQuarter.length} selected`);
        await press(page, bar.getByRole('button', { name: 'Reject selected' }));
        await confirmWithReason(page, `Reject ${wrongQuarter.length} submissions?`, REASONS.roundWindow, 'Reject');
        await expect(page.getByRole('status').filter({ hasText: `${wrongQuarter.length} records resolved.` })).toBeVisible({ timeout: 60_000 });

        // A visit recorded on both ODK Central and KoboToolbox: use the held copy instead.
        const comparison = page.getByRole('table', { name: 'Both sides of the duplicate' }).filter({ hasText: 'ODK Central' }).filter({ hasText: 'KoboToolbox' }).first();
        await page.goto('/admin/data-issues?rule=DUPLICATE_ASSESSMENT');
        await heading(page, 'Data issues');
        await expect(comparison).toBeVisible();
        await expect(comparison.getByRole('rowheader', { name: 'Overall it scores' })).toBeVisible();
        await comparison.scrollIntoViewIfNeeded();
        await shot(page, 'data-issues-duplicate');
        await press(page, page.getByRole('button', { name: 'Use this one instead' }).first());
        await confirmWithReason(page, 'Use this submission instead?', REASONS.duplicate, 'Use this one instead');
        await expect(page.getByRole('status').filter({ hasText: 'This submission now counts in place of the earlier one.' })).toBeVisible({ timeout: 60_000 });

        // The visit deleted on KoboToolbox: withdraw its assessment.
        await page.goto('/admin/data-issues?tab=deleted');
        await heading(page, 'Data issues');
        await expect(page.getByRole('group', { name: 'Kind of issue' }).getByRole('button', { name: /Deleted at source \(1\)/ })).toHaveAttribute('aria-pressed', 'true');
        await shot(page, 'data-issues-deleted');
        await press(page, page.getByRole('button', { name: /^Withdraw the assessment of / }).first());
        await confirmWithReason(page, 'Withdraw this assessment?', REASONS.withdraw, 'Withdraw assessment');
        await expect(page.getByRole('status').filter({ hasText: 'Withdrawn: the assessment no longer counts.' })).toBeVisible({ timeout: 60_000 });
    });

    test('f. correct an accepted assessment in the admin facility view', async ({ page }) => {
        test.setTimeout(600_000);
        // From the admin preview of the Facility view, Q3 2026, through the drawer's link.
        await page.goto('/facilities?year=2026&quarter=3');
        await heading(page, 'Facility view');
        const trigger = page.getByRole('table', { name: /Facilities in view/ }).getByRole('rowheader').first().getByRole('button');
        const name = ((await trigger.textContent()) ?? '').trim();
        await trigger.focus();
        await page.keyboard.press('Enter');
        const drawer = page.getByRole('dialog', { name });
        await expect(drawer.getByRole('heading', { level: 3, name: `Scores, ${ROUND}` })).toBeVisible({ timeout: 90_000 });
        await press(page, drawer.getByRole('link', { name: 'Open the admin facility view' }), false);
        await expect(page).toHaveURL(/\/admin\/facilities\/\d+$/, { timeout: 90_000 });
        const facilityId = Number(new URL(page.url()).pathname.split('/').pop());
        saveNotes({ ...notes(), correctedFacility: facilityId, correctedName: name });

        const view = page.getByRole('main');
        await expect(view.getByRole('heading', { level: 2, name })).toBeVisible({ timeout: 90_000 });
        await press(page, view.getByRole('button', { name: 'Correct this assessment' }));
        const panel = view.getByRole('region', { name: 'Correcting this assessment' });
        await expect(panel).toBeVisible();
        const select = view.getByRole('combobox').first();
        const before = await select.inputValue();
        const after = before === 'pass' ? 'fail' : 'pass';
        await tabTo(page, select);
        await page.keyboard.type(after === 'pass' ? 'P' : 'F');
        await expect(select).toHaveValue(after);
        await expect(panel.getByRole('status')).toContainText('1 item changed:');
        await shot(page, 'facility-correction');
        await press(page, panel.getByRole('button', { name: 'Review and save' }), false);
        await confirmWithReason(page, 'Save this correction?', REASONS.correct, 'Save correction');
        await expect(view.getByRole('button', { name: 'Correct this assessment' })).toBeVisible({ timeout: 90_000 });
        const changes = view.getByRole('heading', { level: 3, name: 'Changes' }).locator('xpath=..');
        await expect(changes.getByRole('listitem').filter({ hasText: 'Corrected' }).filter({ hasText: REASONS.correct }).first()).toBeVisible();
    });

    test('g. edit and deactivate a facility, then regenerate the media file when prompted', async ({ page }) => {
        test.setTimeout(600_000);
        await page.goto('/admin/facilities');
        await heading(page, 'Facility list');
        // One facility not touched so far: the first active one on the list that is not the corrected one.
        const table = page.getByRole('table', { name: /Facility master list/ });
        const rows = table.getByRole('row').filter({ has: page.getByRole('rowheader') });
        const corrected = notes().correctedName;
        let code = '';
        for (let i = 0; i < (await rows.count()); i += 1) {
            const candidate = rows.nth(i);
            const name = ((await candidate.getByRole('rowheader').textContent()) ?? '').trim();
            if (name !== corrected && (await candidate.getByRole('button', { name: /^Deactivate… / }).count()) === 1) {
                code = ((await candidate.getByRole('cell').first().textContent()) ?? '').trim();
                break;
            }
        }
        expect(code).not.toBe('');

        // Search by its code: one row.
        await tabFromTop(page, page.getByLabel('Search', { exact: true }));
        await page.keyboard.type(code);
        await expect(page).toHaveURL(new RegExp(`[?&]q=${code}`), { timeout: 60_000 });
        await expect(rows).toHaveCount(1, { timeout: 60_000 });
        const original = ((await rows.first().getByRole('rowheader').textContent()) ?? '').trim();
        const renamed = `${original} (renamed)`;

        // Edit: the name field has focus when the editor opens.
        await press(page, rows.first().getByRole('button', { name: `Edit ${original}` }));
        await expect(page.getByRole('heading', { level: 3, name: `Edit ${original}` })).toBeVisible();
        await page.keyboard.press('ControlOrMeta+A');
        await page.keyboard.type(renamed);
        await shot(page, 'facility-list-edit');
        await page.keyboard.press('Enter');
        await expect(page.getByRole('status').filter({ hasText: `${renamed} is saved.` })).toBeVisible({ timeout: 60_000 });

        // Deactivate, with a reason; never a delete.
        await press(page, rows.first().getByRole('button', { name: `Deactivate… ${renamed}` }));
        await confirmWithReason(page, `Deactivate ${renamed}`, REASONS.deactivate, 'Deactivate');
        await expect(page.getByRole('status').filter({ hasText: `${renamed} is deactivated.` })).toBeVisible({ timeout: 60_000 });
        await expect(rows.first()).toContainText('Deactivated');

        // The prompt: the media file is out of date. Regenerate it.
        await expect(page.getByText('The facility media file is out of date — regenerate it and attach it to the form on ODK Central and KoboToolbox.')).toBeVisible({ timeout: 60_000 });
        await shot(page, 'facility-list');
        await press(page, page.getByRole('button', { name: 'Regenerate facility media file' }));
        await expect(page.getByText(/^Ready: 23 LGAs, [\d,]+ wards, [\d,]+ facilities\.$/)).toBeVisible({ timeout: 120_000 });
        await expect(page.getByText('The facility media file is out of date', { exact: false })).toHaveCount(0, { timeout: 60_000 });
    });

    test('h. draft, preview and publish a band change', async ({ page }) => {
        test.setTimeout(900_000);
        await page.goto('/admin/scoring-rules');
        await expect(page.getByRole('heading', { name: /^Current rule version: v\d+$/ })).toBeVisible({ timeout: 90_000 });
        await press(page, page.getByRole('button', { name: /^Start a draft from v\d+$/ }));
        const strong = page.getByLabel('Strong from');
        await tabFromTop(page, strong);
        await page.keyboard.press('ControlOrMeta+A');
        await page.keyboard.type('85');
        await page.keyboard.press('Enter'); // the draft editor is a form: Enter saves it
        await expect(page.getByRole('status').filter({ hasText: /^Draft v\d+ saved\.$/ })).toBeVisible({ timeout: 60_000 });

        await focusAndPress(page, page.getByRole('button', { name: 'Preview impact' }));
        await expect(page.getByText(/facility\(ies\) and \d+ LGA\(s\) change band/).first()).toBeVisible({ timeout: 180_000 });
        await expect(page.getByText('The public dashboard only changes when an open round is closed.')).toBeVisible();
        await shot(page, 'scoring-rules-preview');

        await focusAndPress(page, page.getByRole('button', { name: 'Publish draft…' }));
        await confirmWithReason(page, /^Publish v\d+$/, REASONS.publish, 'Publish');
        await expect(page.getByRole('status').filter({ hasText: 'is published. The open rounds are being rescored.' })).toBeVisible({ timeout: 60_000 });
        await expect(page.getByText(/^Rescore .+: Finished$/).first()).toBeVisible({ timeout: 600_000 });
    });

    test('i. reconcile every source, then close the round', async ({ page }) => {
        test.setTimeout(900_000);
        await page.goto('/admin/rounds');
        await heading(page, 'Rounds');
        await press(page, page.getByRole('button', { name: `Close… ${ROUND}` }));
        await expect(page.getByRole('heading', { name: `Close ${ROUND}` })).toBeVisible();

        await press(page, page.getByRole('button', { name: 'Reconcile all sources now' }));
        for (const name of ['ODK Central', 'KoboToolbox']) {
            const progress = page.getByRole('region', { name: `Reconcile ${name}`, exact: true });
            await expect(progress.getByText('Finished')).toBeVisible({ timeout: 600_000 });
        }
        await shot(page, 'rounds-close');

        await press(page, page.getByRole('button', { name: 'Close round…' }));
        await confirmWithReason(page, `Close ${ROUND}`, REASONS.close, 'Close and publish');
        await expect(page.getByRole('status').filter({ hasText: `${ROUND} is closed and its figures are on the public dashboard.` })).toBeVisible({ timeout: 120_000 });
        await expect(page.getByRole('row').filter({ hasText: ROUND })).toContainText('Closed — public');
    });

    test('j. the audit log shows every step, with its reason and source', async ({ page }) => {
        test.setTimeout(600_000);
        await page.goto('/admin/audit');
        await heading(page, /Audit log/);

        // Filter by event with the keyboard once; then by URL.
        const event = page.getByLabel('Event', { exact: true });
        await tabFromTop(page, event);
        await page.keyboard.type('Rou'); // typeahead: "Round closed", the only event starting so
        await expect(page).toHaveURL(/[?&]event=closed/, { timeout: 60_000 });
        await expect(page.getByRole('row').filter({ hasText: REASONS.close }).first()).toContainText('E2E Administrator');
        await shot(page, 'audit-log');

        const expected: [string, string[]][] = [
            // Models' own entries (every score computed) are "created" too: the round's is found by its type.
            ['created&type=round', [ROUND]],
            ['cascade_exported', ['Facility media file generated']],
            ['corrected', [REASONS.nullLga, REASONS.correct]],
            ['rejected', [REASONS.reject, REASONS.roundWindow]],
            ['assessment_replaced', [REASONS.duplicate]],
            ['assessment_withdrawn', [REASONS.withdraw]],
            ['facility_updated', ['Facility changed']],
            ['facility_deactivated', [REASONS.deactivate]],
            ['published', [REASONS.publish]],
            ['closed', [REASONS.close]],
        ];
        for (const [name, texts] of expected) {
            await page.goto(`/admin/audit?event=${name}`);
            await heading(page, /Audit log/);
            for (const text of texts) {
                await expect(page.getByRole('row').filter({ hasText: text }).filter({ hasText: 'E2E Administrator' }).first(), `${name}: ${text}`).toBeVisible();
            }
        }

        // The pulls and reconciles name their source.
        for (const source of ['odk', 'kobo']) {
            await page.goto(`/admin/audit?source=${source}`);
            await heading(page, /Audit log/);
            await expect(page.getByRole('row').filter({ hasText: 'Pull requested' }).filter({ hasText: source }).first()).toBeVisible();
            await expect(page.getByRole('row').filter({ hasText: 'Reconcile requested' }).filter({ hasText: source }).first()).toBeVisible();
        }

        // The sign-in is there too, and My account lists it.
        await page.goto('/admin/audit?type=auth');
        await heading(page, /Audit log/);
        await expect(page.getByRole('row').filter({ hasText: 'Login' }).filter({ hasText: 'E2E Administrator' }).first()).toBeVisible();
        await page.goto('/admin/account');
        const confirm = page.getByLabel('Password', { exact: true });
        if (await confirm.isVisible({ timeout: 10_000 }).catch(() => false)) {
            await tabFromTop(page, confirm);
            await page.keyboard.type(E2E_PASSWORD);
            await page.keyboard.press('Enter');
        }
        await expect(page.getByRole('heading', { name: 'Your recent sign-ins' })).toBeVisible({ timeout: 90_000 });
        await shot(page, 'my-account');
    });

    test('l. export the closed round from the admin area: facility scores (Excel), LGA table (CSV), the workplan, the PDF report', async ({ page }) => {
        test.setTimeout(900_000);
        mkdirSync(DOWNLOADS, { recursive: true });
        const done: ExportNote[] = [];

        /**
         * One export, from the keyboard: Export…, the type and format where there is a choice,
         * Start export; then wait for the download link (the worker writes it), follow it and
         * check the file's first bytes.
         */
        const exportAndDownload = async (scope: Locator, label: string, choices: string[], file: RegExp, magic: Buffer): Promise<void> => {
            await press(page, scope.getByRole('button', { name: 'Export…' }));
            for (const choice of choices) {
                const radio = scope.getByRole('radio', { name: choice, exact: true });
                await radio.focus();
                await page.keyboard.press('Space');
                await expect(radio).toBeChecked();
            }
            const started = Date.now();
            await focusAndPress(page, scope.getByRole('button', { name: 'Start export' }));
            const link = scope.getByRole('link', { name: /^Download / });
            await expect(link).toBeVisible({ timeout: 600_000 });
            const seconds = (Date.now() - started) / 1000;
            const href = (await link.getAttribute('href')) ?? '';
            expect(href, `${label}: a relative signed link`).toMatch(/^\/admin\/exports\/\d+\/download\?.*signature=/);

            const downloading = page.waitForEvent('download');
            await link.focus();
            await page.keyboard.press('Enter');
            const download = await downloading;
            const name = download.suggestedFilename();
            expect(name, `${label}: file name`).toMatch(file);
            const path = `${DOWNLOADS}/${name}`;
            await download.saveAs(path);
            expect(readFileSync(path).subarray(0, magic.length).equals(magic), `${label}: ${name} starts like a ${file.source.split('.').pop()} file`).toBe(true);
            done.push({ label, file: name, href, id: Number(/\/admin\/exports\/(\d+)\//.exec(href)?.[1]), seconds });
        };

        // Facility scores, Excel: the admin preview of the Facility view, for the closed round.
        await page.goto('/facilities?year=2026&quarter=3');
        await heading(page, 'Facility view');
        const preview = page.getByRole('region', { name: 'Admin preview' });
        await exportAndDownload(preview, 'Facility scores (Excel)', ['Facility scores (every page, every metric)', 'Excel'], /^facility_scores-\d{8}-\d{4}\.xlsx$/, Buffer.from('PK'));

        // The LGA table, CSV (the default format): the admin preview of the LGA view.
        await page.goto('/lgas?year=2026&quarter=3');
        await heading(page, 'LGA view');
        await exportAndDownload(page.getByRole('region', { name: 'Admin preview' }), 'LGA table (CSV)', [], /^lga_table-\d{8}-\d{4}\.csv$/, Buffer.from([0xef, 0xbb, 0xbf]));

        // The workplan, Excel only: the admin Implementation plan page, for the closed round.
        await page.goto('/admin/plan');
        const round = await page.getByRole('option', { name: ROUND, exact: true }).getAttribute('value');
        await page.goto(`/admin/plan?round=${round}`);
        await heading(page, 'Implementation plan');
        await exportAndDownload(page.locator('main'), 'Workplan (Excel)', [], /^workplan-\d{8}-\d{4}\.xlsx$/, Buffer.from('PK'));

        // The PDF round report: the Rounds page panel, which offers the latest closed round first.
        await page.goto('/admin/rounds');
        await heading(page, 'Rounds');
        const report = page.getByRole('region', { name: 'Round report (PDF)' });
        await expect(report.getByRole('combobox', { name: 'Round' })).toHaveValue(round ?? '');
        await exportAndDownload(report, 'Round report (PDF)', [], /^round-report-2026-Q3\.pdf$/, Buffer.from('%PDF-'));

        saveNotes({ ...notes(), exports: done });
        writeFileSync('storage/e2e/export-timings.json', JSON.stringify(done, null, 2));
        process.stdout.write(`Export timings (request to download link): ${done.map((e) => `${e.label} ${e.seconds.toFixed(1)} s`).join('; ')}\n`);

        // Each export is in the audit log: asked for, completed, downloaded.
        for (const event of ['export_requested', 'export_completed', 'export_downloaded']) {
            await page.goto(`/admin/audit?event=${event}`);
            await heading(page, /Audit log/);
            await expect(page.getByRole('row').filter({ hasText: 'E2E Administrator' }), event).toHaveCount(4, { timeout: 30_000 });
        }
    });

    test('k. log out to the public dashboard: Q3 2026 is published, the date moved, nothing private shows', async ({ page }) => {
        test.setTimeout(600_000);
        await page.goto('/admin/rounds');
        await heading(page, 'Rounds');
        await press(page, page.getByRole('button', { name: 'Log out' }));
        await expect(page).toHaveURL(/\/$/, { timeout: 90_000 });
        await heading(page, 'Home');
        await expect(page.getByRole('region', { name: 'Admin preview' })).toHaveCount(0);

        // The closed round is the newest published one, and "Figures last updated" has moved on.
        expect((await publicRounds(page.request))[0]).toEqual({ year: 2026, quarter: 3 });
        const before = notes().updatedAt;
        const after = await figuresUpdatedAt(page);
        expect(after).not.toBeNull();
        expect((after ?? '').slice(0, 10)).not.toBe((before ?? '').slice(0, 10));
        expect(Date.parse(after ?? '')).toBeGreaterThan(Date.parse(before ?? ''));
        await shot(page, 'public-home-after');

        // The leak checks on every public page and the facility API, as the visitor sees them.
        for (const { path, heading: name } of PUBLIC_PAGES) {
            await page.goto(path);
            await heading(page, name);
            await expect(page.getByRole('region', { name: 'Admin preview' })).toHaveCount(0);
            await expect(page.getByRole('button', { name: 'Export…' }), `${path} offers an export`).toHaveCount(0);
            const hrefs = await page.locator('a[href]').evaluateAll((links) => links.map((link) => link.getAttribute('href') ?? ''));
            expect(hrefs.filter((href) => /^\/(login|admin)(\/|$|\?)/.test(href)), `${path} links to the admin area`).toEqual([]);
            assertNothingLeaks(`${path} (rendered)`, await page.content(), null);
            const response = await page.request.get(path);
            const body = await response.text();
            assertNothingLeaks(path, body, inertiaPage(body));
        }
        const list = await page.request.get('/api/public/facilities?year=2026&quarter=3', { headers: { Accept: 'application/json' } });
        expect(list.status()).toBe(200);
        assertNothingLeaks('/api/public/facilities', await list.text(), await list.json());
        const corrected = notes().correctedFacility;
        const detail = await page.request.get(`/api/public/facilities/${corrected}?year=2026&quarter=3`, { headers: { Accept: 'application/json' } });
        expect(detail.status()).toBe(200);
        assertNothingLeaks(`/api/public/facilities/${corrected}`, await detail.text(), await detail.json());

        // No export reaches a visitor: not the signed download links made in step l (still
        // within their 30 minutes), not their progress, not a new request.
        const made = notes().exports ?? [];
        expect(made, 'the four exports of step l').toHaveLength(4);
        for (const done of made) {
            const download = await page.request.get(done.href, { maxRedirects: 0 });
            expect(download.status(), `${done.label}: signed link, signed out`).toBe(302);
            expect(download.headers().location ?? '').toMatch(/\/login$/);
            const progress = await page.request.get(`/admin/api/exports/${done.id}`, { maxRedirects: 0, headers: { Accept: 'application/json' } });
            expect(progress.status(), `${done.label}: progress, signed out`).toBe(401);
        }
        const ask = await page.request.post('/admin/api/exports', {
            maxRedirects: 0,
            headers: { Accept: 'application/json' },
            data: { type: 'round_report', format: 'pdf', filter: {} },
        });
        expect([401, 419], 'a new export, signed out').toContain(ask.status());
    });
});
