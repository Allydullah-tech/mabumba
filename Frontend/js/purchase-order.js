/**
 * MABUMBATECH — Low-stock Purchase Order
 *
 * openPurchaseOrderModal() lists every type that is at or below its reorder
 * level. The person types how many of each they plan to buy, then downloads
 * a ready-to-print Purchase Order PDF containing only the items they chose.
 * Needs shell.js (openModal, toast, escapeHtml) and api.js (api, API_BASE).
 * Works for both administrators and workers — no buying prices are involved.
 */
async function openPurchaseOrderModal() {
  const modal = openModal({
    title: 'Purchase Order — Quantity to Buy',
    large: true,
    bodyHtml: `<div id="po-body" style="text-align:center; color:var(--slate-muted); padding:30px;">Loading low-stock items…</div>`,
  });

  let rows;
  try {
    rows = await api.get('api/product_variants.php', { names_only: 1, status: 'active', low_stock: 1 });
  } catch (err) {
    modal.querySelector('#po-body').textContent = err.message;
    return;
  }

  const body = modal.querySelector('#po-body');
  if (!rows.length) {
    body.innerHTML = `<div class="empty-state"><div class="t">Nothing is low on stock</div>All items are above their reorder level.</div>`;
    return;
  }

  // Out-of-stock first, then the lowest stock.
  rows.sort((a, b) => Number(a.stock_quantity) - Number(b.stock_quantity) || a.display_name.localeCompare(b.display_name));

  body.style.cssText = '';
  body.innerHTML = `
    <p style="margin:0 0 14px; font-size:13px; color:var(--slate-muted);">
      Enter how many of each item you plan to buy. Only items with a quantity go on the Purchase Order.
    </p>
    <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
      <div id="po-summary" style="font-size:12.5px; font-weight:600; color:var(--slate);">Nothing selected yet</div>
      <div style="display:flex; gap:8px;">
        <button class="btn btn-secondary btn-sm" id="po-suggest" type="button">Fill suggested</button>
        <button class="btn btn-secondary btn-sm" id="po-clear" type="button">Clear</button>
      </div>
    </div>
    <div style="max-height:52vh; overflow-y:auto; border:1px solid var(--border); border-radius:12px;">
      <table style="width:100%; table-layout:fixed;">
        <thead style="position:sticky; top:0; background:var(--white); z-index:1;">
          <tr>
            <th>Item</th>
            <th style="width:76px;">In stock</th>
            <th style="width:70px;">Reorder</th>
            <th style="width:112px;">Qty to buy</th>
          </tr>
        </thead>
        <tbody>
          ${rows.map(r => {
            const stock = Number(r.stock_quantity);
            return `<tr>
              <td style="word-wrap:break-word;">
                <div class="pname">${escapeHtml(r.display_name)}</div>
                <span class="badge ${stock <= 0 ? 'danger' : 'warn'}" style="margin-top:3px;">${stock <= 0 ? 'Out of stock' : 'Low stock'}</span>
              </td>
              <td>${stock} <span class="psub">${escapeHtml(r.unit || '')}</span></td>
              <td>${Number(r.reorder_level)}</td>
              <td>
                <input type="number" min="0" step="1" inputmode="numeric" placeholder="0" class="po-qty"
                  data-id="${r.id}" data-suggest="${Math.max(1, Number(r.reorder_level) * 2 - stock)}"
                  style="width:100%; padding:8px 10px; border:1.5px solid var(--border); border-radius:8px; font-size:14px; text-align:right;">
              </td>
            </tr>`;
          }).join('')}
        </tbody>
      </table>
    </div>
    <div style="display:flex; justify-content:flex-end; gap:10px; margin-top:16px;">
      <button class="btn btn-secondary" id="po-cancel" type="button">Cancel</button>
      <button class="btn btn-primary" id="po-download" type="button">
        <svg width="15" height="15" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.7"><path d="M10 3v10m0 0-4-4m4 4 4-4M4 16h12"/></svg>
        Download PDF
      </button>
    </div>
  `;

  const inputs = () => [...body.querySelectorAll('.po-qty')];
  const chosen = () => inputs()
    .map(i => ({ id: i.dataset.id, qty: Math.floor(Number(i.value)) }))
    .filter(x => x.qty > 0);

  const refreshSummary = () => {
    const list = chosen();
    const units = list.reduce((s, x) => s + x.qty, 0);
    body.querySelector('#po-summary').textContent = list.length
      ? `${list.length} item${list.length === 1 ? '' : 's'} selected · ${units} unit${units === 1 ? '' : 's'} to buy`
      : 'Nothing selected yet';
  };

  body.addEventListener('input', (e) => { if (e.target.classList.contains('po-qty')) refreshSummary(); });
  body.querySelector('#po-suggest').addEventListener('click', () => {
    inputs().forEach(i => { i.value = i.dataset.suggest; });
    refreshSummary();
  });
  body.querySelector('#po-clear').addEventListener('click', () => {
    inputs().forEach(i => { i.value = ''; });
    refreshSummary();
  });
  body.querySelector('#po-cancel').addEventListener('click', closeModal);
  body.querySelector('#po-download').addEventListener('click', () => {
    const list = chosen();
    if (!list.length) { toast('Enter a quantity for at least one item.', 'error'); return; }
    window.open(`${API_BASE}/api/purchase_order_pdf.php?items=${encodeURIComponent(list.map(x => `${x.id}:${x.qty}`).join(','))}`, '_blank');
    toast('Purchase Order downloading…', 'success');
  });
}
