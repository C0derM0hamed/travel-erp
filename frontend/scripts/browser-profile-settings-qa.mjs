/**
 * Browser E2E test for Settings → Personal Profile (account settings)
 * Run: node frontend/scripts/browser-profile-settings-qa.mjs
 */
import { chromium } from 'playwright';

const BASE = process.env.BASE_URL || 'http://127.0.0.1:8000';
const PASS = process.env.SEED_USER_PASSWORD || 'travel-erp-test-secret';
const results = [];

function pass(msg) { results.push(['PASS', msg]); console.log('✓', msg); }
function fail(msg, detail = '') { results.push(['FAIL', msg, detail]); console.log('✗', msg, detail); }

const browser = await chromium.launch({ headless: true });
const page = await browser.newPage();
const stamp = Date.now();
const newEmail = `profile-qa-${stamp}@travel.kw`;
const newPassword = `ProfileQa${stamp}`;

try {
  await page.goto(BASE, { waitUntil: 'networkidle' });
  await page.fill('#loginEmail', 'auditor@travel.kw');
  await page.fill('#loginPass', PASS);
  await page.locator('#loginPage button, #loginPage .btn-primary').first().click();
  await page.waitForTimeout(1500);

  if (await page.locator('#appLayout').isVisible()) pass('Auditor user login');
  else { fail('Auditor user login'); throw new Error('login failed'); }

  await page.locator('.nav-item[data-page="settings"]').click();
  await page.waitForTimeout(800);

  const profileCard = page.locator('.profile-settings');
  if (await profileCard.count()) pass('Personal profile section renders');
  else fail('Personal profile section renders');

  if (await page.locator('#prof_email').isEditable()) pass('Email field is editable');
  else fail('Email field is editable');

  for (const id of ['prof_current_password', 'prof_new_password', 'prof_confirm_password']) {
    if (await page.locator(`#${id}`).count()) pass(`Password field ${id} present`);
    else fail(`Password field ${id} present`);
  }

  // Password visibility toggles
  await page.fill('#prof_new_password', 'TestPass123');
  const toggle = page.locator('.password-field:has(#prof_new_password) .password-toggle');
  await toggle.click();
  const typeAfter = await page.locator('#prof_new_password').getAttribute('type');
  if (typeAfter === 'text') pass('Password show/hide toggle reveals password');
  else fail('Password show/hide toggle reveals password', `type=${typeAfter}`);
  await toggle.click();
  const typeHidden = await page.locator('#prof_new_password').getAttribute('type');
  if (typeHidden === 'password') pass('Password show/hide toggle hides password');
  else fail('Password show/hide toggle hides password');
  await page.fill('#prof_new_password', '');

  // Wrong current password
  await page.fill('#prof_current_password', 'WrongPass123');
  await page.fill('#prof_new_password', 'NewPass12345');
  await page.fill('#prof_confirm_password', 'NewPass12345');
  await page.locator('#saveProfilePasswordBtn').click();
  await page.waitForTimeout(1200);
  const pwdErr1 = await page.locator('#profPasswordError').innerText().catch(() => '');
  if (pwdErr1.includes('غير صحيحة') || pwdErr1.includes('الحالية')) pass('Wrong current password shows error');
  else fail('Wrong current password shows error', pwdErr1);

  // Confirmation mismatch
  await page.fill('#prof_current_password', PASS);
  await page.fill('#prof_new_password', 'NewPass12345');
  await page.fill('#prof_confirm_password', 'Mismatch12345');
  await page.locator('#saveProfilePasswordBtn').click();
  await page.waitForTimeout(800);
  const pwdErr2 = await page.locator('#profPasswordError').innerText().catch(() => '');
  if (pwdErr2.includes('متطابق') || pwdErr2.includes('تأكيد')) pass('Password confirmation mismatch shows error');
  else fail('Password confirmation mismatch shows error', pwdErr2);

  // Duplicate email (another user's address)
  await page.fill('#prof_email', 'admin@travel.kw');
  await page.locator('#saveProfileBtn').click();
  await page.waitForTimeout(1200);
  const profErr = await page.locator('#profProfileError').innerText().catch(() => '');
  if (profErr.includes('مستخدم') || profErr.includes('البريد') || profErr.includes('مستخدم آخر')) pass('Duplicate email shows error');
  else fail('Duplicate email shows error', profErr || '(no error shown)');

  // Update profile name + email
  await page.fill('#prof_name', 'مدير QA');
  await page.fill('#prof_email', newEmail);
  await page.locator('#saveProfileBtn').click();
  await page.waitForTimeout(1500);
  const savedEmail = await page.locator('#prof_email').inputValue();
  if (savedEmail === newEmail) pass('Profile name and email saved');
  else fail('Profile name and email saved', savedEmail);

  // Successful password change
  await page.fill('#prof_current_password', PASS);
  await page.fill('#prof_new_password', newPassword);
  await page.fill('#prof_confirm_password', newPassword);
  await page.locator('#saveProfilePasswordBtn').click();
  await page.waitForTimeout(1500);
  const pwdErr3 = await page.locator('#profPasswordError').isVisible();
  if (!pwdErr3) pass('Password change succeeds without error');
  else fail('Password change succeeds', await page.locator('#profPasswordError').innerText());

  // Logout and login with new password
  await page.locator('[onclick="doLogout()"]').click();
  await page.waitForTimeout(1000);
  await page.fill('#loginEmail', newEmail);
  await page.fill('#loginPass', newPassword);
  await page.locator('#loginPage button, #loginPage .btn-primary').first().click();
  await page.waitForTimeout(2000);
  if (await page.locator('#appLayout').isVisible()) pass('Login with updated email and new password');
  else fail('Login with updated email and new password');

  // Restore admin email/password for dev DB (best effort via API if still logged in - skip restore in QA script)
} catch (e) {
  fail('Browser test exception', e.message);
} finally {
  await browser.close();
}

const failed = results.filter(r => r[0] === 'FAIL').length;
console.log('\n======== PROFILE SETTINGS BROWSER QA ========');
results.forEach(r => console.log(r.join(' — ')));
console.log(`Passed: ${results.length - failed} | Failed: ${failed}`);
process.exit(failed ? 1 : 0);
