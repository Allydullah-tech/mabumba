/**
 * MABUMBATECH — App shell
 * Renders the sidebar + topbar into #app-shell-mount, checks the
 * session, and exposes small shared UI helpers (toast, modal, currency
 * formatting) used by every logged-in page.
 *
 * Usage: a page sets `window.CURRENT_PAGE = 'dashboard'` (matching a
 * key in NAV_ITEMS below) before including this script, then calls
 * initShell().then(user => { ...page-specific code... }).
 */

const NAV_GROUPS = [
  {
    label: 'Main',
    items: [
      { key: 'dashboard', label: 'Dashboard', href: 'dashboard.html', icon: 'grid', roles: null },
      { key: 'sales', label: 'Sales', href: 'pages/sales.html', icon: 'cart', roles: ['administrator', 'worker'] },
      { key: 'customers', label: 'Customers', href: 'pages/customers.html', icon: 'users', roles: ['administrator'] },
    ],
  },
  {
    label: 'Inventory',
    items: [
      { key: 'products', label: 'Products', href: 'pages/products.html', icon: 'box', roles: ['administrator', 'worker'] },
      { key: 'services', label: 'Services', href: 'pages/services.html', icon: 'wrench', roles: ['administrator', 'worker'] },
      { key: 'stock', label: 'Stock', href: 'pages/stock.html', icon: 'layers', roles: ['administrator', 'worker'] },
      { key: 'purchases', label: 'Purchases', href: 'pages/purchases.html', icon: 'file', roles: ['administrator'] },
      { key: 'suppliers', label: 'Suppliers', href: 'pages/suppliers.html', icon: 'truck', roles: ['administrator'] },
      { key: 'damages', label: 'Damages', href: 'pages/damages.html', icon: 'alert', roles: ['administrator', 'worker'] },
    ],
  },
  {
    label: 'Finance',
    items: [
      { key: 'expenses', label: 'Expenses', href: 'pages/expenses.html', icon: 'wallet', roles: ['administrator', 'worker'] },
      { key: 'debts', label: 'Payments & Debts', href: 'pages/debts.html', icon: 'clock', roles: ['administrator'] },
    ],
  },
  {
    label: 'Reports',
    items: [
      { key: 'reports', label: 'Reports Center', href: 'pages/reports.html', icon: 'file', roles: ['administrator', 'worker'] },
    ],
  },
  {
    label: 'Management',
    items: [
      { key: 'users', label: 'Users', href: 'pages/users.html', icon: 'user-group', roles: ['administrator'] },
      { key: 'audit', label: 'Activity Log', href: 'pages/audit-log.html', icon: 'history', roles: ['administrator'] },
      { key: 'settings', label: 'Settings', href: 'pages/settings.html', icon: 'settings', roles: ['administrator'] },
    ],
  },
];

const ICONS = {
  grid: '<rect x="3" y="3" width="6" height="6" rx="1.4"/><rect x="11" y="3" width="6" height="6" rx="1.4"/><rect x="3" y="11" width="6" height="6" rx="1.4"/><rect x="11" y="11" width="6" height="6" rx="1.4"/>',
  cart: '<path d="M3 6h14l-1.4 8.4a2 2 0 0 1-2 1.6H6.4a2 2 0 0 1-2-1.6L3 6Z"/><path d="M6 6V4.5A2.5 2.5 0 0 1 8.5 2h3A2.5 2.5 0 0 1 14 4.5V6"/>',
  users: '<circle cx="10" cy="7" r="3"/><path d="M3.5 17c0-3 3-5 6.5-5s6.5 2 6.5 5"/>',
  box: '<path d="M10 2 3 5.5v9L10 18l7-3.5v-9L10 2Z"/><path d="M3 5.5 10 9l7-3.5"/><path d="M10 9v9"/>',
  wrench: '<path d="M15.5 4.5 12 8M4.5 15.5 8 12M9 5l-2-2-2.5 2.5L6.5 7.5 9 5Zm6 6-2-2-1 1 2 2 1-1Z"/><path d="M6.5 7.5 12.5 13.5"/><circle cx="14" cy="14" r="1.4"/>',
  layers: '<path d="M3 8h14M5 8v8a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1V8M8 8V5a2 2 0 0 1 4 0v3"/>',
  truck: '<rect x="3" y="4" width="14" height="12" rx="1.6"/><path d="M3 8h14M7 4v-.5M13 4v-.5"/>',
  alert: '<path d="M10 3 4 6v5c0 3.5 2.5 5.3 6 6 3.5-.7 6-2.5 6-6V6l-6-3Z"/><path d="M8 10l1.5 1.5L13 8"/>',
  wallet: '<path d="M3 15V9M8 15V5M13 15v-7M17 15V11"/>',
  clock: '<circle cx="10" cy="10" r="7.5"/><path d="M10 6v4l3 2"/>',
  file: '<rect x="4" y="2.5" width="12" height="15" rx="1.4"/><path d="M7 7h6M7 10.5h6M7 14h3.5"/>',
  'user-group': '<circle cx="10" cy="6.5" r="3"/><path d="M4 17c0-3 2.7-5 6-5s6 2 6 5"/>',
  history: '<path d="M10 2v3M10 15v3M4 10H2M18 10h-2M5 5l-1.5-1.5M16.5 16.5 15 15M15 5l1.5-1.5M3.5 16.5 5 15"/><circle cx="10" cy="10" r="3.2"/>',
  settings: '<circle cx="10" cy="10" r="2.4"/><path d="M10 3v1.6M10 15.4V17M17 10h-1.6M4.6 10H3M15 5l-1.1 1.1M6.1 13.9 5 15M15 15l-1.1-1.1M6.1 6.1 5 5"/>',
};

function svgIcon(key) {
  return `<svg viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">${ICONS[key] || ''}</svg>`;
}

function renderShell(user) {
  const mount = document.getElementById('app-shell-mount');
  if (!mount) return;

  // Relative to the Frontend folder itself, so the whole app works when
  // deployed under a subfolder (e.g. htdocs/mabumbatech-business/) —
  // each page sets this before shell.js loads: '' at the Frontend root,
  // '../' for pages under Frontend/pages/.
  const BASE = window.FRONTEND_BASE || '';

  const groupsHtml = NAV_GROUPS.map(group => {
    const items = group.items.filter(item => !item.roles || item.roles.includes(user.role));
    if (!items.length) return '';
    const itemsHtml = items.map(item => `
      <a class="nav-item ${item.key === window.CURRENT_PAGE ? 'active' : ''}" href="${BASE}${item.href}">
        ${svgIcon(item.icon)}<span>${item.label}</span>
      </a>`).join('');
    return `<div class="nav-group"><div class="nav-group-label">${group.label}</div>${itemsHtml}</div>`;
  }).join('');

  const initials = user.full_name.split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase();

  mount.innerHTML = `
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-brand">
        <img src="${BASE}assets/logo-sidebar.png" alt="MABUMBATECH — Technology. Innovation. Solutions.">
      </div>
      <nav class="nav-scroll">${groupsHtml}</nav>
      <div class="sidebar-foot">v1.0 · MABUMBATECH BMS</div>
    </aside>

    <div class="main">
      <div class="topbar">
        <div class="topbar-left" style="display:flex; align-items:center; gap:12px;">
          <button class="btn-icon hide-mobile-inverse" id="mobile-nav-toggle" style="display:none;">
            <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h14M3 10h14M3 14h14"/></svg>
          </button>
          <div>
            <h1 id="page-title"></h1>
            <div class="date" id="page-date"></div>
          </div>
        </div>
        <div class="topbar-right">
          <div class="user-chip" id="user-chip">
            <div class="avatar">${initials}</div>
            <div class="who hide-mobile">
              <div class="n">${user.full_name}</div>
              <div class="r">${user.role_name}</div>
            </div>
            <div class="user-menu" id="user-menu">
              <button id="menu-profile">My profile</button>
              <button class="danger" id="menu-logout">Log out</button>
            </div>
          </div>
        </div>
      </div>
      <div class="content" id="page-content"></div>
    </div>
  `;

  // Keep the sidebar menu where the user left it: every menu link is a full page
  // load, so without this the list would jump back to the top on each click.
  (function keepSidebarScroll() {
    const nav = mount.querySelector('.nav-scroll');
    if (!nav) return;
    const KEY = 'bms_sidebar_scroll';
    try {
      const saved = parseInt(sessionStorage.getItem(KEY), 10);
      if (!isNaN(saved)) nav.scrollTop = saved;
    } catch (e) { /* storage unavailable */ }
    const save = () => { try { sessionStorage.setItem(KEY, String(nav.scrollTop)); } catch (e) {} };
    nav.addEventListener('scroll', save, { passive: true });
    nav.addEventListener('click', save);
    window.addEventListener('pagehide', save);
  })();


  document.getElementById('page-date').textContent = new Date().toLocaleDateString('en-GB', { weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' });

  const chip = document.getElementById('user-chip');
  const menu = document.getElementById('user-menu');
  chip.addEventListener('click', (e) => { menu.classList.toggle('open'); e.stopPropagation(); });
  document.addEventListener('click', () => menu.classList.remove('open'));

  document.getElementById('menu-logout').addEventListener('click', async () => {
    try { await api.post('auth/logout.php'); } catch (e) { /* ignore */ }
    window.location.href = BASE + 'login.html';
  });
  document.getElementById('menu-profile').addEventListener('click', () => { window.location.href = BASE + 'pages/profile.html'; });

  const toggle = document.getElementById('mobile-nav-toggle');
  if (window.innerWidth <= 900) {
    toggle.style.display = 'inline-flex';
    toggle.addEventListener('click', () => document.getElementById('sidebar').classList.toggle('open'));
  }
}

function setPageTitle(title) {
  const el = document.getElementById('page-title');
  if (el) el.textContent = title;
}

async function initShell() {
  let user;
  try {
    user = await api.get('auth/session_check.php');
  } catch (e) {
    window.location.href = (window.FRONTEND_BASE || '') + 'login.html';
    return null;
  }
  renderShell(user);
  return user;
}

/* ---------- Shared UI helpers ---------- */

function toast(message, type = 'info') {
  let stack = document.querySelector('.toast-stack');
  if (!stack) {
    stack = document.createElement('div');
    stack.className = 'toast-stack';
    document.body.appendChild(stack);
  }
  const el = document.createElement('div');
  el.className = `toast ${type}`;
  el.textContent = message;
  stack.appendChild(el);
  setTimeout(() => el.remove(), 3800);
}

function money(n) {
  const num = Number(n) || 0;
  return num.toLocaleString('en-US', { maximumFractionDigits: 0 });
}

function fmtDate(d) {
  if (!d) return '—';
  const dt = new Date(d);
  if (isNaN(dt)) return d;
  return dt.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

/**
 * Open a simple modal with the given title + body HTML. Returns the
 * modal root element so the caller can wire up its own form/buttons.
 * Closes on backdrop click or the × button.
 */
function openModal({ title, bodyHtml, footHtml = '', large = false }) {
  let backdrop = document.getElementById('shared-modal-backdrop');
  if (backdrop) backdrop.remove();

  backdrop = document.createElement('div');
  backdrop.className = 'modal-backdrop open';
  backdrop.id = 'shared-modal-backdrop';
  backdrop.innerHTML = `
    <div class="modal ${large ? 'modal-lg' : ''}">
      <div class="modal-head">
        <h3>${title}</h3>
        <button class="modal-close" id="modal-close-btn">
          <svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 5l10 10M15 5 5 15"/></svg>
        </button>
      </div>
      <div class="modal-body">${bodyHtml}</div>
      ${footHtml ? `<div class="modal-foot">${footHtml}</div>` : ''}
    </div>
  `;
  document.body.appendChild(backdrop);

  const close = () => backdrop.remove();
  backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
  backdrop.querySelector('#modal-close-btn').addEventListener('click', close);

  return backdrop;
}

function closeModal() {
  const backdrop = document.getElementById('shared-modal-backdrop');
  if (backdrop) backdrop.remove();
}

/** Simple confirm dialog returning a Promise<boolean>, styled to match the app instead of the browser's native confirm(). */
function confirmDialog(message, { confirmLabel = 'Confirm', danger = true } = {}) {
  return new Promise(resolve => {
    const modal = openModal({
      title: 'Please confirm',
      bodyHtml: `<p style="font-size:14px; color:var(--slate);">${escapeHtml(message)}</p>`,
      footHtml: `
        <button class="btn btn-secondary" id="confirm-cancel">Cancel</button>
        <button class="btn ${danger ? 'btn-danger' : 'btn-primary'}" id="confirm-ok">${confirmLabel}</button>
      `,
    });
    modal.querySelector('#confirm-cancel').addEventListener('click', () => { closeModal(); resolve(false); });
    modal.querySelector('#confirm-ok').addEventListener('click', () => { closeModal(); resolve(true); });
  });
}
