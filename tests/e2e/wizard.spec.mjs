import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

const importerUrl = '/wp-admin/admin.php?page=tds-product-importer';

async function login(page) {
	for (let attempt = 0; attempt < 2; attempt += 1) {
		await page.goto('/wp-admin/', { waitUntil: 'domcontentloaded' });
		if (page.url().includes('/wp-admin/')) {
			return;
		}
		await page.getByLabel(/Username|Email Address/i).fill('admin');
		await page.locator('#user_pass').fill('password');
		await page.getByRole('button', { name: /Log In/i }).click();
		try {
			await expect(page).toHaveURL(/wp-admin/, { timeout: 30_000 });
			return;
		} catch (error) {
			if (attempt === 1) {
				throw error;
			}
		}
	}
}

async function openImporter(page) {
	await login(page);
	await page.goto(importerUrl);
	await expect(page.getByRole('heading', { name: 'TDS Product Importer', exact: true })).toBeVisible();
}

async function createCsvDraft(page, suffix) {
	await page.getByRole('button', { name: 'New import', exact: true }).click();
	await expect(page.getByRole('heading', { name: 'Source', exact: true })).toBeFocused();
	await page.getByLabel(/Import name/i).fill(`Browser import ${suffix}`);
	await page.locator('#tds-source-upload').setInputFiles({
		name: `products-${suffix}.csv`,
		mimeType: 'text/csv',
		buffer: Buffer.from(`sku,name,price\nE2E-${suffix},Browser product ${suffix},19.90\n`),
	});
	await expect(page.locator('#tds-importer-admin').getByText(/was stored securely/i)).toBeVisible();
	await page.getByRole('button', { name: /Test connection/i }).click();
	await expect(page.locator('#tds-importer-admin').getByText(/Source tested successfully/i)).toBeVisible();
}

async function mappingTargetCount(page, target) {
	return page.getByLabel('WooCommerce target').evaluateAll(
		(elements, expected) => elements.filter((element) => element.value === expected).length,
		target,
	);
}

async function expectNoSeriousA11yViolations(page) {
	const accessibility = await new AxeBuilder({ page })
		.include('.tds-app')
		.withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
		.analyze();
	const serious = accessibility.violations.filter((violation) => ['serious', 'critical'].includes(violation.impact));
	expect(serious, JSON.stringify(serious, null, 2)).toEqual([]);
}

test('wizard is keyboard accessible, responsive, and passes axe', async ({ page }, testInfo) => {
	await openImporter(page);

	const app = page.locator('.tds-app');
	const viewport = page.viewportSize();
	const width = await app.evaluate((element) => element.scrollWidth);
	expect(width).toBeLessThanOrEqual(viewport.width);

	const importTab = page.getByRole('tab', { name: 'Import' });
	await importTab.focus();
	await page.keyboard.press('ArrowRight');
	await expect(page.getByRole('tab', { name: 'Presets' })).toHaveAttribute('aria-selected', 'true');
	await page.getByRole('tab', { name: 'Import' }).click();

	await expectNoSeriousA11yViolations(page);

	await createCsvDraft(page, `${testInfo.project.name}-${Date.now()}`);
	await expectNoSeriousA11yViolations(page);
	const sourceRadios = page.getByRole('radio');
	await sourceRadios.first().focus();
	await page.keyboard.press('ArrowRight');
	await expect(sourceRadios.nth(1)).toHaveAttribute('aria-checked', 'true');
	await page.keyboard.press('ArrowLeft');
	await expect(sourceRadios.first()).toHaveAttribute('aria-checked', 'true');
	await expect(page.locator('.tds-save-state')).toContainText(/Changes|Saving/i);
	await expect(page.locator('.tds-save-state')).toContainText(/Saved/i);

	if (testInfo.project.name.startsWith('mobile')) {
		await expect(page.locator('.tds-mobile-steps')).toBeVisible();
	}
});

test('CSV wizard resumes after reload, confirms suggestions, and reaches live progress', async ({ page }, testInfo) => {
	await openImporter(page);
	const suffix = `${testInfo.project.name}-${Date.now()}`;
	await createCsvDraft(page, suffix);

	await page.getByRole('button', { name: 'Continue' }).click();
	await expect(page.getByRole('heading', { name: 'Structure', exact: true })).toBeFocused();
	await page.getByRole('button', { name: 'Continue' }).click();
	await expect(page.getByRole('heading', { name: 'Mapping', exact: true })).toBeFocused();
	await expect(page.locator('#tds-importer-admin').getByText(/Nothing has been applied yet/i)).toBeVisible();
	await page.getByRole('button', { name: /Apply suggestions/i }).click();
	await expect(page.locator('#tds-importer-admin').getByText(/Suggestions reviewed/i)).toBeVisible();
	await expect.poll(() => mappingTargetCount(page, 'name')).toBe(1);
	await expectNoSeriousA11yViolations(page);

	await expect(page.locator('.tds-save-state')).toContainText(/Saved/i);
	await page.reload();
	await expect(page.getByRole('heading', { name: 'Mapping', exact: true })).toBeVisible();
	await expect.poll(() => mappingTargetCount(page, 'name')).toBe(1);

	await page.getByRole('button', { name: 'Continue' }).click();
	await expect(page.getByRole('heading', { name: 'Rules', exact: true })).toBeFocused();
	await page.getByLabel('Missing products').selectOption('trash');
	await page.getByRole('button', { name: 'Continue' }).click();
	await expect(page.getByRole('heading', { name: 'Review', exact: true })).toBeFocused();
	await expect(page.locator('#tds-importer-admin').getByText(/Preflight successful/i)).toBeVisible({ timeout: 60_000 });
	const startImport = page.getByRole('button', { name: /Start import/i });
	await expect(startImport).toBeDisabled();
	await page.getByLabel(/I confirm that missing products/i).check();
	await expect(startImport).toBeEnabled();
	await expectNoSeriousA11yViolations(page);
	await startImport.click();
	await expect(page.getByRole('heading', { name: 'Progress', exact: true })).toBeFocused();
	await expect(page.getByRole('progressbar')).toBeVisible();
	await expect(page.locator('#tds-importer-admin').getByText(/Records \/ minute/i)).toBeVisible();
	await expectNoSeriousA11yViolations(page);

	await page.reload();
	await expect(page.getByRole('progressbar')).toBeVisible();
	await expect(page.locator('.tds-status')).toHaveText(/completed|partial|failed/i, { timeout: 90_000 });
});

test('stale draft revisions surface a recoverable autosave conflict', async ({ page }) => {
	await openImporter(page);
	await page.getByRole('button', { name: 'New import', exact: true }).click();
	await expect(page).toHaveURL(/[?&]draft=\d+/);
	await page.evaluate(async () => {
		const id = Number(new URL(window.location.href).searchParams.get('draft'));
		const drafts = await window.wp.apiFetch({ path: '/tds-import/v1/wizard/drafts' });
		const draft = drafts.find((row) => row.id === id);
		if (!draft) {
			throw new Error(`Draft ${id} was not returned by the API.`);
		}
		const payload = { name: draft.name, config: draft.config, revision: draft.revision, wizard_step: 1 };
		await window.wp.apiFetch({ path: `/tds-import/v1/wizard/drafts/${id}`, method: 'PATCH', data: payload });
	});
	await page.getByLabel(/Import name/i).fill('Conflicting local name');
	await expect(page.locator('#tds-importer-admin').getByText(/changed in another window/i)).toBeVisible({ timeout: 15_000 });
	const reloadServer = page.getByRole('button', { name: /Reload server version/i });
	await expect(reloadServer).toBeFocused();
	await reloadServer.click();
	await page.waitForLoadState('domcontentloaded');
	await expect(page.getByRole('heading', { name: 'Source', exact: true })).toBeVisible();
	await expect(page.getByLabel(/Import name/i)).not.toHaveValue('Conflicting local name');
});
