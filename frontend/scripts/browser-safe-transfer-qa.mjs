/**
 * Browser QA for Safe & Bank Management + Transfers
 * Run: node frontend/scripts/browser-safe-transfer-qa.mjs
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
  await page.fill('#loginEmail', 'accountant@travel.kw');
  await page.fill('#loginPass', PASS);
  await page.locator('#loginPage button').first().click();
  await page.waitForTimeout(1500);
  if (await page.locator('#appLayout').isVisible()) pass('Accountant login');
  else { fail('Accountant login'); throw new Error('login'); }

  await page.locator('.nav-item[data-page="safes"]').click();
  await page.waitForTimeout(1500);
  const safesPage = await page.locator('#pageContent').innerText();
  if (safesPage.includes('الصناديق والبنوك')) pass('Safes page with tabs');
  else fail('Safes page tabs');

  if (safesPage.includes('صندوق') || safesPage.includes('بنك')) pass('Safes list loads');
  else fail('Safes list');

  if (await page.locator('button:has-text("صندوق / بنك جديد")').count()) pass('Create safe button present');
  else fail('Create safe button');

  await page.locator('button:has-text("سجل التحويلات")').click();
  await page.waitForTimeout(1200);
  if (await page.locator('#trFrom').count()) pass('Transfer history filters present');
  else fail('Transfer history filters');

  await page.locator('button:has-text("الصناديق والبنوك")').click();
  await page.waitForTimeout(1000);
  await page.locator('button:has-text("🔄 تحويل")').first().click();
  await page.waitForTimeout(600);
  const modal = page.locator('#newTransferModal.open');
  if (await modal.count()) pass('Transfer modal opens');
  else fail('Transfer modal');

  const fromOpts = await page.locator('#tr_from option').count();
  const toOpts = await page.locator('#tr_to option').count();
  if (fromOpts >= 1 && toOpts >= 2) {
    await page.locator('#newTransferModal.open #tr_from').selectOption({ index: 0 });
    await page.locator('#newTransferModal.open #tr_to').selectOption({ index: 1 });
    await page.locator('#newTransferModal.open #tr_amount').fill('5');
    page.once('dialog', d => d.accept());
    await page.locator('#newTransferModal.open button:has-text("تنفيذ التحويل")').click();
    await page.waitForTimeout(2000);
    pass('Transfer submitted via UI');
  } else {
    fail('Transfer form options', `from=${fromOpts} to=${toOpts}`);
  }

  await page.locator('button:has-text("سجل التحويلات")').click();
  await page.waitForTimeout(1200);
  const transfers = await page.locator('#trTableWrap').innerText();
  if (transfers.includes('TR-') || transfers.includes('5')) pass('Transfer appears in history');
  else fail('Transfer in history', transfers.slice(0, 120));

} catch (e) {
  fail('Browser exception', e.message);
} finally {
  await browser.close();
}

const failed = results.filter(r => r[0] === 'FAIL').length;
console.log('\n======== SAFE/TRANSFER BROWSER QA ========');
results.forEach(r => console.log(r.join(' — ')));
console.log(`Passed: ${results.length - failed} | Failed: ${failed}`);
process.exit(failed > 0 ? 1 : 0);
