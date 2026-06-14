/**
 * Browser QA for Create Operation flow
 * Run: node frontend/scripts/browser-operation-create-qa.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const PASS = process.env.SEED_USER_PASSWORD || 'travel-erp-test-secret';
const results = [];

function pass(msg) { results.push(['PASS', msg]); console.log('✓', msg); }
function fail(msg, detail = '') { results.push(['FAIL', msg, detail]); console.log('✗', msg, detail); }

async function login(page, email) {
  await page.goto(BASE, { waitUntil: 'networkidle' });
  await page.fill('#loginEmail', email);
  await page.fill('#loginPass', PASS);
  await page.locator('#loginPage button').first().click();
  await page.waitForTimeout(2200);
}

async function createOperation(page, label) {
  let apiStatus = null;
  let apiBody = '';
  const handler = async (res) => {
    if (res.url().includes('/api/operations') && res.request().method() === 'POST') {
      apiStatus = res.status();
      apiBody = await res.text().catch(() => '');
    }
  };
  page.on('response', handler);

  await page.locator('#topbarNewOpBtn').click();
  await page.waitForTimeout(1800);

  const clientOpts = await page.locator('#op_client option').count();
  const vendorOpts = await page.locator('#op_vendor option').count();
  if (clientOpts < 2 && vendorOpts < 2) {
    const err = (await page.locator('#newOpModalError').textContent().catch(() => ''))?.trim();
    if (err && err.includes('المكتب الحالي')) {
      pass(`${label}: empty office shows Arabic guidance`);
      page.off('response', handler);
      if (await page.locator('#newOpModal.open').count()) await page.locator('#newOpModal .modal-close').click().catch(() => {});
      return;
    }
    fail(`${label}: client dropdown populated`, `options=${clientOpts}`);
    page.off('response', handler);
    return;
  }
  if (vendorOpts < 2) {
    const err = (await page.locator('#newOpModalError').textContent().catch(() => ''))?.trim();
    if (err && err.includes('مورد')) {
      pass(`${label}: missing vendors shows Arabic guidance`);
      page.off('response', handler);
      if (await page.locator('#newOpModal.open').count()) await page.locator('#newOpModal .modal-close').click().catch(() => {});
      return;
    }
    fail(`${label}: vendor dropdown populated`, `options=${vendorOpts}`);
    page.off('response', handler);
    return;
  }
  pass(`${label}: dropdowns loaded (clients=${clientOpts - 1}, vendors=${vendorOpts - 1})`);

  await page.selectOption('#op_client', { index: 1 });
  await page.selectOption('#op_service', { index: 1 });
  await page.selectOption('#op_vendor', { index: 1 });
  await page.fill('#op_client_price', '175');
  await page.fill('#op_vendor_cost', '120');
  await page.locator('#newOpModal .btn-primary').click();
  await page.waitForTimeout(2200);

  const err = (await page.locator('#newOpModalError').textContent().catch(() => ''))?.trim();
  page.off('response', handler);

  if (apiStatus === 201) {
    pass(`${label}: operation created`);
  } else {
    fail(`${label}: operation created`, err || apiBody.slice(0, 200));
  }

  if (err && /invalid|error|required/i.test(err)) {
    fail(`${label}: no English/technical error shown`, err);
  } else if (err) {
    pass(`${label}: Arabic error message when failed`);
  } else {
    pass(`${label}: no error banner after success`);
  }

  if (await page.locator('#newOpModal.open').count()) {
    await page.locator('#newOpModal .modal-close').click().catch(() => {});
  }
}

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();

try {
  await login(page, 'sales@travel.kw');
  if (await page.locator('#appLayout').isVisible()) pass('Sales user enters app');
  else fail('Sales user enters app');
  await createOperation(page, 'sales');

  await page.locator('.topbar-actions .btn-icon').last().click();
  await page.waitForTimeout(1000);

  await login(page, 'admin@travel.kw');
  if (await page.locator('#appLayout').isVisible()) pass('Admin user enters app');
  else fail('Admin user enters app');
  await createOperation(page, 'admin');

  await page.locator('.topbar-actions .btn-icon').last().click();
  await page.waitForTimeout(1000);

  await login(page, 'super@travel.kw');
  if (await page.locator('#appLayout').isVisible()) pass('Super admin enters app');
  else fail('Super admin enters app');
  await createOperation(page, 'super_admin');

  const officeCount = await page.locator('#officeSwitcher option').count();
  if (officeCount > 1) {
    await page.selectOption('#officeSwitcher', { index: 1 });
    await page.waitForTimeout(2200);
    await createOperation(page, 'super_admin_switched_office');
  } else {
    results.push(['SKIP', 'super_admin office switch', 'only one office']);
    console.log('~ super_admin office switch skipped (single office)');
  }

  // Stale cross-office IDs should be blocked client-side or server-side in Arabic
  await page.evaluate(() => {
    document.getElementById('op_client').innerHTML = '<option value="1">Stale</option>';
    document.getElementById('op_vendor').innerHTML = '<option value="1">Stale</option>';
    document.getElementById('op_service').innerHTML = '<option value="1">Svc</option>';
    document.getElementById('op_client').value = '1';
    document.getElementById('op_vendor').value = '1';
    document.getElementById('op_service').value = '1';
    document.getElementById('op_client_price').value = '100';
    document.getElementById('op_vendor_cost').value = '80';
    showModal('newOpModal');
  });
  await page.locator('#newOpModal .btn-primary').click();
  await page.waitForTimeout(1500);
  const staleErr = (await page.locator('#newOpModalError').textContent().catch(() => ''))?.trim();
  if (staleErr && !/invalid/i.test(staleErr)) pass('Stale IDs blocked with Arabic message');
  else fail('Stale IDs blocked with Arabic message', staleErr || '(no error)');

} catch (e) {
  fail('QA script', e.message);
}

await browser.close();

const failed = results.filter(r => r[0] === 'FAIL').length;
console.log('\n--- Summary ---');
results.forEach(r => console.log(r.join(' | ')));
console.log(`\n${results.filter(r => r[0] === 'PASS').length} passed, ${failed} failed, ${results.filter(r => r[0] === 'SKIP').length} skipped`);
process.exit(failed ? 1 : 0);
