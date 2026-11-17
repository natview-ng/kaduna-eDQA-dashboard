import { expect, test } from '@playwright/test';
import { checkA11y } from './support/a11y';

// The Scoring rules page end to end. It PUBLISHES a rule version in the dev
// database (Strong 90 → 85), which cannot be undone short of `make fresh`, so it runs only when
// asked: E2E_SCORING=1 make e2e c="tests/e2e/scoring.spec.ts --project=desktop". Needs an open
// round with scored assessments (the demo seed has one) and the worker running for the rescore.

const asked = process.env.E2E_SCORING === '1';

test.describe.configure({ mode: 'serial' });

test('lowers Strong to 85, previews who moves, publishes, and the admin preview follows the rescore', async ({ page }, testInfo) => {
    test.skip(!asked, 'Publishes a rule version: run with E2E_SCORING=1');
    test.skip(testInfo.project.name !== 'desktop', 'Published once, from the desktop project');
    test.setTimeout(600_000);

    await page.goto('/admin/scoring-rules');
    await expect(page.getByRole('heading', { name: /^Current rule version: v\d+$/ })).toBeVisible();
    await checkA11y(page);

    // Start from a clean draft.
    const discard = page.getByRole('button', { name: 'Discard draft' });
    if (await discard.isVisible()) {
        await discard.click();
        await page.getByRole('button', { name: 'Yes, discard' }).click();
        await expect(page.getByRole('status')).toHaveText('Draft discarded.');
    }
    await page.getByRole('button', { name: /^Start a draft from v\d+$/ }).click();

    // Inline validation first: Acceptable may not reach Strong.
    const strong = page.getByLabel('Strong from');
    await strong.fill('85');
    await page.getByLabel('Acceptable from').fill('86');
    await expect(page.locator('#draft-band-acceptable-error')).toHaveText('Must be below Strong (85).');
    await page.getByLabel('Acceptable from').fill('80');
    await page.getByRole('button', { name: 'Save draft' }).click();
    await expect(page.getByRole('status')).toHaveText(/^Draft v\d+ saved\.$/);

    // The preview names who moves; the count it states is the table's length.
    await page.getByRole('button', { name: 'Preview impact' }).click();
    const summary = page.getByText(/facility\(ies\) and \d+ LGA\(s\) change band/).first();
    await expect(summary).toBeVisible({ timeout: 120_000 });
    const moving = Number((await summary.textContent())?.match(/^(\d+) facility/)?.[1] ?? '0');
    if (moving > 0) {
        await expect(page.getByRole('table', { name: /^Facilities changing band in / }).locator('tbody tr')).toHaveCount(moving);
    }
    await expect(page.getByText('The public dashboard only changes when an open round is closed.')).toBeVisible();
    const after = (await page.locator('dl', { hasText: 'State overall' }).locator('dd > span').last().textContent()) ?? '';
    await checkA11y(page);

    // Publish behind a reason, then follow the open rounds' rescores to the end.
    await page.getByRole('button', { name: 'Publish draft…' }).click();
    const dialog = page.getByRole('dialog', { name: /^Publish v\d+$/ });
    await expect(dialog).toContainText('Closed rounds keep their version.');
    await dialog.getByRole('textbox', { name: 'Reason' }).fill('Strong lowered to 85 (e2e check)');
    await dialog.getByRole('button', { name: 'Publish' }).click();
    await expect(page.getByRole('status')).toContainText('is published. The open rounds are being rescored.');
    await expect(page.getByText(/^Rescore .+: Finished$/).first()).toBeVisible({ timeout: 300_000 });

    // The admin preview of Home now reads the new version: the state overall shows the band the
    // preview said it would.
    const band = after.replace(/^[\d.,]+/, '').trim();
    await page.goto('/');
    await expect(page.getByRole('definition').first()).toContainText(band);
});
