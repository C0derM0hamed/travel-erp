const API_BASE = '/api';
let __apiBootstrapped = false;
let __journalLoaded = false;
let __saveInFlight = false;
let BOOTSTRAP_METRICS = {};
let DASHBOARD_DATA = null;
let REPORT_CACHE = {};
const __originalNavigate = navigate;
const __originalRenderRptContent = renderRptContent;
const PAGE_RENDERERS = {
  dashboard: 'renderDashboard', operations: 'renderOperations', clients: 'renderClients',
  vendors: 'renderVendors', vouchers: 'renderVouchers', journal: 'renderJournal',
  safes: 'renderSafes', reports: 'renderReports', settings: 'renderSettings',
};

/** Central UI lifecycle: overlays, auth isolation, post-mutation sync */
const AppShell = {
  authMode: 'login',
  activeDrawer: null,
  drawerContext: null,
  _viewGeneration: 0,

  setAuthMode(mode) {
    this.authMode = mode;
    document.body.dataset.auth = mode;
    const chrome = document.getElementById('appChrome');
    if (chrome) chrome.setAttribute('aria-hidden', mode === 'login' ? 'true' : 'false');
  },

  clearGlobalSearch() {
    const gs = document.getElementById('globalSearch');
    if (gs) gs.value = '';
    const opSearch = document.getElementById('opSearchInput');
    if (opSearch) opSearch.value = '';
  },

  clearDrawerContent() {
    const dt = document.getElementById('drawerTitle');
    const db = document.getElementById('drawerBody');
    const st = document.getElementById('stmtTitle');
    const sb = document.getElementById('stmtBody');
    if (dt) dt.textContent = 'تفاصيل العملية';
    if (db) db.innerHTML = '';
    if (st) st.textContent = 'كشف الحساب';
    if (sb) sb.innerHTML = '';
    this.activeDrawer = null;
    this.drawerContext = null;
    this._viewGeneration++;
  },

  closeDrawer(clearContent = true) {
    ['opDrawer', 'stmtDrawer'].forEach(id => document.getElementById(id)?.classList.remove('open'));
    const overlay = document.getElementById('drawerOverlay');
    if (overlay) {
      overlay.classList.remove('open', 'is-open');
      overlay.style.display = 'none';
    }
    if (clearContent) this.clearDrawerContent();
    else { this.activeDrawer = null; this.drawerContext = null; }
  },

  openDrawer(id, context = null) {
    if (this.authMode !== 'app') return;
    document.getElementById('opDrawer')?.classList.remove('open');
    document.getElementById('stmtDrawer')?.classList.remove('open');
    document.getElementById(id)?.classList.add('open');
    const overlay = document.getElementById('drawerOverlay');
    if (overlay) {
      overlay.classList.add('open', 'is-open');
      overlay.style.display = 'block';
    }
    this.activeDrawer = id;
    if (context) this.drawerContext = context;
  },

  resetOverlays() {
    document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
    this.closeDrawer(true);
  },

  prepareModal(modalId) {
    if (this.authMode !== 'app') return;
    this.closeDrawer(false);
  },

  resetForLogin() {
    this.resetOverlays();
    this.clearGlobalSearch();
    this.setAuthMode('login');
    const email = document.getElementById('loginEmail');
    const pass = document.getElementById('loginPass');
    if (email) email.value = '';
    if (pass) pass.value = '';
    const err = document.getElementById('loginError');
    if (err) { err.style.display = 'none'; err.textContent = ''; }
  },

  async refreshActiveDrawer() {
    if (this.authMode !== 'app' || !this.activeDrawer || !this.drawerContext) return;
    const ctx = this.drawerContext;
    const gen = ++this._viewGeneration;
    try {
      if (ctx.type === 'operation' && typeof viewOp === 'function') await viewOp(ctx.id, { refresh: true, generation: gen });
      else if (ctx.type === 'client_stmt' && typeof viewClientStmt === 'function') await viewClientStmt(ctx.id, { refresh: true, generation: gen });
      else if (ctx.type === 'vendor_stmt' && typeof viewVendorStmt === 'function') await viewVendorStmt(ctx.id, { refresh: true, generation: gen });
    } catch (e) { /* drawer shows error from view* */ }
  },

  rerenderCurrentPage() {
    if (this.authMode !== 'app' || !currentUser || typeof currentPage === 'undefined') return;
    const pc = document.getElementById('pageContent');
    const fnName = PAGE_RENDERERS[currentPage];
    const fn = fnName && typeof window[fnName] === 'function' ? window[fnName] : null;
    if (pc && fn) fn(pc);
  },

  async syncAfterDataLoad() {
    if (this.authMode !== 'app' || !currentUser) return;
    this.rerenderCurrentPage();
    await this.refreshActiveDrawer();
  },
};
window.AppShell = AppShell;

function normalizePhone(phone) {
  let digits = String(phone || '').replace(/\D+/g, '');
  if (digits.startsWith('965')) digits = digits.slice(3);
  digits = digits.replace(/^0+/, '');
  return digits;
}

function csrfToken(){
  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
  return match ? decodeURIComponent(match[1]) : '';
}
async function refreshCsrfCookie(){
  await fetch('/sanctum/csrf-cookie', {credentials:'same-origin'});
}
function needsCsrf(method){
  return ['POST','PUT','PATCH','DELETE'].includes((method||'GET').toUpperCase());
}
async function apiFetch(path, options = {}){
  const {skipAuthRedirect=false, retryOnCsrf=true, ...fetchOptions} = options;
  const opts = {credentials:'same-origin', headers:{'Accept':'application/json', ...(fetchOptions.headers||{})}, ...fetchOptions};
  if(opts.body && !(opts.body instanceof FormData)) opts.headers['Content-Type']='application/json';
  if((opts.method||'GET').toUpperCase()==='POST' && !opts.headers['Idempotency-Key']){
    opts.headers['Idempotency-Key']=(crypto?.randomUUID?.() || `${Date.now()}-${Math.random()}`);
  }
  if(needsCsrf(opts.method) && !opts.headers['X-XSRF-TOKEN']) opts.headers['X-XSRF-TOKEN']=csrfToken();
  const res = await fetch(API_BASE + path, opts);
  if(res.status === 419 && retryOnCsrf && needsCsrf(opts.method)){
    await refreshCsrfCookie();
    return apiFetch(path, {...options, retryOnCsrf:false});
  }
  if(!res.ok){
    let msg = 'حدث خطأ في الاتصال بالخادم';
    try{
      const err = await res.json();
      msg = err.message || Object.values(err.errors||{})[0]?.[0] || msg;
    }catch(e){}
    if(res.status === 403) msg = 'ليس لديك صلاحية لتنفيذ هذا الإجراء';
    else if(res.status === 401) msg = 'انتهت الجلسة، يرجى تسجيل الدخول مرة أخرى';
    else if(res.status === 419) msg = 'انتهت صلاحية الجلسة، يرجى تسجيل الدخول مرة أخرى';
    else if(res.status === 429) msg = 'محاولات كثيرة، يرجى الانتظار قليلاً';
    else if(res.status === 422 && msg === 'حدث خطأ في الاتصال بالخادم') msg = 'يرجى التحقق من البيانات المدخلة';
    if((res.status === 401 || res.status === 419) && !skipAuthRedirect) handleSessionExpired(msg);
    throw new Error(msg);
  }
  return res.status === 204 ? null : res.json();
}
function replaceArray(target, items){ target.splice(0, target.length, ...(items||[])); }

async function fetchAllPages(path, params = {}){
  const perPage = params.per_page || 500;
  const query = new URLSearchParams({...params, per_page: perPage, page: 1});
  const first = await apiFetch(`${path}?${query.toString()}`);
  const rows = [...(first.data || [])];
  const lastPage = first.meta?.last_page || 1;
  for(let page=2; page<=lastPage; page++){
    query.set('page', page);
    const next = await apiFetch(`${path}?${query.toString()}`);
    rows.push(...(next.data || []));
  }
  return rows;
}

function clearLocalState(){
  currentUser=null; __apiBootstrapped=false; __journalLoaded=false; JOURNAL_CACHE=[]; BOOTSTRAP_METRICS={}; DASHBOARD_DATA=null; REPORT_CACHE={};
  if(typeof vcTab!=='undefined') vcTab='receipt';
  if(typeof currentPage!=='undefined') currentPage='dashboard';
  Object.values(charts).forEach(c=>{try{c.destroy();}catch(e){}});
  charts={};
  AppShell.clearDrawerContent();
}

function showLogin(){
  clearLocalState();
  AppShell.resetForLogin();
  document.getElementById('appLayout').style.display='none';
  document.getElementById('loginPage').style.display='flex';
}

function enterApp(user){
  currentUser = user;
  AppShell.setAuthMode('app');
  document.getElementById('loginPage').style.display='none';
  document.getElementById('appLayout').style.display='flex';
  document.getElementById('userName').textContent=currentUser.name;
  document.getElementById('userRole').textContent=currentUser.roleLabel;
  document.getElementById('userAvatar').textContent=currentUser.avatar;
  document.getElementById('loginError').style.display='none';
  if(typeof applyRoleUi==='function')applyRoleUi();
}

function handleSessionExpired(message){
  showLogin();
  notify(message || 'انتهت الجلسة، يرجى تسجيل الدخول مرة أخرى', 'warning');
}

async function loadJournalCache(){
  if(__journalLoaded) return;
  const all = [];
  let page = 1;
  while(true){
    const res = await apiFetch(`/journal?per_page=500&page=${page}`);
    all.push(...(res.data||[]));
    if(!res.meta || page >= res.meta.last_page) break;
    page++;
  }
  JOURNAL_CACHE = all;
  __journalLoaded = true;
}

async function loadDashboardData(){
  try{ DASHBOARD_DATA = await apiFetch('/dashboard'); }
  catch(e){ DASHBOARD_DATA = null; notify('تعذر تحميل بيانات لوحة التحكم', 'error'); }
}

const LIST_META = { clients: null, vendors: null, operations: null, vouchers: null };
const LIST_PER_PAGE = 25;
let __listSearchTimer = null;

async function refreshBootstrap(){
  const data = await apiFetch('/bootstrap');
  replaceArray(USERS, data.users);
  replaceArray(SERVICES, data.services);
  replaceArray(SAFES, data.safes);
  BOOTSTRAP_METRICS = data.metrics || {};
  const safesPayload = await apiFetch('/safes').catch(() => ({ data: data.safes }));
  replaceArray(SAFES, safesPayload.data || data.safes);
}

async function fetchListPage(path, page = 1, params = {}){
  const q = new URLSearchParams({ page, per_page: LIST_PER_PAGE, ...params });
  return apiFetch(`${path}?${q.toString()}`);
}

async function reloadClientsList(page){
  const wrap = document.getElementById('clTableWrap');
  if (wrap) wrap.innerHTML = '<p style="padding:24px;text-align:center;color:var(--text-sm)">جاري التحميل...</p>';
  const search = document.getElementById('clSearch')?.value?.trim() || '';
  const p = page || tablePages.clients || 1;
  const res = await fetchListPage('/clients', p, search ? { search } : {});
  replaceArray(CLIENTS, res.data || []);
  LIST_META.clients = res.meta || null;
  tablePages.clients = res.meta?.current_page || 1;
  const h3 = document.querySelector('#pageContent h3');
  if (h3 && currentPage === 'clients') h3.textContent = `👤 إدارة العملاء (${res.meta?.total ?? CLIENTS.length})`;
  paintClientsTable();
}

async function reloadVendorsList(page){
  const wrap = document.getElementById('vnTableWrap');
  if (wrap) wrap.innerHTML = '<p style="padding:24px;text-align:center;color:var(--text-sm)">جاري التحميل...</p>';
  const search = document.getElementById('vnSearch')?.value?.trim() || '';
  const p = page || tablePages.vendors || 1;
  const res = await fetchListPage('/vendors', p, search ? { search } : {});
  replaceArray(VENDORS, res.data || []);
  LIST_META.vendors = res.meta || null;
  tablePages.vendors = res.meta?.current_page || 1;
  const h3 = document.querySelector('#pageContent h3');
  if (h3 && currentPage === 'vendors') h3.textContent = `🏢 الموردون والمكاتب (${res.meta?.total ?? VENDORS.length})`;
  paintVendorsTable();
}

async function reloadOperationsList(page){
  const wrap = document.getElementById('opTableWrap');
  if (wrap) wrap.innerHTML = '<p style="padding:24px;text-align:center;color:var(--text-sm)">جاري التحميل...</p>';
  const search = document.getElementById('opSearchInput')?.value?.trim() || '';
  const status = document.getElementById('opStatusFilter')?.value || 'all';
  const service = document.getElementById('opSvcFilter')?.value || 'all';
  const p = page || tablePages.ops || 1;
  const params = {};
  if (search) params.search = search;
  if (status !== 'all') params.status = status;
  if (service !== 'all') params.service = service;
  const res = await fetchListPage('/operations', p, params);
  replaceArray(OPS, res.data || []);
  LIST_META.operations = res.meta || null;
  tablePages.ops = res.meta?.current_page || 1;
  const h3 = document.querySelector('#pageContent h3');
  if (h3 && currentPage === 'operations') h3.textContent = `📋 إدارة العمليات (${res.meta?.total ?? OPS.length})`;
  paintOperationsTable();
}

async function reloadVouchersList(page){
  const from = document.getElementById('vcFrom')?.value || '';
  const to = document.getElementById('vcTo')?.value || '';
  const p = page || tablePages.vouchers || 1;
  const params = { type: vcTab };
  if (from) params.from = from;
  if (to) params.to = to;
  const res = await fetchListPage('/vouchers', p, params);
  replaceArray(VOUCHERS, res.data || []);
  LIST_META.vouchers = res.meta || null;
  tablePages.vouchers = res.meta?.current_page || 1;
  renderVcTable();
}

function serverPagerHtml(key, meta, reloadFn){
  if (!meta || meta.total <= meta.per_page) return '';
  const page = meta.current_page;
  const totalPages = meta.last_page;
  return `<div style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px;border-top:1px solid var(--border);font-size:13px">
    <span style="color:var(--text-sm)">${meta.total} سجل — صفحة ${page} / ${totalPages}</span>
    <div style="display:flex;gap:6px">
      <button class="btn btn-sm btn-outline" ${page<=1?'disabled':''} onclick="${reloadFn}(${page-1})">السابق</button>
      <button class="btn btn-sm btn-outline" ${page>=totalPages?'disabled':''} onclick="${reloadFn}(${page+1})">التالي</button>
    </div></div>`;
}

function paintClientsTable(){
  const wrap = document.getElementById('clTableWrap');
  if (!wrap) return;
  const meta = LIST_META.clients;
  wrap.innerHTML = `<table class="table"><thead><tr><th>#</th><th>الاسم</th><th>الهاتف</th><th>الرقم المدني</th><th>الجنسية</th><th>الرصيد</th><th>العمليات</th><th>إجراءات</th></tr></thead>
  <tbody>${CLIENTS.map(c=>{
    const bal = typeof c.balance === 'number' ? c.balance : getClientBalance(c.id);
    const b = formatBalance(bal);
    const ops = c.operations_count ?? 0;
    const editBtn = canDo('write_master') ? `<button class="btn btn-sm btn-outline" onclick="openEditClient(${c.id})">تعديل</button> ` : '';
    const deleteBtn = canDo('write_master') ? `<button class="btn btn-sm btn-danger" onclick="deleteClient(${c.id})">حذف</button> ` : '';
    return `<tr><td>${c.id}</td><td><b>${c.name}</b></td><td>${c.phone}</td><td>${displayVal(c.civil_id)}</td><td>${displayVal(c.nationality)}</td><td style="color:${b.color};font-weight:700">${b.text}</td><td><span class="badge badge-info">${ops}</span></td><td>${editBtn}${deleteBtn}<button class="btn btn-sm btn-outline" onclick="viewClientStmt(${c.id})">كشف حساب</button></td></tr>`;
  }).join('')||'<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-sm)">لا يوجد عملاء</td></tr>'}</tbody></table>${serverPagerHtml('clients', meta, 'reloadClientsList')}`;
}

function paintVendorsTable(){
  const wrap = document.getElementById('vnTableWrap');
  if (!wrap) return;
  const meta = LIST_META.vendors;
  wrap.innerHTML = `<table class="table"><thead><tr><th>#</th><th>الاسم</th><th>التصنيف</th><th>الهاتف</th><th>جهة الاتصال</th><th>الرصيد</th><th>إجراءات</th></tr></thead>
  <tbody>${VENDORS.map(v=>{
    const bal = typeof v.balance === 'number' ? v.balance : getVendorBalance(v.id);
    const editBtn = canDo('write_master') ? `<button class="btn btn-sm btn-outline" onclick="openEditVendor(${v.id})">تعديل</button> ` : '';
    const deleteBtn = canDo('write_master') ? `<button class="btn btn-sm btn-danger" onclick="deleteVendor(${v.id})">حذف</button> ` : '';
    return `<tr><td>${v.id}</td><td><b>${v.name}</b></td><td>${categoryLabel[v.category]||v.category}</td><td>${v.phone||'—'}</td><td>${v.contact||'—'}</td><td style="color:${bal>0?'var(--warning)':'var(--success)'};font-weight:700">${fmt(bal)}</td><td>${editBtn}${deleteBtn}<button class="btn btn-sm btn-outline" onclick="viewVendorStmt(${v.id})">كشف حساب</button></td></tr>`;
  }).join('')}</tbody></table>${serverPagerHtml('vendors', meta, 'reloadVendorsList')}`;
}

function paintOperationsTable(){
  const wrap = document.getElementById('opTableWrap');
  if (!wrap) return;
  const meta = LIST_META.operations;
  wrap.innerHTML = `<table class="table">
    <thead><tr><th>المرجع</th><th>التاريخ</th><th>العميل</th><th>الخدمة</th><th>المورد</th><th>سعر العميل</th><th>التكلفة</th><th>الربح</th><th>الحالة</th><th>إجراءات</th></tr></thead>
    <tbody>${OPS.map(o=>{
      const editBtn = canDo('update_op') && o.status !== 'cancelled' && o.status !== 'completed' ? `<button class="btn btn-sm btn-outline" onclick="openEditOperation(${o.id})">تعديل</button> ` : '';
      return `<tr>
      <td><b style="color:var(--primary);cursor:pointer" onclick="viewOp(${o.id})">${o.ref}</b></td>
      <td>${o.date}</td><td>${o.client||clientName(o.client_id)}</td><td>${o.service||serviceName(o.service_id)}</td><td>${o.vendor||vendorName(o.vendor_id)}</td>
      <td><b>${fmt(o.client_price)}</b></td><td>${fmt(o.vendor_cost)}</td>
      <td style="color:${o.profit>=0?'var(--success)':'var(--danger)'};font-weight:700">${fmt(o.profit)}</td>
      <td><span class="badge ${statusClass[o.status]}">${statusLabel[o.status]}</span></td>
      <td>${editBtn}<button class="btn btn-sm btn-outline" onclick="viewOp(${o.id})">تفاصيل</button>${o.status!=='cancelled'&&canDo('cancel_op')?` <button class="btn btn-sm btn-danger" onclick="cancelOp(${o.id})">إلغاء</button>`:''}</td>
    </tr>`;
    }).join('')||'<tr><td colspan="10" style="text-align:center;color:var(--text-sm);padding:40px">لا توجد عمليات</td></tr>'}</tbody>
  </table>${serverPagerHtml('operations', meta, 'reloadOperationsList')}`;
}

async function loadPageList(page){
  if (page === 'clients') await reloadClientsList(1);
  else if (page === 'vendors') await reloadVendorsList(1);
  else if (page === 'operations') await reloadOperationsList(1);
  else if (page === 'vouchers') await reloadVouchersList(1);
}

async function loadAllData(){
  await refreshBootstrap();
  replaceArray(CLIENTS, []);
  replaceArray(VENDORS, []);
  replaceArray(OPS, []);
  replaceArray(VOUCHERS, []);
  REPORT_CACHE = {};
  JOURNAL_CACHE = [];
  __journalLoaded = false;
  __apiBootstrapped = true;
  await loadDashboardData();
  await loadPageList(currentPage);
  await AppShell.syncAfterDataLoad();
}

async function refreshAfterMutation(){
  await refreshBootstrap();
  await loadDashboardData();
  REPORT_CACHE = {};
  if (currentPage === 'journal' || currentPage === 'reports') {
    JOURNAL_CACHE = [];
    __journalLoaded = false;
    try { await loadJournalCache(); } catch (e) { /* optional */ }
  }
  await loadPageList(currentPage);
  await AppShell.syncAfterDataLoad();
}
getJournal = function(){ return JOURNAL_CACHE; };

async function withSaveGuard(btn, fn){
  if(__saveInFlight){ notify('جاري الحفظ، يرجى الانتظار', 'warning'); return; }
  __saveInFlight = true;
  const el = typeof btn === 'string' ? document.querySelector(btn) : btn;
  const prevText = el?.textContent;
  if(el){ el.disabled = true; el.style.opacity = '0.7'; }
  try{ return await fn(); }
  finally{
    __saveInFlight = false;
    if(el){ el.disabled = false; el.style.opacity = ''; if(prevText) el.textContent = prevText; }
  }
}

doLogin = async function(){
  const email=document.getElementById('loginEmail').value.trim();
  const password=document.getElementById('loginPass').value;
  try{
    await refreshCsrfCookie();
    const data = await apiFetch('/login', {method:'POST', body:JSON.stringify({email,password})});
    await refreshCsrfCookie();
    enterApp(data.user);
    await loadAllData();
    await navigate('dashboard');
  }catch(e){ const err=document.getElementById('loginError'); err.textContent=e.message; err.style.display='block'; }
};

doLogout = async function(){
  try{ await apiFetch('/logout', {method:'POST', skipAuthRedirect:true}); }catch(e){ notify('تعذر إنهاء الجلسة على الخادم', 'warning'); }
  showLogin();
};

navigate = async function(page){
  if(currentUser && typeof canViewPage==='function' && !canViewPage(page)){
    notify('ليس لديك صلاحية للوصول إلى هذه الصفحة', 'warning');
    page = 'dashboard';
  }
  if(currentUser && !__apiBootstrapped){
    try{ await loadAllData(); }catch(e){ console.error(e); notify('تعذر تحميل البيانات', 'error'); }
  }
  AppShell.resetOverlays();
  AppShell.clearGlobalSearch();
  if(page === 'journal' || page === 'reports') {
    try{ await loadJournalCache(); }catch(e){ notify('تعذر تحميل القيود المحاسبية', 'error'); }
  }
  __originalNavigate(page);
  if (__apiBootstrapped && ['clients','vendors','operations','vouchers'].includes(page)) {
    try { await loadPageList(page); } catch (e) { notify('تعذر تحميل البيانات', 'error'); }
  }
};

filterClients = function(){
  clearTimeout(__listSearchTimer);
  __listSearchTimer = setTimeout(() => reloadClientsList(1), 300);
};
filterVendors = function(){
  clearTimeout(__listSearchTimer);
  __listSearchTimer = setTimeout(() => reloadVendorsList(1), 300);
};
filterOps = function(){
  clearTimeout(__listSearchTimer);
  __listSearchTimer = setTimeout(() => reloadOperationsList(1), 300);
};
filterVouchers = function(){
  clearTimeout(__listSearchTimer);
  __listSearchTimer = setTimeout(() => reloadVouchersList(1), 300);
};
switchVcTab = function(tab){ vcTab = tab; tablePages.vouchers = 1; navigate('vouchers'); };

renderVcTable = function(){
  const wrap = document.getElementById('vcTableWrap');
  if (!wrap) return;
  const meta = LIST_META.vouchers;
  wrap.innerHTML = `<table class="table"><thead><tr><th>الرقم</th><th>التاريخ</th><th>الطرف</th><th>المبلغ</th><th>الحالة</th><th>الطريقة</th><th>الصندوق</th><th>العملية</th><th>البيان</th><th>إجراءات</th></tr></thead>
  <tbody>${VOUCHERS.map(v=>{
    const party = partyLabel(v);
    const opRef = v.operation_id ? (OPS.find(o=>o.id===v.operation_id)?.ref || '—') : '—';
    const reversed = v.reversed;
    const voidBtn = canDo('void_voucher') && !reversed ? `<button class="btn btn-xs btn-danger" onclick="voidVoucher(${v.id})">إلغاء</button> ` : '';
    return `<tr style="${reversed?'opacity:.65':''}"><td><b style="color:var(--primary)">${v.ref}</b></td><td>${v.date}</td><td>${party}</td><td style="font-weight:700;color:${vcTab==='receipt'?'var(--success)':'var(--danger)'}">${fmt(v.amount)}</td><td>${reversed?'<span class="badge badge-danger">ملغى</span>':'<span class="badge badge-success">فعّال</span>'}</td><td>${methodLabel(v.method)}</td><td>${safeName(v.safe_id)}</td><td>${opRef}</td><td style="font-size:12px">${displayVal(v.desc)}</td><td>${voidBtn}<button class="btn btn-xs btn-outline" onclick="printVoucher(${v.id})">🖨️</button></td></tr>`;
  }).join('')||'<tr><td colspan="10" style="text-align:center;padding:40px;color:var(--text-sm)">لا توجد سندات</td></tr>'}</tbody></table>${serverPagerHtml('vouchers', meta, 'reloadVouchersList')}`;
};

populateOpForm = async function(){
  try {
    const [clientsRes, vendorsRes] = await Promise.all([
      apiFetch('/clients?per_page=500'),
      apiFetch('/vendors?per_page=500'),
    ]);
    const cs = document.getElementById('op_client');
    const vn = document.getElementById('op_vendor');
    if (cs) cs.innerHTML = '<option value="">-- اختر عميل --</option>' + (clientsRes.data||[]).map(c=>`<option value="${c.id}">${c.name}</option>`).join('');
    if (vn) vn.innerHTML = '<option value="">-- اختر مورد --</option>' + (vendorsRes.data||[]).map(v=>`<option value="${v.id}">${v.name}</option>`).join('');
  } catch (e) { notify(e.message, 'error'); }
  const sv = document.getElementById('op_service');
  if (sv) sv.innerHTML = '<option value="">-- اختر خدمة --</option>' + SERVICES.filter(s=>s.active).map(s=>`<option value="${s.id}">${s.icon} ${s.name}</option>`).join('');
};

openNewVoucher = async function(type){
  vcTab = type;
  document.getElementById('voucherModalTitle').textContent = type==='receipt'?'🧾 سند قبض جديد':'💸 سند صرف جديد';
  document.getElementById('voucherSaveBtn').style.background = type==='receipt'?'var(--success)':'var(--danger)';
  updateVoucherParties();
  const vcSafe = document.getElementById('vc_safe');
  if (vcSafe) vcSafe.innerHTML = SAFES.map(s=>`<option value="${s.id}">${s.name}</option>`).join('');
  const vcOp = document.getElementById('vc_operation');
  if (vcOp) {
    try {
      const res = await apiFetch('/operations?per_page=100&status=processing');
      const rows = [...(res.data||[])];
      const resNew = await apiFetch('/operations?per_page=100&status=new');
      rows.push(...(resNew.data||[]));
      vcOp.innerHTML = '<option value="">-- بدون ربط --</option>' + rows.map(o=>`<option value="${o.id}">${o.ref} - ${o.client||clientName(o.client_id)}</option>`).join('');
    } catch (e) { vcOp.innerHTML = '<option value="">-- بدون ربط --</option>'; }
  }
  ['vc_amount','vc_ref'].forEach(id=>{ const el=document.getElementById(id); if(el) el.value=''; });
  document.getElementById('vc_desc').value = '';
  showModal('newVoucherModal');
};

updateVoucherParties = async function(){
  const pt = document.getElementById('vc_party_type')?.value;
  const sel = document.getElementById('vc_party_id');
  if (!sel) return;
  try {
    if (pt === 'client') {
      const res = await apiFetch('/clients?per_page=500');
      sel.innerHTML = '<option value="">-- اختر عميل --</option>' + (res.data||[]).map(c=>`<option value="${c.id}">${c.name}</option>`).join('');
    } else if (pt === 'vendor') {
      const res = await apiFetch('/vendors?per_page=500');
      sel.innerHTML = '<option value="">-- اختر مورد --</option>' + (res.data||[]).map(v=>`<option value="${v.id}">${v.name}</option>`).join('');
    } else sel.innerHTML = '<option value="">-- عام --</option>';
  } catch (e) { sel.innerHTML = '<option value="">--</option>'; }
};

closeDrawer = function(clearContent){ AppShell.closeDrawer(clearContent !== false); };
openDrawer = function(id){ AppShell.openDrawer(id); };
closeAllOverlays = function(){ AppShell.resetOverlays(); };

saveClient = async function(){
  const name=document.getElementById('cl_name').value.trim();
  const phone=normalizePhone(document.getElementById('cl_phone').value);
  const civilId=document.getElementById('cl_civil_id').value.trim();
  if(!name||!phone){ showModalError('newClientModal','الاسم ورقم الهاتف مطلوبان'); return; }
  if(CLIENTS.some(c=>normalizePhone(c.phone)===phone)){ showModalError('newClientModal','رقم الهاتف مسجل مسبقاً لعميل آخر'); return; }
  if(civilId&&CLIENTS.some(c=>c.civil_id===civilId)){ showModalError('newClientModal','الرقم المدني مسجل مسبقاً'); return; }
  await withSaveGuard('#newClientModal .btn-primary', async ()=>{
    await apiFetch('/clients',{method:'POST',body:JSON.stringify({
      name, phone, alt_phone:normalizePhone(document.getElementById('cl_alt_phone').value)||null,
      civil_id:document.getElementById('cl_civil_id').value, email:document.getElementById('cl_email').value, nationality:document.getElementById('cl_nationality').value, notes:document.getElementById('cl_notes').value
    })});
    closeModal('newClientModal'); await refreshAfterMutation(); await navigate('clients');
    notify('تم حفظ العميل بنجاح', 'success');
  }).catch(e=> showModalError('newClientModal', e.message));
};

saveVendor = async function(){
  const name=document.getElementById('vn_name').value.trim();
  if(!name){ notify('اسم المورد مطلوب', 'warning'); return; }
  if(VENDORS.some(v=>v.name===name)){ notify('اسم المورد مسجل مسبقاً', 'warning'); return; }
  await withSaveGuard('#newVendorModal .btn-primary', async ()=>{
    await apiFetch('/vendors',{method:'POST',body:JSON.stringify({
      name, category:document.getElementById('vn_category').value, phone:document.getElementById('vn_phone').value,
      contact:document.getElementById('vn_contact').value, address:document.getElementById('vn_address').value
    })});
    closeModal('newVendorModal'); await refreshAfterMutation(); await navigate('vendors');
    notify('تم حفظ المورد بنجاح', 'success');
  }).catch(e=> notify(e.message, 'error'));
};

saveOperation = async function(){
  const cid=+document.getElementById('op_client').value;
  const sid=+document.getElementById('op_service').value;
  const vid=+document.getElementById('op_vendor').value;
  const cp=+document.getElementById('op_client_price').value;
  const vc=+document.getElementById('op_vendor_cost').value;
  const ip=+document.getElementById('op_initial_payment').value||0;
  if(!cid||!sid||!vid){ showModalError('newOpModal','يرجى اختيار العميل والخدمة والمورد'); return; }
  if(!cp||cp<1){ showModalError('newOpModal','الحد الأدنى لسعر العميل 1 د.ك'); return; }
  if(vc<0||isNaN(vc)){ showModalError('newOpModal','يرجى إدخال تكلفة المورد بشكل صحيح'); return; }
  if(vc>cp){ showModalError('newOpModal','تكلفة المورد لا يمكن أن تتجاوز سعر العميل'); return; }
  if(ip>cp){ showModalError('newOpModal','الدفعة الأولى لا يمكن أن تتجاوز سعر العميل'); return; }
  const svc=SERVICES.find(s=>s.id===sid);
  if(svc&&!svc.active){ showModalError('newOpModal','الخدمة المحددة غير مفعّلة'); return; }
  await withSaveGuard('#newOpModal .btn-primary', async ()=>{
    await apiFetch('/operations',{method:'POST',body:JSON.stringify({
      client_id:cid, service_id:sid, vendor_id:vid,
      currency:'KWD', client_price:cp, vendor_cost:vc,
      initial_payment:+document.getElementById('op_initial_payment').value||0, payment_method:document.getElementById('op_payment_method').value, notes:document.getElementById('op_notes').value
    })});
    closeModal('newOpModal'); await refreshAfterMutation(); await navigate('operations');
    notify('تم حفظ العملية وإنشاء القيود المحاسبية بنجاح', 'success');
  }).catch(e=> showModalError('newOpModal', e.message));
};

saveVoucher = async function(){
  const amt=+document.getElementById('vc_amount').value;
  const partyType=document.getElementById('vc_party_type').value;
  const partyId=+document.getElementById('vc_party_id').value||null;
  if(!amt||amt<1){ notify('الحد الأدنى للمبلغ 1 د.ك', 'warning'); return; }
  if((partyType==='client'||partyType==='vendor')&&!partyId){ notify('يجب تحديد الطرف (عميل أو مورد)', 'warning'); return; }
  await withSaveGuard('#voucherSaveBtn', async ()=>{
    await apiFetch('/vouchers',{method:'POST',body:JSON.stringify({
      type:vcTab, party_type:document.getElementById('vc_party_type').value, party_id:+document.getElementById('vc_party_id').value||null,
      amount:amt, currency:'KWD', method:document.getElementById('vc_method').value,
      safe_id:+document.getElementById('vc_safe').value, operation_id:+document.getElementById('vc_operation').value||null, ref:document.getElementById('vc_ref').value||undefined,
      description:document.getElementById('vc_desc').value
    })});
    const linkedOpId = +document.getElementById('vc_operation').value || null;
    closeModal('newVoucherModal');
    await refreshAfterMutation();
    if (linkedOpId && AppShell.drawerContext?.type === 'operation' && AppShell.drawerContext.id === linkedOpId) {
      await viewOp(linkedOpId, { refresh: true });
    } else {
      await navigate('vouchers');
    }
    notify('تم حفظ السند وتسجيل القيود المحاسبية', 'success');
  }).catch(e=> notify(e.message, 'error'));
};

cancelOp = async function(id){
  if(!confirm('هل أنت متأكد من إلغاء هذه العملية؟ سيتم إنشاء قيود عكسية تلقائياً.'))return;
  await withSaveGuard(null, async ()=>{
    await apiFetch(`/operations/${id}/cancel`,{method:'POST'});
    await refreshAfterMutation(); await navigate('operations');
    notify('تم إلغاء العملية وإنشاء القيود العكسية بنجاح', 'success');
  }).catch(e=> notify(e.message, 'error'));
};

toggleService = async function(id){
  await withSaveGuard(null, async ()=>{
    await apiFetch(`/services/${id}/toggle`,{method:'PATCH'});
    await refreshAfterMutation(); await navigate('settings');
    notify('تم تحديث حالة الخدمة', 'success');
  }).catch(e=> notify(e.message, 'error'));
};

updateOperationStatus = async function(id, status){
  await withSaveGuard(null, async ()=>{
    const op = await apiFetch(`/operations/${id}/status`, {method:'PATCH', body:JSON.stringify({status})});
    const idx = OPS.findIndex(o=>o.id===id);
    if(idx>=0) OPS[idx] = op;
    await refreshAfterMutation();
    await viewOp(id);
    notify('تم تحديث حالة العملية', 'success');
  }).catch(e=> notify(e.message, 'error'));
};

function operationStatusControls(op){
  if(op.status === 'cancelled' || op.status === 'completed') return '';
  if(op.status === 'new' && ['admin','sales'].includes(currentUser?.role)){
    return `<button class="btn btn-sm btn-outline" onclick="updateOperationStatus(${op.id},'processing')">نقل إلى قيد التنفيذ</button>`;
  }
  if(op.status === 'processing' && ['admin','accountant'].includes(currentUser?.role)){
    const settled = (+op.client_outstanding||0) <= 0.001 && (+op.vendor_outstanding||0) <= 0.001;
    return `<button class="btn btn-sm btn-outline" ${settled?'':'disabled'} onclick="updateOperationStatus(${op.id},'completed')">إكمال العملية</button>${settled?'':'<small style="color:var(--text-sm);margin-inline-start:8px">يتطلب تسوية العميل والمورد</small>'}`;
  }
  return '';
}

function signedAmount(value, positiveColor, negativeColor){
  const n = +value || 0;
  if(Math.abs(n) < 0.0005) return '—';
  return `<span style="color:${n>=0?positiveColor:negativeColor}">${fmt(n)}</span>`;
}

viewOp = async function(id, opts = {}){
  if (AppShell.authMode !== 'app') return;
  const gen = opts.generation ?? ++AppShell._viewGeneration;
  AppShell.drawerContext = { type: 'operation', id };
  const existing=OPS.find(o=>o.id===id);
  document.getElementById('drawerTitle').textContent=`تفاصيل العملية${existing ? ' - '+existing.ref : ''}`;
  document.getElementById('drawerBody').innerHTML='<p style="padding:20px;text-align:center;color:var(--text-sm)">جاري تحميل التفاصيل...</p>';
  AppShell.openDrawer('opDrawer', AppShell.drawerContext);
  try{
    const op = await apiFetch(`/operations/${id}`);
    if (gen !== AppShell._viewGeneration && !opts.refresh) return;
    const idx = OPS.findIndex(o=>o.id===id);
    if(idx>=0) OPS[idx] = {...OPS[idx], ...op};
    const je=op.journal||[];
    const vcs=op.vouchers||[];
    document.getElementById('drawerTitle').textContent=`تفاصيل العملية - ${op.ref}`;
    document.getElementById('drawerBody').innerHTML=`
      <div style="margin-bottom:16px">
        <div class="grid-2" style="margin-bottom:16px">
          <div class="info-item"><span class="info-label">رقم العملية</span><span class="info-value">${op.ref}</span></div>
          <div class="info-item"><span class="info-label">التاريخ</span><span class="info-value">${op.date}</span></div>
          <div class="info-item"><span class="info-label">العميل</span><span class="info-value">${op.client||clientName(op.client_id)}</span></div>
          <div class="info-item"><span class="info-label">الخدمة</span><span class="info-value">${op.service||serviceName(op.service_id)}</span></div>
          <div class="info-item"><span class="info-label">المورد</span><span class="info-value">${op.vendor||vendorName(op.vendor_id)}</span></div>
          <div class="info-item"><span class="info-label">الحالة</span><span><span class="badge ${statusClass[op.status]}">${statusLabel[op.status]}</span></span>${operationStatusControls(op)}</div>
          <div class="info-item"><span class="info-label">سعر العميل</span><span class="info-value" style="color:var(--primary);font-weight:700">${fmt(op.client_price)}</span></div>
          <div class="info-item"><span class="info-label">تكلفة المورد</span><span class="info-value">${fmt(op.vendor_cost)}</span></div>
          <div class="info-item"><span class="info-label">رصيد العميل للعملية</span><span class="info-value" style="color:${op.client_outstanding>0?'var(--danger)':'var(--success)'}">${fmt(op.client_outstanding)}</span></div>
          <div class="info-item"><span class="info-label">رصيد المورد للعملية</span><span class="info-value" style="color:${op.vendor_outstanding>0?'var(--warning)':'var(--success)'}">${fmt(op.vendor_outstanding)}</span></div>
          <div class="info-item"><span class="info-label">الربح المتوقع</span><span class="info-value" style="color:${op.profit>=0?'var(--success)':'var(--danger)'};font-weight:700">${fmt(op.profit)}</span></div>
          <div class="info-item"><span class="info-label">العملة</span><span class="info-value">${op.currency_label||currencyLabel(op.currency)}</span></div>
        </div>
        ${op.notes?`<div class="info-item"><span class="info-label">ملاحظات</span><span class="info-value">${op.notes}</span></div>`:''}
      </div>
      <div style="margin-bottom:16px">
        <h4 style="margin-bottom:10px;color:var(--primary)">القيود المحاسبية</h4>
        <div class="table-wrapper"><table class="table"><thead><tr><th>الحساب</th><th>مدين</th><th>دائن</th><th>البيان</th></tr></thead>
        <tbody>${je.map(j=>`<tr><td>${j.account}</td><td>${signedAmount(j.debit,'var(--danger)','var(--success)')}</td><td>${signedAmount(j.credit,'var(--success)','var(--danger)')}</td><td style="font-size:12px">${j.desc||''}</td></tr>`).join('')||'<tr><td colspan="4" style="text-align:center;color:var(--text-sm)">لا توجد قيود</td></tr>'}</tbody></table></div>
      </div>
      <div>
        <h4 style="margin-bottom:10px;color:var(--primary)">السندات المرتبطة</h4>
        ${vcs.length?`<div class="table-wrapper"><table class="table"><thead><tr><th>الرقم</th><th>النوع</th><th>المبلغ</th><th>الطريقة</th><th>التاريخ</th><th>الحالة</th><th>إجراء</th></tr></thead>
        <tbody>${vcs.map(v=>{
          const rev = v.reversed || op.status==='cancelled';
          const voidBtn = canDo('void_voucher') && !rev ? `<button class="btn btn-xs btn-danger" onclick="voidVoucher(${v.id})">إلغاء</button>` : '—';
          return `<tr><td><b>${v.ref}</b></td><td><span class="badge ${v.type==='receipt'?'badge-success':'badge-danger'}">${vcTypeLabel[v.type]}</span></td><td>${fmt(v.amount)}</td><td>${methodLabel(v.method)}</td><td>${v.date}</td><td>${rev?'<span class="badge badge-danger">ملغى</span>':'<span class="badge badge-success">فعّال</span>'}</td><td>${voidBtn}</td></tr>`;
        }).join('')}</tbody></table></div>`:'<p style="color:var(--text-sm);text-align:center;padding:20px">لا توجد سندات مرتبطة</p>'}
      </div>`;
  }catch(e){ document.getElementById('drawerBody').innerHTML=`<p style="padding:20px;text-align:center;color:var(--danger)">${e.message}</p>`; }
};

viewClientStmt = async function(cid, opts = {}){
  if (AppShell.authMode !== 'app') return;
  const gen = opts.generation ?? ++AppShell._viewGeneration;
  AppShell.drawerContext = { type: 'client_stmt', id: cid };
  const cl=CLIENTS.find(c=>c.id===cid);
  document.getElementById('stmtTitle').textContent=`كشف حساب${cl ? ' - '+cl.name : ''}`;
  document.getElementById('stmtBody').innerHTML='<p style="padding:20px;text-align:center;color:var(--text-sm)">جاري تحميل كشف الحساب...</p>';
  AppShell.openDrawer('stmtDrawer', AppShell.drawerContext);
  try{
    const stmt = await apiFetch(`/clients/${cid}/statement`);
    if (gen !== AppShell._viewGeneration && !opts.refresh) return;
    const rows = stmt.rows || [];
    document.getElementById('stmtTitle').textContent=`كشف حساب - ${stmt.client?.name || cl?.name || ''}`;
    document.getElementById('stmtBody').innerHTML=`
      <div class="grid-3" style="margin-bottom:20px">
        <div class="card" style="background:var(--bg)"><div class="card-body" style="text-align:center"><div style="font-size:11px;color:var(--text-sm)">إجمالي المشتريات</div><div style="font-size:20px;font-weight:800;color:var(--primary)">${fmt(stmt.total_purchases)}</div></div></div>
        <div class="card" style="background:var(--bg)"><div class="card-body" style="text-align:center"><div style="font-size:11px;color:var(--text-sm)">المدفوع</div><div style="font-size:20px;font-weight:800;color:var(--success)">${fmt(stmt.paid)}</div></div></div>
        <div class="card" style="background:var(--bg)"><div class="card-body" style="text-align:center"><div style="font-size:11px;color:var(--text-sm)">الرصيد المتبقي</div><div style="font-size:20px;font-weight:800;color:${stmt.balance>0?'var(--danger)':'var(--success)'}">${fmt(stmt.balance)}</div></div></div>
      </div>
      <div class="drawer-actions"><h4 style="margin:0">حركات الحساب</h4><button class="btn btn-sm btn-outline" onclick="exportClientStmt(${cid})">تصدير</button></div>
      <div class="table-wrapper"><table class="table"><thead><tr><th>التاريخ</th><th>المرجع</th><th>البيان</th><th>مدين</th><th>دائن</th><th>الرصيد</th></tr></thead>
      <tbody>${rows.map(j=>`<tr><td>${j.date}</td><td>${j.ref}</td><td style="font-size:12px">${j.desc||''}</td><td>${signedAmount(j.debit,'var(--danger)','var(--success)')}</td><td>${signedAmount(j.credit,'var(--success)','var(--danger)')}</td><td style="font-weight:700;color:${j.balance>0?'var(--danger)':'var(--success)'}">${fmt(j.balance)}</td></tr>`).join('')||'<tr><td colspan="6" style="text-align:center;color:var(--text-sm)">لا توجد حركات</td></tr>'}</tbody></table></div>`;
  }catch(e){ document.getElementById('stmtBody').innerHTML=`<p style="padding:20px;text-align:center;color:var(--danger)">${e.message}</p>`; }
};

viewVendorStmt = async function(vid, opts = {}){
  if (AppShell.authMode !== 'app') return;
  const gen = opts.generation ?? ++AppShell._viewGeneration;
  AppShell.drawerContext = { type: 'vendor_stmt', id: vid };
  const vn=VENDORS.find(v=>v.id===vid);
  document.getElementById('stmtTitle').textContent=`كشف حساب مورد${vn ? ' - '+vn.name : ''}`;
  document.getElementById('stmtBody').innerHTML='<p style="padding:20px;text-align:center;color:var(--text-sm)">جاري تحميل كشف الحساب...</p>';
  AppShell.openDrawer('stmtDrawer', AppShell.drawerContext);
  try{
    const stmt = await apiFetch(`/vendors/${vid}/statement`);
    if (gen !== AppShell._viewGeneration && !opts.refresh) return;
    const rows = stmt.rows || [];
    const totalOwed = rows.reduce((s,j)=>s+(+j.credit||0),0);
    document.getElementById('stmtTitle').textContent=`كشف حساب مورد - ${stmt.vendor?.name || vn?.name || ''}`;
    document.getElementById('stmtBody').innerHTML=`
      <div class="grid-3" style="margin-bottom:20px">
        <div class="card" style="background:var(--bg)"><div class="card-body" style="text-align:center"><div style="font-size:11px;color:var(--text-sm)">إجمالي المستحقات</div><div style="font-size:20px;font-weight:800;color:var(--warning)">${fmt(totalOwed)}</div></div></div>
        <div class="card" style="background:var(--bg)"><div class="card-body" style="text-align:center"><div style="font-size:11px;color:var(--text-sm)">المدفوع</div><div style="font-size:20px;font-weight:800;color:var(--success)">${fmt(stmt.paid)}</div></div></div>
        <div class="card" style="background:var(--bg)"><div class="card-body" style="text-align:center"><div style="font-size:11px;color:var(--text-sm)">الرصيد الحالي</div><div style="font-size:20px;font-weight:800;color:${stmt.balance>0?'var(--warning)':'var(--success)'}">${fmt(stmt.balance)}</div></div></div>
      </div>
      <div class="table-wrapper"><table class="table"><thead><tr><th>التاريخ</th><th>المرجع</th><th>البيان</th><th>مدين</th><th>دائن</th></tr></thead>
      <tbody>${rows.map(j=>`<tr><td>${j.date}</td><td>${j.ref}</td><td style="font-size:12px">${j.desc||''}</td><td>${signedAmount(j.debit,'var(--success)','var(--danger)')}</td><td>${signedAmount(j.credit,'var(--danger)','var(--success)')}</td></tr>`).join('')||'<tr><td colspan="5" style="text-align:center;color:var(--text-sm)">لا توجد حركات</td></tr>'}</tbody></table></div>`;
  }catch(e){ document.getElementById('stmtBody').innerHTML=`<p style="padding:20px;text-align:center;color:var(--danger)">${e.message}</p>`; }
};

exportClientStmt = async function(cid){
  try{
    const stmt = await apiFetch(`/clients/${cid}/statement`);
    toExcelAndDownload((stmt.rows||[]).map(j=>({date:j.date,ref:j.ref,desc:j.desc,debit:j.debit||0,credit:j.credit||0,balance:j.balance})),['التاريخ','المرجع','البيان','مدين','دائن','الرصيد'],`كشف_حساب_${stmt.client?.name||cid}`);
  }catch(e){ notify(e.message, 'error'); }
};

async function loadReport(type, params = {}){
  const qs = new URLSearchParams(Object.entries(params).filter(([, v]) => v));
  const cacheKey = type + (qs.toString() ? '?' + qs.toString() : '');
  if (REPORT_CACHE[cacheKey]) return REPORT_CACHE[cacheKey];
  const path = `/reports/${type}${qs.toString() ? '?' + qs.toString() : ''}`;
  const data = await apiFetch(path);
  REPORT_CACHE[cacheKey] = data;
  return data;
}

function rptOpsDateParams(){
  return {
    from: document.getElementById('rptOpsFrom')?.value || '',
    to: document.getElementById('rptOpsTo')?.value || '',
  };
}

filterRptOps = function(){
  renderRptContent();
};

renderRptContent = async function(){
  const wrap=document.getElementById('rptContent');
  if(!wrap)return;
  if(rptTab === 'ops'){
    const dateParams = rptOpsDateParams();
    wrap.innerHTML=`
      <div class="filter-bar" style="margin-bottom:12px">
        <input type="date" class="form-control filter-control" id="rptOpsFrom" value="${dateParams.from}" onchange="filterRptOps()" title="من تاريخ">
        <input type="date" class="form-control filter-control" id="rptOpsTo" value="${dateParams.to}" onchange="filterRptOps()" title="إلى تاريخ">
      </div>
      <p style="padding:20px;text-align:center;color:var(--text-sm)">جاري تحميل تقرير العمليات...</p>`;
    try{
      const data = await loadReport('operations', dateParams);
      const rows = data.rows || [];
      const totals = data.totals || {};
      wrap.innerHTML=`
        <div class="filter-bar" style="margin-bottom:12px">
          <input type="date" class="form-control filter-control" id="rptOpsFrom" value="${dateParams.from}" onchange="filterRptOps()" title="من تاريخ">
          <input type="date" class="form-control filter-control" id="rptOpsTo" value="${dateParams.to}" onchange="filterRptOps()" title="إلى تاريخ">
        </div>
        <div class="grid-kpi-3">
          ${kpiCard('💰','إجمالي الإيرادات',fmt(totals.revenue||0),'var(--primary)','')}
          ${kpiCard('💸','إجمالي التكاليف',fmt(totals.cost||0),'var(--danger)','')}
          ${kpiCard('📈','صافي الربح',fmt(totals.profit||0),'var(--success)','')}
        </div>
        <div class="table-wrapper"><table class="table"><thead><tr><th>المرجع</th><th>التاريخ</th><th>العميل</th><th>الخدمة</th><th>الإيراد</th><th>التكلفة</th><th>الربح</th><th>الحالة</th></tr></thead>
        <tbody>${rows.map(o=>`<tr><td><b>${o.ref}</b></td><td>${o.date}</td><td>${o.client||clientName(o.client_id)}</td><td>${o.service||serviceName(o.service_id)}</td><td>${fmt(o.client_price)}</td><td>${fmt(o.vendor_cost)}</td><td style="color:${o.profit>=0?'var(--success)':'var(--danger)'};font-weight:700">${fmt(o.profit)}</td><td><span class="badge ${statusClass[o.status]}">${statusLabel[o.status]}</span></td></tr>`).join('')||'<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-sm)">لا توجد عمليات في هذه الفترة</td></tr>'}</tbody></table></div>`;
    }catch(e){ wrap.innerHTML=`<p style="padding:20px;text-align:center;color:var(--danger)">${e.message}</p>`; }
    return;
  }
  if(rptTab === 'profit'){
    wrap.innerHTML='<p style="padding:20px;text-align:center;color:var(--text-sm)">جاري تحميل تقرير الربحية...</p>';
    try{
      const data = await loadReport('profit');
      const rows = data.rows || [];
      wrap.innerHTML=`<div class="table-wrapper"><table class="table"><thead><tr><th>الخدمة</th><th>عدد العمليات</th><th>الإيرادات</th><th>التكاليف</th><th>الربح</th><th>هامش الربح</th></tr></thead>
      <tbody>${rows.map(s=>`<tr><td>${s.icon||''} <b>${s.name}</b></td><td><span class="badge badge-info">${s.count}</span></td><td>${fmt(s.revenue)}</td><td>${fmt(s.cost)}</td><td style="color:var(--success);font-weight:700">${fmt(s.profit)}</td><td>${s.revenue>0?(s.profit/s.revenue*100).toFixed(1)+'%':'—'}</td></tr>`).join('')}</tbody></table></div>`;
    }catch(e){ wrap.innerHTML=`<p style="padding:20px;text-align:center;color:var(--danger)">${e.message}</p>`; }
    return;
  }
  if(rptTab === 'aging'){
    wrap.innerHTML='<p style="padding:20px;text-align:center;color:var(--text-sm)">جاري تحميل تقرير التقادم...</p>';
    try{
      const data = await loadReport('aging');
      const aged = data.rows || [];
      wrap.innerHTML=`
        <div style="margin-bottom:12px;display:flex;justify-content:flex-end"><button class="btn btn-sm btn-outline" onclick="exportAgingReport()">Excel</button></div>
        <div class="table-wrapper"><table class="table"><thead><tr><th>العميل</th><th>الإجمالي</th><th>1-30 يوم</th><th>31-60 يوم</th><th>61-90 يوم</th><th>+90 يوم</th></tr></thead>
        <tbody>${aged.map(a=>`<tr><td><b>${a.name}</b><br><small style="color:var(--text-sm)">${a.days} يوم</small></td><td style="font-weight:700;color:var(--danger)">${fmt(a.balance)}</td><td>${a.b1>0?fmt(a.b1):'—'}</td><td style="color:${a.b2>0?'var(--warning)':'inherit'}">${a.b2>0?fmt(a.b2):'—'}</td><td style="color:${a.b3>0?'var(--danger)':'inherit'}">${a.b3>0?fmt(a.b3):'—'}</td><td style="color:${a.b4>0?'var(--danger)':'inherit'};font-weight:${a.b4>0?'700':'400'}">${a.b4>0?fmt(a.b4):'—'}</td></tr>`).join('')||'<tr><td colspan="6" style="text-align:center;padding:40px;color:var(--success)">لا توجد ديون متأخرة</td></tr>'}</tbody></table></div>`;
    }catch(e){ wrap.innerHTML=`<p style="padding:20px;text-align:center;color:var(--danger)">${e.message}</p>`; }
    return;
  }
  if(rptTab === 'cashflow'){
    wrap.innerHTML='<p style="padding:20px;text-align:center;color:var(--text-sm)">جاري تحميل التدفق النقدي...</p>';
    try{
      const data = await loadReport('cashflow');
      const safes = data.safes || [];
      const rows = data.rows || [];
      const safeHeaders = safes.map(s=>`<th>رصيد ${s.name}</th>`).join('');
      wrap.innerHTML=`
        <div style="margin-bottom:12px;display:flex;justify-content:flex-end"><button class="btn btn-sm btn-outline" onclick="exportCashFlow()">Excel</button></div>
        <div class="table-wrapper"><table class="table"><thead><tr><th>التاريخ</th><th>وارد</th><th>صادر</th><th>صافي</th>${safeHeaders}</tr></thead>
        <tbody>${rows.map(r=>`<tr><td>${r.date}</td><td style="color:var(--success)">${r.inflow!==0?fmt(r.inflow):'—'}</td><td style="color:var(--danger)">${r.outflow!==0?fmt(r.outflow):'—'}</td><td style="font-weight:700;color:${r.net>=0?'var(--success)':'var(--danger)'}">${fmt(r.net)}</td>${safes.map(s=>`<td>${fmt(r.safes?.[s.id]||0)}</td>`).join('')}</tr>`).join('')||'<tr><td colspan="'+(4+safes.length)+'" style="text-align:center;color:var(--text-sm)">لا توجد حركات نقدية</td></tr>'}</tbody></table></div>`;
    }catch(e){ wrap.innerHTML=`<p style="padding:20px;text-align:center;color:var(--danger)">${e.message}</p>`; }
    return;
  }
  if(rptTab === 'employee'){
    wrap.innerHTML='<p style="padding:20px;text-align:center;color:var(--text-sm)">جاري تحميل أداء الموظفين...</p>';
    try{
      const data = await loadReport('employee');
      const rows = data.rows || [];
      wrap.innerHTML=`<div class="table-wrapper"><table class="table"><thead><tr><th>الموظف</th><th>الدور</th><th>العمليات</th><th>الإيراد</th><th>الربح</th></tr></thead>
      <tbody>${rows.map(u=>`<tr><td><b>${u.name}</b></td><td>${u.role||''}</td><td><span class="badge badge-info">${u.count}</span></td><td>${fmt(u.revenue)}</td><td style="color:var(--success);font-weight:700">${fmt(u.profit)}</td></tr>`).join('')}</tbody></table></div>`;
    }catch(e){ wrap.innerHTML=`<p style="padding:20px;text-align:center;color:var(--danger)">${e.message}</p>`; }
    return;
  }
  if(rptTab === 'clients_debt'){
    wrap.innerHTML='<p style="padding:20px;text-align:center;color:var(--text-sm)">جاري تحميل مديونية العملاء...</p>';
    try{
      const data = await loadReport('clients-debt');
      const rows = data.rows || [];
      wrap.innerHTML=`<div class="table-wrapper"><table class="table"><thead><tr><th>العميل</th><th>الهاتف</th><th>المشتريات</th><th>المدفوع</th><th>الرصيد</th><th>آخر عملية</th></tr></thead>
      <tbody>${rows.map(c=>`<tr><td><b>${c.name}</b></td><td>${c.phone||''}</td><td>${fmt(c.totalPurchases)}</td><td>${fmt(c.totalPaid)}</td><td style="color:var(--danger);font-weight:700">${fmt(c.balance)}</td><td>${c.lastOpDate}</td></tr>`).join('')||'<tr><td colspan="6" style="text-align:center;color:var(--success)">لا توجد مديونيات</td></tr>'}</tbody></table></div>`;
    }catch(e){ wrap.innerHTML=`<p style="padding:20px;text-align:center;color:var(--danger)">${e.message}</p>`; }
    return;
  }
  if(rptTab === 'vendors_balance'){
    wrap.innerHTML='<p style="padding:20px;text-align:center;color:var(--text-sm)">جاري تحميل أرصدة الموردين...</p>';
    try{
      const data = await loadReport('vendors-balance');
      const rows = data.rows || [];
      wrap.innerHTML=`<div class="table-wrapper"><table class="table"><thead><tr><th>المورد</th><th>التصنيف</th><th>إجمالي الخدمات</th><th>المدفوع</th><th>الرصيد</th><th>آخر عملية</th></tr></thead>
      <tbody>${rows.map(v=>`<tr><td><b>${v.name}</b></td><td>${v.category||''}</td><td>${fmt(v.totalServices)}</td><td>${fmt(v.totalPaid)}</td><td style="color:var(--warning);font-weight:700">${fmt(v.balance)}</td><td>${v.lastOpDate}</td></tr>`).join('')||'<tr><td colspan="6" style="text-align:center;color:var(--success)">لا توجد أرصدة مستحقة</td></tr>'}</tbody></table></div>`;
    }catch(e){ wrap.innerHTML=`<p style="padding:20px;text-align:center;color:var(--danger)">${e.message}</p>`; }
    return;
  }
  __originalRenderRptContent();
};

exportAgingReport = async function(){
  try{
    const data = await loadReport('aging');
    const rows = data.rows || [];
    toExcelAndDownload(rows.map(a=>({name:a.name,balance:a.balance,days:a.days,b1:a.b1,b2:a.b2,b3:a.b3,b4:a.b4})),['العميل','الرصيد','عمر الدين (أيام)','1-30 يوم','31-60 يوم','61-90 يوم','+90 يوم'],'تقرير_التقادم');
  }catch(e){ notify(e.message, 'error'); }
};

exportCashFlow = async function(){
  try{
    const data = await loadReport('cashflow');
    const safes = data.safes || [];
    const headers = ['التاريخ','وارد','صادر','صافي',...safes.map(s=>'رصيد '+s.name)];
    const rows = (data.rows||[]).map(r=>Object.fromEntries([
      ['date', r.date],
      ['inflow', r.inflow],
      ['outflow', r.outflow],
      ['net', r.net],
      ...safes.map(s=>['safe_'+s.id, r.safes?.[s.id]||0])
    ]));
    toExcelAndDownload(rows,headers,'التدفق_النقدي');
  }catch(e){ notify(e.message, 'error'); }
};

saveProfile = async function(){
  await withSaveGuard(null, async ()=>{
    const data = await apiFetch('/profile',{method:'PATCH',body:JSON.stringify({name:document.getElementById('prof_name')?.value.trim()})});
    currentUser=data.user;
    document.getElementById('userName').textContent=currentUser.name;
    notify('تم حفظ البيانات', 'success');
  }).catch(e=> notify(e.message, 'error'));
};

exportClients = async function(){
  try {
    const rows = await fetchAllPages('/clients');
    toExcelAndDownload(rows.map(c=>({id:c.id,name:c.name,phone:c.phone,civil_id:c.civil_id,email:c.email,nationality:c.nationality,balance:c.balance})),['#','الاسم','الهاتف','الرقم المدني','الإيميل','الجنسية','الرصيد'],'العملاء');
  } catch (e) { notify(e.message, 'error'); }
};
exportVendors = async function(){
  try {
    const rows = await fetchAllPages('/vendors');
    toExcelAndDownload(rows.map(v=>({id:v.id,name:v.name,category:categoryLabel[v.category]||v.category,phone:v.phone,contact:v.contact,balance:v.balance})),['#','الاسم','التصنيف','الهاتف','جهة الاتصال','الرصيد'],'الموردون');
  } catch (e) { notify(e.message, 'error'); }
};
exportVouchers = async function(){
  try {
    const from = document.getElementById('vcFrom')?.value || '';
    const to = document.getElementById('vcTo')?.value || '';
    const params = { type: vcTab };
    if (from) params.from = from;
    if (to) params.to = to;
    const rows = await fetchAllPages('/vouchers', params);
    toExcelAndDownload(rows.map(v=>({ref:v.ref,type:vcTypeLabel[v.type],date:v.date,party:partyLabel(v),amount:v.amount,method:methodLabel(v.method),safe:safeName(v.safe_id),status:v.reversed?'ملغى':'فعّال',desc:displayVal(v.desc)})),['الرقم','النوع','التاريخ','الطرف','المبلغ','الطريقة','الصندوق','الحالة','البيان'],'السندات');
  } catch (e) { notify(e.message, 'error'); }
};

openEditClient = async function(id){
  let c = CLIENTS.find(x=>x.id===id);
  if (!c) {
    try { const list = await apiFetch(`/clients?search=&per_page=1`); } catch (e) {}
    const res = await apiFetch(`/clients?per_page=500`);
    c = (res.data||[]).find(x=>x.id===id);
  }
  if (!c) { notify('العميل غير موجود', 'error'); return; }
  document.getElementById('ecl_id').value = c.id;
  document.getElementById('ecl_name').value = c.name||'';
  document.getElementById('ecl_phone').value = c.phone||'';
  document.getElementById('ecl_alt_phone').value = c.alt_phone||'';
  document.getElementById('ecl_civil_id').value = c.civil_id||'';
  document.getElementById('ecl_email').value = c.email||'';
  document.getElementById('ecl_nationality').value = c.nationality||'';
  document.getElementById('ecl_notes').value = c.notes||'';
  clearModalError('editClientModal');
  showModal('editClientModal');
};

saveClientEdit = async function(){
  const id = +document.getElementById('ecl_id').value;
  await withSaveGuard('#editClientModal .btn-primary', async ()=>{
    await apiFetch(`/clients/${id}`, {method:'PATCH', body:JSON.stringify({
      name: document.getElementById('ecl_name').value.trim(),
      phone: normalizePhone(document.getElementById('ecl_phone').value),
      alt_phone: normalizePhone(document.getElementById('ecl_alt_phone').value)||null,
      civil_id: document.getElementById('ecl_civil_id').value.trim()||null,
      email: document.getElementById('ecl_email').value,
      nationality: document.getElementById('ecl_nationality').value,
      notes: document.getElementById('ecl_notes').value,
    })});
    closeModal('editClientModal');
    await refreshAfterMutation();
    notify('تم تحديث العميل', 'success');
  }).catch(e=> showModalError('editClientModal', e.message));
};

deleteClient = async function(id){
  let c = CLIENTS.find(x=>x.id===id);
  if (!c) {
    const res = await apiFetch('/clients?per_page=500');
    c = (res.data||[]).find(x=>x.id===id);
  }
  if (!c) { notify('العميل غير موجود', 'error'); return; }
  if (!confirm(`هل أنت متأكد من حذف العميل «${c.name}»؟\n\nلا يمكن الحذف إذا كان مرتبطاً بعمليات أو سندات أو قيود محاسبية.`)) return;
  await withSaveGuard(null, async ()=>{
    await apiFetch(`/clients/${id}`, {method:'DELETE'});
    await refreshAfterMutation();
    notify('تم حذف العميل بنجاح', 'success');
  }).catch(e=> notify(e.message, 'error'));
};

openEditVendor = async function(id){
  let v = VENDORS.find(x=>x.id===id);
  if (!v) {
    const res = await apiFetch('/vendors?per_page=500');
    v = (res.data||[]).find(x=>x.id===id);
  }
  if (!v) { notify('المورد غير موجود', 'error'); return; }
  document.getElementById('evn_id').value = v.id;
  document.getElementById('evn_name').value = v.name||'';
  document.getElementById('evn_category').value = v.category||'other';
  document.getElementById('evn_phone').value = v.phone||'';
  document.getElementById('evn_contact').value = v.contact||'';
  document.getElementById('evn_address').value = v.address||'';
  showModal('editVendorModal');
};

saveVendorEdit = async function(){
  const id = +document.getElementById('evn_id').value;
  await withSaveGuard('#editVendorModal .btn-primary', async ()=>{
    await apiFetch(`/vendors/${id}`, {method:'PATCH', body:JSON.stringify({
      name: document.getElementById('evn_name').value.trim(),
      category: document.getElementById('evn_category').value,
      phone: document.getElementById('evn_phone').value,
      contact: document.getElementById('evn_contact').value,
      address: document.getElementById('evn_address').value,
    })});
    closeModal('editVendorModal');
    await refreshAfterMutation();
    notify('تم تحديث المورد', 'success');
  }).catch(e=> notify(e.message, 'error'));
};

deleteVendor = async function(id){
  let v = VENDORS.find(x=>x.id===id);
  if (!v) {
    const res = await apiFetch('/vendors?per_page=500');
    v = (res.data||[]).find(x=>x.id===id);
  }
  if (!v) { notify('المكتب غير موجود', 'error'); return; }
  if (!confirm(`هل أنت متأكد من حذف المكتب «${v.name}»؟\n\nلا يمكن الحذف إذا كان مرتبطاً بعمليات أو سندات أو قيود محاسبية.`)) return;
  await withSaveGuard(null, async ()=>{
    await apiFetch(`/vendors/${id}`, {method:'DELETE'});
    await refreshAfterMutation();
    notify('تم حذف المكتب بنجاح', 'success');
  }).catch(e=> notify(e.message, 'error'));
};

openEditOperation = async function(id){
  try {
    const op = await apiFetch(`/operations/${id}`);
    document.getElementById('eop_id').value = op.id;
    document.getElementById('eop_notes').value = op.notes||'';
    document.getElementById('eop_date').value = op.date||'';
    const fin = document.getElementById('eop_financial_fields');
    const isNew = op.status === 'new';
    if (fin) fin.style.display = isNew ? '' : 'none';
    if (isNew) {
      const [clientsRes, vendorsRes] = await Promise.all([
        apiFetch('/clients?per_page=500'),
        apiFetch('/vendors?per_page=500'),
      ]);
      document.getElementById('eop_client').innerHTML = (clientsRes.data||[]).map(c=>`<option value="${c.id}">${c.name}</option>`).join('');
      document.getElementById('eop_vendor').innerHTML = (vendorsRes.data||[]).map(v=>`<option value="${v.id}">${v.name}</option>`).join('');
      document.getElementById('eop_service').innerHTML = SERVICES.filter(s=>s.active).map(s=>`<option value="${s.id}">${s.icon} ${s.name}</option>`).join('');
      document.getElementById('eop_client').value = op.client_id;
      document.getElementById('eop_vendor').value = op.vendor_id;
      document.getElementById('eop_service').value = op.service_id;
      document.getElementById('eop_client_price').value = op.client_price;
      document.getElementById('eop_vendor_cost').value = op.vendor_cost;
      document.getElementById('eop_initial_payment').value = op.initial_payment;
      document.getElementById('eop_payment_method').value = op.payment_method||'cash';
    }
    clearModalError('editOpModal');
    showModal('editOpModal');
  } catch (e) { notify(e.message, 'error'); }
};

saveOperationEdit = async function(){
  const id = +document.getElementById('eop_id').value;
  await withSaveGuard('#editOpModal .btn-primary', async ()=>{
    const op = await apiFetch(`/operations/${id}`);
    const body = { notes: document.getElementById('eop_notes').value, date: document.getElementById('eop_date').value || undefined };
    if (op.status === 'new') {
      Object.assign(body, {
        client_id: +document.getElementById('eop_client').value,
        service_id: +document.getElementById('eop_service').value,
        vendor_id: +document.getElementById('eop_vendor').value,
        client_price: +document.getElementById('eop_client_price').value,
        vendor_cost: +document.getElementById('eop_vendor_cost').value,
        initial_payment: +document.getElementById('eop_initial_payment').value||0,
        payment_method: document.getElementById('eop_payment_method').value,
        currency: 'KWD',
      });
    }
    await apiFetch(`/operations/${id}`, {method:'PATCH', body:JSON.stringify(body)});
    closeModal('editOpModal');
    await refreshAfterMutation();
    await viewOp(id);
    notify('تم تحديث العملية', 'success');
  }).catch(e=> showModalError('editOpModal', e.message));
};

voidVoucher = async function(id){
  if (!confirm('إلغاء هذا السند؟ سيتم إنشاء قيود عكسية في دفتر اليومية.')) return;
  await withSaveGuard(null, async ()=>{
    await apiFetch(`/vouchers/${id}/void`, {method:'POST'});
    await refreshAfterMutation();
    if (AppShell.drawerContext?.type === 'operation') await viewOp(AppShell.drawerContext.id, { refresh: true });
    else if (currentPage === 'vouchers') await reloadVouchersList();
    notify('تم إلغاء السند وتسجيل القيود العكسية', 'success');
  }).catch(e=> notify(e.message, 'error'));
};

const __originalRenderDashboard = renderDashboard;
renderDashboard = function(pc){
  if (DASHBOARD_DATA?.last_operations) {
    const lastOps = DASHBOARD_DATA.last_operations;
    const debtors = (DASHBOARD_DATA.top_debtors||[]).map(c=>({...c, bal:c.balance}));
    const creditors = (DASHBOARD_DATA.top_creditors||[]).map(v=>({...v, bal:v.balance}));
    const overdueList = DASHBOARD_DATA.overdue_operations||[];
    const todayStr = today();
    const todaySales = DASHBOARD_DATA.today_sales ?? 0;
    const todayProfit = DASHBOARD_DATA.today_profit ?? 0;
    const totalReceipts = DASHBOARD_DATA.total_cash_receipts ?? 0;
    const totalPayments = DASHBOARD_DATA.total_payments ?? 0;
    const days = DASHBOARD_DATA.week?.days || [];
    const weekReceipts = DASHBOARD_DATA.week?.receipts || [];
    const weekPayments = DASHBOARD_DATA.week?.payments || [];
    const svcCount = DASHBOARD_DATA.services || [];
    const todayOpsCount = OPS.filter(o=>o.date===todayStr&&o.status!=='cancelled').length;

    pc.innerHTML=`
  <div class="page-shell">
    <div class="grid-kpi-4">
      ${kpiCard('💰','مبيعات اليوم',fmt(todaySales),'var(--primary)','اليوم: '+todayOpsCount+' عمليات')}
      ${kpiCard('📈','ربح متوقع اليوم',fmt(todayProfit),'var(--success)',formatMargin(todayProfit,todaySales))}
      ${kpiCard('🧾','التحصيلات النقدية',fmt(totalReceipts),'var(--info)','')}
      ${kpiCard('💸','إجمالي المدفوعات',fmt(totalPayments),'var(--warning)','')}
    </div>
    <div class="grid-charts-2-1">
      <div class="card"><div class="card-header"><h3>حركة الأسبوع (قبض ودفع)</h3></div><div class="card-body" style="min-height:240px"><div class="chart-box"><canvas id="weekChart"></canvas></div></div></div>
      <div class="card"><div class="card-header"><h3>العمليات حسب الخدمة</h3></div><div class="card-body" style="min-height:240px"><div class="chart-box"><canvas id="svcChart"></canvas></div></div></div>
    </div>
    <div class="grid-dashboard-split">
      <div class="card">
        <div class="card-header"><h3>آخر 10 عمليات</h3><button class="btn btn-sm btn-outline" onclick="navigate('operations')">عرض الكل</button></div>
        <div class="card-body" style="padding:0"><div class="table-wrapper">
        <table class="table"><thead><tr><th>الرقم</th><th>العميل</th><th>الخدمة</th><th>المبلغ</th><th>الربح</th><th>الحالة</th></tr></thead>
        <tbody>${lastOps.map(o=>`<tr onclick="viewOp(${o.id})" style="cursor:pointer"><td><b>${o.ref}</b></td><td>${o.client||clientName(o.client_id)}</td><td>${o.service||serviceName(o.service_id)}</td><td>${fmt(o.client_price)}</td><td style="color:${o.profit>=0?'var(--success)':'var(--danger)'}">${fmt(o.profit)}</td><td><span class="badge ${statusClass[o.status]}">${statusLabel[o.status]}</span></td></tr>`).join('')}</tbody></table>
        </div></div>
      </div>
      <div class="grid-stack">
        ${overdueList.length>0?`<div class="card" style="border-right:4px solid var(--warning)"><div class="card-header"><h3>⏰ متأخر التحصيل (+7 أيام)</h3></div><div class="card-body">${overdueList.map(o=>`<div style="padding:8px 0;border-bottom:1px solid var(--border);font-size:13px"><b>${o.ref}</b> - ${o.client||clientName(o.client_id)}<br><small style="color:var(--danger)">تاريخ: ${o.date} | ${statusLabel[o.status]} | متبقي: ${fmt(+o.client_outstanding||0)}</small></div>`).join('')}</div></div>`:''}
        <div class="card"><div class="card-header"><h3>🔴 أعلى 5 مدينين</h3></div><div class="card-body" style="padding:0"><div class="table-wrapper">
        <table class="table"><thead><tr><th>العميل</th><th>المبلغ</th></tr></thead><tbody>${debtors.map(c=>`<tr><td>${c.name}</td><td style="color:var(--danger);font-weight:700">${fmt(c.bal)}</td></tr>`).join('')||'<tr><td colspan="2" style="text-align:center;color:var(--text-sm)">لا يوجد مدينون</td></tr>'}</tbody></table></div></div></div>
        <div class="card"><div class="card-header"><h3>🟡 أعلى 5 دائنين</h3></div><div class="card-body" style="padding:0"><div class="table-wrapper">
        <table class="table"><thead><tr><th>المورد</th><th>المبلغ</th></tr></thead><tbody>${creditors.map(v=>`<tr><td>${v.name}</td><td style="color:var(--warning);font-weight:700">${fmt(v.bal)}</td></tr>`).join('')||'<tr><td colspan="2" style="text-align:center;color:var(--text-sm)">لا يوجد دائنون</td></tr>'}</tbody></table></div></div></div>
      </div>
    </div>
  </div>`;
    chartRenderTimer=setTimeout(()=>{
      chartRenderTimer=null;
      if(currentPage!=='dashboard')return;
      requestAnimationFrame(()=>{
        const wctx=document.getElementById('weekChart')?.getContext('2d');
        if(wctx){if(charts.week){try{charts.week.destroy();}catch(e){}} charts.week=new Chart(wctx,{type:'bar',data:{labels:days.map(d=>String(d).slice(5)),datasets:[{label:'مقبوضات',data:weekReceipts,backgroundColor:'rgba(5,150,105,.7)',borderRadius:6},{label:'مدفوعات',data:weekPayments,backgroundColor:'rgba(220,38,38,.7)',borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top'}},scales:{y:{beginAtZero:true}}}});}
        const sctx=document.getElementById('svcChart')?.getContext('2d');
        if(sctx){if(charts.svc){try{charts.svc.destroy();}catch(e){}} charts.svc=new Chart(sctx,{type:'doughnut',data:{labels:svcCount.map(s=>s.name),datasets:[{data:svcCount.map(s=>s.count),backgroundColor:['#1E3A8A','#059669','#D97706','#DC2626','#0891B2'],borderWidth:2}]},options:{responsive:true,maintainAspectRatio:false,cutout:'65%',plugins:{legend:{position:'bottom'}}}});}
      });
    },200);
    return;
  }
  __originalRenderDashboard(pc);
};

async function restoreSession(){
  AppShell.setAuthMode('login');
  document.getElementById('appLayout').style.display='none';
  document.getElementById('loginPage').style.display='flex';
  try{
    const data = await apiFetch('/me', {skipAuthRedirect:true});
    await refreshCsrfCookie();
    enterApp(data.user);
    await loadAllData();
    await navigate('dashboard');
  }catch(e){
    showLogin();
  }
}

document.addEventListener('DOMContentLoaded', restoreSession);
