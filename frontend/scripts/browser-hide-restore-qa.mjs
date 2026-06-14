/**
 * Browser QA for Hide/Restore feature
 * Run: node frontend/scripts/browser-hide-restore-qa.mjs
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

  // Clients page has hide button
  await page.locator('.nav-item[data-page="clients"]').click();
  await page.waitForTimeout(1200);
  const clTable = await page.locator('#clTableWrap').innerText();
  if (clTable.includes('إخفاء')) pass('Clients table shows hide action');
  else fail('Clients hide button', clTable.slice(0, 120));

  // Operations page has hide button
  await page.locator('.nav-item[data-page="operations"]').click();
  await page.waitForTimeout(1200);
  const opTable = await page.locator('#opTableWrap').innerText();
  if (opTable.includes('إخفاء')) pass('Operations table shows hide action');
  else fail('Operations hide button', opTable.slice(0, 120));

  // Settings hidden sections
  await page.locator('.nav-item[data-page="settings"]').click();
  await page.waitForTimeout(1500);
  const settingsText = await page.locator('#pageContent').innerText();
  if (settingsText.includes('العملاء المخفيون')) pass('Settings: Hidden Clients section');
  else fail('Settings: Hidden Clients section');
  if (settingsText.includes('العمليات المخفية')) pass('Settings: Hidden Operations section');
  else fail('Settings: Hidden Operations section');

  // Hide first client via UI if available
  await page.locator('.nav-item[data-page="clients"]').click();
  await page.waitForTimeout(1200);
  const hideBtn = page.locator('#clTableWrap button:has-text("إخفاء")').first();
  if (await hideBtn.count()) {
    page.once('dialog', d => d.accept());
    await hideBtn.click();
    await page.waitForTimeout(1500);
    pass('Hide client action triggered');
  } else {
    fail('No hide button to click');
  }

  // Verify hidden client appears in settings
  await page.locator('.nav-item[data-page="settings"]').click();
  await page.waitForTimeout(1500);
  const hiddenClients = await page.locator('#hiddenClientsBody').innerText();
  if (hiddenClients.includes('استعادة') || hiddenClients.includes('مخفي')) pass('Hidden client visible in settings');
  else fail('Hidden client in settings', hiddenClients.slice(0, 120));

  // Restore from settings
  const restoreBtn = page.locator('#hiddenClientsBody button:has-text("استعادة")').first();
  if (await restoreBtn.count()) {
    page.once('dialog', d => d.accept());
    await restoreBtn.click();
    await page.waitForTimeout(1500);
    pass('Restore client from settings');
  } else {
    fail('Restore button in hidden clients');
  }

} catch (e) {
  fail('Browser exception', e.message);
} finally {
  await browser.close();
}

const failed = results.filter(r => r[0] === 'FAIL').length;
console.log('\n======== HIDE/RESTORE BROWSER QA ========');
results.forEach(r => console.log(r.join(' — ')));
console.log(`Passed: ${results.length - failed} | Failed: ${failed}`);
process.exit(failed > 0 ? 1 : 0);
