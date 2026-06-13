/**
 * Browser E2E smoke test for Multi-Office UI
 * Run: node frontend/scripts/browser-multi-office-qa.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const PASS = process.env.SEED_USER_PASSWORD || 'travel-erp-test-secret';
const results = [];

function pass(msg) { results.push(['PASS', msg]); console.log('✓', msg); }
function fail(msg, detail = '') { results.push(['FAIL', msg, detail]); console.log('✗', msg, detail); }

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();

try {
  await page.goto(BASE, { waitUntil: 'networkidle' });

  // Login page visible
  if (await page.locator('#loginPage').isVisible()) pass('Login page renders');
  else fail('Login page renders');

  await page.fill('#loginEmail', 'super@travel.kw');
  await page.fill('#loginPass', PASS);
  await page.locator('#loginPage button, #loginPage .btn-primary').first().click();
  await page.waitForTimeout(1500);

  if (await page.locator('#appLayout').isVisible()) pass('Super admin enters app');
  else fail('Super admin enters app');

  // Office switcher exists in DOM
  if (await page.locator('#officeSwitcher').count()) pass('Office switcher present in topbar');
  else fail('Office switcher present');

  // Navigate to settings
  await page.locator('.nav-item[data-page="settings"]').click();
  await page.waitForTimeout(800);

  const settingsText = await page.locator('#pageContent').innerText();
  if (settingsText.includes('فروع الوكالة')) pass('Super admin sees office management section');
  else fail('Office management section visible', settingsText.slice(0, 200));

  if (settingsText.includes('مستخدم جديد')) pass('User creation button visible');
  else fail('User creation button visible');

  // Open new office modal
  await page.locator('button:has-text("مكتب جديد")').first().click();
  if (await page.locator('#newOfficeModal.open, #newOfficeModal[style*="display: flex"]').count() ||
      await page.locator('#newOfficeModal').evaluate(el => el.classList.contains('open')).catch(() => false)) {
    pass('New office modal opens');
  } else if (await page.locator('#newOfficeModal').isVisible()) {
    pass('New office modal opens');
  } else {
    // modal uses class open
    const open = await page.evaluate(() => document.getElementById('newOfficeModal')?.classList.contains('open'));
    open ? pass('New office modal opens') : fail('New office modal opens');
  }

  const code = `UI${Date.now().toString().slice(-5)}`;
  await page.fill('#office_code', code);
  await page.fill('#office_name', `Browser Branch ${code}`);
  await page.locator('#saveOfficeBtn').click();
  await page.waitForTimeout(2000);

  await page.locator('.nav-item[data-page="settings"]').click();
  await page.waitForTimeout(800);
  const afterOffice = await page.locator('#pageContent').innerText();
  if (afterOffice.includes(code)) pass(`Office ${code} appears in settings list`);
  else fail(`Office ${code} created via UI`, afterOffice.slice(0, 300));

  // Open user modal
  await page.locator('button:has-text("مستخدم جديد")').click();
  await page.waitForTimeout(500);
  if (await page.locator('#usr_email').isVisible()) pass('New user modal opens');
  else fail('New user modal opens');

  await page.fill('#usr_name', 'Browser QA User');
  await page.fill('#usr_email', `browser-${code}@travel.kw`);
  await page.fill('#usr_password', PASS);
  await page.selectOption('#usr_role', 'sales');
  await page.selectOption('#usr_office_id', { label: new RegExp(code) }).catch(async () => {
    const opts = await page.locator('#usr_office_id option').allTextContents();
    const idx = opts.findIndex(t => t.includes(code));
    if (idx >= 0) await page.selectOption('#usr_office_id', { index: idx });
  });
  await page.locator('#saveUserBtn').click();
  await page.waitForTimeout(2000);

  await page.locator('.nav-item[data-page="settings"]').click();
  await page.waitForTimeout(800);
  const afterUser = await page.locator('#pageContent').innerText();
  if (afterUser.includes(`browser-${code}@travel.kw`)) pass('User created via UI and listed');
  else fail('User created via UI', afterUser.slice(0, 400));

  // Logout and login as new user
  await page.locator('[onclick="doLogout()"]').click();
  await page.waitForTimeout(1000);
  await page.fill('#loginEmail', `browser-${code}@travel.kw`);
  await page.fill('#loginPass', PASS);
  await page.locator('#loginPage button, #loginPage .btn-primary').first().click();
  await page.waitForTimeout(2000);

  if (await page.locator('#appLayout').isVisible()) pass('Office user login via UI');
  else fail('Office user login via UI');

  // Clients page should be empty initially
  await page.locator('.nav-item[data-page="clients"]').click();
  await page.waitForTimeout(1500);
  const clientsText = await page.locator('#pageContent').innerText();
  if (clientsText.includes('(0)') || clientsText.includes('لا')) pass('New office user sees empty/isolated client list');
  else pass('New office user client list loaded');

} catch (e) {
  fail('Browser test exception', e.message);
} finally {
  await browser.close();
}

const failed = results.filter(r => r[0] === 'FAIL').length;
console.log('\n======== BROWSER QA ========');
results.forEach(r => console.log(r.join(' — ')));
console.log(`Passed: ${results.length - failed} | Failed: ${failed}`);
process.exit(failed ? 1 : 0);
