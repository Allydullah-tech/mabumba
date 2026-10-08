/**
 * MABUMBATECH — Cash Audit modal system.
 * Loaded on the Sales page; openCashAuditModal() is the entry point
 * (wired to the "Cash Audit" button). Everything else here is private
 * to this flow. Uses the shared openModal()/closeModal()/toast()/money()
 * helpers from shell.js and the shared api.js client.
 */

const DENOMS = [10000, 5000, 2000, 1000, 500, 200, 100, 50];

// Survives across modal swaps (openModal() always replaces the DOM), so
// the user's in-progress counting isn't lost when they pop into Opening
// Cash / Bank Deposit / History and come back.
let CA = {
  date: '',
  shift: '',
  cashier: '',
  remarks: '',
  denomQty: {},
  summary: null,
  deposits: null,
  savedId: null,
};

function caTodayStr() { return new Date().toISOString().slice(0, 10); }
function caTzs(n) { return money(n) + ' TZS'; }

function caResetState() {
  CA = {
    date: caTodayStr(),
    shift: '',
    cashier: (CURRENT_USER && CURRENT_USER.full_name) || '',
    remarks: '',
    denomQty: Object.fromEntries(DENOMS.map(d => [d, 0])),
    summary: null,
    deposits: null,
    savedId: null,
  };
}

function caPartATotal() {
  return DENOMS.reduce((sum, d) => sum + (Number(CA.denomQty[d]) || 0) * d, 0);
}

/** Pull whatever's currently in the document's inputs back into CA before swapping the modal out. */
function caSyncFormToState() {
  const dateEl = document.getElementById('ca-date');
  if (!dateEl) return; // main modal isn't the one on screen right now
  CA.date = dateEl.value || CA.date;
  CA.shift = document.getElementById('ca-shift').value;
  CA.cashier = document.getElementById('ca-cashier').value;
  CA.remarks = document.getElementById('ca-remarks').value;
  DENOMS.forEach(d => {
    const el = document.getElementById(`ca-qty-${d}`);
    if (el) CA.denomQty[d] = Number(el.value) || 0;
  });
}

/* =========================== One-step "back" for inner windows ===========================
   History / Opening Cash / Bank Deposit windows replace the Cash Audit window
   (openModal() swaps the DOM), so the shared top-right X and a click outside
   would close everything and land on the Sales page. This turns both — and the
   Esc key — into a single step back to the window the user came from. */
function caBackable(modal, backFn) {
  modal._caBack = backFn;
  const x = modal.querySelector('#modal-close-btn');
  if (x) {
    const back = x.cloneNode(false);            // drops the "close everything" listener
    back.id = 'modal-close-btn';
    back.className = x.className;
    back.title = 'Back';
    back.setAttribute('aria-label', 'Back');
    back.innerHTML = '<svg width="18" height="18" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4 6 10l6 6"/></svg>';
    back.addEventListener('click', () => backFn());
    x.replaceWith(back);
  }
  // capture phase so it runs before the shared "click outside = close" handler
  modal.addEventListener('click', (e) => {
    if (e.target === modal) { e.stopImmediatePropagation(); backFn(); }
  }, true);
}
document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape') return;
  const m = document.getElementById('shared-modal-backdrop');
  if (m && m._caBack) { e.preventDefault(); m._caBack(); }
});

/* =========================== Main Cash Audit modal =========================== */

function openCashAuditModal() {
  if (!CA.date) caResetState();
  renderCashAuditModal();
}

async function renderCashAuditModal() {
  const modal = openModal({
    title: 'Cash Audit — Daily Cash Closing',
    large: true,
    bodyHtml: caToolbarHtml() + caDocumentHtml(),
    footHtml: `<button class="btn btn-secondary" id="ca-close-btn">Close</button><button class="btn btn-primary" id="ca-save-btn">Save Cash Audit</button>`,
  });
  modal.querySelector('#ca-close-btn').addEventListener('click', closeModal);
  wireCaDocumentEvents();
  if (!CA.summary || CA.summary.date !== CA.date) await caRefreshSummary();
  await caRefreshDeposits();
}

const CA_STYLE = `
<style>
  .ca-toolbar{ display:flex; gap:8px; flex-wrap:wrap; margin-bottom:18px; }
  .ca-toolbar .btn svg{ color:var(--blue); }
  .ca-sheet{ background:#fff; border:1px solid var(--border); border-radius:16px; overflow:hidden; box-shadow:var(--shadow-md); }
  .ca-banner{ display:flex; align-items:center; gap:22px; padding:20px 28px; color:#fff; background:linear-gradient(120deg,#0a1f16 0%,#123324 60%,#1b6b3c 130%); }
  .ca-banner img{ height:60px; width:auto; display:block; flex-shrink:0; }
  .ca-banner-text{ flex:1; text-align:right; border-left:1px solid rgba(255,255,255,0.14); padding-left:22px; }
  .ca-banner-title{ font:700 16px 'Space Grotesk',sans-serif; letter-spacing:.6px; line-height:1.35; }
  .ca-banner-sub{ font-size:11.5px; color:#8fe3b0; margin-top:4px; letter-spacing:.3px; }
  .ca-body{ padding:6px 28px 26px; }
  .ca-meta{ display:grid; grid-template-columns:1fr 1fr 1.4fr; gap:14px; padding-top:22px; }
  .ca-meta .field{ margin:0; }
  .ca-meta label{ font-size:11.5px; text-transform:uppercase; letter-spacing:.5px; }
  .ca-section{ display:flex; align-items:center; gap:12px; margin:30px 0 12px; }
  .ca-section .letter{ width:32px; height:32px; border-radius:10px; background:var(--blue); color:#fff; display:flex; align-items:center; justify-content:center; font:700 14px 'Space Grotesk',sans-serif; flex-shrink:0; box-shadow:0 4px 10px rgba(22,163,74,0.28); }
  .ca-section .name{ font-weight:700; font-size:15px; color:var(--slate); line-height:1.2; }
  .ca-section .desc{ font-size:12px; color:var(--slate-muted); margin-top:2px; }
  .ca-section .grow{ flex:1; }
  .ca-box{ border:1px solid var(--border); border-radius:12px; overflow:hidden; }
  .ca-tbl{ width:100%; border-collapse:collapse; }
  .ca-tbl th{ background:#f4faf5; font-size:11px; text-transform:uppercase; letter-spacing:.5px; color:var(--slate-muted); font-weight:700; padding:11px 16px; text-align:left; }
  .ca-tbl td{ padding:9px 16px; border-top:1px solid #eef5f0; font-size:13.5px; color:var(--slate); }
  .ca-tbl .num{ text-align:right; font-variant-numeric:tabular-nums; }
  .ca-denom{ display:inline-block; min-width:92px; text-align:center; padding:5px 12px; border-radius:8px; background:var(--blue-pale); color:#0f6b31; font:700 13px 'Space Grotesk',sans-serif; }
  .ca-qty{ width:92px; padding:8px 10px; border:1px solid var(--border); border-radius:9px; text-align:center; font:600 14px 'Inter',sans-serif; color:var(--slate); background:#fff; transition:border-color .15s, box-shadow .15s; }
  .ca-qty:focus{ outline:none; border-color:var(--blue); box-shadow:0 0 0 3px rgba(22,163,74,0.16); }
  .ca-amt{ font:600 14px 'Space Grotesk',sans-serif; }
  tr.ca-zero .ca-amt{ color:#a3b8aa; font-weight:500; }
  tr.ca-total td{ background:var(--ink-navy); color:#fff; border-top:none; padding:15px 16px; font-weight:700; letter-spacing:.3px; }
  tr.ca-total .ca-big{ font:700 21px 'Space Grotesk',sans-serif; color:var(--blue-bright); }
  .ca-line{ display:flex; align-items:center; justify-content:space-between; gap:18px; padding:11px 16px; border-top:1px solid #eef5f0; font-size:13px; }
  .ca-line:first-child{ border-top:none; }
  .ca-line .lbl{ color:var(--slate); min-width:0; }
  .ca-line .lbl small{ display:block; font-size:11px; color:var(--slate-muted); margin-top:2px; font-weight:500; }
  .ca-line .amt{ font:600 13.5px 'Space Grotesk',sans-serif; white-space:nowrap; }
  .ca-line.neg .amt{ color:var(--danger); }
  .ca-line.sub{ background:var(--blue-pale); font-weight:700; }
  .ca-line.sub .amt{ color:#0f6b31; font-size:14.5px; }
  .ca-line.final{ background:var(--ink-navy); color:#fff; padding:15px 16px; }
  .ca-line.final .lbl{ color:#fff; font-weight:700; }
  .ca-line.final .lbl small{ color:#8fe3b0; }
  .ca-line.final .amt{ color:var(--blue-bright); font-size:18px; }
  .ca-line.info{ background:#fafcfb; }
  .ca-line.info .lbl, .ca-line.info .amt{ color:var(--slate-muted); }
  .ca-result{ display:grid; grid-template-columns:1fr 1fr 1fr; gap:12px; margin-top:14px; }
  .ca-stat{ background:#fff; border:1px solid var(--border); border-top:3px solid var(--blue); border-radius:12px; padding:14px 16px; box-shadow:var(--shadow-sm); }
  .ca-stat.ok{ border-top-color:var(--success); }
  .ca-stat.warn{ border-top-color:var(--warn); }
  .ca-stat.bad{ border-top-color:var(--danger); }
  .ca-stat .k{ font-size:11px; text-transform:uppercase; letter-spacing:.5px; font-weight:700; color:var(--slate-muted); }
  .ca-stat .v{ font:700 20px 'Space Grotesk',sans-serif; margin-top:7px; color:var(--slate); }
  .ca-stat .v small{ font-size:11px; font-weight:600; color:var(--slate-muted); }
  .ca-stat .badge{ font-size:12.5px; padding:5px 13px; margin-top:6px; }
  .ca-stats{ display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:14px; }
  .ca-alert{ font-size:12px; color:var(--danger); font-weight:600; background:var(--danger-pale); border-radius:9px; padding:9px 12px; margin-bottom:12px; }
  .ca-empty{ font-size:12.5px; color:var(--slate-muted); padding:16px; border:1px dashed var(--border); border-radius:12px; text-align:center; background:#fbfdfb; }
  .ca-remarks{ margin-top:26px; }
  .ca-remarks label{ font-size:11.5px; text-transform:uppercase; letter-spacing:.5px; }
  @media (max-width:640px){
    .ca-banner{ flex-direction:column; align-items:flex-start; padding:18px 20px; }
    .ca-banner-text{ text-align:left; border-left:none; padding-left:0; }
    .ca-body{ padding:4px 16px 20px; }
    .ca-meta, .ca-result, .ca-stats{ grid-template-columns:1fr; }
    .ca-tbl th, .ca-tbl td{ padding-left:10px; padding-right:10px; }
  }
</style>`;

function caToolbarHtml() {
  const ico = (path) => `<svg width="15" height="15" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${path}</svg>`;
  return `
    <div class="ca-toolbar">
      <button class="btn btn-secondary btn-sm" id="ca-opening-btn">${ico('<rect x="3" y="5" width="14" height="10" rx="1.8"/><circle cx="10" cy="10" r="2.2"/>')} Opening Cash</button>
      <button class="btn btn-secondary btn-sm" id="ca-deposit-btn">${ico('<path d="M3 8 10 4l7 4M5 8v6m4-6v6m2-6v6m4-6v6M3 16h14"/>')} Bank Deposit</button>
      <button class="btn btn-secondary btn-sm" id="ca-history-btn">${ico('<circle cx="10" cy="10" r="7"/><path d="M10 6v4l2.5 1.5"/>')} History</button>
      <button class="btn btn-secondary btn-sm" id="ca-download-btn">${ico('<path d="M10 3v10m0 0-3.5-3.5M10 13l3.5-3.5M4 16h12"/>')} Download PDF</button>
    </div>
  `;
}

function caSectionHead(letter, name, desc, rightHtml = '') {
  return `
    <div class="ca-section">
      <div class="letter">${letter}</div>
      <div><div class="name">${name}</div><div class="desc">${desc}</div></div>
      <div class="grow"></div>${rightHtml}
    </div>`;
}

function caDocumentHtml() {
  const total = caPartATotal();
  return CA_STYLE + `
    <div class="ca-sheet">
      <div class="ca-banner">
        <img src="../assets/logo-header.png" alt="MABUMBATECH">
        <div class="ca-banner-text">
          <div class="ca-banner-title">DAILY CASH CLOSING &amp;<br>SALES RECONCILIATION SHEET</div>
          <div class="ca-banner-sub">Count the cash, compare it with expected sales, record the bank deposit</div>
        </div>
      </div>

      <div class="ca-body">
        <div class="ca-meta">
          <div class="field"><label>Date</label><input type="date" id="ca-date" value="${CA.date}"></div>
          <div class="field"><label>Shift (optional)</label><input type="text" id="ca-shift" value="${escapeHtml(CA.shift)}" placeholder="e.g. Morning"></div>
          <div class="field"><label>Cashier</label><input type="text" id="ca-cashier" value="${escapeHtml(CA.cashier)}"></div>
        </div>

        ${caSectionHead('A', 'Cash Count', 'Enter how many notes or coins of each denomination are in the till')}
        <div class="ca-box">
          <table class="ca-tbl">
            <thead><tr><th>Denomination (TZS)</th><th style="text-align:center;">Quantity</th><th class="num">Amount (TZS)</th></tr></thead>
            <tbody>
              ${DENOMS.map(d => `
                <tr class="${(CA.denomQty[d] || 0) ? '' : 'ca-zero'}">
                  <td><span class="ca-denom">${d.toLocaleString('en-US')}</span></td>
                  <td style="text-align:center;"><input type="number" class="ca-qty" id="ca-qty-${d}" data-denom="${d}" min="0" step="1" value="${CA.denomQty[d] || 0}"></td>
                  <td class="num ca-amt" data-denom-amt="${d}">${((CA.denomQty[d] || 0) * d).toLocaleString('en-US')}</td>
                </tr>`).join('')}
              <tr class="ca-total"><td>TOTAL CASH IN HAND</td><td></td><td class="num ca-big" id="ca-total-counted">${total.toLocaleString('en-US')}</td></tr>
            </tbody>
          </table>
        </div>

        ${caSectionHead('B', 'Sales Reconciliation', 'What the till should hold, based on today\'s recorded sales and expenses')}
        <div id="ca-partb">
          <div class="loading-row"><span class="spinner"></span> Loading…</div>
        </div>

        ${caSectionHead('C', 'Bank Deposit &amp; Remaining Cash', 'Cash taken to the bank and what is left in hand', '<button type="button" class="btn btn-secondary btn-sm" id="ca-partc-record-btn">+ Record Bank Deposit</button>')}
        <div id="ca-partc">
          <div class="loading-row"><span class="spinner"></span> Loading…</div>
        </div>

        <div class="field ca-remarks"><label>Remarks (optional)</label><textarea id="ca-remarks" rows="2" placeholder="Anything worth noting about today's closing…">${escapeHtml(CA.remarks)}</textarea></div>
      </div>
    </div>
  `;
}

function caPartBHtml() {
  const s = CA.summary;
  if (!s) return `<div class="empty-state">Could not load.</div>`;
  const line = (label, amt, kind = '', hint = '') => `
    <div class="ca-line ${kind}">
      <div class="lbl">${label}${hint ? `<small>${hint}</small>` : ''}</div>
      <div class="amt">${caTzs(amt)}</div>
    </div>`;
  let rows = '';
  rows += line('Opening Cash (Physical Float)', s.opening_float);
  rows += line('Cash In Hand Sales', s.cash_physical_sales);
  (s.electronic_sales || []).forEach(e => { rows += line('Electronic Sales — ' + escapeHtml(e.method), e.amount); });
  if (!s.electronic_sales || !s.electronic_sales.length) rows += line('Electronic Sales', 0, '', 'Mixx by Yas / M-Pesa / Bank');
  rows += line('Credit Sales', s.credit_sales);
  rows += line('Total Sales', s.total_sales, 'sub', 'Cash In Hand Sales + Electronic Sales + Credit Sales');
  rows += line('Less: Credit Sales', -s.credit_sales, 'neg', 'Owed by customers');
  rows += line('Add: Cash Collections from Previous Credit Sales', s.credit_collected_today, '', 'Received on this date');
  rows += line('Cash Available', s.cash_available, 'sub', 'Opening Cash + Total Sales \u2212 Credit Sales + Cash Collected');
  rows += line('Outstanding Customer Change', s.outstanding_customer_change, '', 'Owed to customers');
  rows += line('Expenses', s.expenses_total);
  rows += line('Expected Closing Cash', s.expected_cash, 'final', '(Cash Available + Outstanding Customer Change) \u2212 Expenses');
  rows += line('Outstanding Customer Receivables', s.outstanding_receivables, 'info', 'Unpaid balances; excluded from Expected Closing Cash');

  const counted = caPartATotal();
  const variance = counted - s.expected_cash;
  const balanced = Math.abs(variance) < 1;
  const vLabel = balanced ? 'Balanced' : (variance < 0 ? `Short ${caTzs(Math.abs(variance))}` : `Over ${caTzs(variance)}`);
  const vClass = balanced ? 'success' : (variance < 0 ? 'danger' : 'warn');

  return `
    <div class="ca-box">${rows}</div>
    <div class="ca-result">
      <div class="ca-stat"><div class="k">Cash Counted (Part A)</div><div class="v" id="ca-var-counted">${caTzs(counted)}</div></div>
      <div class="ca-stat"><div class="k">Expected Closing Cash</div><div class="v">${caTzs(s.expected_cash)}</div></div>
      <div class="ca-stat ${balanced ? 'ok' : (variance < 0 ? 'bad' : 'warn')}" id="ca-var-card"><div class="k">Result</div><div><span class="badge ${vClass}">${vLabel}</span></div></div>
    </div>`;
}

function caPartCHtml() {
  const dep = CA.deposits;
  if (!dep) return `<div class="empty-state">Could not load bank deposits.</div>`;
  const counted = caPartATotal();
  const deposited = Number(dep.already_deposited) || 0;
  const remaining = counted - deposited;
  const over = remaining < 0;
  const list = dep.deposits || [];
  const tz = (n) => `${money(n)} <small>TZS</small>`;

  const cards = `
    <div class="ca-stats">
      <div class="ca-stat"><div class="k">Total Cash Counted</div><div class="v">${tz(counted)}</div></div>
      <div class="ca-stat ok"><div class="k">Deposited to Bank</div><div class="v">${tz(deposited)}</div></div>
      <div class="ca-stat ${over ? 'bad' : 'warn'}"><div class="k">Remaining Cash</div><div class="v">${tz(remaining)}</div></div>
    </div>
    ${over ? `<div class="ca-alert">Deposits recorded for this date are more than the cash counted in Part A. Check the cash count or the deposit amounts.</div>` : ''}`;

  const table = list.length ? `
    <div class="ca-box">
      <table class="ca-tbl">
        <thead><tr><th>Time</th><th>Saved By</th><th class="num">Deposited</th><th class="num">Remaining After</th><th>Remark</th></tr></thead>
        <tbody>
          ${list.map(d => `<tr>
            <td>${new Date(d.created_at).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}</td>
            <td>${escapeHtml(d.created_by_name || '—')}</td>
            <td class="num ca-amt">${money(d.deposit_amount)}</td>
            <td class="num">${money(d.remaining_cash)}</td>
            <td style="color:var(--slate-muted);">${escapeHtml(d.remark || '—')}</td>
          </tr>`).join('')}
          <tr class="ca-total">
            <td colspan="2">TOTAL DEPOSITED</td>
            <td class="num">${money(deposited)}</td>
            <td class="num">${money(remaining)}</td>
            <td></td>
          </tr>
        </tbody>
      </table>
    </div>` : `<div class="ca-empty">No bank deposit recorded for ${fmtDate(CA.date)} yet — the full ${money(counted)} TZS counted is still in hand.</div>`;

  return cards + table;
}

function caRenderPartC() {
  const box = document.getElementById('ca-partc');
  if (box) box.innerHTML = caPartCHtml();
}

async function caRefreshDeposits() {
  const box = document.getElementById('ca-partc');
  if (box) box.innerHTML = `<div class="loading-row"><span class="spinner"></span> Loading…</div>`;
  try {
    CA.deposits = await api.get('api/bank_deposits.php', { date: CA.date });
  } catch (e) {
    CA.deposits = null;
  }
  caRenderPartC();
}

async function caRefreshSummary() {
  const body = document.getElementById('ca-partb');
  if (body) body.innerHTML = `<div class="loading-row"><span class="spinner"></span> Loading…</div>`;
  try {
    CA.summary = await api.get('api/cash_audits.php', { action: 'summary', date: CA.date });
  } catch (e) {
    CA.summary = null;
    if (body) { body.innerHTML = `<div class="empty-state">${escapeHtml(e.message)}</div>`; return; }
  }
  if (body) body.innerHTML = caPartBHtml();
}

function wireCaDocumentEvents() {
  document.querySelectorAll('.ca-qty').forEach(inp => inp.addEventListener('input', () => {
    const d = inp.dataset.denom;
    // Keep the shared state in step with what's typed, otherwise the total
    // (which is computed from CA.denomQty) stays at its old value.
    CA.denomQty[d] = Math.max(0, Number(inp.value) || 0);
    document.querySelector(`[data-denom-amt="${d}"]`).textContent = ((Number(inp.value) || 0) * Number(d)).toLocaleString('en-US');
    const rowEl = inp.closest('tr');
    if (rowEl) rowEl.classList.toggle('ca-zero', !(Number(inp.value) > 0));
    document.getElementById('ca-total-counted').textContent = caPartATotal().toLocaleString('en-US');
    caRenderPartC();
    const vEl = document.querySelector('#ca-partb .badge');
    if (vEl && CA.summary) {
      const variance = caPartATotal() - CA.summary.expected_cash;
      vEl.textContent = Math.abs(variance) < 1 ? 'Balanced' : (variance < 0 ? `Short ${caTzs(Math.abs(variance))}` : `Over ${caTzs(variance)}`);
      vEl.className = 'badge ' + (Math.abs(variance) < 1 ? 'success' : (variance < 0 ? 'danger' : 'warn'));
      const card = document.getElementById('ca-var-card');
      if (card) card.className = 'ca-stat ' + (Math.abs(variance) < 1 ? 'ok' : (variance < 0 ? 'bad' : 'warn'));
    }
    const cEl = document.getElementById('ca-var-counted');
    if (cEl) cEl.textContent = caTzs(caPartATotal());
  }));
  document.getElementById('ca-date').addEventListener('change', async () => {
    caSyncFormToState();
    CA.savedId = null;
    await caRefreshSummary();
    await caRefreshDeposits();
  });

  document.getElementById('ca-opening-btn').addEventListener('click', () => { caSyncFormToState(); openOpeningCashModal(); });
  document.getElementById('ca-deposit-btn').addEventListener('click', () => { caSyncFormToState(); openBankDepositModal(); });
  document.getElementById('ca-history-btn').addEventListener('click', () => { caSyncFormToState(); openCashAuditHistoryModal(); });
  document.getElementById('ca-partc-record-btn').addEventListener('click', () => { caSyncFormToState(); openBankDepositModal(); });
  document.getElementById('ca-download-btn').addEventListener('click', () => {
    if (!CA.savedId) { toast('Save the cash audit first, then download it.', 'error'); return; }
    window.open(`../../Backend/api/cash_audit_pdf.php?id=${CA.savedId}`, '_blank');
  });
  document.getElementById('ca-save-btn').addEventListener('click', caSaveAudit);
}

async function caSaveAudit() {
  caSyncFormToState();
  if (!CA.cashier.trim()) { toast('Enter the cashier name.', 'error'); return; }
  const btn = document.getElementById('ca-save-btn');
  btn.disabled = true; btn.textContent = 'Saving…';
  try {
    const denominations = {};
    DENOMS.forEach(d => { denominations[d] = { qty: Number(CA.denomQty[d]) || 0 }; });
    const result = await api.post('api/cash_audits.php', {
      audit_date: CA.date,
      shift: CA.shift,
      cashier_name: CA.cashier,
      denominations,
      remarks: CA.remarks,
      prepared_by_name: CA.cashier,
    });
    CA.savedId = result.id;
    toast('Cash audit saved.', 'success');
  } catch (e) {
    toast(e.message, 'error');
  } finally {
    btn.disabled = false; btn.textContent = 'Save Cash Audit';
  }
}

/* =========================== Opening Cash & Float =========================== */

async function openOpeningCashModal() {
  const modal = openModal({
    title: 'Opening Cash &amp; Float',
    bodyHtml: `
      <div style="display:flex; justify-content:flex-end; margin:0 0 14px;">
        <button class="btn btn-secondary btn-sm" id="oc-history-btn">
          <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="4" width="14" height="13" rx="1.6"/><path d="M3 8h14"/></svg>
          History
        </button>
      </div>
      <div class="field"><label>Date</label><input type="date" id="oc-date" value="${CA.date}"></div>
      <div id="oc-stats" style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin:14px 0;"></div>
      <div id="oc-note" style="font-size:12.5px; padding:10px 12px; border-radius:8px; margin-bottom:14px;"></div>
      <div id="oc-entries" style="font-size:13px; color:var(--slate-muted); margin-bottom:16px;"></div>
      <hr style="border:none; border-top:1px solid var(--border); margin:0 0 16px;">
      <div class="field"><label>What happened?</label>
        <select id="oc-type">
          <option value="opening">Set opening cash (what the day started with)</option>
          <option value="add">Add to float</option>
          <option value="reduce">Reduce float</option>
        </select>
      </div>
      <div class="field"><label>Amount (TZS)</label><input type="number" id="oc-amount" min="0" value="0"></div>
      <div class="field"><label>Note (optional)</label><input type="text" id="oc-note-input" placeholder="e.g. change added, cash taken to bank"></div>
    `,
    footHtml: `<button class="btn btn-secondary" id="oc-close-btn">Close</button><button class="btn btn-primary" id="oc-save-btn">Save</button>`,
  });
  caBackable(modal, () => renderCashAuditModal());
  modal.querySelector('#oc-close-btn').addEventListener('click', () => renderCashAuditModal());
  modal.querySelector('#oc-history-btn').addEventListener('click', () => openFloatHistoryModal());
  modal.querySelector('#oc-date').addEventListener('change', (e) => { CA.date = e.target.value; loadOpeningCashPanel(); });
  modal.querySelector('#oc-save-btn').addEventListener('click', saveOpeningCashEntry);
  await loadOpeningCashPanel();
}

async function loadOpeningCashPanel() {
  const stats = document.getElementById('oc-stats');
  const note = document.getElementById('oc-note');
  const entriesBox = document.getElementById('oc-entries');
  stats.innerHTML = `<div class="loading-row"><span class="spinner"></span></div>`;
  try {
    const st = await api.get('api/cash_float.php', { date: document.getElementById('oc-date').value });
    stats.innerHTML = `
      <div class="metric-mini"><div class="label">Opening cash (automatic)</div><div class="amount">${money(st.opening_effective)}</div></div>
      <div class="metric-mini success"><div class="label">Added</div><div class="amount">+${money(st.added)}</div></div>
      <div class="metric-mini danger"><div class="label">Reduced</div><div class="amount">-${money(st.reduced)}</div></div>
      <div class="metric-mini warn"><div class="label">Current float</div><div class="amount">${money(st.current_float)}</div></div>
    `;
    if (st.opening_manual === null && st.opening_auto_from_date) {
      note.style.background = 'var(--warn-pale)'; note.style.color = '#a97418';
      note.innerHTML = `<strong>Automatic:</strong> brought forward from the Remaining Cash of ${fmtDate(st.opening_auto_from_date)} (${money(st.opening_auto)} TZS). You can change it by setting the opening cash below.`;
    } else if (st.opening_manual !== null) {
      note.style.background = 'var(--blue-pale)'; note.style.color = 'var(--blue)';
      note.innerHTML = `Opening cash for this date was set by hand: <strong>${money(st.opening_manual)} TZS</strong>.`;
    } else {
      note.style.background = 'var(--border)'; note.style.color = 'var(--slate-muted)';
      note.innerHTML = `No earlier bank deposit on record yet — set the opening cash below to get started.`;
    }
    const activeEntries = st.entries.filter(e => !(e.type === 'opening' && e.replaced));
    entriesBox.textContent = activeEntries.length ? '' : 'Nothing recorded for this date yet.';
  } catch (e) {
    stats.innerHTML = `<div class="empty-state">${escapeHtml(e.message)}</div>`;
  }
}

async function saveOpeningCashEntry() {
  const entry_date = document.getElementById('oc-date').value;
  const type = document.getElementById('oc-type').value;
  const amount = Number(document.getElementById('oc-amount').value);
  const note = document.getElementById('oc-note-input').value.trim();
  if (!amount || amount <= 0) { toast('Enter an amount greater than zero.', 'error'); return; }
  const btn = document.getElementById('oc-save-btn');
  btn.disabled = true;
  try {
    await api.post('api/cash_float.php', { entry_date, type, amount, note });
    toast('Saved.', 'success');
    document.getElementById('oc-amount').value = 0;
    document.getElementById('oc-note-input').value = '';
    if (entry_date === CA.date) CA.summary = null;
    await loadOpeningCashPanel();
  } catch (e) {
    toast(e.message, 'error');
  } finally {
    btn.disabled = false;
  }
}

/* =========================== Opening Cash History =========================== */

async function openFloatHistoryModal() {
  const modal = openModal({
    title: 'Opening Cash — History',
    large: true,
    bodyHtml: `
      <div class="toolbar">
        <div class="field" style="margin:0;"><label style="font-size:11px;">From</label><input type="date" id="fh-start"></div>
        <div class="field" style="margin:0;"><label style="font-size:11px;">To</label><input type="date" id="fh-end"></div>
        <div class="field" style="margin:0;"><label style="font-size:11px;">Type</label>
          <select id="fh-type" class="filter-select">
            <option value="">All records</option><option value="opening">Opening</option><option value="add">Added</option><option value="reduce">Reduced</option>
          </select>
        </div>
        <div class="field" style="margin:0;"><label style="font-size:11px;">Saved By</label><select id="fh-by" class="filter-select"><option value="">Everyone</option></select></div>
        <button class="btn btn-secondary btn-sm" id="fh-filter-btn" style="align-self:flex-end;">Filter</button>
      </div>
      <div class="table-wrap"><table>
        <thead><tr><th>Date</th><th>Type</th><th>Amount</th><th>Note</th><th>Saved By</th><th>Saved At</th><th>Actions</th></tr></thead>
        <tbody id="fh-body"><tr><td colspan="7" class="loading-row"><span class="spinner"></span></td></tr></tbody>
      </table></div>
    `,
    footHtml: `<button class="btn btn-secondary" id="fh-close-btn">Close</button>`,
  });
  caBackable(modal, () => openOpeningCashModal());
  modal.querySelector('#fh-close-btn').addEventListener('click', () => openOpeningCashModal());
  modal.querySelector('#fh-filter-btn').addEventListener('click', loadFloatHistory);
  await loadFloatHistory();
}

async function loadFloatHistory() {
  const body = document.getElementById('fh-body');
  body.innerHTML = `<tr><td colspan="7" class="loading-row"><span class="spinner"></span></td></tr>`;
  try {
    const start = document.getElementById('fh-start').value;
    const end = document.getElementById('fh-end').value;
    const by = document.getElementById('fh-by').value;
    let rows = await api.get('api/cash_float.php', { start, end, by });
    caPopulateSavedByOptions('fh-by', rows);
    const typeFilter = document.getElementById('fh-type').value;
    if (typeFilter) rows = rows.filter(r => r.type === typeFilter);
    body.innerHTML = rows.length ? rows.map(e => `
      <tr style="${e.replaced ? 'opacity:0.55;' : ''}">
        <td>${fmtDate(e.entry_date)}</td>
        <td><span class="badge ${e.type === 'opening' ? 'blue' : (e.type === 'add' ? 'success' : 'danger')}">${e.type === 'opening' ? 'Opening' : (e.type === 'add' ? 'Added' : 'Reduced')}</span>${e.replaced ? ' <span class="badge neutral">Replaced</span>' : ''}</td>
        <td style="${e.replaced ? 'text-decoration:line-through;' : ''}">${e.type === 'reduce' ? '-' : (e.type === 'add' ? '+' : '')}${money(e.amount)}</td>
        <td>${escapeHtml(e.note || '—')}</td>
        <td>${escapeHtml(e.created_by_name)}</td>
        <td>${new Date(e.created_at).toLocaleString('en-GB', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' })}</td>
        <td><button class="btn btn-secondary btn-sm" onclick="deleteFloatEntry(${e.id})">Delete</button></td>
      </tr>`).join('') : `<tr><td colspan="7" class="empty-state">No entries found.</td></tr>`;
  } catch (e) {
    body.innerHTML = `<tr><td colspan="7" class="empty-state">${escapeHtml(e.message)}</td></tr>`;
  }
}

async function deleteFloatEntry(id) {
  const ok = await confirmDialog('Delete this float entry? This cannot be undone.', { confirmLabel: 'Delete' });
  if (ok) {
    try {
      await api.del('api/cash_float.php', { id });
      toast('Deleted.', 'success');
      CA.summary = null;
    } catch (e) {
      toast(e.message, 'error');
    }
  }
  openFloatHistoryModal();   // the confirm dialog replaced it — put it back
}

function caPopulateSavedByOptions(selectId, rows) {
  const sel = document.getElementById(selectId);
  if (!sel || sel.dataset.populated) return;
  const names = [...new Set(rows.map(r => r.created_by_name).filter(Boolean))].sort();
  names.forEach(n => { const o = document.createElement('option'); o.value = n; o.textContent = n; sel.appendChild(o); });
  sel.dataset.populated = '1';
}

/* =========================== Bank Deposit & Closing Cash =========================== */

async function openBankDepositModal() {
  const modal = openModal({
    title: 'Bank Deposit &amp; Closing Cash',
    bodyHtml: `
      <div style="display:flex; justify-content:flex-end; margin:0 0 10px;">
        <button class="btn btn-secondary btn-sm" id="bd-history-btn">
          <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="4" width="14" height="13" rx="1.6"/><path d="M3 8h14"/></svg>
          History
        </button>
      </div>
      <p style="font-size:12.5px; color:var(--slate-muted); margin:0 0 12px;">Deposit for ${fmtDate(CA.date)} &middot; recorded under your name</p>
      <div id="bd-stats" style="display:grid; grid-template-columns:repeat(4,1fr); gap:10px; margin-bottom:14px;"></div>
      <p style="font-size:12.5px; color:var(--danger); font-weight:600; margin:0 0 16px;">Count the cash in Part A of the Cash Audit first — the bank deposit is taken out of the cash counted.</p>
      <div class="field"><label>Bank Deposit (TZS)</label><input type="number" id="bd-amount" min="0" value="0"></div>
      <div class="field"><label>Bank Deposit Remark (optional)</label><textarea id="bd-remark" rows="2" placeholder="e.g. Deposited today's excess cash to the boss's bank account."></textarea></div>
      <div id="bd-existing" style="font-size:12.5px; color:var(--slate-muted);"></div>
    `,
    footHtml: `<button class="btn btn-secondary" id="bd-close-btn">Close</button><button class="btn btn-primary" id="bd-save-btn">Save Bank Deposit</button>`,
  });
  caBackable(modal, () => renderCashAuditModal());
  modal.querySelector('#bd-close-btn').addEventListener('click', () => renderCashAuditModal());
  modal.querySelector('#bd-history-btn').addEventListener('click', () => openBankDepositHistoryModal());
  modal.querySelector('#bd-amount').addEventListener('input', renderBankDepositStats);
  modal.querySelector('#bd-save-btn').addEventListener('click', saveBankDeposit);
  await loadBankDepositPanel();
}

let BD_ALREADY_DEPOSITED = 0;

async function loadBankDepositPanel() {
  const box = document.getElementById('bd-existing');
  try {
    const r = await api.get('api/bank_deposits.php', { date: CA.date });
    BD_ALREADY_DEPOSITED = r.already_deposited;
    box.textContent = r.deposits.length
      ? `${r.deposits.length} deposit(s) already recorded for this date, totalling ${money(r.already_deposited)} TZS.`
      : 'No bank deposit recorded for this date yet.';
  } catch (e) {
    BD_ALREADY_DEPOSITED = 0;
    box.textContent = '';
  }
  renderBankDepositStats();
}

function renderBankDepositStats() {
  if (!document.getElementById('bd-amount')) return;   // window already left
  const totalCash = caPartATotal();
  const thisDeposit = Number(document.getElementById('bd-amount').value) || 0;
  const remaining = totalCash - BD_ALREADY_DEPOSITED - thisDeposit;
  document.getElementById('bd-stats').innerHTML = `
    <div class="metric-mini"><div class="label">Total Cash</div><div class="amount">${money(totalCash)}</div></div>
    <div class="metric-mini"><div class="label">Already deposited</div><div class="amount">${money(BD_ALREADY_DEPOSITED)}</div></div>
    <div class="metric-mini"><div class="label">This deposit</div><div class="amount">${money(thisDeposit)}</div></div>
    <div class="metric-mini warn"><div class="label">Remaining Cash</div><div class="amount">${money(remaining)}</div></div>
  `;
}

async function saveBankDeposit() {
  const deposit_amount = Number(document.getElementById('bd-amount').value);
  if (!deposit_amount || deposit_amount <= 0) { toast('Enter a deposit amount greater than zero.', 'error'); return; }
  const btn = document.getElementById('bd-save-btn');
  btn.disabled = true;
  try {
    await api.post('api/bank_deposits.php', {
      deposit_date: CA.date, total_cash: caPartATotal(), deposit_amount,
      remark: document.getElementById('bd-remark').value.trim(),
    });
    toast('Bank deposit saved.', 'success');
    document.getElementById('bd-amount').value = 0;
    document.getElementById('bd-remark').value = '';
    await loadBankDepositPanel();
  } catch (e) {
    toast(e.message, 'error');
  } finally {
    btn.disabled = false;
  }
}

/* =========================== Bank Deposit History =========================== */

async function openBankDepositHistoryModal() {
  const modal = openModal({
    title: 'Bank Deposits — History',
    large: true,
    bodyHtml: `
      <div class="toolbar">
        <div class="field" style="margin:0;"><label style="font-size:11px;">From</label><input type="date" id="bh-start"></div>
        <div class="field" style="margin:0;"><label style="font-size:11px;">To</label><input type="date" id="bh-end"></div>
        <div class="field" style="margin:0;"><label style="font-size:11px;">Saved By</label><select id="bh-by" class="filter-select"><option value="">Everyone</option></select></div>
        <button class="btn btn-secondary btn-sm" id="bh-filter-btn" style="align-self:flex-end;">Filter</button>
      </div>
      <div class="table-wrap"><table>
        <thead><tr><th>Date</th><th>Time</th><th>Saved By</th><th>Total Cash</th><th>Bank Deposit</th><th>Remaining Cash</th><th>Deposit Remark</th><th>Actions</th></tr></thead>
        <tbody id="bh-body"><tr><td colspan="8" class="loading-row"><span class="spinner"></span></td></tr></tbody>
      </table></div>
    `,
    footHtml: `<button class="btn btn-secondary" id="bh-close-btn">Close</button>`,
  });
  caBackable(modal, () => openBankDepositModal());
  modal.querySelector('#bh-close-btn').addEventListener('click', () => openBankDepositModal());
  modal.querySelector('#bh-filter-btn').addEventListener('click', loadBankDepositHistory);
  await loadBankDepositHistory();
}

async function loadBankDepositHistory() {
  const body = document.getElementById('bh-body');
  body.innerHTML = `<tr><td colspan="8" class="loading-row"><span class="spinner"></span></td></tr>`;
  try {
    const start = document.getElementById('bh-start').value;
    const end = document.getElementById('bh-end').value;
    const by = document.getElementById('bh-by').value;
    const rows = await api.get('api/bank_deposits.php', { start, end, by });
    caPopulateSavedByOptions('bh-by', rows);
    body.innerHTML = rows.length ? rows.map(d => `
      <tr>
        <td>${fmtDate(d.deposit_date)}</td>
        <td>${new Date(d.created_at).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}</td>
        <td>${escapeHtml(d.created_by_name)}</td>
        <td>${money(d.total_cash)}</td>
        <td class="pname">${money(d.deposit_amount)}</td>
        <td>${money(d.remaining_cash)}</td>
        <td>${escapeHtml(d.remark || '—')}</td>
        <td><button class="btn btn-secondary btn-sm" onclick="deleteBankDeposit(${d.id})">Delete</button></td>
      </tr>`).join('') : `<tr><td colspan="8" class="empty-state">No bank deposits found.</td></tr>`;
  } catch (e) {
    body.innerHTML = `<tr><td colspan="8" class="empty-state">${escapeHtml(e.message)}</td></tr>`;
  }
}

async function deleteBankDeposit(id) {
  const ok = await confirmDialog('Delete this bank deposit record? This cannot be undone.', { confirmLabel: 'Delete' });
  if (ok) {
    try {
      await api.del('api/bank_deposits.php', { id });
      toast('Deleted.', 'success');
    } catch (e) {
      toast(e.message, 'error');
    }
  }
  openBankDepositHistoryModal();   // the confirm dialog replaced it — put it back
}

/* =========================== Cash Audit History =========================== */

async function openCashAuditHistoryModal() {
  const modal = openModal({
    title: 'Cash Audit — History',
    large: true,
    bodyHtml: `
      <div class="toolbar">
        <div class="field" style="margin:0;"><label style="font-size:11px;">From</label><input type="date" id="ah-start"></div>
        <div class="field" style="margin:0;"><label style="font-size:11px;">To</label><input type="date" id="ah-end"></div>
        <div class="field" style="margin:0;"><label style="font-size:11px;">Saved By</label><select id="ah-by" class="filter-select"><option value="">Everyone</option></select></div>
        <button class="btn btn-secondary btn-sm" id="ah-filter-btn" style="align-self:flex-end;">Filter</button>
      </div>
      <div class="table-wrap"><table>
        <thead><tr><th>Date</th><th>Cashier</th><th>Shift</th><th>Total Cash</th><th>Expected</th><th>Variance</th><th>Bank Deposit</th><th>Remaining Cash</th><th>Remarks</th><th>Actions</th></tr></thead>
        <tbody id="ah-body"><tr><td colspan="10" class="loading-row"><span class="spinner"></span></td></tr></tbody>
      </table></div>
    `,
    footHtml: `<button class="btn btn-secondary" id="ah-close-btn">Close</button>`,
  });
  caBackable(modal, () => renderCashAuditModal());
  modal.querySelector('#ah-close-btn').addEventListener('click', () => renderCashAuditModal());
  modal.querySelector('#ah-filter-btn').addEventListener('click', loadCashAuditHistory);
  await loadCashAuditHistory();
}

async function loadCashAuditHistory() {
  const body = document.getElementById('ah-body');
  body.innerHTML = `<tr><td colspan="10" class="loading-row"><span class="spinner"></span></td></tr>`;
  try {
    const start = document.getElementById('ah-start').value;
    const end = document.getElementById('ah-end').value;
    const by = document.getElementById('ah-by').value;
    const rows = await api.get('api/cash_audits.php', { start, end, by });
    caPopulateSavedByOptions('ah-by', rows);
    body.innerHTML = rows.length ? rows.map(r => {
      const variance = Number(r.variance);
      const vLabel = Math.abs(variance) < 1 ? 'Balanced' : (variance < 0 ? `Short ${money(Math.abs(variance))}` : `Over ${money(variance)}`);
      const vClass = Math.abs(variance) < 1 ? 'success' : (variance < 0 ? 'danger' : 'warn');
      return `<tr>
        <td>${fmtDate(r.audit_date)}</td><td>${escapeHtml(r.cashier_name)}</td><td>${escapeHtml(r.shift || '—')}</td>
        <td>${money(r.total_cash_counted)}</td><td>${money(r.expected_cash)}</td>
        <td><span class="badge ${vClass}">${vLabel}</span></td>
        <td>${money(r.bank_deposit_total || 0)}</td><td>${r.remaining_cash !== null ? money(r.remaining_cash) : '—'}</td>
        <td>${escapeHtml(r.remarks || '—')}</td>
        <td style="white-space:nowrap;">
          <button class="btn-icon" title="Download PDF" onclick="window.open('../../Backend/api/cash_audit_pdf.php?id=${r.id}','_blank')">
            <svg width="14" height="14" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M10 3v10m0 0-3.5-3.5M10 13l3.5-3.5M4 16h12"/></svg>
          </button>
        </td>
      </tr>`;
    }).join('') : `<tr><td colspan="10" class="empty-state">No saved cash audits found.</td></tr>`;
  } catch (e) {
    body.innerHTML = `<tr><td colspan="10" class="empty-state">${escapeHtml(e.message)}</td></tr>`;
  }
}
