const API_BASE = '/api';
let __apiBootstrapped = false;
let __journalLoaded = false;
let __saveInFlight = false;
let BOOTSTRAP_METRICS = {};
let DASHBOARD_DATA = null;
let REPORT_CACHE = {};
let OFFICES = [];
let currentOffice = null;
let opFormState = { ready: false, clients: [], vendors: [], loading: false };
const __originalNavigate = navigate;
const __originalRenderRptContent = renderRptContent;
const PAGE_RENDERERS = {
  dashboard: 'renderDashboard', operations: 'renderOperations', clients: 'renderClients',
  vendors: 'renderVendors', vouchers: 'renderVouchers', journal: 'renderJournal',
  safes: 'renderSafes', reports: 'renderReports', activity: 'renderActivityLogs', settings: 'renderSettings',
};

const _avatarColors = ['#696CFF', '#71DD37', '#03C3EC', '#FFAB00', '#FF3E1D', '#8592A3', '#E83E8C', '#20C997'];
function getInitialsAvatar(name) {
  if(!name) return '';
  const n = String(name).trim();
  const parts = n.split(' ').filter(Boolean);
  const init = parts.length > 1 ? (parts[0][0] + parts[1][0]) : (n.substring(0, 2));
  const charSum = n.split('').reduce((sum, c) => sum + c.charCodeAt(0), 0);
  const color = _avatarColors[charSum % _avatarColors.length];
  return `<div class="avatar-circle" style="background:${color};box-shadow:0 2px 6px ${color}40">${init}</div>`;
}

function emptyStateHtml(icon, title, desc) {
  return `<div class="empty-state"><i class="${icon}"></i><h4>${title}</h4><p>${desc}</p></div>`;
}

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
  if(currentOffice?.id) opts.headers['X-Office-Id'] = String(currentOffice.id);
  const res = await fetch(API_BASE + path, opts);
  if(res.status === 419 && retryOnCsrf && needsCsrf(opts.method)){
    await refreshCsrfCookie();
    return apiFetch(path, {...options, retryOnCsrf:false});
  }
  if(!res.ok){
    let msg = 'حدث خطأ في الاتصال بالخادم';
    try{
      const err = await res.json();
      msg = translateApiMessage(err.message || Object.values(err.errors||{})[0]?.[0] || msg);
    }catch(e){}
    if(res.status === 403) msg = translateApiMessage(msg);
    else if(res.status === 401) msg = 'يرجى تسجيل الدخول للمتابعة.';
    else if(res.status === 404) msg = 'العنصر المطلوب غير موجود.';
    else if(res.status === 419) msg = 'انتهت صلاحية الجلسة، يرجى تسجيل الدخول مرة أخرى.';
    else if(res.status === 429) msg = 'محاولات كثيرة، يرجى الانتظار قليلاً.';
    else if(res.status === 500) msg = 'حدث خطأ غير متوقع، يرجى المحاولة مرة أخرى.';
    else if(res.status === 422 && msg === 'حدث خطأ في الاتصال بالخادم') msg = 'يرجى التحقق من البيانات المدخلة.';
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

function exportCtxArg(ctx){
  if (!ctx || typeof ctx !== 'object') return '';
  const parts = Object.entries(ctx).map(([k, v]) => {
    if (typeof v === 'number') return `${k}:${v}`;
    return `${k}:'${String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'")}'`;
  });
  return parts.length ? `, {${parts.join(', ')}}` : '';
}

function exportActionBar(handler, { excel = true, pdf = true, print = false, ctx = null } = {}){
  const ctxJson = exportCtxArg(ctx);
  const parts = [];
  if (excel) parts.push(`<button type="button" class="btn btn-sm btn-outline" onclick="runBackendExport('${handler}','xlsx', false${ctxJson})"><i class='bx bx-download'></i> Excel</button>`);
  if (pdf) parts.push(`<button type="button" class="btn btn-sm btn-outline" onclick="runBackendExport('${handler}','pdf', false${ctxJson})"><i class='bx bx-file'></i> PDF</button>`);
  if (print) parts.push(`<button type="button" class="btn btn-sm btn-outline" onclick="runBackendExport('${handler}','pdf', true${ctxJson})"><i class='bx bx-printer'></i> طباعة</button>`);
  return parts.join('');
}

function exportParamsFor(handler, ctx = {}){
  const dateRange = (fromId, toId) => {
    const p = {};
    applyDateParams(p, fromId, toId);
    return p;
  };
  switch (handler) {
    case 'operations':
      return Object.assign(
        {},
        document.getElementById('opSearchInput')?.value?.trim() ? { search: document.getElementById('opSearchInput').value.trim() } : {},
        (document.getElementById('opStatusFilter')?.value || 'all') !== 'all' ? { status: document.getElementById('opStatusFilter').value } : {},
        (document.getElementById('opSvcFilter')?.value || 'all') !== 'all' ? { service: document.getElementById('opSvcFilter').value } : {},
        dateRange('opFrom', 'opTo'),
      );
    case 'clients':
      return document.getElementById('clSearch')?.value?.trim() ? { search: document.getElementById('clSearch').value.trim() } : {};
    case 'vendors':
      return document.getElementById('vnSearch')?.value?.trim() ? { search: document.getElementById('vnSearch').value.trim() } : {};
    case 'vouchers':
      return Object.assign(
        { type: vcTab },
        document.getElementById('vcSearch')?.value?.trim() ? { search: document.getElementById('vcSearch').value.trim() } : {},
        dateRange('vcFrom', 'vcTo'),
      );
    case 'journal':
      return Object.assign(
        {},
        document.getElementById('jeSearch')?.value?.trim() ? { search: document.getElementById('jeSearch').value.trim() } : {},
        (document.getElementById('jeAccFilter')?.value || 'all') !== 'all' ? { account: document.getElementById('jeAccFilter').value } : {},
        dateRange('jeFrom', 'jeTo'),
      );
    case 'activity_logs':
      return Object.assign(
        {},
        document.getElementById('actSearch')?.value?.trim() ? { search: document.getElementById('actSearch').value.trim() } : {},
        (document.getElementById('actActionFilter')?.value || 'all') !== 'all' ? { action: document.getElementById('actActionFilter').value } : {},
        dateRange('actFrom', 'actTo'),
      );
    case 'client_statement':
      return dateRange('stmtFrom', 'stmtTo');
    case 'vendor_statement':
      return dateRange('vstmtFrom', 'vstmtTo');
    case 'report':
      return rptDateParams();
    default:
      return ctx.params || {};
  }
}

function exportPathFor(handler, ctx = {}){
  switch (handler) {
    case 'operations': return '/exports/operations';
    case 'clients': return '/exports/clients';
    case 'vendors': return '/exports/vendors';
    case 'vouchers': return '/exports/vouchers';
    case 'journal': return '/exports/journal';
    case 'activity_logs': return '/exports/activity-logs';
    case 'operation_detail': return `/exports/operations/${ctx.id}`;
    case 'operation_invoice': return `/exports/operations/${ctx.id}/invoice`;
    case 'voucher_detail': return `/exports/vouchers/${ctx.id}`;
    case 'client_statement': return `/exports/clients/${ctx.id}/statement`;
    case 'vendor_statement': return `/exports/vendors/${ctx.id}/statement`;
    case 'report': {
      const type = ctx.type || reportExportType();
      return `/exports/reports/${type}`;
    }
    default: return ctx.path || '/exports/operations';
  }
}

async function downloadBackendExport(path, params = {}, { format = 'xlsx', inline = false } = {}){
  const qs = new URLSearchParams({ ...params, format });
  if (inline) qs.set('inline', '1');
  const headers = {};
  if (currentOffice?.id) headers['X-Office-Id'] = String(currentOffice.id);
  const res = await fetch(`${API_BASE}${path}?${qs.toString()}`, { credentials: 'same-origin', headers });
  if (!res.ok) {
    let msg = 'فشل التصدير';
    try {
      const err = await res.json();
      msg = translateApiMessage(err.message || msg);
    } catch (e) {}
    if (res.status === 403) msg = 'ليس لديك صلاحية لتنفيذ هذا الإجراء.';
    else if (res.status === 404) msg = 'العنصر المطلوب غير موجود.';
    else if (res.status === 500) msg = 'حدث خطأ غير متوقع، يرجى المحاولة مرة أخرى.';
    throw new Error(msg);
  }
  const blob = await res.blob();
  const disposition = res.headers.get('Content-Disposition') || '';
  const match = disposition.match(/filename=\"?([^\";]+)\"?/i);
  const filename = match ? decodeURIComponent(match[1]) : `export.${format === 'pdf' ? 'pdf' : 'xlsx'}`;
  const url = URL.createObjectURL(blob);
  if (inline) {
    const w = window.open(url, '_blank');
    if (!w) {
      notify('يرجى السماح بالنوافذ المنبثقة للطباعة', 'warning');
    } else {
      w.onload = () => { try { w.focus(); w.print(); } catch (e) {} };
    }
    setTimeout(() => URL.revokeObjectURL(url), 60000);
    return;
  }
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

async function runBackendExport(handler, format, forPrint = false, ctx = {}){
  try {
    await downloadBackendExport(
      exportPathFor(handler, ctx),
      exportParamsFor(handler, ctx),
      { format, inline: forPrint && format === 'pdf' },
    );
    if (!forPrint) notify('تم التصدير بنجاح', 'success');
  } catch (e) {
    notify(e.message, 'error');
  }
}
window.runBackendExport = runBackendExport;

exportOps = () => runBackendExport('operations', 'xlsx');
exportOpsPDF = () => runBackendExport('operations', 'pdf');
exportClients = () => runBackendExport('clients', 'xlsx');
exportClientsPDF = () => runBackendExport('clients', 'pdf');
exportVendors = () => runBackendExport('vendors', 'xlsx');
exportVendorsPDF = () => runBackendExport('vendors', 'pdf');
exportVouchers = () => runBackendExport('vouchers', 'xlsx');
exportVouchersPDF = () => runBackendExport('vouchers', 'pdf');
exportJournal = () => runBackendExport('journal', 'xlsx');
exportJournalPDF = () => runBackendExport('journal', 'pdf');
printJournal = () => runBackendExport('journal', 'pdf', true);
exportActivityLogs = () => runBackendExport('activity_logs', 'xlsx');
exportActivityLogsPDF = () => runBackendExport('activity_logs', 'pdf');
exportClientStmt = (cid) => runBackendExport('client_statement', 'xlsx', false, { id: cid });
exportClientStmtPDF = (cid) => runBackendExport('client_statement', 'pdf', false, { id: cid });
printClientStmtPDF = (cid) => runBackendExport('client_statement', 'pdf', true, { id: cid });
printVendorStmtPDF = (vid) => runBackendExport('vendor_statement', 'pdf', true, { id: vid });
exportVendorStmt = (vid) => runBackendExport('vendor_statement', 'xlsx', false, { id: vid });
exportVendorStmtPDF = (vid) => runBackendExport('vendor_statement', 'pdf', false, { id: vid });
function translateApiMessage(message){
  if(!message) return 'يرجى التحقق من البيانات المدخلة.';
  const key = String(message).trim().toLowerCase();
  const map = {
    'forbidden': 'ليس لديك صلاحية لتنفيذ هذا الإجراء.',
    'this action is unauthorized.': 'ليس لديك صلاحية لتنفيذ هذا الإجراء.',
    'unauthorized': 'يرجى تسجيل الدخول للمتابعة.',
    'unauthenticated.': 'يرجى تسجيل الدخول للمتابعة.',
    'not found': 'العنصر المطلوب غير موجود.',
    'not found.': 'العنصر المطلوب غير موجود.',
    'server error': 'حدث خطأ غير متوقع، يرجى المحاولة مرة أخرى.',
    'internal server error': 'حدث خطأ غير متوقع، يرجى المحاولة مرة أخرى.',
    'the selected client id is invalid.': 'العميل المحدد غير موجود أو لا ينتمي إلى المكتب الحالي.',
    'the selected vendor id is invalid.': 'المورد المحدد غير موجود أو لا ينتمي إلى المكتب الحالي.',
    'the selected service id is invalid.': 'الخدمة غير موجودة أو غير مفعّلة.',
    'the selected operation id is invalid.': 'العملية المحددة غير موجودة أو لا تنتمي إلى المكتب الحالي.',
    'the selected safe id is invalid.': 'الصندوق المحدد غير موجود أو لا ينتمي إلى المكتب الحالي.',
    'office context is required.': 'لم يتم تحديد المكتب الحالي. يرجى إعادة تسجيل الدخول.',
    'validation failed.': 'يرجى التحقق من البيانات المدخلة.',
  };
  if (map[key]) return map[key];
  if (/^the selected .+ is invalid\.?$/.test(key)) return 'القيمة المحددة غير صالحة أو لا تنتمي إلى المكتب الحالي.';
  if (/^the .+ field is required\.?$/.test(key)) return 'يرجى تعبئة جميع الحقول المطلوبة.';
  return message;
}

function activityActionLabel(action, actionLabel){
  if (actionLabel) return actionLabel;
  const map = {
    'operation.created': 'تم إنشاء عملية',
    'operation.updated': 'تم تعديل عملية',
    'operation.hidden': 'تم إخفاء عملية',
    'operation.restored': 'تم استعادة عملية',
    'operation.cancelled': 'تم إلغاء عملية',
    'operation.status_updated': 'تم تحديث حالة عملية',
    'client.created': 'تم إنشاء عميل',
    'client.updated': 'تم تعديل عميل',
    'client.deleted': 'تم حذف عميل',
    'client.hidden': 'تم إخفاء عميل',
    'client.restored': 'تم استعادة عميل',
    'vendor.created': 'تم إنشاء مورد',
    'vendor.updated': 'تم تعديل مورد',
    'vendor.deleted': 'تم حذف مورد',
    'voucher.created': 'تم إنشاء سند',
    'voucher.voided': 'تم إلغاء سند',
    'safe.created': 'تم إنشاء صندوق',
    'safe.updated': 'تم تعديل صندوق',
    'safe.toggled': 'تم تغيير حالة صندوق',
    'safe_transfer.created': 'تم إنشاء تحويل بين الصناديق',
    'user.created': 'تم إنشاء مستخدم',
    'user.updated': 'تم تعديل مستخدم',
    'user.password_reset': 'تم إعادة تعيين كلمة مرور',
    'office.created': 'تم إنشاء مكتب',
    'office.updated': 'تم تعديل مكتب',
    'office.logo_updated': 'تم تحديث شعار المكتب',
    'office.logo_removed': 'تم حذف شعار المكتب',
    'service.toggled': 'تم تغيير حالة خدمة',
  };
  return map[action] || 'حدث نشاط';
}

function formatActivityDetails(log){
  if (log?.details) return log.details;
  return '—';
}

async function sendWhatsAppInvoice(operationId){
  try {
    const share = await apiFetch(`/operations/${operationId}/invoice-share`);
    if (!share.phone) {
      notify('لا يوجد رقم هاتف مسجل للعميل', 'warning');
      return;
    }
    const url = share.whatsapp_url;
    if (!url) {
      notify('تعذر إنشاء رابط واتساب', 'error');
      return;
    }
    window.open(url, '_blank');
    notify('تم فتح واتساب مع رسالة الفاتورة', 'success');
  } catch (e) {
    notify(e.message || 'تعذر إرسال الفاتورة عبر واتساب', 'error');
  }
}
window.sendWhatsAppInvoice = sendWhatsAppInvoice;
printOperationDetail = (id) => runBackendExport('operation_detail', 'pdf', true, { id });
exportOperationDetailPDF = (id) => runBackendExport('operation_detail', 'pdf', false, { id });
exportOperationInvoicePDF = (id) => runBackendExport('operation_invoice', 'pdf', false, { id });
printOperationInvoicePDF = (id) => runBackendExport('operation_invoice', 'pdf', true, { id });
printVoucherExport = (id) => runBackendExport('voucher_detail', 'pdf', true, { id });
exportReportExcel = () => runBackendExport('report', 'xlsx', false, { type: reportExportType() });
exportReportPDF = () => runBackendExport('report', 'pdf', false, { type: reportExportType() });
exportAgingReport = () => runBackendExport('report', 'xlsx', false, { type: 'aging' });
exportCashFlow = () => runBackendExport('report', 'xlsx', false, { type: 'cashflow' });
exportProfitReport = () => runBackendExport('report', 'xlsx', false, { type: 'profit' });
exportClientsDebtReport = () => runBackendExport('report', 'xlsx', false, { type: 'clients-debt' });
exportVendorsBalanceReport = () => runBackendExport('report', 'xlsx', false, { type: 'vendors-balance' });
function reportExportType(){
  if (rptTab === 'ops') return 'operations';
  if (rptTab === 'clients_debt') return 'clients-debt';
  if (rptTab === 'vendors_balance') return 'vendors-balance';
  return rptTab;
}
function reportExportBar(){
  return exportActionBar('report');
}


function clearLocalState(){
  currentUser=null; currentOffice=null; OFFICES=[]; __apiBootstrapped=false; __journalLoaded=false; JOURNAL_CACHE=[]; BOOTSTRAP_METRICS={}; DASHBOARD_DATA=null; REPORT_CACHE={};
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
  const officeId = user.current_office_id || user.office_id || user.office?.id || null;
  currentOffice = officeId
    ? { id: officeId, ...(user.office || {}) }
    : (user.office || null);
  AppShell.setAuthMode('app');
  document.getElementById('loginPage').style.display='none';
  document.getElementById('appLayout').style.display='flex';
  document.getElementById('userName').textContent=currentUser.name;
  document.getElementById('userRole').textContent=currentUser.roleLabel;
  document.getElementById('userAvatar').textContent=currentUser.avatar;
  document.getElementById('loginError').style.display='none';
  if(typeof applyRoleUi==='function')applyRoleUi();
  renderOfficeSwitcher();
  renderOfficeBranding();
}

function renderOfficeSwitcher(){
  const el = document.getElementById('officeSwitcher');
  if(!el) return;
  const offices = OFFICES.length ? OFFICES : (currentOffice ? [currentOffice] : []);
  if(offices.length <= 1 && currentUser?.role !== 'super_admin' && currentUser?.role !== 'admin'){
    el.style.display = 'none';
    return;
  }
  el.style.display = '';
  el.innerHTML = offices.map(o => `<option value="${o.id}" ${String(o.id)===String(currentOffice?.id)?'selected':''}>${o.office_name || o.office_code}</option>`).join('');
}

async function switchOffice(officeId){
  if(!officeId) return;
  try{
    closeModal('newOpModal');
    closeModal('editOpModal');
    opFormState = { ready: false, clients: [], vendors: [], loading: false };
    const data = await apiFetch('/session/office', {method:'POST', body:JSON.stringify({office_id:+officeId})});
    currentOffice = data.office;
    currentUser = data.user;
    OFFICES.forEach(o => { if(+o.id === +officeId) Object.assign(o, data.office); });
    renderOfficeSwitcher();
    replaceArray(CLIENTS, []); replaceArray(VENDORS, []); replaceArray(OPS, []); replaceArray(VOUCHERS, []);
    JOURNAL_CACHE=[]; __journalLoaded=false; DASHBOARD_DATA=null; REPORT_CACHE={};
    await loadAllData();
    if(typeof PAGE_RENDERERS !== 'undefined' && typeof window[PAGE_RENDERERS[currentPage]] === 'function'){
      window[PAGE_RENDERERS[currentPage]](document.getElementById('pageContent'));
    }
    notify('تم التبديل إلى: ' + (currentOffice.office_name || currentOffice.office_code), 'success');
    renderOfficeBranding();
  }catch(e){ notify(e.message, 'error'); }
}

function officeLogoUrl(office){
  if(!office) return null;
  if(office.logo_url) return office.logo_url;
  if(office.logo && !String(office.logo).startsWith('http')) return '/storage/' + String(office.logo).replace(/^\/+/, '');
  return office.logo || null;
}

function renderOfficeBranding(){
  const office = currentOffice || currentUser?.office || null;
  const iconEl = document.getElementById('sidebarOfficeLogo');
  const nameEl = document.getElementById('sidebarOfficeName');
  const topbarEl = document.getElementById('topbarOfficeLogo');
  const url = officeLogoUrl(office);
  if(iconEl){
    iconEl.innerHTML = url
      ? `<img src="${url}" alt="${office?.office_name || 'شعار المكتب'}" onerror="this.src='logo.png'; this.onerror=null;">`
      : '<img src="logo.png" alt="Logo" onerror="this.src=\'logo.png\'; this.onerror=null;">';
  }
  if(nameEl){
    const subtitle = office?.office_code ? `<small style="font-size:10px;opacity:.6">${office.office_code}</small>` : '<small style="font-size:10px;opacity:.6">ERP للوكالات</small>';
    nameEl.innerHTML = `${office?.office_name || 'نظام السفر'}<br>${subtitle}`;
  }
  if(topbarEl){
    topbarEl.style.display = 'flex';
    topbarEl.innerHTML = url
      ? `<img src="${url}" alt="" onerror="this.src='logo.png'; this.onerror=null;">`
      : '<img src="logo.png" alt="Logo" onerror="this.src=\'logo.png\'; this.onerror=null;">';
  }
}

function officeBrandBlock(subtitle=''){
  const office = currentOffice || currentUser?.office || null;
  const url = officeLogoUrl(office);
  const name = office?.office_name || 'نظام إدارة خدمات السفر';
  const esc = typeof escapeHtml === 'function' ? escapeHtml : (v) => String(v ?? '');
  const logoHtml = url
    ? `<img src="${esc(url)}" alt="" style="width:56px;height:56px;object-fit:contain;border-radius:8px" onerror="this.src='logo.png'; this.onerror=null;">`
    : `<img src="logo.png" alt="" style="width:56px;height:56px;object-fit:contain;border-radius:8px" onerror="this.src='logo.png'; this.onerror=null;">`;
  return `<div style="display:flex;align-items:center;gap:12px">${logoHtml}<div><h1 style="font-size:18px;font-weight:800;color:var(--primary);margin:0">${esc(name)}</h1>${subtitle ? `<div style="font-size:13px;color:var(--text-sm);margin-top:4px;font-weight:700">${esc(subtitle)}</div>` : `<div style="font-size:12px;color:var(--text-sm);margin-top:2px">Travel Services Management System</div>`}</div></div>`;
}

function previewOfficeLogo(inputId, previewId, clearBtnId){
  const input = document.getElementById(inputId);
  const preview = document.getElementById(previewId);
  const clearBtn = clearBtnId ? document.getElementById(clearBtnId) : document.getElementById(inputId.replace('logo','logo_clear'));
  if(!input?.files?.[0] || !preview) return;
  const file = input.files[0];
  const allowed = ['image/jpeg','image/jpg','image/png','image/webp'];
  if(!allowed.includes(file.type)){ notify('نوع الملف غير مدعوم. المسموح: JPG, PNG, WEBP', 'warning'); input.value=''; return; }
  if(file.size > 5 * 1024 * 1024){ notify('حجم الشعار يجب ألا يتجاوز 5MB', 'warning'); input.value=''; return; }
  const reader = new FileReader();
  reader.onload = () => { preview.innerHTML = `<img src="${reader.result}" alt="معاينة الشعار">`; };
  reader.readAsDataURL(file);
  if(clearBtn) clearBtn.style.display = 'inline-flex';
}

function clearOfficeLogoPick(inputId, previewId, clearBtnId){
  const input = document.getElementById(inputId);
  const preview = document.getElementById(previewId);
  const clearBtn = clearBtnId ? document.getElementById(clearBtnId) : null;
  if(input) input.value = '';
  if(preview) preview.innerHTML = '<span class="office-logo-fallback"><i class="bx bx-building"></i></span>';
  if(clearBtn) clearBtn.style.display = 'none';
}

function resetNewOfficeForm(){
  ['office_code','office_name'].forEach(id => { const el = document.getElementById(id); if(el) el.value = ''; });
  clearOfficeLogoPick('office_logo','office_logo_preview','office_logo_clear');
  const err = document.getElementById('newOfficeModalError');
  if(err){ err.style.display='none'; err.textContent=''; }
}

let __editOfficeHasLogo = false;

function openEditOffice(id){
  const office = OFFICES.find(o => +o.id === +id);
  if(!office){ notify('المكتب غير موجود', 'error'); return; }
  document.getElementById('eoffice_id').value = office.id;
  document.getElementById('eoffice_code').value = office.office_code || '';
  document.getElementById('eoffice_name').value = office.office_name || '';
  __editOfficeHasLogo = !!officeLogoUrl(office);
  const preview = document.getElementById('eoffice_logo_preview');
  const url = officeLogoUrl(office);
  if(preview){
    preview.innerHTML = url
      ? `<img src="${url}" alt="شعار المكتب" onerror="this.src='logo.png'; this.onerror=null;">`
      : '<img src="logo.png" alt="Logo" style="width:100%;height:100%;object-fit:contain" onerror="this.src=\'logo.png\'; this.onerror=null;">';
  }
  clearOfficeLogoPick('eoffice_logo','eoffice_logo_preview','eoffice_logo_clear');
  const removeBtn = document.getElementById('eoffice_logo_remove');
  if(removeBtn) removeBtn.style.display = __editOfficeHasLogo ? 'inline-flex' : 'none';
  const err = document.getElementById('editOfficeModalError');
  if(err){ err.style.display='none'; err.textContent=''; }
  showModal('editOfficeModal');
}

async function saveOffice(){
  const code = document.getElementById('office_code')?.value?.trim();
  const name = document.getElementById('office_name')?.value?.trim();
  const fileInput = document.getElementById('office_logo');
  if(!code || !name){ notify('يرجى إدخال رمز واسم المكتب', 'warning'); return; }
  await withSaveGuard('#saveOfficeBtn', async () => {
    const form = new FormData();
    form.append('office_code', code);
    form.append('office_name', name);
    form.append('is_active', '1');
    if(fileInput?.files?.[0]) form.append('logo', fileInput.files[0]);
    await apiFetch('/offices', {method:'POST', body: form});
    closeModal('newOfficeModal');
    resetNewOfficeForm();
    await refreshBootstrap();
    if(currentPage === 'offices' && typeof renderOffices === 'function') {
      renderOffices(document.getElementById('pageContent'));
    } else if(currentPage === 'settings') {
      renderSettings(document.getElementById('pageContent'));
    }
    notify('تم إنشاء المكتب بنجاح', 'success');
  }).catch(e => {
    const err = document.getElementById('newOfficeModalError');
    if(err){ err.textContent = e.message; err.style.display = 'block'; }
    else notify(e.message, 'error');
  });
}

async function saveOfficeEdit(){
  const id = document.getElementById('eoffice_id')?.value;
  const code = document.getElementById('eoffice_code')?.value?.trim();
  const name = document.getElementById('eoffice_name')?.value?.trim();
  const fileInput = document.getElementById('eoffice_logo');
  if(!id || !code || !name){ notify('يرجى تعبئة جميع الحقول المطلوبة', 'warning'); return; }
  await withSaveGuard('#saveEditOfficeBtn', async () => {
    await apiFetch(`/offices/${id}`, { method:'PATCH', body: JSON.stringify({ office_code: code, office_name: name }) });
    if(fileInput?.files?.[0]){
      const form = new FormData();
      form.append('logo', fileInput.files[0]);
      await apiFetch(`/offices/${id}/logo`, { method:'POST', body: form });
    }
    closeModal('editOfficeModal');
    await refreshBootstrap();
    if(currentPage === 'offices' && typeof renderOffices === 'function') {
      renderOffices(document.getElementById('pageContent'));
    } else if(currentPage === 'settings') {
      renderSettings(document.getElementById('pageContent'));
    }
    if(+currentOffice?.id === +id){
      currentOffice = OFFICES.find(o => +o.id === +id) || currentOffice;
      renderOfficeBranding();
    }
    notify('تم تحديث المكتب', 'success');
  }).catch(e => {
    const err = document.getElementById('editOfficeModalError');
    if(err){ err.textContent = e.message; err.style.display = 'block'; }
    else notify(e.message, 'error');
  });
}

async function removeOfficeLogo(){
  const id = document.getElementById('eoffice_id')?.value;
  if(!id || !__editOfficeHasLogo) return;
  if(!confirm('حذف شعار هذا المكتب؟')) return;
  await withSaveGuard(null, async () => {
    await apiFetch(`/offices/${id}/logo`, { method:'DELETE' });
    __editOfficeHasLogo = false;
    document.getElementById('eoffice_logo_preview').innerHTML = '<span class="office-logo-fallback"><i class="bx bx-building"></i></span>';
    document.getElementById('eoffice_logo_remove').style.display = 'none';
    await refreshBootstrap();
    renderSettings(document.getElementById('pageContent'));
    if(+currentOffice?.id === +id){
      currentOffice = OFFICES.find(o => +o.id === +id) || currentOffice;
      renderOfficeBranding();
    }
    notify('تم حذف الشعار', 'success');
  }).catch(e => notify(e.message, 'error'));
}

async function toggleOfficeActive(officeId, active){
  await apiFetch(`/offices/${officeId}`, {method:'PATCH', body:JSON.stringify({is_active:active})});
  await refreshBootstrap();
  if(currentPage === 'offices' && typeof renderOffices === 'function') {
    renderOffices(document.getElementById('pageContent'));
  } else if(currentPage === 'settings') {
    renderSettings(document.getElementById('pageContent'));
  }
  notify(active ? 'تم تفعيل المكتب' : 'تم تعطيل المكتب', 'success');
}

function populateUserForm(){
  const sel = document.getElementById('usr_office_id');
  const roleSel = document.getElementById('usr_role');
  if(!sel || !roleSel) return;
  const offices = (currentUser?.role === 'super_admin') 
    ? (OFFICES.length ? OFFICES : (currentOffice ? [currentOffice] : []))
    : (currentOffice ? [currentOffice] : []);
  sel.innerHTML = '<option value="">-- اختر المكتب --</option>' + offices.map(o => `<option value="${o.id}">${o.office_name || o.office_code}</option>`).join('');
  sel.disabled = false;
  if(currentUser?.role === 'super_admin' && !roleSel.querySelector('option[value="super_admin"]')){
    roleSel.insertAdjacentHTML('beforeend', '<option value="super_admin">مدير عام</option>');
  }
  roleSel.onchange = () => {
    const row = document.getElementById('usr_office_row');
    if(row) row.style.display = roleSel.value === 'super_admin' ? 'none' : '';
  };
  roleSel.onchange();
}

async function saveUser(){
  const name = document.getElementById('usr_name')?.value?.trim();
  const email = document.getElementById('usr_email')?.value?.trim();
  const password = document.getElementById('usr_password')?.value;
  const role = document.getElementById('usr_role')?.value;
  const officeId = document.getElementById('usr_office_id')?.value;
  if(!name || !email || !password || !role){ notify('يرجى تعبئة جميع الحقول المطلوبة', 'warning'); return; }
  if(role !== 'super_admin' && !officeId){ notify('يرجى اختيار المكتب', 'warning'); return; }
  await withSaveGuard('#saveUserBtn', async () => {
    const body = { name, email, password, role };
    if(role !== 'super_admin') body.office_id = +officeId;
    await apiFetch('/users', { method:'POST', body: JSON.stringify(body) });
    closeModal('newUserModal');
    await refreshBootstrap();
    renderSettings(document.getElementById('pageContent'));
    notify('تم إنشاء المستخدم بنجاح', 'success');
  }).catch(e => showModalError('newUserModal', e.message));
}

function usersSettingsRows(){
  const canManage = currentUser?.role === 'admin' || currentUser?.role === 'super_admin';
  return USERS.map(u => {
    const isSelf = u.id === currentUser?.id;
    const active = u.is_active !== false;
    const actions = canManage ? `
      <button class="btn btn-sm btn-outline" onclick="openEditUser(${u.id})">تعديل</button>
      ${isSelf ? '' : `<button class="btn btn-sm ${active ? 'btn-danger' : 'btn-success'}" onclick="toggleUserActive(${u.id}, ${active ? 0 : 1})">${active ? 'تعطيل' : 'تفعيل'}</button>`}
      <button class="btn btn-sm btn-outline" onclick="resetUserPassword(${u.id})">إعادة كلمة المرور</button>
    ` : '—';
    return `<tr>
      <td style="white-space:nowrap"><div style="display:flex;align-items:center;gap:8px"><div style="width:32px;height:32px;border-radius:50%;background:var(--primary);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px">${u.avatar || '?'}</div><b>${u.name}</b></div></td>
      <td style="font-size:13px;white-space:nowrap">${u.email}</td>
      <td style="white-space:nowrap"><span class="badge badge-info">${u.roleLabel || u.role}</span></td>
      <td style="white-space:nowrap">${u.office?.office_name || u.office?.office_code || '—'}</td>
      <td style="white-space:nowrap"><span class="badge ${active ? 'badge-success' : 'badge-danger'}">${active ? 'مفعل' : 'معطل'}</span></td>
      <td style="white-space:nowrap">${actions}</td>
    </tr>`;
  }).join('');
}

function populateEditUserForm(user){
  const sel = document.getElementById('eusr_office_id');
  const roleSel = document.getElementById('eusr_role');
  if(!sel || !roleSel || !user) return;
  const offices = (currentUser?.role === 'super_admin') 
    ? (OFFICES.length ? OFFICES : (currentOffice ? [currentOffice] : []))
    : (currentOffice ? [currentOffice] : []);
  sel.innerHTML = '<option value="">-- بدون مكتب --</option>' + offices.map(o => `<option value="${o.id}">${o.office_name || o.office_code}</option>`).join('');
  if(currentUser?.role === 'super_admin' && !roleSel.querySelector('option[value="super_admin"]')){
    roleSel.insertAdjacentHTML('beforeend', '<option value="super_admin">مدير عام</option>');
  }
  document.getElementById('eusr_id').value = user.id;
  document.getElementById('eusr_name').value = user.name || '';
  document.getElementById('eusr_email').value = user.email || '';
  roleSel.value = user.role || 'sales';
  sel.value = user.office_id ? String(user.office_id) : '';
  sel.disabled = roleSel.value === 'super_admin';
  roleSel.onchange = () => {
    sel.disabled = roleSel.value === 'super_admin';
    if(roleSel.value === 'super_admin') sel.value = '';
  };
}

function openEditUser(id){
  const user = USERS.find(u => +u.id === +id);
  if(!user){ notify('المستخدم غير موجود', 'error'); return; }
  populateEditUserForm(user);
  showModal('editUserModal');
}

async function saveUserEdit(){
  const id = document.getElementById('eusr_id')?.value;
  const name = document.getElementById('eusr_name')?.value?.trim();
  const email = document.getElementById('eusr_email')?.value?.trim();
  const role = document.getElementById('eusr_role')?.value;
  const officeId = document.getElementById('eusr_office_id')?.value;
  if(!id || !name || !email || !role){ notify('يرجى تعبئة جميع الحقول المطلوبة', 'warning'); return; }
  if(role !== 'super_admin' && !officeId){ notify('يرجى اختيار المكتب', 'warning'); return; }
  await withSaveGuard('#saveEditUserBtn', async () => {
    const body = { name, email, role };
    if(role !== 'super_admin') body.office_id = +officeId;
    else body.office_id = null;
    await apiFetch(`/users/${id}`, { method:'PATCH', body: JSON.stringify(body) });
    closeModal('editUserModal');
    await refreshBootstrap();
    renderSettings(document.getElementById('pageContent'));
    notify('تم تحديث المستخدم', 'success');
  }).catch(e => showModalError('editUserModal', e.message));
}

async function toggleUserActive(id, active){
  const user = USERS.find(u => +u.id === +id);
  if(!user) return;
  if(+id === +currentUser?.id){ notify('لا يمكن تعطيل حسابك الشخصي', 'warning'); return; }
  const label = active ? 'تفعيل' : 'تعطيل';
  if(!confirm(`${label} المستخدم "${user.name}"؟`)) return;
  await withSaveGuard(null, async () => {
    await apiFetch(`/users/${id}`, { method:'PATCH', body: JSON.stringify({ is_active: !!active }) });
    await refreshBootstrap();
    renderSettings(document.getElementById('pageContent'));
    notify(`تم ${label} المستخدم`, 'success');
  }).catch(e => notify(e.message, 'error'));
}

async function resetUserPassword(id){
  const user = USERS.find(u => +u.id === +id);
  if(!user) return;
  const password = prompt(`أدخل كلمة المرور الجديدة للمستخدم "${user.name}":`);
  if(!password) return;
  if(password.length < 8){ notify('كلمة المرور يجب أن تكون 8 أحرف على الأقل', 'warning'); return; }
  await withSaveGuard(null, async () => {
    await apiFetch(`/users/${id}/reset-password`, { method:'PATCH', body: JSON.stringify({ password }) });
    notify('تم إعادة تعيين كلمة المرور', 'success');
  }).catch(e => notify(e.message, 'error'));
}

const __showModal = typeof showModal === 'function' ? showModal : (id) => document.getElementById(id)?.classList.add('open');
showModal = function(id){
  __showModal(id);
  if(id === 'newUserModal') populateUserForm();
  if(id === 'newOfficeModal') resetNewOfficeForm();
  if(id === 'newTransferModal') populateTransferForm();
  if(id === 'newTransferModal') populateTransferForm();
  if(id === 'newTransferModal') populateTransferForm();
  if(id === 'editUserModal'){
    const uid = document.getElementById('eusr_id')?.value;
    if(uid) populateEditUserForm(USERS.find(u => +u.id === +uid));
  }
};

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
  try{
    const d = dateParams('dashFrom', 'dashTo');
    const qs = new URLSearchParams();
    if(d.from) qs.set('from', d.from);
    if(d.to) qs.set('to', d.to);
    DASHBOARD_DATA = await apiFetch('/dashboard' + (qs.toString() ? '?' + qs : ''));
  }
  catch(e){ DASHBOARD_DATA = null; notify('تعذر تحميل بيانات لوحة التحكم', 'error'); }
}

filterDashboard = function(){
  clearTimeout(__listSearchTimer);
  __listSearchTimer = setTimeout(async ()=>{
    await loadDashboardData();
    renderDashboard(document.getElementById('pageContent'));
  }, 300);
};

const LIST_META = { clients: null, vendors: null, operations: null, vouchers: null, journal: null, activity: null };
const LIST_PER_PAGE = 25;
let __listSearchTimer = null;
let JOURNAL_TOTALS = {};
let ACTIVITY_LOGS = [];

function dateParams(fromId, toId){
  return { from: document.getElementById(fromId)?.value || '', to: document.getElementById(toId)?.value || '' };
}
function dateFilterBar(fromId, toId, onChangeFn, fromVal='', toVal='', extra=''){
  return `<div class="filter-bar">${extra}<input type="date" class="form-control filter-control" id="${fromId}" value="${fromVal}" onchange="${onChangeFn}()" title="من تاريخ"><input type="date" class="form-control filter-control" id="${toId}" value="${toVal}" onchange="${onChangeFn}()" title="إلى تاريخ"></div>`;
}
function rptDateParams(){ return dateParams('rptFrom', 'rptTo'); }
function applyDateParams(params, fromId, toId){
  const d = dateParams(fromId, toId);
  if(d.from) params.from = d.from;
  if(d.to) params.to = d.to;
  return params;
}
function searchFilterBar(inputId, placeholder, onInputFn, extra=''){
  return `<input type="text" class="form-control filter-control" id="${inputId}" placeholder="${placeholder}" oninput="${onInputFn}()">${extra}`;
}

async function refreshBootstrap(){
  const data = await apiFetch('/bootstrap');
  replaceArray(USERS, data.users);
  replaceArray(SERVICES, data.services);
  replaceArray(SAFES, data.safes);
  replaceArray(OFFICES, data.offices || []);
  currentOffice = data.current_office || currentOffice;
  BOOTSTRAP_METRICS = data.metrics || {};
  const safesPayload = await apiFetch('/safes?per_page=500').catch(() => ({ data: data.safes }));
  replaceArray(SAFES, safesPayload.data || data.safes);
  renderOfficeSwitcher();
  renderOfficeBranding();
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
  if (h3 && currentPage === 'clients') h3.innerHTML = `<i class='bx bx-user'></i> إدارة العملاء (${res.meta?.total ?? CLIENTS.length})`;
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
  if (h3 && currentPage === 'vendors') h3.innerHTML = `<i class='bx bx-building'></i> الموردون والمكاتب (${res.meta?.total ?? VENDORS.length})`;
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
  applyDateParams(params, 'opFrom', 'opTo');
  applyDateParams(params, 'opFrom', 'opTo');
  applyDateParams(params, 'opFrom', 'opTo');
  const res = await fetchListPage('/operations', p, params);
  replaceArray(OPS, res.data || []);
  LIST_META.operations = res.meta || null;
  tablePages.ops = res.meta?.current_page || 1;
  const h3 = document.querySelector('#pageContent h3');
  if (h3 && currentPage === 'operations') h3.innerHTML = `<i class='bx bx-briefcase'></i> إدارة العمليات (${res.meta?.total ?? OPS.length})`;
  paintOperationsTable();
}

async function reloadVouchersList(page){
  const from = document.getElementById('vcFrom')?.value || '';
  const to = document.getElementById('vcTo')?.value || '';
  const search = document.getElementById('vcSearch')?.value?.trim() || '';
  const p = page || tablePages.vouchers || 1;
  const params = { type: vcTab };
  if (from) params.from = from;
  if (to) params.to = to;
  if (search) params.search = search;
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
    const hideBtn = canDo('write_master') ? `<button class="btn btn-sm btn-outline" onclick="hideClient(${c.id})">إخفاء</button> ` : '';
    const deleteBtn = canDo('write_master') ? `<button class="btn btn-sm btn-danger" onclick="deleteClient(${c.id})">حذف</button> ` : '';
    return `<tr><td>${c.id}</td><td><div class="client-cell">${getInitialsAvatar(c.name)}<div><span>${c.name}</span></div></div></td><td>${c.phone}</td><td>${displayVal(c.civil_id)}</td><td>${displayVal(c.nationality)}</td><td style="color:${b.color};font-weight:700">${b.text}</td><td><span class="badge badge-info">${ops}</span></td><td>${editBtn}${hideBtn}${deleteBtn}<button class="btn btn-sm btn-outline" onclick="viewClientStmt(${c.id})">كشف حساب</button></td></tr>`;
  }).join('')||`<tr>${emptyStateHtml('bx bx-user-x', 'لا يوجد عملاء', 'لم يتم إضافة أي عملاء بعد، قم بإضافة عميل جديد للبدء', 8)}</tr>`}</tbody></table>${serverPagerHtml('clients', meta, 'reloadClientsList')}`;
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
    return `<tr><td>${v.id}</td><td><div class="client-cell">${getInitialsAvatar(v.name)}<div><span>${v.name}</span><small>${categoryLabel[v.category]||v.category}</small></div></div></td><td>${categoryLabel[v.category]||v.category}</td><td>${v.phone||'—'}</td><td>${v.contact||'—'}</td><td style="color:${bal>0?'var(--warning)':'var(--success)'};font-weight:700">${fmt(bal)}</td><td>${editBtn}${deleteBtn}<button class="btn btn-sm btn-outline" onclick="viewVendorStmt(${v.id})">كشف حساب</button></td></tr>`;
  }).join('')||`<tr>${emptyStateHtml('bx bx-store-alt', 'لا يوجد موردون', 'قم بإضافة مورد أو مكتب وكيل جديد لتتمكن من إسناد العمليات إليهم', 7)}</tr>`}</tbody></table>${serverPagerHtml('vendors', meta, 'reloadVendorsList')}`;
}

function paintOperationsTable(){
  const wrap = document.getElementById('opTableWrap');
  if (!wrap) return;
  const meta = LIST_META.operations;
  wrap.innerHTML = `<table class="table">
    <thead><tr><th>المرجع</th><th>التاريخ</th><th>العميل</th><th>الخدمة</th><th>المورد</th><th>سعر العميل</th><th>التكلفة</th><th>الربح</th><th>الحالة</th><th>إجراءات</th></tr></thead>
    <tbody>${OPS.map(o=>{
      const editBtn = canDo('update_op') && o.status !== 'cancelled' && o.status !== 'completed' ? `<button class="btn btn-sm btn-outline" onclick="openEditOperation(${o.id})">تعديل</button> ` : '';
      const hideBtn = (canDo('write_master') || canDo('cancel_op')) ? `<button class="btn btn-sm btn-outline" onclick="hideOperation(${o.id})">إخفاء</button> ` : '';
      return `<tr>
      <td><b style="color:var(--primary);cursor:pointer" onclick="viewOp(${o.id})">${o.ref}</b></td>
      <td>${o.date}</td><td>${o.client||clientName(o.client_id)}</td><td>${o.service||serviceName(o.service_id)}</td><td>${o.vendor||vendorName(o.vendor_id)}</td>
      <td><b>${fmt(o.client_price)}</b></td><td>${fmt(o.vendor_cost)}</td>
      <td style="color:${o.profit>=0?'var(--success)':'var(--danger)'};font-weight:700">${fmt(o.profit)}</td>
      <td><span class="badge ${statusClass[o.status]}">${statusLabel[o.status]}</span></td>
      <td>${editBtn}${hideBtn}<button class="btn btn-sm btn-outline" onclick="viewOp(${o.id})">تفاصيل</button>${o.status!=='cancelled'&&canDo('cancel_op')?` <button class="btn btn-sm btn-danger" onclick="cancelOp(${o.id})">إلغاء</button>`:''}</td>
    </tr>`;
    }).join('')||'<tr><td colspan="10" style="text-align:center;color:var(--text-sm);padding:40px">لا توجد عمليات</td></tr>'}</tbody>
  </table>${serverPagerHtml('operations', meta, 'reloadOperationsList')}`;
}

async function reloadJournalList(page){
  const wrap = document.getElementById('jeTableWrap');
  if (wrap) wrap.innerHTML = '<p style="padding:24px;text-align:center;color:var(--text-sm)">جاري التحميل...</p>';
  const search = document.getElementById('jeSearch')?.value?.trim() || '';
  const account = document.getElementById('jeAccFilter')?.value || 'all';
  const p = page || tablePages.journal || 1;
  const params = {};
  if (search) params.search = search;
  if (account !== 'all') params.account = account;
  applyDateParams(params, 'jeFrom', 'jeTo');
  const res = await fetchListPage('/journal', p, params);
  JOURNAL_CACHE = res.data || [];
  JOURNAL_TOTALS = res.totals || {};
  LIST_META.journal = res.meta || null;
  tablePages.journal = res.meta?.current_page || 1;
  __journalLoaded = true;
  if (res.accounts && document.getElementById('jeAccFilter')) {
    const sel = document.getElementById('jeAccFilter');
    const current = sel.value || 'all';
    sel.innerHTML = '<option value="all">كل الحسابات</option>' + (res.accounts||[]).map(a=>`<option value="${a}">${a}</option>`).join('');
    sel.value = current;
  }
  paintJournalTable();
}

async function reloadActivityLogs(page){
  const wrap = document.getElementById('actTableWrap');
  if (wrap) wrap.innerHTML = '<p style="padding:24px;text-align:center;color:var(--text-sm)">جاري التحميل...</p>';
  const search = document.getElementById('actSearch')?.value?.trim() || '';
  const action = document.getElementById('actActionFilter')?.value || 'all';
  const p = page || tablePages.activity || 1;
  const params = {};
  if (search) params.search = search;
  if (action !== 'all') params.action = action;
  applyDateParams(params, 'actFrom', 'actTo');
  const res = await fetchListPage('/activity-logs', p, params);
  ACTIVITY_LOGS = res.data || [];
  LIST_META.activity = res.meta || null;
  tablePages.activity = res.meta?.current_page || 1;
  paintActivityTable();
}

function paintJournalTable(){
  const wrap = document.getElementById('jeTableWrap');
  if (!wrap) return;
  const meta = LIST_META.journal;
  const je = JOURNAL_CACHE;
  const totalD = JOURNAL_TOTALS.debit ?? je.reduce((s,j)=>s+j.debit,0);
  const totalC = JOURNAL_TOTALS.credit ?? je.reduce((s,j)=>s+j.credit,0);
  const balanced = JOURNAL_TOTALS.filtered ? Math.abs(totalD-totalC) < 0.01 : (JOURNAL_TOTALS.balanced ?? Math.abs(totalD-totalC) < 0.01);
  wrap.innerHTML = `<div class="table-wrapper"><table class="table"><thead><tr><th>#</th><th>التاريخ</th><th>المرجع</th><th>الحساب</th><th>البيان</th><th>مدين</th><th>دائن</th></tr></thead>
  <tbody>${je.map(j=>`<tr><td>${j.id}</td><td>${j.date}</td><td>${j.ref}</td><td><b>${j.account}</b></td><td style="font-size:12px">${j.desc}</td><td style="color:var(--danger);font-weight:700">${j.debit!==0?fmt(j.debit):'—'}</td><td style="color:var(--success);font-weight:700">${j.credit!==0?fmt(j.credit):'—'}</td></tr>`).join('')||`<tr>${emptyStateHtml('bx bx-book-content', 'دفتر الأستاذ فارغ', 'لا توجد أي قيود مالية مسجلة حالياً', 7)}</tr>`}
  <tr style="background:#F1F5F9;font-weight:800"><td colspan="5" style="text-align:center;padding:10px 16px;color:var(--text-sm)">الإجمالي (${meta?.total ?? je.length} قيد)</td><td style="color:var(--danger);padding:10px 16px">${fmt(totalD)}</td><td style="color:var(--success);padding:10px 16px">${fmt(totalC)} ${balanced?'<span style="color:var(--success)"><i class="bx bx-check-circle"></i></span>':'<span style="color:var(--danger)"><i class="bx bx-x-circle"></i></span>'}</td></tr>
  </tbody></table></div>${serverPagerHtml('journal', meta, 'reloadJournalList')}`;
}

function paintActivityTable(){
  const wrap = document.getElementById('actTableWrap');
  if (!wrap) return;
  const meta = LIST_META.activity;
  wrap.innerHTML = `<div class="table-wrapper"><table class="table"><thead><tr><th>#</th><th>التاريخ</th><th>المستخدم</th><th>المكتب</th><th>الإجراء</th><th>مرجع العملية</th><th>التفاصيل</th><th>IP</th></tr></thead>
  <tbody>${ACTIVITY_LOGS.map(l=>`<tr><td>${l.id}</td><td>${(l.created_at||'').slice(0,19).replace('T',' ')}</td><td>${l.user_name||'—'}</td><td>${l.office_name||'—'}</td><td><span class="badge badge-info">${activityActionLabel(l.action, l.action_label)}</span></td><td>${l.operation_ref?`<b style="color:var(--primary)">${l.operation_ref}</b>`:'—'}</td><td style="font-size:12px">${formatActivityDetails(l)}</td><td>${l.ip||'—'}</td></tr>`).join('')||`<tr>${emptyStateHtml('bx bx-history', 'لا يوجد نشاط', 'سجل النشاطات للمستخدمين فارغ حتى الآن', 8)}</tr>`}</tbody></table></div>${serverPagerHtml('activity', meta, 'reloadActivityLogs')}`;
}

async function loadPageList(page){
  if (page === 'clients') await reloadClientsList(1);
  else if (page === 'vendors') await reloadVendorsList(1);
  else if (page === 'operations') await reloadOperationsList(1);
  else if (page === 'vouchers') await reloadVouchersList(1);
  else if (page === 'journal') await reloadJournalList(1);
  else if (page === 'activity') await reloadActivityLogs(1);
  else if (page === 'settings') await loadHiddenSettings();
  else if (page === 'safes') await reloadSafesPage();
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
    REPORT_CACHE = {};
    JOURNAL_CACHE = [];
    __journalLoaded = false;
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

window.showForgotPasswordModal = function() {
  document.getElementById('forgotError').style.display = 'none';
  document.getElementById('forgotSuccess').style.display = 'none';
  document.getElementById('forgotEmail').value = '';
  showModal('forgotPasswordModal');
};

window.doForgotPassword = async function() {
  const email = document.getElementById('forgotEmail').value.trim();
  const err = document.getElementById('forgotError');
  const suc = document.getElementById('forgotSuccess');
  const btn = document.getElementById('btnForgotPass');
  err.style.display = 'none';
  suc.style.display = 'none';
  
  if (!email || !/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) {
    err.textContent = 'يرجى إدخال بريد إلكتروني صحيح.';
    err.style.display = 'block';
    return;
  }
  
  try {
    btn.disabled = true;
    btn.style.opacity = '0.7';
    await refreshCsrfCookie();
    const res = await apiFetch('/forgot-password', {
      method: 'POST',
      body: JSON.stringify({ email })
    });
    suc.textContent = res.message || 'تم إرسال رابط إعادة تعيين كلمة المرور إلى بريدك الإلكتروني.';
    suc.style.display = 'block';
  } catch (e) {
    err.textContent = e.message || 'البريد الإلكتروني غير مسجل في النظام.';
    err.style.display = 'block';
  } finally {
    btn.disabled = false;
    btn.style.opacity = '1';
  }
};

window.checkResetPasswordStrength = function() {
  const val = document.getElementById('resetPass').value;
  const ind = document.getElementById('resetPassStrength');
  if(!val){ ind.textContent=''; return; }
  let score = 0;
  if(val.length >= 8) score++;
  if(/[A-Z]/.test(val)) score++;
  if(/[0-9]/.test(val)) score++;
  if(/[^A-Za-z0-9]/.test(val)) score++;
  
  if(score < 2) { ind.textContent='ضعيفة'; ind.style.color='var(--danger)'; }
  else if(score === 2) { ind.textContent='متوسطة'; ind.style.color='var(--warning)'; }
  else { ind.textContent='قوية'; ind.style.color='var(--success)'; }
};

window.doResetPassword = async function() {
  const urlParams = new URLSearchParams(window.location.search);
  const token = urlParams.get('reset');
  const email = urlParams.get('email');
  
  const password = document.getElementById('resetPass').value;
  const password_confirmation = document.getElementById('resetPassConfirm').value;
  const err = document.getElementById('resetError');
  const suc = document.getElementById('resetSuccess');
  const btn = document.getElementById('btnResetPass');
  
  err.style.display = 'none';
  suc.style.display = 'none';
  
  if (!password || password.length < 8) {
    err.textContent = 'يجب أن تتكون كلمة المرور من 8 أحرف على الأقل.';
    err.style.display = 'block';
    return;
  }
  if (password !== password_confirmation) {
    err.textContent = 'كلمتا المرور غير متطابقتين.';
    err.style.display = 'block';
    return;
  }
  
  try {
    btn.disabled = true;
    btn.style.opacity = '0.7';
    await refreshCsrfCookie();
    const res = await apiFetch('/reset-password', {
      method: 'POST',
      body: JSON.stringify({ token, email, password, password_confirmation })
    });
    
    document.getElementById('resetFormArea').style.display = 'none';
    suc.textContent = res.message || 'تم تغيير كلمة المرور بنجاح.';
    suc.style.display = 'block';
    
    setTimeout(() => {
      window.location.href = window.location.pathname;
    }, 3000);
  } catch (e) {
    err.textContent = e.message || 'رابط إعادة تعيين كلمة المرور غير صالح أو منتهي الصلاحية.';
    err.style.display = 'block';
    btn.disabled = false;
    btn.style.opacity = '1';
  }
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
    REPORT_CACHE = {};
  }
  __originalNavigate(page);
  if (__apiBootstrapped && ['clients','vendors','operations','vouchers','journal','activity'].includes(page)) {
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
filterJournal = function(){
  clearTimeout(__listSearchTimer);
  __listSearchTimer = setTimeout(() => reloadJournalList(1), 300);
};
filterActivityLogs = function(){
  clearTimeout(__listSearchTimer);
  __listSearchTimer = setTimeout(() => reloadActivityLogs(1), 300);
};
filterJournal = function(){
  clearTimeout(__listSearchTimer);
  __listSearchTimer = setTimeout(() => reloadJournalList(1), 300);
};
filterActivityLogs = function(){
  clearTimeout(__listSearchTimer);
  __listSearchTimer = setTimeout(() => reloadActivityLogs(1), 300);
};
filterJournal = function(){
  clearTimeout(__listSearchTimer);
  __listSearchTimer = setTimeout(() => reloadJournalList(1), 300);
};
filterActivityLogs = function(){
  clearTimeout(__listSearchTimer);
  __listSearchTimer = setTimeout(() => reloadActivityLogs(1), 300);
};
filterVouchers = function(){
  clearTimeout(__listSearchTimer);
  __listSearchTimer = setTimeout(() => reloadVouchersList(1), 300);
};
switchVcTab = function(tab){ vcTab = tab; tablePages.vouchers = 1; navigate('vouchers'); };

const __originalRenderOperations = renderOperations;
const __originalRenderClients = renderClients;
const __originalRenderVendors = renderVendors;

renderClients = function(pc){
  pc.innerHTML=`
  <div class="page-shell">
    <div class="card">
      <div class="card-header">
        <h3><i class='bx bx-user'></i> إدارة العملاء</h3>
        <div class="card-header-actions">
          ${searchFilterBar('clSearch', 'بحث (اسم، هاتف، مدني...)', 'filterClients')}
          ${canDo('write_master')?`<button class="btn btn-primary" onclick="showModal('newClientModal')"><i class='bx bx-plus'></i> عميل جديد</button>`:''}
          ${exportActionBar('clients')}
        </div>
      </div>
      <div class="card-body" style="padding:0"><div id="clTableWrap"></div></div>
    </div>
  </div>`;
  reloadClientsList(1);
};

renderVendors = function(pc){
  pc.innerHTML=`
  <div class="page-shell">
    <div class="card">
      <div class="card-header">
        <h3><i class='bx bx-building'></i> الموردون والمكاتب</h3>
        <div class="card-header-actions">
          ${searchFilterBar('vnSearch', 'بحث (اسم، هاتف...)', 'filterVendors')}
          ${canDo('write_master')?`<button class="btn btn-primary" onclick="showModal('newVendorModal')"><i class='bx bx-plus'></i> مورد جديد</button>`:''}
          ${exportActionBar('vendors')}
        </div>
      </div>
      <div class="card-body" style="padding:0"><div id="vnTableWrap"></div></div>
    </div>
  </div>`;
  reloadVendorsList(1);
};

renderOperations = function(pc){
  pc.innerHTML=`
  <div class="page-shell">
    <div class="card">
      <div class="card-header">
        <h3><i class='bx bx-briefcase'></i> إدارة العمليات</h3>
        <div class="card-header-actions">
          ${searchFilterBar('opSearchInput', 'بحث (رقم، عميل، هاتف، مورد، خدمة، ملاحظات...)', 'filterOps')}
          <select class="form-control filter-control" id="opStatusFilter" onchange="filterOps()">
            <option value="all">كل الحالات</option>
            <option value="new">جديدة</option>
            <option value="processing">قيد التنفيذ</option>
            <option value="completed">مكتملة</option>
            <option value="cancelled">ملغاة</option>
          </select>
          <select class="form-control filter-control" id="opSvcFilter" onchange="filterOps()">
            <option value="all">كل الخدمات</option>
            ${SERVICES.map(s=>`<option value="${s.id}">${s.name}</option>`).join('')}
          </select>
          <input type="date" class="form-control filter-control" id="opFrom" onchange="filterOps()" title="من تاريخ">
          <input type="date" class="form-control filter-control" id="opTo" onchange="filterOps()" title="إلى تاريخ">
          ${canDo('create_op')?`<button class="btn btn-primary" onclick="showModal('newOpModal');populateOpForm()"><i class='bx bx-plus'></i> عملية جديدة</button>`:''}
          ${exportActionBar('operations')}
        </div>
      </div>
      <div class="card-body" style="padding:0"><div id="opTableWrap"></div></div>
    </div>
  </div>`;
  reloadOperationsList(1);
};

const __originalRenderVouchers = renderVouchers;
renderVouchers = function(pc){
  pc.innerHTML=`
  <div class="page-shell">
    <div class="card">
      <div class="card-header">
        <h3><i class='bx bx-receipt'></i> السندات المالية</h3>
        <div class="card-header-actions">
          ${canDo('create_voucher')?`<button class="btn btn-success" onclick="openNewVoucher('receipt')"><i class='bx bx-plus'></i> سند قبض</button><button class="btn btn-danger" onclick="openNewVoucher('payment')"><i class='bx bx-plus'></i> سند صرف</button>`:''}
          ${exportActionBar('vouchers')}
        </div>
      </div>
      <div class="card-body">
        <div style="display:flex;gap:0;margin-bottom:20px;border-bottom:2px solid var(--border)">
          <button class="tab-btn ${vcTab==='receipt'?'active':''}" onclick="switchVcTab('receipt')">سندات القبض</button>
          <button class="tab-btn ${vcTab==='payment'?'active':''}" onclick="switchVcTab('payment')">سندات الصرف</button>
        </div>
        <div class="filter-bar" style="margin-bottom:16px">
          ${searchFilterBar('vcSearch', 'بحث (رقم، بيان، طرف...)', 'filterVouchers')}
          <input type="date" class="form-control filter-control" id="vcFrom" onchange="filterVouchers()" title="من تاريخ">
          <input type="date" class="form-control filter-control" id="vcTo" onchange="filterVouchers()" title="إلى تاريخ">
        </div>
        <div id="vcTableWrap"></div>
      </div>
    </div>
  </div>`;
  reloadVouchersList(1);
};

const __originalRenderJournal = renderJournal;
renderJournal = function(pc){
  const minDate = new Date(Date.now() - 30*86400000).toISOString().slice(0,10);
  pc.innerHTML=`
  <div class="page-shell">
    <div class="grid-kpi-3" id="jeKpiRow"></div>
    <div class="card">
      <div class="card-header">
        <h3><i class='bx bx-book'></i> دفتر الأستاذ</h3>
        <div class="card-header-actions">
          ${searchFilterBar('jeSearch', 'بحث (مرجع، بيان، حساب)...', 'filterJournal')}
          <select class="form-control filter-control" id="jeAccFilter" onchange="filterJournal()"><option value="all">كل الحسابات</option></select>
          <input type="date" class="form-control filter-control" id="jeFrom" value="${minDate}" onchange="filterJournal()" title="من تاريخ">
          <input type="date" class="form-control filter-control" id="jeTo" value="${today()}" onchange="filterJournal()" title="إلى تاريخ">
          ${exportActionBar('journal', { print: true })}
        </div>
      </div>
      <div class="card-body" style="padding:0"><div id="jeTableWrap"></div></div>
    </div>
  </div>`;
  reloadJournalList(1);
};

renderActivityLogs = async function(pc){
  pc.innerHTML=`
  <div class="page-shell">
    <div class="card">
      <div class="card-header"><h3><i class='bx bx-history'></i> سجل النشاط</h3><div class="card-header-actions">${exportActionBar('activity_logs')}</div></div>
      <div class="card-body">
        <div class="filter-bar" style="margin-bottom:16px">
          ${searchFilterBar('actSearch', 'بحث (إجراء، مستخدم، تفاصيل)...', 'filterActivityLogs')}
          <select class="form-control filter-control" id="actActionFilter" onchange="filterActivityLogs()"><option value="all">كل الإجراءات</option></select>
          <input type="date" class="form-control filter-control" id="actFrom" onchange="filterActivityLogs()" title="من تاريخ">
          <input type="date" class="form-control filter-control" id="actTo" onchange="filterActivityLogs()" title="إلى تاريخ">
        </div>
        <div id="actTableWrap"></div>
      </div>
    </div>
  </div>`;
  try{
    const actions = await apiFetch('/activity-logs/actions');
    const sel = document.getElementById('actActionFilter');
    if(sel) sel.innerHTML = '<option value="all">كل الإجراءات</option><option value="operations">عمليات (الكل)</option>' + (actions.data||[]).map(a=>{
      const key = typeof a === 'string' ? a : a.key;
      const label = typeof a === 'string' ? activityActionLabel(a) : (a.label || activityActionLabel(a.key));
      return `<option value="${key}">${label}</option>`;
    }).join('');
  }catch(e){}
  await reloadActivityLogs(1);
};



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
    return `<tr style="${reversed?'opacity:.65':''}"><td><b style="color:var(--primary)">${v.ref}</b></td><td>${v.date}</td><td>${party}</td><td style="font-weight:700;color:${vcTab==='receipt'?'var(--success)':'var(--danger)'}">${fmt(v.amount)}</td><td>${reversed?'<span class="badge badge-danger">ملغى</span>':'<span class="badge badge-success">فعّال</span>'}</td><td>${methodLabel(v.method)}</td><td>${safeName(v.safe_id)}</td><td>${opRef}</td><td style="font-size:12px">${displayVal(v.desc)}</td><td>${voidBtn}<button class="btn btn-xs btn-outline" onclick="printVoucherExport(${v.id})"><i class='bx bx-printer'></i> طباعة</button></td></tr>`;
  }).join('')||`<tr>${emptyStateHtml('bx bx-receipt', 'لا توجد سندات مالية', 'لم يتم إنشاء أي سندات قبض أو صرف في النظام', 10)}</tr>`}</tbody></table>${serverPagerHtml('vouchers', meta, 'reloadVouchersList')}`;
};

function setOpFormLoading(loading){
  opFormState.loading = loading;
  const saveBtn = document.querySelector('#newOpModal .btn-primary');
  if (saveBtn) {
    saveBtn.disabled = loading;
    saveBtn.style.opacity = loading ? '0.65' : '';
  }
  const cs = document.getElementById('op_client');
  const vn = document.getElementById('op_vendor');
  const placeholder = loading ? 'جاري التحميل...' : '-- اختر --';
  if (loading) {
    if (cs) cs.innerHTML = `<option value="">${placeholder}</option>`;
    if (vn) vn.innerHTML = `<option value="">${placeholder}</option>`;
  }
}

function resetOpFormFields(){
  ['op_client_price','op_vendor_cost','op_profit','op_initial_payment','op_notes', 'op_client_phone_search'].forEach(id=>{
    const el = document.getElementById(id);
    if (el) el.value = '';
  });
  const jp = document.getElementById('op_journal_preview');
  if (jp) jp.style.display = 'none';
}

function opOptionIds(selectId){
  const sel = document.getElementById(selectId);
  if (!sel) return [];
  return Array.from(sel.options).map(o => +o.value).filter(id => id > 0);
}

populateOpForm = async function(){
  opFormState = { ready: false, clients: [], vendors: [], loading: true };
  setOpFormLoading(true);
  resetOpFormFields();
  const sv = document.getElementById('op_service');
  if (sv) sv.innerHTML = '<option value="">-- اختر خدمة --</option>' + SERVICES.filter(s=>s.active).map(s=>`<option value="${s.id}">${s.icon} ${s.name}</option>`).join('');
  try {
    const [clientsRes, vendorsRes] = await Promise.all([
      apiFetch('/clients?per_page=500'),
      apiFetch('/vendors?per_page=500'),
    ]);
    opFormState.clients = clientsRes.data || [];
    opFormState.vendors = vendorsRes.data || [];
    const cs = document.getElementById('op_client');
    const vn = document.getElementById('op_vendor');
    const dl = document.getElementById('op_client_phones_list');
    if (cs) cs.innerHTML = '<option value="">-- اختر عميل --</option>' + opFormState.clients.map(c=>`<option value="${c.id}" data-phone="${escapeHtml(c.phone||'')}">${c.name} ${c.phone ? ' - '+escapeHtml(c.phone) : ''}</option>`).join('');
    if (dl) dl.innerHTML = opFormState.clients.filter(c=>c.phone).map(c=>`<option value="${escapeHtml(c.phone)}">${c.name}</option>`).join('');
    if (vn) vn.innerHTML = '<option value="">-- اختر مورد --</option>' + opFormState.vendors.map(v=>`<option value="${v.id}">${v.name}</option>`).join('');
    opFormState.ready = true;
    if (!opFormState.clients.length) showModalError('newOpModal', 'لا يوجد عملاء في المكتب الحالي. أضف عميلاً أولاً.');
    else if (!opFormState.vendors.length) showModalError('newOpModal', 'لا يوجد موردون في المكتب الحالي. أضف مورداً أولاً.');
    else {
      const err = document.getElementById('newOpModalError');
      if (err) err.style.display = 'none';
    }
  } catch (e) {
    opFormState = { ready: false, clients: [], vendors: [], loading: false };
    const cs = document.getElementById('op_client');
    const vn = document.getElementById('op_vendor');
    if (cs) cs.innerHTML = '<option value="">-- اختر عميل --</option>';
    if (vn) vn.innerHTML = '<option value="">-- اختر مورد --</option>';
    notify(e.message, 'error');
  } finally {
    setOpFormLoading(false);
  }
};

window.selectClientByPhone = function(phone) {
  if (!phone) return;
  const client = opFormState.clients.find(c => c.phone === phone);
  if (client) {
    document.getElementById('op_client').value = client.id;
  }
};

openNewVoucher = async function(type){
  vcTab = type;
  document.getElementById('voucherModalTitle').innerHTML = type==='receipt'?"<i class='bx bx-receipt'></i> سند قبض جديد":"<i class='bx bx-money'></i> سند صرف جديد";
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
  if (opFormState.loading) { showModalError('newOpModal', 'جاري تحميل العملاء والموردين، يرجى الانتظار'); return; }
  if (!opFormState.ready) { showModalError('newOpModal', 'تعذر تحميل قائمة العملاء والموردين. أغلق النافذة وحاول مرة أخرى.'); return; }
  const cid=+document.getElementById('op_client').value;
  const sid=+document.getElementById('op_service').value;
  const vid=+document.getElementById('op_vendor').value;
  const cp=+document.getElementById('op_client_price').value;
  const vc=+document.getElementById('op_vendor_cost').value;
  const ip=+document.getElementById('op_initial_payment').value||0;
  if(!cid||!sid||!vid){ showModalError('newOpModal','يرجى اختيار العميل والخدمة والمورد'); return; }
  if(!opFormState.clients.some(c=>c.id===cid)){ showModalError('newOpModal','العميل المحدد غير متاح في المكتب الحالي. أعد فتح النموذج.'); return; }
  if(!opFormState.vendors.some(v=>v.id===vid)){ showModalError('newOpModal','المورد المحدد غير متاح في المكتب الحالي. أعد فتح النموذج.'); return; }
  if(!opOptionIds('op_client').includes(cid) || !opOptionIds('op_vendor').includes(vid)){
    showModalError('newOpModal','بيانات النموذج غير محدّثة. أغلق النافذة وافتحها مرة أخرى.');
    return;
  }
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
      <div class="drawer-actions" style="margin-bottom:12px;justify-content:flex-end;flex-wrap:wrap;gap:8px">
        <button type="button" class="btn btn-sm btn-outline" onclick="exportOperationInvoicePDF(${op.id})"><i class='bx bx-receipt'></i> فاتورة PDF</button>
        <button type="button" class="btn btn-sm btn-outline" onclick="printOperationInvoicePDF(${op.id})"><i class='bx bx-printer'></i> طباعة فاتورة</button>
        <button type="button" class="btn btn-sm btn-success" onclick="sendWhatsAppInvoice(${op.id})" title="إرسال عبر واتساب"><i class='bx bxl-whatsapp'></i> واتساب</button>
        ${exportActionBar('operation_detail', { excel: false, pdf: true, print: true, ctx: { id: op.id } })}
      </div>
      <div style="margin-bottom:16px">
        <div class="grid-2" style="margin-bottom:16px">
          <div class="info-item"><span class="info-label">رقم العملية</span><span class="info-value">${op.ref}</span></div>
          <div class="info-item"><span class="info-label">التاريخ</span><span class="info-value">${op.date}</span></div>
          <div class="info-item"><span class="info-label">العميل</span><span class="info-value">${op.client||clientName(op.client_id)}</span></div>
          <div class="info-item"><span class="info-label">الخدمة</span><span class="info-value">${op.service||serviceName(op.service_id)}</span></div>
          <div class="info-item"><span class="info-label">المورد</span><span class="info-value">${op.vendor||vendorName(op.vendor_id)}</span></div>
          <div class="info-item"><span class="info-label">الحالة</span><div style="display:flex;align-items:center;gap:8px"><span class="badge ${statusClass[op.status]}">${statusLabel[op.status]}</span>${operationStatusControls(op)}</div></div>
          <div class="info-item"><span class="info-label">سعر العميل</span><span class="info-value" style="color:var(--primary);font-weight:700">${fmt(op.client_price)}</span></div>
          <div class="info-item"><span class="info-label">تكلفة المورد</span><span class="info-value">${fmt(op.vendor_cost)}</span></div>
          <div class="info-item"><span class="info-label">رصيد العميل للعملية</span><span class="info-value" style="color:${op.client_outstanding>0?'var(--danger)':'var(--success)'}">${fmt(op.client_outstanding)}</span></div>
          <div class="info-item"><span class="info-label">رصيد المورد للعملية</span><span class="info-value" style="color:${op.vendor_outstanding>0?'var(--warning)':'var(--success)'}">${fmt(op.vendor_outstanding)}</span></div>
          <div class="info-item"><span class="info-label">الربح المتوقع</span><span class="info-value" style="color:${op.profit>=0?'var(--success)':'var(--danger)'};font-weight:700">${fmt(op.profit)}</span></div>
          <div class="info-item"><span class="info-label">العملة</span><span class="info-value">${op.currency_label||currencyLabel(op.currency)}</span></div>
        </div>
        ${op.notes?`<div class="info-item-block"><span class="info-label">ملاحظات</span><span class="info-value">${op.notes}</span></div>`:''}
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
    const stmtParams = new URLSearchParams();
    const from = document.getElementById('stmtFrom')?.value || '';
    const to = document.getElementById('stmtTo')?.value || '';
    if(from) stmtParams.set('from', from);
    if(to) stmtParams.set('to', to);
    const stmt = await apiFetch(`/clients/${cid}/statement${stmtParams.toString() ? '?' + stmtParams : ''}`);
    if (gen !== AppShell._viewGeneration && !opts.refresh) return;
    const rows = stmt.rows || [];
    document.getElementById('stmtTitle').textContent=`كشف حساب - ${stmt.client?.name || cl?.name || ''}`;
    document.getElementById('stmtBody').innerHTML=`
      <div class="grid-3" style="margin-bottom:20px">
        <div class="card" style="background:var(--bg)"><div class="card-body" style="text-align:center"><div style="font-size:11px;color:var(--text-sm)">إجمالي المشتريات</div><div style="font-size:20px;font-weight:800;color:var(--primary)">${fmt(stmt.total_purchases)}</div></div></div>
        <div class="card" style="background:var(--bg)"><div class="card-body" style="text-align:center"><div style="font-size:11px;color:var(--text-sm)">المدفوع</div><div style="font-size:20px;font-weight:800;color:var(--success)">${fmt(stmt.paid)}</div></div></div>
        <div class="card" style="background:var(--bg)"><div class="card-body" style="text-align:center"><div style="font-size:11px;color:var(--text-sm)">الرصيد المتبقي</div><div style="font-size:20px;font-weight:800;color:${stmt.balance>0?'var(--danger)':'var(--success)'}">${fmt(stmt.balance)}</div></div></div>
      </div>
      <div class="drawer-actions" style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <h4 style="margin:0">حركات الحساب</h4>
        <input type="date" class="form-control filter-control" id="stmtFrom" onchange="viewClientStmt(${cid})" title="من تاريخ">
        <input type="date" class="form-control filter-control" id="stmtTo" onchange="viewClientStmt(${cid})" title="إلى تاريخ">
        <button class="btn btn-sm btn-outline" onclick="exportClientStmtPDF(${cid})"><i class='bx bx-file'></i> PDF</button>
        <button class="btn btn-sm btn-outline" onclick="runBackendExport('client_statement','pdf',true,{id:${cid}})"><i class='bx bx-printer'></i> طباعة</button>
        <button class="btn btn-sm btn-outline" onclick="exportClientStmt(${cid})"><i class='bx bx-download'></i> Excel</button></div>
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
    const stmtParams = new URLSearchParams();
    const from = document.getElementById('vstmtFrom')?.value || '';
    const to = document.getElementById('vstmtTo')?.value || '';
    if(from) stmtParams.set('from', from);
    if(to) stmtParams.set('to', to);
    const stmt = await apiFetch(`/vendors/${vid}/statement${stmtParams.toString() ? '?' + stmtParams : ''}`);
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
      <div class="drawer-actions" style="margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
        <h4 style="margin:0">حركات الحساب</h4>
        <input type="date" class="form-control filter-control" id="vstmtFrom" onchange="viewVendorStmt(${vid})" title="من تاريخ">
        <input type="date" class="form-control filter-control" id="vstmtTo" onchange="viewVendorStmt(${vid})" title="إلى تاريخ">
        <button class="btn btn-sm btn-outline" onclick="exportVendorStmtPDF(${vid})"><i class='bx bx-file'></i> PDF</button>
        <button class="btn btn-sm btn-outline" onclick="runBackendExport('vendor_statement','pdf',true,{id:${vid}})"><i class='bx bx-printer'></i> طباعة</button>
        <button class="btn btn-sm btn-outline" onclick="exportVendorStmt(${vid})"><i class='bx bx-download'></i> Excel</button>
      </div>
      <div class="table-wrapper"><table class="table"><thead><tr><th>التاريخ</th><th>المرجع</th><th>البيان</th><th>مدين</th><th>دائن</th></tr></thead>
      <tbody>${rows.map(j=>`<tr><td>${j.date}</td><td>${j.ref}</td><td style="font-size:12px">${j.desc||''}</td><td>${signedAmount(j.debit,'var(--success)','var(--danger)')}</td><td>${signedAmount(j.credit,'var(--danger)','var(--success)')}</td></tr>`).join('')||'<tr><td colspan="5" style="text-align:center;color:var(--text-sm)">لا توجد حركات</td></tr>'}</tbody></table></div>`;
  }catch(e){ document.getElementById('stmtBody').innerHTML=`<p style="padding:20px;text-align:center;color:var(--danger)">${e.message}</p>`; }
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

function rptOpsDateParams(){ return rptDateParams(); }

filterRptOps = function(){
  REPORT_CACHE = {};
  renderRptContent();
};

function rptDateBarHtml(onChangeFn='filterRptOps'){
  const d = rptDateParams();
  return dateFilterBar('rptFrom', 'rptTo', onChangeFn, d.from, d.to);
}

renderRptContent = async function(){
  const wrap=document.getElementById('rptContent');
  if(!wrap)return;
  if(rptTab === 'ops'){
    const dateParams = rptDateParams();
    wrap.innerHTML=`${rptDateBarHtml()}<p style="padding:20px;text-align:center;color:var(--text-sm)">جاري تحميل تقرير العمليات...</p>`;
    try{
      const data = await loadReport('operations', { ...dateParams, page: tablePages.rptOps || 1, per_page: LIST_PER_PAGE });
      const rows = data.rows || [];
      const totals = data.totals || {};
      const meta = data.meta;
      wrap.innerHTML=`
        ${rptDateBarHtml()}
        <div style="margin-bottom:12px;display:flex;justify-content:flex-end">${exportActionBar('report', { ctx: { type: 'operations' } })}</div>
        <div class="grid-kpi-3">
          ${kpiCard('<i class="bx bxs-dollar-circle" style="font-size:24px"></i>','إجمالي الإيرادات',fmt(totals.revenue||0),'var(--primary)','')}
          ${kpiCard('<i class="bx bxs-credit-card" style="font-size:24px"></i>','إجمالي التكاليف',fmt(totals.cost||0),'var(--danger)','')}
          ${kpiCard('<i class="bx bxs-wallet" style="font-size:24px"></i>','صافي الربح',fmt(totals.profit||0),'var(--success)','')}
        </div>
        <div class="table-wrapper"><table class="table"><thead><tr><th>المرجع</th><th>التاريخ</th><th>العميل</th><th>الخدمة</th><th>الإيراد</th><th>التكلفة</th><th>الربح</th><th>الحالة</th></tr></thead>
        <tbody>${rows.map(o=>`<tr><td><b>${o.ref}</b></td><td>${o.date}</td><td>${o.client||clientName(o.client_id)}</td><td>${o.service||serviceName(o.service_id)}</td><td>${fmt(o.client_price)}</td><td>${fmt(o.vendor_cost)}</td><td style="color:${o.profit>=0?'var(--success)':'var(--danger)'};font-weight:700">${fmt(o.profit)}</td><td><span class="badge ${statusClass[o.status]}">${statusLabel[o.status]}</span></td></tr>`).join('')||'<tr><td colspan="8" style="text-align:center;padding:40px;color:var(--text-sm)">لا توجد عمليات في هذه الفترة</td></tr>'}</tbody></table></div>
        ${meta ? serverPagerHtml('rptOps', meta, 'reloadRptOpsPage') : ''}`;
    }catch(e){ wrap.innerHTML=`${rptDateBarHtml()}<p style="padding:20px;text-align:center;color:var(--danger)">${e.message}</p>`; }
    return;
  }
  if(rptTab === 'profit'){
    const dateParams = rptDateParams();
    wrap.innerHTML=`${rptDateBarHtml()}<p style="padding:20px;text-align:center;color:var(--text-sm)">جاري تحميل تقرير الربحية...</p>`;
    try{
      const data = await loadReport('profit', dateParams);
      const rows = data.rows || [];
      wrap.innerHTML=`${rptDateBarHtml()}<div style="margin-bottom:12px;display:flex;justify-content:flex-end">${exportActionBar('report', { ctx: { type: 'profit' } })}</div><div class="table-wrapper"><table class="table"><thead><tr><th>الخدمة</th><th>عدد العمليات</th><th>الإيرادات</th><th>التكاليف</th><th>الربح</th><th>هامش الربح</th></tr></thead>
      <tbody>${rows.map(s=>`<tr><td>${s.icon||''} <b>${s.name}</b></td><td><span class="badge badge-info">${s.count}</span></td><td>${fmt(s.revenue)}</td><td>${fmt(s.cost)}</td><td style="color:var(--success);font-weight:700">${fmt(s.profit)}</td><td>${s.revenue>0?(s.profit/s.revenue*100).toFixed(1)+'%':'—'}</td></tr>`).join('')}</tbody></table></div>`;
    }catch(e){ wrap.innerHTML=`${rptDateBarHtml()}<p style="padding:20px;text-align:center;color:var(--danger)">${e.message}</p>`; }
    return;
  }
  if(rptTab === 'aging'){
    const dateParams = rptDateParams();
    wrap.innerHTML=`${rptDateBarHtml()}<p style="padding:20px;text-align:center;color:var(--text-sm)">جاري تحميل تقرير التقادم...</p>`;
    try{
      const data = await loadReport('aging', dateParams);
      const aged = data.rows || [];
      wrap.innerHTML=`
        ${rptDateBarHtml()}
        <div style="margin-bottom:12px;display:flex;justify-content:flex-end">${exportActionBar('report', { ctx: { type: 'aging' } })}</div>
        <div class="table-wrapper"><table class="table"><thead><tr><th>العميل</th><th>الإجمالي</th><th>1-30 يوم</th><th>31-60 يوم</th><th>61-90 يوم</th><th>+90 يوم</th></tr></thead>
        <tbody>${aged.map(a=>`<tr><td><b>${a.name}</b><br><small style="color:var(--text-sm)">${a.days} يوم</small></td><td style="font-weight:700;color:var(--danger)">${fmt(a.balance)}</td><td>${a.b1>0?fmt(a.b1):'—'}</td><td style="color:${a.b2>0?'var(--warning)':'inherit'}">${a.b2>0?fmt(a.b2):'—'}</td><td style="color:${a.b3>0?'var(--danger)':'inherit'}">${a.b3>0?fmt(a.b3):'—'}</td><td style="color:${a.b4>0?'var(--danger)':'inherit'};font-weight:${a.b4>0?'700':'400'}">${a.b4>0?fmt(a.b4):'—'}</td></tr>`).join('')||'<tr><td colspan="6" style="text-align:center;padding:40px;color:var(--success)">لا توجد ديون متأخرة</td></tr>'}</tbody></table></div>`;
    }catch(e){ wrap.innerHTML=`${rptDateBarHtml()}<p style="padding:20px;text-align:center;color:var(--danger)">${e.message}</p>`; }
    return;
  }
  if(rptTab === 'cashflow'){
    const dateParams = rptDateParams();
    wrap.innerHTML=`${rptDateBarHtml()}<p style="padding:20px;text-align:center;color:var(--text-sm)">جاري تحميل التدفق النقدي...</p>`;
    try{
      const data = await loadReport('cashflow', dateParams);
      const safes = data.safes || [];
      const rows = data.rows || [];
      const safeHeaders = safes.map(s=>`<th>رصيد ${s.name}</th>`).join('');
      wrap.innerHTML=`
        ${rptDateBarHtml()}
        <div style="margin-bottom:12px;display:flex;justify-content:flex-end">${exportActionBar('report', { ctx: { type: 'cashflow' } })}</div>
        <div class="table-wrapper"><table class="table"><thead><tr><th>التاريخ</th><th>وارد</th><th>صادر</th><th>صافي</th>${safeHeaders}</tr></thead>
        <tbody>${rows.map(r=>`<tr><td>${r.date}</td><td style="color:var(--success)">${r.inflow!==0?fmt(r.inflow):'—'}</td><td style="color:var(--danger)">${r.outflow!==0?fmt(r.outflow):'—'}</td><td style="font-weight:700;color:${r.net>=0?'var(--success)':'var(--danger)'}">${fmt(r.net)}</td>${safes.map(s=>`<td>${fmt(r.safes?.[s.id]||0)}</td>`).join('')}</tr>`).join('')||'<tr><td colspan="'+(4+safes.length)+'" style="text-align:center;color:var(--text-sm)">لا توجد حركات نقدية</td></tr>'}</tbody></table></div>`;
    }catch(e){ wrap.innerHTML=`${rptDateBarHtml()}<p style="padding:20px;text-align:center;color:var(--danger)">${e.message}</p>`; }
    return;
  }
  if(rptTab === 'employee'){
    const dateParams = rptDateParams();
    wrap.innerHTML=`${rptDateBarHtml()}<p style="padding:20px;text-align:center;color:var(--text-sm)">جاري تحميل أداء الموظفين...</p>`;
    try{
      const data = await loadReport('employee', dateParams);
      const rows = data.rows || [];
      wrap.innerHTML=`${rptDateBarHtml()}<div style="margin-bottom:12px;display:flex;justify-content:flex-end">${exportActionBar('report', { ctx: { type: 'employee' } })}</div><div class="table-wrapper"><table class="table"><thead><tr><th>الموظف</th><th>الدور</th><th>العمليات</th><th>الإيراد</th><th>الربح</th></tr></thead>
      <tbody>${rows.map(u=>`<tr><td><b>${u.name}</b></td><td>${u.role||''}</td><td><span class="badge badge-info">${u.count}</span></td><td>${fmt(u.revenue)}</td><td style="color:var(--success);font-weight:700">${fmt(u.profit)}</td></tr>`).join('')}</tbody></table></div>`;
    }catch(e){ wrap.innerHTML=`${rptDateBarHtml()}<p style="padding:20px;text-align:center;color:var(--danger)">${e.message}</p>`; }
    return;
  }
  if(rptTab === 'clients_debt'){
    const dateParams = rptDateParams();
    wrap.innerHTML=`${rptDateBarHtml()}<p style="padding:20px;text-align:center;color:var(--text-sm)">جاري تحميل مديونية العملاء...</p>`;
    try{
      const data = await loadReport('clients-debt', dateParams);
      const rows = data.rows || [];
      wrap.innerHTML=`${rptDateBarHtml()}<div style="margin-bottom:12px;display:flex;justify-content:flex-end">${exportActionBar('report', { ctx: { type: 'clients-debt' } })}</div><div class="table-wrapper"><table class="table"><thead><tr><th>العميل</th><th>الهاتف</th><th>المشتريات</th><th>المدفوع</th><th>الرصيد</th><th>آخر عملية</th></tr></thead>
      <tbody>${rows.map(c=>`<tr><td><b>${c.name}</b></td><td>${c.phone||''}</td><td>${fmt(c.totalPurchases)}</td><td>${fmt(c.totalPaid)}</td><td style="color:var(--danger);font-weight:700">${fmt(c.balance)}</td><td>${c.lastOpDate}</td></tr>`).join('')||'<tr><td colspan="6" style="text-align:center;color:var(--success)">لا توجد مديونيات</td></tr>'}</tbody></table></div>`;
    }catch(e){ wrap.innerHTML=`${rptDateBarHtml()}<p style="padding:20px;text-align:center;color:var(--danger)">${e.message}</p>`; }
    return;
  }
  if(rptTab === 'vendors_balance'){
    const dateParams = rptDateParams();
    wrap.innerHTML=`${rptDateBarHtml()}<p style="padding:20px;text-align:center;color:var(--text-sm)">جاري تحميل أرصدة الموردين...</p>`;
    try{
      const data = await loadReport('vendors-balance', dateParams);
      const rows = data.rows || [];
      wrap.innerHTML=`${rptDateBarHtml()}<div style="margin-bottom:12px;display:flex;justify-content:flex-end">${exportActionBar('report', { ctx: { type: 'vendors-balance' } })}</div><div class="table-wrapper"><table class="table"><thead><tr><th>المورد</th><th>التصنيف</th><th>إجمالي الخدمات</th><th>المدفوع</th><th>الرصيد</th><th>آخر عملية</th></tr></thead>
      <tbody>${rows.map(v=>`<tr><td><b>${v.name}</b></td><td>${v.category||''}</td><td>${fmt(v.totalServices)}</td><td>${fmt(v.totalPaid)}</td><td style="color:var(--warning);font-weight:700">${fmt(v.balance)}</td><td>${v.lastOpDate}</td></tr>`).join('')||'<tr><td colspan="6" style="text-align:center;color:var(--success)">لا توجد أرصدة مستحقة</td></tr>'}</tbody></table></div>`;
    }catch(e){ wrap.innerHTML=`${rptDateBarHtml()}<p style="padding:20px;text-align:center;color:var(--danger)">${e.message}</p>`; }
    return;
  }
  __originalRenderRptContent();
};

reloadRptOpsPage = function(page){
  tablePages.rptOps = page;
  REPORT_CACHE = {};
  renderRptContent();
};

saveProfile = async function(){
  clearProfileErrors();
  const name = document.getElementById('prof_name')?.value?.trim();
  const email = document.getElementById('prof_email')?.value?.trim();
  if(!name){ showProfileError('profProfileError', 'الاسم مطلوب'); return; }
  if(!email){ showProfileError('profProfileError', 'البريد الإلكتروني مطلوب'); return; }
  await withSaveGuard('#saveProfileBtn', async ()=>{
    const data = await apiFetch('/profile',{method:'PATCH',body:JSON.stringify({name, email})});
    currentUser = data.user;
    document.getElementById('userName').textContent = currentUser.name;
    notify('تم حفظ البيانات', 'success');
  }).catch(e => showProfileError('profProfileError', e.message));
};

saveProfilePassword = async function(){
  clearProfileErrors();
  const current = document.getElementById('prof_current_password')?.value || '';
  const password = document.getElementById('prof_new_password')?.value || '';
  const confirmation = document.getElementById('prof_confirm_password')?.value || '';
  if(!current){ showProfileError('profPasswordError', 'كلمة المرور الحالية مطلوبة'); return; }
  if(!password){ showProfileError('profPasswordError', 'كلمة المرور الجديدة مطلوبة'); return; }
  if(password.length < 8){ showProfileError('profPasswordError', 'كلمة المرور يجب أن تكون 8 أحرف على الأقل'); return; }
  if(password !== confirmation){ showProfileError('profPasswordError', 'تأكيد كلمة المرور غير متطابق'); return; }
  await withSaveGuard('#saveProfilePasswordBtn', async ()=>{
    await apiFetch('/profile/password', {
      method:'PATCH',
      body: JSON.stringify({
        current_password: current,
        password,
        password_confirmation: confirmation,
      }),
    });
    ['prof_current_password','prof_new_password','prof_confirm_password'].forEach(id => {
      const input = document.getElementById(id);
      if(input){ input.value = ''; input.type = 'password'; }
    });
    document.querySelectorAll('.password-toggle.is-visible').forEach(btn => btn.classList.remove('is-visible'));
    notify('تم تحديث كلمة المرور بنجاح', 'success');
  }).catch(e => showProfileError('profPasswordError', e.message));
};

function clearProfileErrors(){
  ['profProfileError','profPasswordError'].forEach(id => {
    const el = document.getElementById(id);
    if(el){ el.style.display = 'none'; el.textContent = ''; }
  });
}

function showProfileError(id, message){
  const el = document.getElementById(id);
  if(!el){ notify(message, 'error'); return; }
  el.textContent = message;
  el.style.display = 'block';
}

function togglePasswordVisibility(inputId, btn){
  const input = document.getElementById(inputId);
  if(!input || !btn) return;
  const visible = input.type === 'password';
  input.type = visible ? 'text' : 'password';
  btn.classList.toggle('is-visible', visible);
  btn.setAttribute('aria-label', visible ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور');
}

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

hideClient = async function(id){
  let c = CLIENTS.find(x=>x.id===id);
  if (!c) {
    const res = await apiFetch('/clients?per_page=500');
    c = (res.data||[]).find(x=>x.id===id);
  }
  if (!c) { notify('العميل غير موجود', 'error'); return; }
  if (!confirm(`إخفاء العميل «${c.name}»؟\n\nسيُستبعد من القوائم والتقارير دون حذف بياناته أو قيوده المحاسبية.`)) return;
  await withSaveGuard(null, async ()=>{
    await apiFetch(`/clients/${id}/hide`, {method:'POST'});
    await refreshAfterMutation();
    if (currentPage === 'settings') await loadHiddenSettings();
    notify('تم إخفاء العميل', 'success');
  }).catch(e=> notify(e.message, 'error'));
};

restoreClient = async function(id){
  const c = HIDDEN_CLIENTS.find(x=>x.id===id);
  if (!c) { notify('العميل غير موجود', 'error'); return; }
  if (!confirm(`استعادة العميل «${c.name}»؟`)) return;
  await withSaveGuard(null, async ()=>{
    await apiFetch(`/clients/${id}/restore`, {method:'POST'});
    await refreshAfterMutation();
    await loadHiddenSettings();
    notify('تم استعادة العميل', 'success');
  }).catch(e=> notify(e.message, 'error'));
};

hideOperation = async function(id){
  let o = OPS.find(x=>x.id===id);
  if (!o) {
    try { o = await apiFetch(`/operations/${id}`); } catch (e) { notify('العملية غير موجودة', 'error'); return; }
  }
  if (!confirm(`إخفاء العملية «${o.ref}»؟\n\nسيُستبعد من القوائم والتقارير دون حذف بياناته أو قيوده المحاسبية.`)) return;
  await withSaveGuard(null, async ()=>{
    await apiFetch(`/operations/${id}/hide`, {method:'POST'});
    await refreshAfterMutation();
    if (currentPage === 'settings') await loadHiddenSettings();
    notify('تم إخفاء العملية', 'success');
  }).catch(e=> notify(e.message, 'error'));
};

restoreOperation = async function(id){
  const o = HIDDEN_OPS.find(x=>x.id===id);
  if (!o) { notify('العملية غير موجودة', 'error'); return; }
  if (!confirm(`استعادة العملية «${o.ref}»؟`)) return;
  await withSaveGuard(null, async ()=>{
    await apiFetch(`/operations/${id}/restore`, {method:'POST'});
    await refreshAfterMutation();
    await loadHiddenSettings();
    notify('تم استعادة العملية', 'success');
  }).catch(e=> notify(e.message, 'error'));
};

let HIDDEN_CLIENTS = [];
let HIDDEN_OPS = [];

async function loadHiddenSettings(){
  if (!canDo('write_master') && !canDo('cancel_op')) return;
  try {
    const [clientsRes, opsRes] = await Promise.all([
      apiFetch('/clients?hidden=1&per_page=100'),
      apiFetch('/operations?hidden=1&per_page=100'),
    ]);
    HIDDEN_CLIENTS = clientsRes.data || [];
    HIDDEN_OPS = opsRes.data || [];
    paintHiddenClientsSettings();
    paintHiddenOperationsSettings();
  } catch (e) { /* settings still usable */ }
}

function hiddenClientsSettingsRows(){
  if (!HIDDEN_CLIENTS.length) return '<tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text-sm)">لا يوجد عملاء مخفيون</td></tr>';
  return HIDDEN_CLIENTS.map(c=>{
    const bal = typeof c.balance === 'number' ? c.balance : 0;
    const restoreBtn = canDo('write_master') ? `<button class="btn btn-sm btn-success" onclick="restoreClient(${c.id})">استعادة</button>` : '—';
    return `<tr>
      <td><b>${c.name}</b></td>
      <td>${c.phone||'—'}</td>
      <td style="font-weight:700;color:${bal>0?'var(--danger)':'var(--success)'}">${fmt(bal)}</td>
      <td><span class="badge badge-warning">مخفي</span></td>
      <td>${restoreBtn}</td>
    </tr>`;
  }).join('');
}

function hiddenOperationsSettingsRows(){
  if (!HIDDEN_OPS.length) return '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-sm)">لا توجد عمليات مخفية</td></tr>';
  return HIDDEN_OPS.map(o=>{
    const restoreBtn = (canDo('write_master') || canDo('cancel_op')) ? `<button class="btn btn-sm btn-success" onclick="restoreOperation(${o.id})">استعادة</button>` : '—';
    return `<tr>
      <td><b>${o.ref}</b></td>
      <td>${o.date||'—'}</td>
      <td>${o.client||clientName(o.client_id)}</td>
      <td>${fmt(o.client_price)}</td>
      <td><span class="badge badge-warning">مخفي</span></td>
      <td>${restoreBtn}</td>
    </tr>`;
  }).join('');
}

function paintHiddenClientsSettings(){
  const body = document.getElementById('hiddenClientsBody');
  if (body) body.innerHTML = hiddenClientsSettingsRows();
}

function paintHiddenOperationsSettings(){
  const body = document.getElementById('hiddenOpsBody');
  if (body) body.innerHTML = hiddenOperationsSettingsRows();
}

function canManageHiddenRecords(){
  return canDo('write_master') || canDo('cancel_op');
}

let SAFE_TRANSFERS = [];
let LIST_META_SAFES = { safes: null, transfers: null };
let tablePagesSafes = { safes: 1, transfers: 1 };
let safesTab = 'safes';

async function reloadSafesList(page){
  const wrap = document.getElementById('sfTableWrap');
  if (wrap) wrap.innerHTML = '<p style="padding:24px;text-align:center;color:var(--text-sm)">جاري التحميل...</p>';
  const search = document.getElementById('sfSearch')?.value?.trim() || '';
  const type = document.getElementById('sfTypeFilter')?.value || 'all';
  const active = document.getElementById('sfActiveFilter')?.value || 'all';
  const p = page || tablePagesSafes.safes || 1;
  const params = {};
  if (search) params.search = search;
  if (type !== 'all') params.type = type;
  if (active !== 'all') params.active = active === 'active' ? '1' : '0';
  const res = await fetchListPage('/safes', p, params);
  replaceArray(SAFES, res.data || []);
  LIST_META_SAFES.safes = res.meta || null;
  tablePagesSafes.safes = res.meta?.current_page || 1;
  paintSafesTable();
}

async function reloadTransfersList(page){
  const wrap = document.getElementById('trTableWrap');
  if (wrap) wrap.innerHTML = '<p style="padding:24px;text-align:center;color:var(--text-sm)">جاري التحميل...</p>';
  const search = document.getElementById('trSearch')?.value?.trim() || '';
  const p = page || tablePagesSafes.transfers || 1;
  const params = {};
  if (search) params.search = search;
  applyDateParams(params, 'trFrom', 'trTo');
  const res = await fetchListPage('/safe-transfers', p, params);
  SAFE_TRANSFERS = res.data || [];
  LIST_META_SAFES.transfers = res.meta || null;
  tablePagesSafes.transfers = res.meta?.current_page || 1;
  paintTransfersTable();
}

async function reloadSafesPage(){
  if (safesTab === 'transfers') await reloadTransfersList(1);
  else await reloadSafesList(1);
}

function paintSafesTable(){
  const wrap = document.getElementById('sfTableWrap');
  if (!wrap) return;
  const meta = LIST_META_SAFES.safes;
  wrap.innerHTML = `<div class="table-wrapper"><table class="table"><thead><tr><th>#</th><th>الاسم</th><th>النوع</th><th>رمز الحساب</th><th>الرصيد</th><th>الحالة</th><th>إجراءات</th></tr></thead>
  <tbody>${SAFES.map(s=>{
    const bal = typeof s.balance === 'number' ? s.balance : getSafeBalance(s.id);
    const active = s.is_active !== false;
    const manage = canDo('manage_safes');
    const editBtn = manage ? `<button class="btn btn-sm btn-outline" onclick="openEditSafe(${s.id})">تعديل</button> ` : '';
    const toggleBtn = manage ? `<button class="btn btn-sm ${active ? 'btn-danger' : 'btn-success'}" onclick="toggleSafeActive(${s.id}, ${active ? 0 : 1})">${active ? 'تعطيل' : 'تفعيل'}</button> ` : '';
    return `<tr>
      <td>${s.id}</td>
      <td><b>${s.type==='cash'?"<i class='bx bx-wallet'></i>":"<i class='bx bxs-bank'></i>"} ${s.name}</b></td>
      <td>${s.type==='cash'?'صندوق':'بنك'}</td>
      <td>${s.account_code||'—'}</td>
      <td style="font-weight:700;color:${bal>=0?'var(--success)':'var(--danger)'}">${fmt(bal)}</td>
      <td><span class="badge ${active?'badge-success':'badge-danger'}">${active?'مفعل':'معطل'}</span></td>
      <td style="white-space:nowrap">${editBtn}${toggleBtn}</td>
    </tr>`;
  }).join('')||'<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-sm)">لا توجد صناديق</td></tr>'}</tbody></table></div>${serverPagerHtml('safes', meta, 'reloadSafesList')}`;
}

function paintTransfersTable(){
  const wrap = document.getElementById('trTableWrap');
  if (!wrap) return;
  const meta = LIST_META_SAFES.transfers;
  wrap.innerHTML = `<div class="table-wrapper"><table class="table"><thead><tr><th>المرجع</th><th>التاريخ</th><th>من</th><th>إلى</th><th>المبلغ</th><th>بواسطة</th><th>ملاحظات</th></tr></thead>
  <tbody>${SAFE_TRANSFERS.map(t=>`<tr>
    <td><b style="color:var(--primary)">${t.ref}</b></td>
    <td>${t.date||t.transfer_date||'—'}</td>
    <td>${t.from_type==='bank'?"<i class='bx bxs-bank'></i>":"<i class='bx bx-wallet'></i>"} ${t.from_safe||'—'}</td>
    <td>${t.to_type==='bank'?"<i class='bx bxs-bank'></i>":"<i class='bx bx-wallet'></i>"} ${t.to_safe||'—'}</td>
    <td style="font-weight:700">${fmt(t.amount)}</td>
    <td>${t.creator||'—'}</td>
    <td style="font-size:12px">${displayVal(t.notes)}</td>
  </tr>`).join('')||'<tr><td colspan="7" style="text-align:center;padding:40px;color:var(--text-sm)">لا توجد تحويلات</td></tr>'}</tbody></table></div>${serverPagerHtml('transfers', meta, 'reloadTransfersList')}`;
}

function populateTransferForm(){
  const activeSafes = SAFES.filter(s => s.is_active !== false);
  const opts = activeSafes.map(s=>`<option value="${s.id}">${s.type==='bank'?"<i class='bx bxs-bank'></i>":"<i class='bx bx-wallet'></i>"} ${s.name} (${fmt(typeof s.balance==='number'?s.balance:getSafeBalance(s.id))})</option>`).join('');
  const from = document.getElementById('tr_from');
  const to = document.getElementById('tr_to');
  if (from) from.innerHTML = opts;
  if (to) to.innerHTML = opts;
  const dt = document.getElementById('tr_date');
  if (dt) dt.value = today();
  ['tr_amount','tr_notes'].forEach(id=>{ const el=document.getElementById(id); if(el) el.value=''; });
}

async function saveSafe(){
  const name = document.getElementById('sf_name')?.value?.trim();
  const type = document.getElementById('sf_type')?.value;
  const opening = +(document.getElementById('sf_opening')?.value||0);
  if (!name || !type) { showModalError('newSafeModal','الاسم والنوع مطلوبان'); return; }
  await withSaveGuard('#newSafeModal .btn-primary', async ()=>{
    await apiFetch('/safes', { method:'POST', body: JSON.stringify({ name, type, opening_balance: opening, currency: 'KWD' }) });
    closeModal('newSafeModal');
    await refreshAfterMutation();
    if (currentPage === 'safes') await reloadSafesList(tablePagesSafes.safes);
    notify('تم إنشاء الصندوق بنجاح', 'success');
  }).catch(e=> showModalError('newSafeModal', e.message));
}

function openEditSafe(id){
  const s = SAFES.find(x=>+x.id===+id);
  if (!s) { notify('الصندوق غير موجود', 'error'); return; }
  document.getElementById('esf_id').value = s.id;
  document.getElementById('esf_name').value = s.name||'';
  document.getElementById('esf_type').value = s.type||'cash';
  document.getElementById('esf_opening').value = s.opening_balance ?? s.initial ?? 0;
  document.getElementById('esf_account_code').value = s.account_code||'—';
  clearModalError('editSafeModal');
  showModal('editSafeModal');
}

async function saveSafeEdit(){
  const id = +document.getElementById('esf_id')?.value;
  await withSaveGuard('#editSafeModal .btn-primary', async ()=>{
    await apiFetch(`/safes/${id}`, { method:'PATCH', body: JSON.stringify({
      name: document.getElementById('esf_name').value.trim(),
      type: document.getElementById('esf_type').value,
      opening_balance: +document.getElementById('esf_opening').value||0,
    })});
    closeModal('editSafeModal');
    await refreshAfterMutation();
    if (currentPage === 'safes') await reloadSafesList(tablePagesSafes.safes);
    notify('تم تحديث الصندوق', 'success');
  }).catch(e=> showModalError('editSafeModal', e.message));
}

async function toggleSafeActive(id, active){
  const s = SAFES.find(x=>+x.id===+id);
  if (!s) return;
  const label = active ? 'تفعيل' : 'تعطيل';
  if (!confirm(`${label} «${s.name}»؟`)) return;
  await withSaveGuard(null, async ()=>{
    await apiFetch(`/safes/${id}/toggle`, { method:'PATCH' });
    await refreshAfterMutation();
    if (currentPage === 'safes') await reloadSafesList(tablePagesSafes.safes);
    notify(`تم ${label} الصندوق`, 'success');
  }).catch(e=> notify(e.message, 'error'));
}

async function saveTransfer(){
  const fromId = +document.getElementById('tr_from')?.value;
  const toId = +document.getElementById('tr_to')?.value;
  const amount = +document.getElementById('tr_amount')?.value;
  const transferDate = document.getElementById('tr_date')?.value;
  const notes = document.getElementById('tr_notes')?.value||'';
  if (!fromId || !toId || !amount) { showModalError('newTransferModal','يرجى تعبئة المصدر والوجهة والمبلغ'); return; }
  if (fromId === toId) { showModalError('newTransferModal','يجب أن يكون المصدر مختلفاً عن الوجهة'); return; }
  await withSaveGuard('#newTransferModal .btn-primary', async ()=>{
    await apiFetch('/safe-transfers', { method:'POST', body: JSON.stringify({
      from_safe_id: fromId, to_safe_id: toId, amount, transfer_date: transferDate||undefined, notes,
    })});
    closeModal('newTransferModal');
    await refreshAfterMutation();
    safesTab = 'transfers';
    if (currentPage === 'safes') { renderSafes(document.getElementById('pageContent')); await reloadTransfersList(1); }
    notify('تم تنفيذ التحويل بنجاح', 'success');
  }).catch(e=> showModalError('newTransferModal', e.message));
}

let __sfSearchTimer = null;
function filterSafesList(){
  clearTimeout(__sfSearchTimer);
  __sfSearchTimer = setTimeout(()=> reloadSafesList(1), 300);
}
function filterTransfersList(){
  clearTimeout(__sfSearchTimer);
  __sfSearchTimer = setTimeout(()=> reloadTransfersList(1), 300);
}

function switchSafesTab(tab){
  safesTab = tab;
  renderSafes(document.getElementById('pageContent'));
  reloadSafesPage();
}

const __originalRenderSafes = renderSafes;
renderSafes = function(pc){
  pc.innerHTML = `
  <div class="page-shell">
    <div class="card" style="margin-bottom:16px">
      <div class="card-body">
        <div class="tabs" style="margin-bottom:16px">
          <button class="tab-btn ${safesTab==='safes'?'active':''}" onclick="switchSafesTab('safes')"><i class='bx bx-wallet'></i> الصناديق والبنوك</button>
          <button class="tab-btn ${safesTab==='transfers'?'active':''}" onclick="switchSafesTab('transfers')"><i class='bx bx-transfer'></i> سجل التحويلات</button>
        </div>
        ${safesTab==='safes' ? `
          <div class="filter-bar" style="margin-bottom:12px">
            <input type="text" class="form-control filter-control" id="sfSearch" placeholder="بحث بالاسم..." oninput="filterSafesList()">
            <select class="form-control filter-control" id="sfTypeFilter" onchange="reloadSafesList(1)"><option value="all">كل الأنواع</option><option value="cash">صناديق</option><option value="bank">بنوك</option></select>
            <select class="form-control filter-control" id="sfActiveFilter" onchange="reloadSafesList(1)"><option value="all">كل الحالات</option><option value="active">مفعل</option><option value="inactive">معطل</option></select>
            ${canDo('manage_safes')?`<button class="btn btn-primary" onclick="showModal('newSafeModal')"><i class='bx bx-plus'></i> صندوق / بنك جديد</button>`:''}
            ${canDo('manage_safes')?`<button class="btn btn-outline" onclick="populateTransferForm();showModal('newTransferModal')"><i class='bx bx-transfer'></i> تحويل</button>`:''}
          </div>
          <div id="sfTableWrap"><p style="padding:24px;text-align:center;color:var(--text-sm)">جاري التحميل...</p></div>
        ` : `
          <div class="filter-bar" style="margin-bottom:12px">
            <input type="date" class="form-control filter-control" id="trFrom" onchange="reloadTransfersList(1)">
            <input type="date" class="form-control filter-control" id="trTo" onchange="reloadTransfersList(1)">
            <input type="text" class="form-control filter-control" id="trSearch" placeholder="بحث..." oninput="filterTransfersList()">
            ${canDo('manage_safes')?`<button class="btn btn-primary" onclick="populateTransferForm();showModal('newTransferModal')"><i class='bx bx-plus'></i> تحويل جديد</button>`:''}
          </div>
          <div id="trTableWrap"><p style="padding:24px;text-align:center;color:var(--text-sm)">جاري التحميل...</p></div>
        `}
      </div>
    </div>
    ${safesTab==='safes' ? `<div class="grid-2">${SAFES.slice(0,4).map(s=>{
      const bal = typeof s.balance === 'number' ? s.balance : getSafeBalance(s.id);
      const movements = (s.movements&&s.movements.length)?s.movements:[];
      return `<div class="card"><div class="card-body">
        <div style="display:flex;justify-content:space-between;margin-bottom:12px"><div><h3>${s.type==='cash'?"<i class='bx bx-wallet'></i>":"<i class='bx bxs-bank'></i>"} ${s.name}</h3><small style="color:var(--text-sm)">رصيد مبدئي: ${fmt(s.opening_balance??s.initial??0)}</small></div>
        <div style="font-size:22px;font-weight:800;color:${bal>=0?'var(--success)':'var(--danger)'}">${fmt(bal)}</div></div>
        <div class="table-wrapper"><table class="table"><thead><tr><th>التاريخ</th><th>المرجع</th><th>وارد</th><th>صادر</th></tr></thead>
        <tbody>${movements.slice(0,5).map(j=>`<tr><td>${j.date}</td><td>${displayVal(j.ref)}</td><td style="color:var(--success)">${j.debit>0?fmt(j.debit):'—'}</td><td style="color:var(--danger)">${j.credit>0?fmt(j.credit):'—'}</td></tr>`).join('')||'<tr><td colspan="4" style="text-align:center;color:var(--text-sm)">لا توجد حركات</td></tr>'}</tbody></table></div>
      </div></div>`;
    }).join('')}</div>` : ''}
  </div>`;
  reloadSafesPage();
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
    const todaySales = DASHBOARD_DATA.today_sales ?? 0;
    const todayProfit = DASHBOARD_DATA.today_profit ?? 0;
    const totalReceipts = DASHBOARD_DATA.total_cash_receipts ?? 0;
    const totalPayments = DASHBOARD_DATA.total_payments ?? 0;
    const days = DASHBOARD_DATA.week?.days || [];
    const weekReceipts = DASHBOARD_DATA.week?.receipts || [];
    const weekPayments = DASHBOARD_DATA.week?.payments || [];
    const svcCount = DASHBOARD_DATA.services || [];
    const salesLabel = DASHBOARD_DATA.sales_label || 'مبيعات اليوم';
    const profitLabel = DASHBOARD_DATA.profit_label || 'ربح متوقع اليوم';
    const salesSub = DASHBOARD_DATA.sales_sub || '';

    pc.innerHTML=`
  <div class="page-shell">
    ${dateFilterBar('dashFrom', 'dashTo', 'filterDashboard', DASHBOARD_DATA.from||'', DASHBOARD_DATA.to||today(), '<span style="font-size:12px;color:var(--text-sm);align-self:center">فترة الإحصائيات:</span>')}
    <div class="grid-kpi-4">
      ${kpiCard('<i class="bx bxs-dollar-circle" style="font-size:24px"></i>', salesLabel, fmt(todaySales), 'var(--primary)', salesSub)}
      ${kpiCard('<i class="bx bxs-wallet" style="font-size:24px"></i>', profitLabel, fmt(todayProfit), 'var(--success)', formatMargin(todayProfit,todaySales))}
      ${kpiCard('<i class="bx bxs-receipt" style="font-size:24px"></i>','التحصيلات النقدية',fmt(totalReceipts),'var(--info)','')}
      ${kpiCard('<i class="bx bxs-credit-card" style="font-size:24px"></i>','إجمالي المدفوعات',fmt(totalPayments),'var(--warning)','')}
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
        <div class="card"><div class="card-header"><h3>أعلى 5 مدينين</h3></div><div class="card-body" style="padding:0"><div class="table-wrapper">
        <table class="table"><thead><tr><th>العميل</th><th>المبلغ</th></tr></thead><tbody>${debtors.map(c=>`<tr><td>${c.name}</td><td style="color:var(--danger);font-weight:700">${fmt(c.bal)}</td></tr>`).join('')||'<tr><td colspan="2" style="text-align:center;color:var(--text-sm)">لا يوجد مدينون</td></tr>'}</tbody></table></div></div></div>
        <div class="card"><div class="card-header"><h3>أعلى 5 دائنين</h3></div><div class="card-body" style="padding:0"><div class="table-wrapper">
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

const __originalRenderSettings = renderSettings;
renderSettings = function(pc){
  __originalRenderSettings(pc);
  if (canManageHiddenRecords()) {
    const shell = pc.querySelector('.page-shell .grid-2') || pc.querySelector('.grid-2');
    if (shell) {
      const hiddenCard = document.createElement('div');
      hiddenCard.className = 'card';
      hiddenCard.innerHTML = `<div class="card-header"><h3><i class='bx bx-user'></i> العملاء المخفيون</h3></div>
        <div class="card-body" style="padding:0"><div class="table-wrapper">
          <table class="table"><thead><tr><th>الاسم</th><th>الهاتف</th><th>الرصيد</th><th>الحالة</th><th>إجراءات</th></tr></thead>
          <tbody id="hiddenClientsBody"><tr><td colspan="5" style="text-align:center;padding:24px;color:var(--text-sm)">جاري التحميل...</td></tr></tbody>
        </table></div></div>`;
      shell.appendChild(hiddenCard);

      const hiddenOpsCard = document.createElement('div');
      hiddenOpsCard.className = 'card';
      hiddenOpsCard.innerHTML = `<div class="card-header"><h3><i class='bx bx-briefcase'></i> العمليات المخفية</h3></div>
        <div class="card-body" style="padding:0"><div class="table-wrapper">
          <table class="table"><thead><tr><th>المرجع</th><th>التاريخ</th><th>العميل</th><th>المبلغ</th><th>الحالة</th><th>إجراءات</th></tr></thead>
          <tbody id="hiddenOpsBody"><tr><td colspan="6" style="text-align:center;padding:24px;color:var(--text-sm)">جاري التحميل...</td></tr></tbody>
        </table></div></div>`;
      shell.appendChild(hiddenOpsCard);
      loadHiddenSettings();
    }
  }
  renderOfficeSwitcher();
};

window.renderOffices = function(pc){
  if(currentUser?.role !== 'super_admin' && currentUser?.role !== 'admin') return;
  pc.innerHTML = `
  <div class="page-shell">
    <div class="card">
      <div class="card-header">
        <h3><i class='bx bx-building'></i> إدارة المكاتب وفروع الوكالة</h3>
        <button class="btn btn-primary btn-sm" onclick="resetNewOfficeForm();showModal('newOfficeModal')">
          <i class='bx bx-plus'></i> مكتب جديد
        </button>
      </div>
      <div class="card-body" style="padding:0">
        <div class="table-wrapper">
          <table class="table">
            <thead>
              <tr><th>الشعار</th><th>الرمز</th><th>الاسم</th><th>الحالة</th><th>إجراءات</th></tr>
            </thead>
            <tbody>
              ${OFFICES.map(o => {
                const url = officeLogoUrl(o);
                const logoCell = url ? `<img src="${url}" alt="" style="width:36px;height:36px;object-fit:contain;border-radius:8px;border:1px solid var(--border)" onerror="this.src='logo.png'; this.onerror=null;">` : '<img src="logo.png" alt="" style="width:36px;height:36px;object-fit:contain;border-radius:8px;border:1px solid var(--border)" onerror="this.src=\'logo.png\'; this.onerror=null;">';
                return `<tr>
                  <td>${logoCell}</td>
                  <td><b>${o.office_code}</b></td>
                  <td>${o.office_name}</td>
                  <td><span class="badge ${o.is_active?'badge-success':'badge-danger'}">${o.is_active?'مفعل':'معطل'}</span></td>
                  <td style="white-space:nowrap">
                    <button class="btn btn-sm btn-outline" onclick="openEditOffice(${o.id})">تعديل</button>
                    <button class="btn btn-sm ${o.is_active?'btn-danger':'btn-success'}" onclick="toggleOfficeActive(${o.id},${o.is_active?0:1})">${o.is_active?'تعطيل':'تفعيل'}</button>
                  </td>
                </tr>`;
              }).join('')}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>`;
};

async function restoreSession(){
  const urlParams = new URLSearchParams(window.location.search);
  const resetToken = urlParams.get('reset');
  if (resetToken) {
    AppShell.setAuthMode('login');
    document.getElementById('appLayout').style.display='none';
    document.getElementById('loginPage').style.display='none';
    document.getElementById('resetPasswordPage').style.display='flex';
    return;
  }

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
