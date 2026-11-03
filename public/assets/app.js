function hexRgba(hex, a) {
  const m = /^#?([0-9a-f]{6})$/i.exec(hex || '');
  if (!m) return 'rgba(91,159,212,' + a + ')';
  const n = parseInt(m[1], 16);
  return 'rgba(' + ((n >> 16) & 255) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
}

function fmtTick(t) {
  if (!t) return '';
  const d = new Date(String(t).replace(' ', 'T') + 'Z');
  if (isNaN(d.getTime())) return String(t).slice(5, 16);
  const mo = d.getUTCMonth() + 1;
  const day = d.getUTCDate();
  const hr = d.getUTCHours();
  return mo + '/' + day + ' ' + String(hr).padStart(2, '0') + 'h';
}

function drawChart(canvas, points, color) {
  if (!canvas) return;
  const dpr = window.devicePixelRatio || 1;
  const cssW = Math.max(canvas.clientWidth || 320, 160);
  const cssH = Math.max(canvas.clientHeight || 200, 140);
  canvas.width = Math.round(cssW * dpr);
  canvas.height = Math.round(cssH * dpr);
  const ctx = canvas.getContext('2d');
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  ctx.clearRect(0, 0, cssW, cssH);
  const vals = (points || []).map(p => p.v).filter(v => v !== null && v !== undefined);
  if (!vals.length) {
    ctx.fillStyle = '#8b9aab';
    ctx.font = '13px Segoe UI, sans-serif';
    ctx.fillText('no samples yet', 16, cssH / 2);
    return;
  }
  const pad = { l: 42, r: 12, t: 14, b: 28 };
  const min = Math.min(...vals);
  const max = Math.max(...vals);
  const padY = (max - min) * 0.12 || Math.abs(max) * 0.05 || 1;
  const y0 = min - padY;
  const y1 = max + padY;
  const span = (y1 - y0) || 1;
  const innerW = cssW - pad.l - pad.r;
  const innerH = cssH - pad.t - pad.b;
  const xAt = i => pad.l + (i / Math.max(points.length - 1, 1)) * innerW;
  const yAt = v => pad.t + (1 - (v - y0) / span) * innerH;

  ctx.strokeStyle = 'rgba(36,48,66,.9)';
  ctx.fillStyle = '#8b9aab';
  ctx.font = '11px Segoe UI, sans-serif';
  ctx.lineWidth = 1;
  for (let g = 0; g <= 4; g++) {
    const gv = y0 + (span * g) / 4;
    const y = yAt(gv);
    ctx.beginPath();
    ctx.moveTo(pad.l, y);
    ctx.lineTo(cssW - pad.r, y);
    ctx.stroke();
    ctx.textAlign = 'right';
    ctx.fillText(gv >= 100 ? gv.toFixed(0) : gv.toFixed(1), pad.l - 6, y + 4);
  }

  const coords = [];
  points.forEach((p, i) => {
    if (p.v === null || p.v === undefined) return;
    coords.push([xAt(i), yAt(p.v)]);
  });
  if (!coords.length) return;
  const stroke = color || '#5b9fd4';
  const grd = ctx.createLinearGradient(0, pad.t, 0, cssH - pad.b);
  grd.addColorStop(0, hexRgba(stroke, 0.32));
  grd.addColorStop(1, hexRgba(stroke, 0.02));
  ctx.beginPath();
  coords.forEach((c, i) => { if (i === 0) ctx.moveTo(c[0], c[1]); else ctx.lineTo(c[0], c[1]); });
  ctx.lineTo(coords[coords.length - 1][0], cssH - pad.b);
  ctx.lineTo(coords[0][0], cssH - pad.b);
  ctx.closePath();
  ctx.fillStyle = grd;
  ctx.fill();
  ctx.beginPath();
  coords.forEach((c, i) => { if (i === 0) ctx.moveTo(c[0], c[1]); else ctx.lineTo(c[0], c[1]); });
  ctx.strokeStyle = stroke;
  ctx.lineWidth = 2.25;
  ctx.lineJoin = 'round';
  ctx.lineCap = 'round';
  ctx.stroke();
  const last = coords[coords.length - 1];
  ctx.beginPath();
  ctx.arc(last[0], last[1], 3.5, 0, Math.PI * 2);
  ctx.fillStyle = stroke;
  ctx.fill();

  ctx.fillStyle = '#8b9aab';
  ctx.textAlign = 'left';
  const firstT = points[0] && points[0].t;
  const midT = points[Math.floor(points.length / 2)] && points[Math.floor(points.length / 2)].t;
  const lastT = points[points.length - 1] && points[points.length - 1].t;
  ctx.fillText(fmtTick(firstT), pad.l, cssH - 8);
  ctx.textAlign = 'center';
  ctx.fillText(fmtTick(midT), pad.l + innerW / 2, cssH - 8);
  ctx.textAlign = 'right';
  ctx.fillText(fmtTick(lastT), cssW - pad.r, cssH - 8);
}

async function loadSeries(id) {
  const r = await fetch('/api_series.php?id=' + encodeURIComponent(id));
  if (!r.ok) return;
  const data = await r.json();
  document.querySelectorAll('[data-series]').forEach(cv => {
    drawChart(cv, data[cv.dataset.series] || [], cv.dataset.color);
  });
}

function renderRank(el, title, rows, key, unit, color) {
  if (!el) return;
  const list = rows || [];
  const max = Math.max(...list.map(r => Number(r[key]) || 0), 1);
  let html = '<div class="dash-rank">';
  if (!list.length) html += '<p class="muted">No readings yet.</p>';
  list.forEach(row => {
    const v = row[key] != null ? row[key] : row[key.toUpperCase()];
    const name = row.name || row.NAME || 'IDF';
    const id = row.id || row.ID || 0;
    const pct = v == null || Number(v) === 0 ? 0 : Math.max(4, (Number(v) / max) * 100);
    const href = id ? '/idfs?group=' + encodeURIComponent(id) : '#';
    html += '<a class="dash-rank-row" href="' + href + '">';
    html += '<span class="dash-rank-name">' + name + '</span>';
    html += '<span class="dash-rank-track"><span class="dash-rank-fill" style="width:' + pct.toFixed(1) + '%;background:' + color + '"></span></span>';
    html += '<span class="dash-rank-val">' + (v == null ? '—' : Number(v).toFixed(1) + ' ' + unit) + '</span>';
    html += '</a>';
  });
  html += '</div>';
  el.innerHTML = html;
}

async function loadDashboard() {
  const r = await fetch('/api_dashboard.php');
  if (!r.ok) return;
  const data = await r.json();
  drawChart(document.getElementById('dash-power'), data.power || [], '#5b9fd4');
  drawChart(document.getElementById('dash-temp'), data.temp || [], '#ff7a45');
  drawChart(document.getElementById('dash-rh'), data.humid || [], '#3ddc97');
  renderRank(document.getElementById('dash-hot'), 'Hottest IDFs', data.hottest, 't', '°F', '#ff7a45');
  renderRank(document.getElementById('dash-pwr'), 'Highest power IDFs', data.power_top, 'w', 'W', '#5b9fd4');
}

function bindIdfPan() {
  document.querySelectorAll('[data-idf-pan]').forEach(vp => {
    let down = false, startX = 0, startScroll = 0;
    vp.addEventListener('mousedown', e => {
      down = true; startX = e.pageX; startScroll = vp.scrollLeft; vp.classList.add('is-pan');
    });
    window.addEventListener('mouseup', () => { down = false; vp.classList.remove('is-pan'); });
    window.addEventListener('mousemove', e => {
      if (!down) return;
      e.preventDefault();
      vp.scrollLeft = startScroll - (e.pageX - startX);
    });
  });
}

function bindTemplateFill() {
  document.querySelectorAll('[data-tpl-fill]').forEach(sel => {
    sel.addEventListener('change', () => {
      const o = sel.selectedOptions[0];
      if (!o || !sel.form) return;
      const f = sel.form;
      const set = (name, val) => { if (f[name] && val) f[name].value = val; };
      set('kind', o.dataset.kind);
      set('u_height', o.dataset.u);
      set('face', o.dataset.face);
      set('port_count', o.dataset.ports);
      set('manufacturer', o.dataset.mfr);
      set('model', o.dataset.model);
    });
  });
}

function bindLocTree() {
  const root = document.querySelector('[data-loc-tree]');
  if (!root) return;
  const key = 'ba-loc-open';
  const all = () => [...root.querySelectorAll('details[data-loc-id]')];
  try {
    const saved = JSON.parse(localStorage.getItem(key) || 'null');
    if (Array.isArray(saved)) {
      all().forEach(d => { d.open = saved.includes(d.dataset.locId); });
    }
  } catch (e) { /* keep markup defaults */ }
  const persist = () => {
    localStorage.setItem(key, JSON.stringify(all().filter(d => d.open).map(d => d.dataset.locId)));
  };
  root.addEventListener('toggle', persist, true);
  document.getElementById('loc-expand')?.addEventListener('click', () => {
    all().forEach(d => { d.open = true; });
    persist();
  });
  document.getElementById('loc-collapse')?.addEventListener('click', () => {
    all().forEach(d => { d.open = false; });
    persist();
  });
  const search = document.getElementById('loc-search');
  if (!search) return;
  const apply = () => {
    const q = search.value.trim().toLowerCase();
    const branches = all();
    const leaves = [...root.querySelectorAll('.loc-leaf')];
    if (!q) {
      branches.forEach(d => d.classList.remove('loc-hidden'));
      leaves.forEach(a => a.classList.remove('loc-hidden'));
      return;
    }
    leaves.forEach(a => {
      a.classList.toggle('loc-hidden', !(a.dataset.locName || '').includes(q));
    });
    branches.forEach(d => {
      const self = (d.dataset.locName || '').includes(q);
      if (self) {
        d.querySelectorAll('.loc-leaf, details').forEach(n => n.classList.remove('loc-hidden'));
      }
      const childHit = !!d.querySelector('.loc-leaf:not(.loc-hidden), details:not(.loc-hidden)');
      const show = self || childHit;
      d.classList.toggle('loc-hidden', !show);
      if (show) d.open = true;
    });
  };
  search.addEventListener('input', apply);
}

function baToast(title, message, type) {
  let host = document.getElementById('toast-host');
  if (!host) {
    host = document.createElement('div');
    host.id = 'toast-host';
    host.setAttribute('aria-live', 'polite');
    document.body.appendChild(host);
  }
  const el = document.createElement('div');
  el.className = 'ba-toast ba-toast-' + (type || 'info');
  el.setAttribute('role', 'status');
  const mark = document.createElement('span');
  mark.className = 'ba-toast-mark';
  mark.setAttribute('aria-hidden', 'true');
  const body = document.createElement('div');
  const heading = document.createElement('p');
  heading.className = 'ba-toast-title';
  heading.textContent = title || 'BackAisle';
  const msg = document.createElement('p');
  msg.className = 'ba-toast-msg';
  msg.textContent = message || '';
  body.appendChild(heading);
  if (message) body.appendChild(msg);
  const close = document.createElement('button');
  close.type = 'button';
  close.className = 'ba-toast-close';
  close.setAttribute('aria-label', 'Dismiss');
  close.textContent = '×';
  const dismiss = () => {
    el.classList.add('ba-toast-out');
    setTimeout(() => el.remove(), 350);
  };
  close.addEventListener('click', dismiss);
  el.append(mark, body, close);
  host.appendChild(el);
  setTimeout(dismiss, 5000);
}

async function pollNotify() {
  let since = 0;
  try { since = Number(sessionStorage.getItem('ba-notify-since') || 0); } catch (e) { since = 0; }
  const url = '/api_notify.php' + (since > 0 ? '?since_id=' + encodeURIComponent(since) : '');
  let data;
  try {
    const r = await fetch(url, { headers: { 'Accept': 'application/json' } });
    if (!r.ok) return;
    data = await r.json();
  } catch (e) {
    return;
  }
  if (!data || !Array.isArray(data.items)) return;
  let max = since;
  for (const item of data.items) {
    baToast(item.title, item.message, item.toast_type);
    if (item.id > max) max = item.id;
    const body = new URLSearchParams();
    body.set('action', 'mark_read');
    body.set('id', String(item.id));
    fetch('/api_notify.php', { method: 'POST', body }).catch(() => {});
  }
  if (max > since) {
    try { sessionStorage.setItem('ba-notify-since', String(max)); } catch (e) { /* private mode */ }
  }
}

function initFind() {
  const palette = document.getElementById('globalSearchPalette');
  const input = document.getElementById('globalSearchInput');
  const panel = document.getElementById('globalSearchPanel');
  if (!palette || !input || !panel) return;

  let timer = null;
  let abort = null;
  let items = [];
  let active = -1;

  function isOpen() {
    return !palette.hidden;
  }

  function openPalette() {
    palette.hidden = false;
    palette.setAttribute('aria-hidden', 'false');
    input.setAttribute('aria-expanded', 'true');
    document.body.classList.add('gs-palette-open');
    input.focus();
    input.select();
    if (input.value.trim()) run(input.value);
  }

  function closePalette() {
    palette.hidden = true;
    palette.setAttribute('aria-hidden', 'true');
    input.setAttribute('aria-expanded', 'false');
    document.body.classList.remove('gs-palette-open');
    panel.hidden = true;
    panel.innerHTML = '';
    items = [];
    active = -1;
  }

  document.querySelectorAll('[data-open-find]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      e.preventDefault();
      openPalette();
    });
  });
  palette.addEventListener('click', (e) => {
    if (e.target && e.target.closest('[data-close-find]')) closePalette();
  });

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, (c) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[c]);
  }

  function setActive(i) {
    active = i;
    const nodes = panel.querySelectorAll('.gs-hit');
    nodes.forEach((el, idx) => el.classList.toggle('is-active', idx === i));
    if (i >= 0 && nodes[i]) nodes[i].scrollIntoView({ block: 'nearest' });
  }

  function render(data, q) {
    items = [];
    active = -1;
    const groups = (data && data.groups) || [];
    const devicesHref = '/devices.php?q=' + encodeURIComponent(q);
    if (!groups.length) {
      panel.innerHTML = '<div class="gs-empty">No matches for "' + escapeHtml(q) + '". <a href="'
        + escapeHtml(devicesHref) + '">Search inventory</a></div>';
      panel.hidden = false;
      return;
    }
    let html = '';
    groups.forEach((g) => {
      html += '<div class="gs-group"><div class="gs-group-head"><span>' + escapeHtml(g.label || '') + '</span>';
      if (g.href) html += '<a href="' + escapeHtml(g.href) + '">See all</a>';
      html += '</div>';
      (g.hits || []).forEach((hit) => {
        items.push(hit);
        const idx = items.length - 1;
        html += '<a class="gs-hit" role="option" data-i="' + idx + '" href="'
          + escapeHtml(hit.href || '#') + '"><strong>' + escapeHtml(hit.title || '')
          + '</strong><span>' + escapeHtml(hit.subtitle || '') + '</span></a>';
      });
      html += '</div>';
    });
    panel.innerHTML = html;
    panel.hidden = false;
    panel.querySelectorAll('.gs-hit').forEach((el) => {
      el.addEventListener('mouseenter', () => setActive(parseInt(el.getAttribute('data-i') || '-1', 10)));
    });
  }

  function run(q) {
    q = String(q || '').trim();
    if (!q) {
      panel.hidden = true;
      panel.innerHTML = '';
      items = [];
      active = -1;
      return;
    }
    if (abort) {
      try { abort.abort(); } catch (e) { /* ignore */ }
    }
    abort = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    const url = '/api_search.php?q=' + encodeURIComponent(q);
    fetch(url, {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json' },
      signal: abort ? abort.signal : undefined,
    }).then((res) => res.json()).then((data) => {
      if (input.value.trim() !== q) return;
      render(data, q);
    }).catch((err) => {
      if (err && err.name === 'AbortError') return;
    });
  }

  input.addEventListener('input', () => {
    if (timer) clearTimeout(timer);
    timer = setTimeout(() => run(input.value), 180);
  });
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      e.preventDefault();
      e.stopPropagation();
      closePalette();
      input.blur();
      return;
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault();
      if (!items.length) return;
      setActive(active < items.length - 1 ? active + 1 : 0);
      return;
    }
    if (e.key === 'ArrowUp') {
      e.preventDefault();
      if (!items.length) return;
      setActive(active > 0 ? active - 1 : items.length - 1);
      return;
    }
    if (e.key === 'Enter') {
      e.preventDefault();
      const q = input.value.trim();
      if (active >= 0 && items[active]) {
        window.location.href = items[active].href;
        return;
      }
      if (items.length === 1) {
        window.location.href = items[0].href;
        return;
      }
      if (items.length > 1) {
        const firstSee = panel.querySelector('.gs-group-head a');
        window.location.href = (firstSee && firstSee.getAttribute('href')) || items[0].href;
        return;
      }
      if (q) window.location.href = '/devices.php?q=' + encodeURIComponent(q);
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && isOpen()) {
      closePalette();
      return;
    }
    const t = e.target;
    const tag = t && t.tagName ? t.tagName.toLowerCase() : '';
    const typing = tag === 'input' || tag === 'textarea' || tag === 'select' || (t && t.isContentEditable);
    if (e.key === 'k' && (e.ctrlKey || e.metaKey) && !e.altKey && !e.repeat) {
      e.preventDefault();
      openPalette();
      return;
    }
    if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey || e.repeat || e.isComposing || typing) return;
    if (document.querySelector('.ldaps-modal:not([hidden])')) return;
    e.preventDefault();
    openPalette();
  });
}

document.addEventListener('DOMContentLoaded', () => {
  const el = document.getElementById('device-charts');
  if (el) loadSeries(el.dataset.id);
  if (document.getElementById('dash-power')) loadDashboard();
  bindIdfPan();
  bindTemplateFill();
  bindLocTree();
  initFind();
  if (!document.body.classList.contains('login-page')) {
    pollNotify();
    setInterval(pollNotify, 15000);
  }
});
