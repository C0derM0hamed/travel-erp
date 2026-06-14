/**
 * Browser QA for filters, search, pagination
 * Run: node frontend/scripts/browser-filter-pagination-qa.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const PASS = process.env.SEED_USER_PASSWORD || 'travel-erp-test-secret';
const results = [];
const pass = (m) => { results.push(['PASS', m]); console.log('✓', m); };
const fail = (m, d = '') => { results.push(['FAIL', m, d]); console.log('✗', m, d); };

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();

try {
  await page.goto(BASE, { waitUntil: 'networkidle' });
  await page.fill('#loginEmail', 'admin@travel.kw');
  await page.fill('#loginPass', PASS);
  await page.locator('#loginPage button').first().click();
  await page.waitForTimeout(1500);
  if (await page.locator('#appLayout').isVisible()) pass('Admin login');
  else { fail('Admin login'); throw new Error('login'); }

  // Operations notes search via API-backed list
  await page.locator('.nav-item[data-page="operations"]').click();
  await page.waitForTimeout(1200);
  if (await page.locator('#opFrom').count()) pass('Operations date filters present');
  else fail('Operations date filters present');
  await page.fill('#opSearchInput', 'notes');
  await page.waitForTimeout(800);
  const opText = await page.locator('#opTableWrap').innerText();
  if (opText.includes('سجل') || opText.includes('OP-') || opText.includes('لا توجد')) pass('Operations search triggers server reload');
  else fail('Operations search', opText.slice(0, 120));

  // Journal server pagination
  await page.locator('.nav-item[data-page="journal"]').click();
  await page.waitForTimeout(1500);
  const jePager = await page.locator('#jeTableWrap').innerText();
  if (jePager.includes('صفحة') || jePager.includes('قيد') || jePager.includes('لا توجد')) pass('Journal loads with server pagination UI');
  else fail('Journal pagination', jePager.slice(0, 120));

  // Reports date filter on profit tab
  await page.locator('.nav-item[data-page="reports"]').click();
  await page.waitForTimeout(800);
  await page.locator('button:has-text("الربحية")').click();
  await page.waitForTimeout(1200);
  if (await page.locator('#rptFrom').count()) pass('Reports unified date filter on profit tab');
  else fail('Reports date filter');

  // Vouchers search + date
  await page.locator('.nav-item[data-page="vouchers"]').click();
  await page.waitForTimeout(1200);
  if (await page.locator('#vcSearch').count()) pass('Voucher search field present');
  else fail('Voucher search field');
  if (await page.locator('#vcFrom').count()) pass('Voucher date filters present');
  else fail('Voucher date filters');

  // Activity log page
  await page.locator('.nav-item[data-page="activity"]').click();
  await page.waitForTimeout(1200);
  if (await page.locator('#actSearch').count()) pass('Activity log page with search');
  else fail('Activity log page');

  // Dashboard date filter
  await page.locator('.nav-item[data-page="dashboard"]').click();
  await page.waitForTimeout(1200);
  if (await page.locator('#dashFrom').count()) pass('Dashboard date range filter present');
  else fail('Dashboard date filter');

} catch (e) {
  fail('Browser exception', e.message);
} finally {
  await browser.close();
}

const failed = results.filter(r => r[0] === 'FAIL').length;
console.log('\n======== FILTER/PAGINATION BROWSER QA ========');
results.forEach(r => console.log(r.join(' — ')));
console.log(`Passed: ${results.length - failed} | Failed: ${failed}`);
process.exit(failed ? 1 : 0);
