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
  let html = '<div class="dash-chart-head"><div class="legend">' + title + '</div></div><div class="dash-rank">';
  if (!list.length) html += '<p class="muted">No readings yet.</p>';
  list.forEach(row => {
    const v = row[key];
    const pct = v == null ? 0 : Math.max(4, (Number(v) / max) * 100);
    const href = row.id ? '/idfs?group=' + encodeURIComponent(row.id) : '#';
    html += '<a class="dash-rank-row" href="' + href + '">';
    html += '<span class="dash-rank-name">' + (row.name || 'IDF') + '</span>';
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

document.addEventListener('DOMContentLoaded', () => {
  const el = document.getElementById('device-charts');
  if (el) loadSeries(el.dataset.id);
  if (document.getElementById('dash-power')) loadDashboard();
  bindIdfPan();
  bindTemplateFill();
  bindLocTree();
});
