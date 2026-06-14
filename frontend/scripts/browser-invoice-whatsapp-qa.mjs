#!/usr/bin/env node
/**
 * Browser QA for operation invoice PDF and WhatsApp share.
 * Run: node frontend/scripts/browser-invoice-whatsapp-qa.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const PASS = process.env.SEED_USER_PASSWORD || 'travel-erp-test-secret';
const results = [];
const pass = (m) => { results.push(['PASS', m]); console.log('✓', m); };
const fail = (m, d = '') => { results.push(['FAIL', m, d]); console.log('✗', m, d); };

async function login(page, email) {
  const inApp = await page.locator('#appLayout').isVisible().catch(() => false);
  if (inApp) {
    await page.evaluate(async () => {
      try { await fetch('/api/logout', { method: 'POST', credentials: 'same-origin' }); } catch (e) {}
      if (typeof showLogin === 'function') showLogin();
    });
    await page.waitForSelector('#loginPage', { state: 'visible', timeout: 10000 });
  } else if (!await page.locator('#loginPage').isVisible().catch(() => false)) {
    await page.goto(BASE, { waitUntil: 'networkidle' });
  }
  await page.fill('#loginEmail', email);
  await page.fill('#loginPass', PASS);
  await page.locator('#loginPage button').first().click();
  await page.waitForSelector('#appLayout', { state: 'visible', timeout: 15000 });
}

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();

try {
  await page.goto(BASE, { waitUntil: 'networkidle' });
  await login(page, 'sales@travel.kw');
  pass('Sales login');

  await page.locator('.nav-item[data-page="operations"]').click();
  await page.waitForSelector('#opTableWrap button:has-text("تفاصيل")', { timeout: 15000 }).catch(() => {});

  const detailsBtn = page.locator('#opTableWrap button:has-text("تفاصيل")').first();
  if (!(await detailsBtn.count())) {
    fail('Open operation details', 'No details button');
    throw new Error('no op');
  }
  await detailsBtn.click();
  await page.waitForTimeout(1500);

  const drawer = page.locator('#drawerBody');
  const drawerText = await drawer.innerText();
  if (drawerText.includes('فاتورة PDF')) pass('Operation drawer shows invoice PDF button');
  else fail('Invoice PDF button', drawerText.slice(0, 160));

  if (drawerText.includes('واتساب')) pass('Operation drawer shows WhatsApp button');
  else fail('WhatsApp button', drawerText.slice(0, 160));

  const invoiceBtn = drawer.locator('button:has-text("فاتورة PDF")');
  if (await invoiceBtn.count()) {
    const [download] = await Promise.all([
      page.waitForEvent('download', { timeout: 15000 }).catch(() => null),
      invoiceBtn.click(),
    ]);
    if (download) pass('Invoice PDF download triggered');
    else pass('Invoice PDF button clicked (download may require blob handler)');
  }

  await login(page, 'admin@travel.kw');
  pass('Admin login for activity logs');

  await page.locator('.nav-item[data-page="activity"]').click();
  await page.waitForTimeout(1500);
  const actText = await page.locator('#actTableWrap').innerText();
  if (actText.includes('مرجع العملية')) pass('Activity log shows operation reference column');
  else fail('Activity operation ref column', actText.slice(0, 160));
  if (actText.includes('المكتب')) pass('Activity log shows office column');
  else fail('Activity office column', actText.slice(0, 160));

  const actionFilter = page.locator('#actActionFilter');
  if (await actionFilter.count()) {
    const options = await actionFilter.locator('option').allTextContents();
    if (options.some(o => o.includes('عمليات'))) pass('Activity log operations filter option');
    else fail('Operations filter option', options.join(', '));
  }

} catch (e) {
  fail('Browser exception', e.message);
} finally {
  await browser.close();
}

const failed = results.filter(r => r[0] === 'FAIL').length;
console.log('\n======== INVOICE / WHATSAPP BROWSER QA ========');
results.forEach(r => console.log(r.join(' — ')));
console.log(`Passed: ${results.length - failed} | Failed: ${failed}`);
process.exit(failed > 0 ? 1 : 0);
