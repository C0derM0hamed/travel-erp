#!/usr/bin/env node
/**
 * Browser QA for unified backend export buttons.
 * Requires: server running, DB seeded, playwright installed.
 */
import { chromium } from 'playwright';

const BASE_URL = process.env.BASE_URL || 'http://127.0.0.1:8000';
const PASSWORD = process.env.SEED_USER_PASSWORD || 'travel-erp-test-secret';
const results = [];

function pass(name) { results.push({ name, ok: true }); console.log(`PASS ${name}`); }
function fail(name, err) { results.push({ name, ok: false, err }); console.error(`FAIL ${name}: ${err}`); }

async function login(page, email) {
  await page.goto(`${BASE_URL}/`);
  await page.fill('#loginEmail', email);
  await page.fill('#loginPass', PASSWORD);
  await page.locator('#loginPage button').first().click();
  await page.waitForSelector('#appLayout', { state: 'visible', timeout: 15000 });
}

async function expectExportButtons(page, min = 2) {
  const count = await page.locator('.card-header-actions .btn-outline, .drawer-actions .btn-outline').count();
  if (count < min) throw new Error(`Expected at least ${min} export buttons, found ${count}`);
}

async function main() {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage();

  try {
    await login(page, 'admin@travel.kw');
    pass('login');

    await page.click('[data-page="operations"], a[onclick*="operations"]');
    await page.waitForTimeout(800);
    await expectExportButtons(page);
    pass('operations export buttons');

    await page.click('[data-page="clients"], a[onclick*="clients"]');
    await page.waitForTimeout(800);
    await expectExportButtons(page);
    pass('clients export buttons');

    await page.click('[data-page="vendors"], a[onclick*="vendors"]');
    await page.waitForTimeout(800);
    await expectExportButtons(page);
    pass('vendors export buttons');

    await page.click('[data-page="journal"], a[onclick*="journal"]');
    await page.waitForTimeout(800);
    const journalBtns = await page.locator('.card-header-actions .btn-outline').count();
    if (journalBtns < 3) throw new Error('Journal should expose Excel, PDF, and Print');
    pass('journal export buttons');

    await page.click('[data-page="activity"], a[onclick*="activity"]');
    await page.waitForTimeout(800);
    await expectExportButtons(page);
    pass('activity logs export buttons');

    await page.click('[data-page="reports"], a[onclick*="reports"]');
    await page.waitForTimeout(1200);
    await expectExportButtons(page);
    pass('reports export buttons');
  } catch (e) {
    fail('browser export QA', e.message || String(e));
  } finally {
    await browser.close();
  }

  const failed = results.filter(r => !r.ok);
  console.log('\n--- Export QA Report ---');
  console.log(`Passed: ${results.filter(r => r.ok).length}/${results.length}`);
  if (failed.length) {
    process.exitCode = 1;
    failed.forEach(f => console.log(`- ${f.name}: ${f.err}`));
  } else {
    console.log('Arabic/RTL PDF rendering: verified in PHPUnit (ExportSystemTest)');
  }
}

main();
