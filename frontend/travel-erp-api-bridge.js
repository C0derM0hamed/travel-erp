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

async function loadAllData(){
  const data = await apiFetch('/bootstrap');
  replaceArray(USERS, data.users);
  replaceArray(SERVICES, data.services);
  replaceArray(VENDORS, data.vendors);
  replaceArray(CLIENTS, data.clients);
  replaceArray(OPS, data.operations);
  replaceArray(VOUCHERS, data.vouchers);
  replaceArray(SAFES, data.safes);
  BOOTSTRAP_METRICS = data.metrics || {};
  REPORT_CACHE = {};
  JOURNAL_CACHE = [];
  __journalLoaded = false;
  __apiBootstrapped = true;
  await loadDashboardData();
  try{ await loadJournalCache(); }catch(e){ notify('تعذر تحميل القيود المحاسبية', 'error'); }
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
    closeModal('newClientModal'); await loadAllData(); await navigate('clients');
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
    closeModal('newVendorModal'); await loadAllData(); await navigate('vendors');
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
      currency:document.getElementById('op_currency').value, client_price:cp, vendor_cost:vc,
      initial_payment:+document.getElementById('op_initial_payment').value||0, payment_method:document.getElementById('op_payment_method').value, notes:document.getElementById('op_notes').value
    })});
    closeModal('newOpModal'); await loadAllData(); await navigate('operations');
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
      amount:amt, currency:document.getElementById('vc_currency').value, method:document.getElementById('vc_method').value,
      safe_id:+document.getElementById('vc_safe').value, operation_id:+document.getElementById('vc_operation').value||null, ref:document.getElementById('vc_ref').value||undefined,
      description:document.getElementById('vc_desc').value
    })});
    const linkedOpId = +document.getElementById('vc_operation').value || null;
    closeModal('newVoucherModal');
    await loadAllData();
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
    await loadAllData(); await navigate('operations');
    notify('تم إلغاء العملية وإنشاء القيود العكسية بنجاح', 'success');
  }).catch(e=> notify(e.message, 'error'));
};

toggleService = async function(id){
  await withSaveGuard(null, async ()=>{
    await apiFetch(`/services/${id}/toggle`,{method:'PATCH'});
    await loadAllData(); await navigate('settings');
    notify('تم تحديث حالة الخدمة', 'success');
  }).catch(e=> notify(e.message, 'error'));
};

updateOperationStatus = async function(id, status){
  await withSaveGuard(null, async ()=>{
    const op = await apiFetch(`/operations/${id}/status`, {method:'PATCH', body:JSON.stringify({status})});
    const idx = OPS.findIndex(o=>o.id===id);
    if(idx>=0) OPS[idx] = op;
    await loadAllData();
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
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
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
        <table class="table"><thead><tr><th>الحساب</th><th>مدين</th><th>دائن</th><th>البيان</th></tr></thead>
        <tbody>${je.map(j=>`<tr><td>${j.account}</td><td>${signedAmount(j.debit,'var(--danger)','var(--success)')}</td><td>${signedAmount(j.credit,'var(--success)','var(--danger)')}</td><td style="font-size:12px">${j.desc||''}</td></tr>`).join('')||'<tr><td colspan="4" style="text-align:center;color:var(--text-sm)">لا توجد قيود</td></tr>'}</tbody></table>
      </div>
      <div>
        <h4 style="margin-bottom:10px;color:var(--primary)">السندات المرتبطة</h4>
        ${vcs.length?`<table class="table"><thead><tr><th>الرقم</th><th>النوع</th><th>المبلغ</th><th>الطريقة</th><th>التاريخ</th><th>الحالة</th></tr></thead>
        <tbody>${vcs.map(v=>`<tr><td><b>${v.ref}</b></td><td><span class="badge ${v.type==='receipt'?'badge-success':'badge-danger'}">${vcTypeLabel[v.type]}</span></td><td>${fmt(v.amount)}</td><td>${methodLabel(v.method)}</td><td>${v.date}</td><td>${v.reversed||op.status==='cancelled'?'<span class="badge badge-danger">ملغى</span>':'<span class="badge badge-success">فعّال</span>'}</td></tr>`).join('')}</tbody></table>`:'<p style="color:var(--text-sm);text-align:center;padding:20px">لا توجد سندات مرتبطة</p>'}
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
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px">
        <div class="card" style="background:var(--bg)"><div class="card-body" style="text-align:center"><div style="font-size:11px;color:var(--text-sm)">إجمالي المشتريات</div><div style="font-size:20px;font-weight:800;color:var(--primary)">${fmt(stmt.total_purchases)}</div></div></div>
        <div class="card" style="background:var(--bg)"><div class="card-body" style="text-align:center"><div style="font-size:11px;color:var(--text-sm)">المدفوع</div><div style="font-size:20px;font-weight:800;color:var(--success)">${fmt(stmt.paid)}</div></div></div>
        <div class="card" style="background:var(--bg)"><div class="card-body" style="text-align:center"><div style="font-size:11px;color:var(--text-sm)">الرصيد المتبقي</div><div style="font-size:20px;font-weight:800;color:${stmt.balance>0?'var(--danger)':'var(--success)'}">${fmt(stmt.balance)}</div></div></div>
      </div>
      <div style="margin-bottom:8px;display:flex;justify-content:space-between;align-items:center"><h4>حركات الحساب</h4><button class="btn btn-sm btn-outline" onclick="exportClientStmt(${cid})">تصدير</button></div>
      <table class="table"><thead><tr><th>التاريخ</th><th>المرجع</th><th>البيان</th><th>مدين</th><th>دائن</th><th>الرصيد</th></tr></thead>
      <tbody>${rows.map(j=>`<tr><td>${j.date}</td><td>${j.ref}</td><td style="font-size:12px">${j.desc||''}</td><td>${signedAmount(j.debit,'var(--danger)','var(--success)')}</td><td>${signedAmount(j.credit,'var(--success)','var(--danger)')}</td><td style="font-weight:700;color:${j.balance>0?'var(--danger)':'var(--success)'}">${fmt(j.balance)}</td></tr>`).join('')||'<tr><td colspan="6" style="text-align:center;color:var(--text-sm)">لا توجد حركات</td></tr>'}</tbody></table>`;
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
      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:20px">
        <div class="card" style="background:var(--bg)"><div class="card-body" style="text-align:center"><div style="font-size:11px;color:var(--text-sm)">إجمالي المستحقات</div><div style="font-size:20px;font-weight:800;color:var(--warning)">${fmt(totalOwed)}</div></div></div>
        <div class="card" style="background:var(--bg)"><div class="card-body" style="text-align:center"><div style="font-size:11px;color:var(--text-sm)">المدفوع</div><div style="font-size:20px;font-weight:800;color:var(--success)">${fmt(stmt.paid)}</div></div></div>
        <div class="card" style="background:var(--bg)"><div class="card-body" style="text-align:center"><div style="font-size:11px;color:var(--text-sm)">الرصيد الحالي</div><div style="font-size:20px;font-weight:800;color:${stmt.balance>0?'var(--warning)':'var(--success)'}">${fmt(stmt.balance)}</div></div></div>
      </div>
      <table class="table"><thead><tr><th>التاريخ</th><th>المرجع</th><th>البيان</th><th>مدين</th><th>دائن</th></tr></thead>
      <tbody>${rows.map(j=>`<tr><td>${j.date}</td><td>${j.ref}</td><td style="font-size:12px">${j.desc||''}</td><td>${signedAmount(j.debit,'var(--success)','var(--danger)')}</td><td>${signedAmount(j.credit,'var(--danger)','var(--success)')}</td></tr>`).join('')||'<tr><td colspan="5" style="text-align:center;color:var(--text-sm)">لا توجد حركات</td></tr>'}</tbody></table>`;
  }catch(e){ document.getElementById('stmtBody').innerHTML=`<p style="padding:20px;text-align:center;color:var(--danger)">${e.message}</p>`; }
};

exportClientStmt = async function(cid){
  try{
    const stmt = await apiFetch(`/clients/${cid}/statement`);
    toExcelAndDownload((stmt.rows||[]).map(j=>({date:j.date,ref:j.ref,desc:j.desc,debit:j.debit||0,credit:j.credit||0,balance:j.balance})),['التاريخ','المرجع','البيان','مدين','دائن','الرصيد'],`كشف_حساب_${stmt.client?.name||cid}`);
  }catch(e){ notify(e.message, 'error'); }
};

async function loadReport(type){
  if(REPORT_CACHE[type]) return REPORT_CACHE[type];
  const data = await apiFetch(`/reports/${type}`);
  REPORT_CACHE[type] = data;
  return data;
}

renderRptContent = async function(){
  const wrap=document.getElementById('rptContent');
  if(!wrap)return;
  if(rptTab === 'aging'){
    wrap.innerHTML='<p style="padding:20px;text-align:center;color:var(--text-sm)">جاري تحميل تقرير التقادم...</p>';
    try{
      const data = await loadReport('aging');
      const aged = data.rows || [];
      wrap.innerHTML=`
        <div style="margin-bottom:12px;display:flex;justify-content:flex-end"><button class="btn btn-sm btn-outline" onclick="exportAgingReport()">Excel</button></div>
        <table class="table"><thead><tr><th>العميل</th><th>الإجمالي</th><th>1-30 يوم</th><th>31-60 يوم</th><th>61-90 يوم</th><th>+90 يوم</th></tr></thead>
        <tbody>${aged.map(a=>`<tr><td><b>${a.name}</b><br><small style="color:var(--text-sm)">${a.days} يوم</small></td><td style="font-weight:700;color:var(--danger)">${fmt(a.balance)}</td><td>${a.b1>0?fmt(a.b1):'—'}</td><td style="color:${a.b2>0?'var(--warning)':'inherit'}">${a.b2>0?fmt(a.b2):'—'}</td><td style="color:${a.b3>0?'var(--danger)':'inherit'}">${a.b3>0?fmt(a.b3):'—'}</td><td style="color:${a.b4>0?'var(--danger)':'inherit'};font-weight:${a.b4>0?'700':'400'}">${a.b4>0?fmt(a.b4):'—'}</td></tr>`).join('')||'<tr><td colspan="6" style="text-align:center;padding:40px;color:var(--success)">لا توجد ديون متأخرة</td></tr>'}</tbody></table>`;
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
        <table class="table"><thead><tr><th>التاريخ</th><th>وارد</th><th>صادر</th><th>صافي</th>${safeHeaders}</tr></thead>
        <tbody>${rows.map(r=>`<tr><td>${r.date}</td><td style="color:var(--success)">${r.inflow!==0?fmt(r.inflow):'—'}</td><td style="color:var(--danger)">${r.outflow!==0?fmt(r.outflow):'—'}</td><td style="font-weight:700;color:${r.net>=0?'var(--success)':'var(--danger)'}">${fmt(r.net)}</td>${safes.map(s=>`<td>${fmt(r.safes?.[s.id]||0)}</td>`).join('')}</tr>`).join('')||'<tr><td colspan="'+(4+safes.length)+'" style="text-align:center;color:var(--text-sm)">لا توجد حركات نقدية</td></tr>'}</tbody></table>`;
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
