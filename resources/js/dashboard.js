import {
  COLORS, PALETTE, groupBy, bucketTopCategories, truncateLabel,
  fmtMoney, fmtMoneyShort, svgBarChart, svgGroupedBarChart,
  svgDonutChart, svgRangeBarChart, svgBubbleChart, pearson,
} from './chart-engine.js';

/* ==========================================================
   CHART REGISTRY
   Single source of truth for every chart's required column
   roles, sane defaults (matching a typical WORKER sheet), and
   the copy shown in the gear-icon config modal. Add a new
   chart by adding an entry here + a matching <x-chart-panel>
   in the Blade view + a render*() function below.
   ========================================================== */
export const CHART_REGISTRY = {
  funnel: {
    title: 'Pipeline fields (also powers the KPI tiles above)',
    fields: [
      { key: 'messaged', label: '"Messaged" column', default: 'Onboarding Message Sent' },
      { key: 'booked', label: '"Booked" column', default: 'Booked Appointment' },
      { key: 'converted', label: '"Converted" column', default: 'Converted' },
      { key: 'sdr', label: 'SDR / owner column', default: 'SDR' },
    ],
  },
  sdr_conversion: {
    title: 'Conversion Rate by SDR',
    fields: [
      { key: 'sdr', label: 'SDR / owner column', default: 'SDR' },
      { key: 'messaged', label: '"Messaged" column', default: 'Onboarding Message Sent' },
      { key: 'converted', label: '"Converted" column', default: 'Converted' },
    ],
  },
  relationship_status: {
    title: 'LinkedIn Relationship Status',
    fields: [{ key: 'category', label: 'Group by column', default: 'Linked In Relationship Status' }],
  },
  leads_by_industry: {
    title: 'Leads by Industry',
    fields: [{ key: 'category', label: 'Group by column', default: 'industries' }],
  },
  leads_by_location: {
    title: 'Leads by Location',
    fields: [{ key: 'category', label: 'Group by column', default: 'location' }],
  },
  top_job_titles: {
    title: 'Top Job Titles',
    fields: [{ key: 'category', label: 'Group by column', default: 'Job' }],
  },
  employment_type: {
    title: 'Employment Type',
    fields: [{ key: 'category', label: 'Group by column', default: 'employmentType' }],
  },
  seniority_level: {
    title: 'Seniority Level',
    fields: [{ key: 'category', label: 'Group by column', default: 'seniorityLevel' }],
  },
  salary_range: {
    title: 'Salary Range by Job Title',
    fields: [
      { key: 'category', label: 'Job title column', default: 'Job' },
      { key: 'salaryMin', label: 'Salary min column', default: 'salaryMin' },
      { key: 'salaryMax', label: 'Salary max column', default: 'salaryMax' },
    ],
  },
  msg_booked_industry: {
    title: 'Messaged vs. Booked by Industry',
    fields: [
      { key: 'category', label: 'Group by column', default: 'industries' },
      { key: 'messaged', label: '"Messaged" column', default: 'Onboarding Message Sent' },
      { key: 'booked', label: '"Booked" column', default: 'Booked Appointment' },
      { key: 'converted', label: '"Converted" column', default: 'Converted' },
    ],
  },
  correlation: {
    title: 'Messaged–Booked Correlation',
    fields: [
      { key: 'category', label: 'Group by column', default: 'industries' },
      { key: 'messaged', label: '"Messaged" column', default: 'Onboarding Message Sent' },
      { key: 'booked', label: '"Booked" column', default: 'Booked Appointment' },
    ],
  },
  sdr_efficiency: {
    title: 'SDR Efficiency — Messaged vs. Booked',
    fields: [
      { key: 'category', label: 'SDR / owner column', default: 'SDR' },
      { key: 'messaged', label: '"Messaged" column', default: 'Onboarding Message Sent' },
      { key: 'booked', label: '"Booked" column', default: 'Booked Appointment' },
      { key: 'converted', label: '"Converted" column', default: 'Converted' },
    ],
  },
};

/* ==========================================================
   STATE
   ========================================================== */
let rawRows = [];
let savedConfigs = window.INITIAL_DATA?.chartConfigs || {};

function resolveConfig(chartKey) {
  const registry = CHART_REGISTRY[chartKey];
  if (!registry) return {};
  const defaults = {};
  registry.fields.forEach(f => { defaults[f.key] = f.default; });
  return { ...defaults, ...(savedConfigs[chartKey] || {}) };
}

// Same "is this truthy in sheet-speak" heuristic the standalone dashboard used:
// handles "Sent"/"Not Sent", "Booked"/"Not Booked", "Yes"/"No", blanks, etc.
function yesLike(v) {
  const s = String(v || '').trim().toLowerCase();
  if (!s) return false;
  if (s.startsWith('not ') || s === 'no' || s === 'false' || s === '0') return false;
  return true;
}

function num(v) {
  const n = parseFloat(String(v || '').replace(/[^0-9.]/g, ''));
  return isNaN(n) ? null : n;
}

/* ==========================================================
   DATA LOADING
   ========================================================== */
async function loadData(fresh = false) {
  setStatus(true, 'Syncing…');
  try {
    const res = await fetch(`/dashboard/data${fresh ? '?fresh=1' : ''}`, {
      headers: { Accept: 'application/json' },
    });
    const json = await res.json();
    if (!json.ok) throw new Error(json.message || 'Failed to load sheet data');

    rawRows = json.rows;
    document.getElementById('last-sync').textContent = new Date(json.synced_at).toLocaleTimeString();
    setStatus(true, `Live — ${rawRows.length} leads`);
    render();
  } catch (e) {
    console.error(e);
    setStatus(false, e.message || 'Could not load sheet');
  }
}

function setStatus(live, text) {
  const pill = document.getElementById('status-pill');
  if (!pill) return;
  pill.classList.toggle('live', live);
  document.getElementById('status-text').textContent = text;
}

/* ==========================================================
   RENDER — one function per chart, each config-driven
   ========================================================== */
function render() {
  const safe = (fn, ...args) => { try { fn(...args); } catch (e) { console.error(fn.name + ' failed:', e); } };

  safe(renderKPIsAndFunnel);
  safe(renderSDRConversion);
  safe(renderStatus);
  safe(renderIndustry);
  safe(renderLocation);
  safe(renderJobs);
  safe(renderEmployment);
  safe(renderSeniority);
  safe(renderSalary);
  safe(renderMsgBookedByIndustry);
  safe(renderCorrelation);
  safe(renderSdrEfficiency);
  safe(renderTable);
}

function renderKPIsAndFunnel() {
  const c = resolveConfig('funnel');
  const leads = rawRows.length;
  const msgs = rawRows.filter(d => yesLike(d[c.messaged])).length;
  const booked = rawRows.filter(d => yesLike(d[c.booked])).length;
  const converted = rawRows.filter(d => yesLike(d[c.converted])).length;
  const sdrs = new Set(rawRows.map(d => d[c.sdr]).filter(Boolean)).size;
  const conv = msgs ? Math.round((converted / msgs) * 1000) / 10 : 0;

  const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
  set('kpi-leads', leads);
  set('kpi-msgs', msgs);
  set('kpi-msgs-sub', leads ? Math.round((msgs / leads) * 100) + '% of leads' : '');
  set('kpi-booked', booked);
  set('kpi-booked-sub', msgs ? Math.round((booked / msgs) * 100) + '% of messaged' : '');
  set('kpi-converted', converted);
  set('kpi-converted-sub', booked ? Math.round((converted / booked) * 100) + '% of booked' : '');
  set('kpi-conv', conv + '%');
  set('kpi-sdrs', sdrs);
  set('side-leads', leads);
  set('side-msgs', msgs);
  set('side-booked', booked);
  set('side-conv', conv + '%');

  const stages = [
    { label: 'Leads Sourced', count: leads, pct: '100%', cls: 's1' },
    { label: 'Messaged', count: msgs, pct: leads ? Math.round((msgs / leads) * 100) + '%' : '0%', cls: 's2' },
    { label: 'Booked', count: booked, pct: msgs ? Math.round((booked / msgs) * 100) + '%' : '0%', cls: 's3' },
    { label: 'Converted', count: converted, pct: booked ? Math.round((converted / booked) * 100) + '%' : '0%', cls: 's4' },
  ];
  const wrap = document.getElementById('funnel-wrap');
  if (wrap) {
    wrap.innerHTML = '';
    stages.forEach((s, i) => {
      if (i > 0) {
        const arrow = document.createElement('div');
        arrow.className = 'funnel-arrow';
        arrow.textContent = '→';
        wrap.appendChild(arrow);
      }
      const el = document.createElement('div');
      el.className = 'funnel-stage';
      el.innerHTML = `<div class="funnel-shape ${s.cls}">${s.pct}</div>
        <div class="stage-label">${s.label}</div>
        <div class="stage-count">${s.count} leads</div>`;
      wrap.appendChild(el);
    });
  }

  renderSidebarLeaderboard(c);
}

function renderSidebarLeaderboard(funnelConfig) {
  const el = document.getElementById('sdr-leaderboard');
  if (!el) return;
  const sdrs = [...new Set(rawRows.map(d => d[funnelConfig.sdr]).filter(Boolean))];
  const rows = sdrs.map(sdr => {
    const s = rawRows.filter(d => d[funnelConfig.sdr] === sdr);
    const msgs = s.filter(d => yesLike(d[funnelConfig.messaged])).length;
    const converted = s.filter(d => yesLike(d[funnelConfig.converted])).length;
    const rate = msgs ? Math.round((converted / msgs) * 1000) / 10 : 0;
    return { sdr, rate };
  }).sort((a, b) => b.rate - a.rate);

  el.innerHTML = rows.map(r => `
    <div class="sdr-row">
      <span class="sdr-dot"></span>
      <span class="name">${r.sdr}</span>
      <span class="rate">${r.rate}%</span>
    </div>`).join('');
}

function renderSDRConversion() {
  const c = resolveConfig('sdr_conversion');
  const sdrs = [...new Set(rawRows.map(d => d[c.sdr]).filter(Boolean))];
  const rates = sdrs.map(sdr => {
    const rows = rawRows.filter(d => d[c.sdr] === sdr);
    const msgs = rows.filter(d => yesLike(d[c.messaged])).length;
    const converted = rows.filter(d => yesLike(d[c.converted])).length;
    return msgs ? Math.round((converted / msgs) * 1000) / 10 : 0;
  });
  svgBarChart('chart-sdr_conversion', { labels: sdrs, data: rates, color: COLORS.mint, format: v => v + '%' });
}

function renderStatus() {
  const c = resolveConfig('relationship_status');
  const grouped = groupBy(rawRows, c.category);
  svgBarChart('chart-relationship_status', { labels: Object.keys(grouped), data: Object.values(grouped), color: COLORS.amber, horizontal: true });
}

function renderIndustry() {
  const c = resolveConfig('leads_by_industry');
  const { labels, labelFor } = bucketTopCategories(rawRows, c.category, 8);
  const counts = labels.map(l => rawRows.filter(d => labelFor(d[c.category]) === l).length);
  svgDonutChart('chart-leads_by_industry', { labels, data: counts });
}

function renderLocation() {
  const c = resolveConfig('leads_by_location');
  const grouped = groupBy(rawRows, c.category);
  const entries = Object.entries(grouped).sort((a, b) => b[1] - a[1]).slice(0, 8);
  svgBarChart('chart-leads_by_location', { labels: entries.map(e => e[0]), data: entries.map(e => e[1]), color: COLORS.amber, horizontal: true });
}

function renderJobs() {
  const c = resolveConfig('top_job_titles');
  const grouped = groupBy(rawRows, c.category);
  const entries = Object.entries(grouped).sort((a, b) => b[1] - a[1]).slice(0, 8);
  svgBarChart('chart-top_job_titles', { labels: entries.map(e => e[0]), data: entries.map(e => e[1]), color: COLORS.mint, horizontal: true });
}

function renderEmployment() {
  const c = resolveConfig('employment_type');
  const grouped = groupBy(rawRows, c.category);
  svgDonutChart('chart-employment_type', { labels: Object.keys(grouped), data: Object.values(grouped) });
}

function renderSeniority() {
  const c = resolveConfig('seniority_level');
  const order = ['Entry level', 'Associate', 'Mid-Senior level', 'Not Applicable', 'Unspecified'];
  const grouped = groupBy(rawRows, c.category);
  const labels = Object.keys(grouped).sort((a, b) => order.indexOf(a) - order.indexOf(b));
  svgBarChart('chart-seniority_level', { labels, data: labels.map(l => grouped[l]), color: COLORS.coral });
}

function renderSalary() {
  const c = resolveConfig('salary_range');
  const withSalary = rawRows
    .map(d => ({ job: d[c.category], min: num(d[c.salaryMin]), max: num(d[c.salaryMax]) }))
    .filter(d => d.min != null && d.max != null);

  const byJob = {};
  withSalary.forEach(d => {
    if (!byJob[d.job]) byJob[d.job] = { mins: [], maxs: [], count: 0 };
    byJob[d.job].mins.push(d.min);
    byJob[d.job].maxs.push(d.max);
    byJob[d.job].count++;
  });
  const entries = Object.entries(byJob).sort((a, b) => b[1].count - a[1].count).slice(0, 8);
  const labels = entries.map(e => e[0]);
  const ranges = entries.map(e => {
    const avg = arr => arr.reduce((s, v) => s + v, 0) / arr.length;
    return [Math.round(avg(e[1].mins)), Math.round(avg(e[1].maxs))];
  });
  svgRangeBarChart('chart-salary_range', { labels, ranges });

  const allMids = withSalary.map(d => (d.min + d.max) / 2);
  const avgMid = allMids.length ? Math.round(allMids.reduce((s, v) => s + v, 0) / allMids.length) : 0;
  const maxSalary = withSalary.length ? Math.max(...withSalary.map(d => d.max)) : 0;
  const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
  set('stat-avg-salary', allMids.length ? fmtMoney(avgMid) : '—');
  set('stat-max-salary', withSalary.length ? fmtMoney(maxSalary) : '—');
  set('stat-salary-coverage', rawRows.length ? Math.round((withSalary.length / rawRows.length) * 100) + '%' : '—');
}

function renderMsgBookedByIndustry() {
  const c = resolveConfig('msg_booked_industry');
  const { labels: industries, labelFor } = bucketTopCategories(rawRows, c.category, 8);
  const msgCounts = industries.map(ind => rawRows.filter(d => labelFor(d[c.category]) === ind && yesLike(d[c.messaged])).length);
  const bookedCounts = industries.map(ind => rawRows.filter(d => labelFor(d[c.category]) === ind && yesLike(d[c.booked])).length);
  const convertedCounts = industries.map(ind => rawRows.filter(d => labelFor(d[c.category]) === ind && yesLike(d[c.converted])).length);
  svgGroupedBarChart('chart-msg_booked_industry', {
    labels: industries,
    datasets: [
      { label: 'Messaged', data: msgCounts, color: '#B9CCC4' },
      { label: 'Booked', data: bookedCounts, color: COLORS.amber },
      { label: 'Converted', data: convertedCounts, color: COLORS.mint },
    ],
  });
}

function renderCorrelation() {
  const c = resolveConfig('correlation');
  const { labels: industries, labelFor } = bucketTopCategories(rawRows, c.category, 8);
  const points = industries.map((ind, i) => {
    const rows = rawRows.filter(d => labelFor(d[c.category]) === ind);
    const msg = rows.filter(d => yesLike(d[c.messaged])).length;
    const booked = rows.filter(d => yesLike(d[c.booked])).length;
    return { x: msg, y: booked, r: Math.max(6, Math.min(22, rows.length * 2.2)), label: ind, color: PALETTE[i % PALETTE.length] };
  });
  svgBubbleChart('chart-correlation', { points, xLabel: 'Messages sent', yLabel: 'Appointments booked' });

  const insight = document.getElementById('correlation-insight');
  if (!insight) return;
  const totalMsg = points.reduce((s, p) => s + p.x, 0);
  if (points.length < 4 || totalMsg < 5) {
    insight.innerHTML = `<span class="ic">📊</span><span><b>Not enough data yet</b> to read a correlation here — you need more industries and more messages sent before this number means anything.</span>`;
    return;
  }
  const r = pearson(points.map(p => p.x), points.map(p => p.y));
  const strength = Math.abs(r) > 0.7 ? 'strong' : Math.abs(r) > 0.4 ? 'moderate' : 'weak';
  const direction = r >= 0 ? 'positive' : 'negative';
  insight.innerHTML = `<span class="ic">📊</span><span><b>r = ${r.toFixed(2)}</b> — a ${strength} ${direction} correlation between outreach volume and bookings across industries. ` +
    (Math.abs(r) < 0.4 ? 'Messaging more in a segment isn\'t reliably translating into bookings there.' : 'More messaging in a segment is tracking closely with more bookings.') +
    `</span>`;
}

function renderSdrEfficiency() {
  const c = resolveConfig('sdr_efficiency');
  const sdrs = [...new Set(rawRows.map(d => d[c.category]).filter(Boolean))];
  const msgCounts = sdrs.map(s => rawRows.filter(d => d[c.category] === s && yesLike(d[c.messaged])).length);
  const bookedCounts = sdrs.map(s => rawRows.filter(d => d[c.category] === s && yesLike(d[c.booked])).length);
  const convertedCounts = sdrs.map(s => rawRows.filter(d => d[c.category] === s && yesLike(d[c.converted])).length);
  svgGroupedBarChart('chart-sdr_efficiency', {
    labels: sdrs,
    horizontal: true,
    datasets: [
      { label: 'Messaged', data: msgCounts, color: '#B9CCC4' },
      { label: 'Booked', data: bookedCounts, color: COLORS.amber },
      { label: 'Converted', data: convertedCounts, color: COLORS.mint },
    ],
  });
}

/* ==========================================================
   TABLE (uses the shared "funnel" mapping — not independently
   gear-configurable, since it's a raw detail view rather than
   an aggregate chart)
   ========================================================== */
function filteredData() {
  const q = (document.getElementById('search-input')?.value || '').toLowerCase();
  const sdrFilter = document.getElementById('filter-sdr')?.value || '';
  const statusFilter = document.getElementById('filter-status')?.value || '';
  const c = resolveConfig('funnel');
  const statusCol = resolveConfig('relationship_status').category;

  return rawRows.filter(d => {
    if (sdrFilter && d[c.sdr] !== sdrFilter) return false;
    if (statusFilter && d[statusCol] !== statusFilter) return false;
    if (q) {
      const hay = `${d['Company Name'] || ''} ${d['Job'] || ''} ${d['location'] || ''} ${d['industries'] || ''}`.toLowerCase();
      if (!hay.includes(q)) return false;
    }
    return true;
  });
}

function renderFilters() {
  const c = resolveConfig('funnel');
  const statusCol = resolveConfig('relationship_status').category;
  const sdrSel = document.getElementById('filter-sdr');
  const statusSel = document.getElementById('filter-status');
  if (!sdrSel || !statusSel) return;

  const sdrs = [...new Set(rawRows.map(d => d[c.sdr]).filter(Boolean))];
  const statuses = [...new Set(rawRows.map(d => d[statusCol]).filter(Boolean))];

  if (sdrSel.options.length <= 1) {
    sdrs.forEach(s => { const o = document.createElement('option'); o.value = s; o.textContent = s; sdrSel.appendChild(o); });
  }
  if (statusSel.options.length <= 1) {
    statuses.forEach(s => { const o = document.createElement('option'); o.value = s; o.textContent = s; statusSel.appendChild(o); });
  }
}

function renderTable() {
  renderFilters();
  const body = document.getElementById('table-body');
  if (!body) return;
  const c = resolveConfig('funnel');
  const statusCol = resolveConfig('relationship_status').category;
  const data = filteredData();

  body.innerHTML = data.map(d => {
    const sdr = d[c.sdr];
    const msgs = rawRows.filter(x => x[c.sdr] === sdr && yesLike(x[c.messaged])).length;
    const converted = rawRows.filter(x => x[c.sdr] === sdr && yesLike(x[c.converted])).length;
    const rate = msgs ? Math.round((converted / msgs) * 1000) / 10 : 0;
    const msgSent = yesLike(d[c.messaged]);
    const booked = yesLike(d[c.booked]);
    const isConverted = yesLike(d[c.converted]);
    return `<tr>
      <td class="company-cell">
        <div class="cname"><a href="${d['companyUrl'] || '#'}" target="_blank" rel="noopener">${d['Company Name'] || '—'}</a></div>
        <div class="cjob">${d['Job'] || ''}</div>
      </td>
      <td>${d['industries'] || ''}</td>
      <td>${d['location'] || ''}</td>
      <td>${sdr || '—'}</td>
      <td><span class="tag status">${d[statusCol] || 'Unknown'}</span></td>
      <td><span class="tag ${msgSent ? 'yes' : 'no'}">${msgSent ? 'Sent' : 'Not sent'}</span></td>
      <td><span class="tag ${booked ? 'yes' : 'no'}">${booked ? 'Booked' : '—'}</span></td>
      <td><span class="tag ${isConverted ? 'yes' : 'no'}">${isConverted ? 'Converted' : '—'}</span></td>
      <td class="rate-cell">${rate}%</td>
    </tr>`;
  }).join('') || `<tr><td colspan="9" style="text-align:center;color:var(--ink-soft);padding:24px;">No leads match these filters.</td></tr>`;
}

/* ==========================================================
   GEAR-ICON CONFIG MODAL
   One shared modal, populated per chart on open.
   ========================================================== */
export function openChartConfig(chartKey) {
  const registry = CHART_REGISTRY[chartKey];
  if (!registry) return;

  const modal = document.getElementById('chart-config-modal');
  const titleEl = document.getElementById('chart-config-title');
  const fieldsEl = document.getElementById('chart-config-fields');
  titleEl.textContent = registry.title;
  fieldsEl.innerHTML = '';

  const current = resolveConfig(chartKey);
  const headers = window.INITIAL_DATA?.headers || [];

  registry.fields.forEach(f => {
    const wrap = document.createElement('div');
    wrap.style.marginBottom = '12px';
    const label = document.createElement('label');
    label.textContent = f.label;
    label.style.cssText = 'display:block;font-size:11.5px;color:var(--ink-soft);margin-bottom:4px;';
    const select = document.createElement('select');
    select.dataset.fieldKey = f.key;
    select.style.cssText = 'width:100%;font-family:IBM Plex Mono,monospace;font-size:12px;padding:8px;border:1px solid var(--line);border-radius:8px;background:var(--panel-alt);';
    headers.forEach(h => {
      const opt = document.createElement('option');
      opt.value = h;
      opt.textContent = h;
      if (h === current[f.key]) opt.selected = true;
      select.appendChild(opt);
    });
    wrap.appendChild(label);
    wrap.appendChild(select);
    fieldsEl.appendChild(wrap);
  });

  modal.dataset.chartKey = chartKey;
  modal.classList.add('open');
}

export async function saveChartConfig() {
  const modal = document.getElementById('chart-config-modal');
  const chartKey = modal.dataset.chartKey;
  const fieldsEl = document.getElementById('chart-config-fields');
  const mapping = {};
  fieldsEl.querySelectorAll('select[data-field-key]').forEach(sel => {
    mapping[sel.dataset.fieldKey] = sel.value;
  });

  const res = await fetch(`/charts/${chartKey}/config`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
      Accept: 'application/json',
    },
    body: JSON.stringify({ mapping }),
  });
  const json = await res.json();
  if (json.ok) {
    savedConfigs[chartKey] = json.mapping;
    modal.classList.remove('open');
    render();
  }
}

/* ==========================================================
   INIT
   ========================================================== */
document.addEventListener('DOMContentLoaded', () => {
  // This layout (and its <script> seeding window.INITIAL_DATA) is shared across
  // every authenticated page, but only the dashboard page itself has these
  // elements — skip all dashboard wiring elsewhere (e.g. the admin settings page).
  if (!document.getElementById('kpi-leads')) return;

  document.getElementById('search-input')?.addEventListener('input', renderTable);
  document.getElementById('filter-sdr')?.addEventListener('change', renderTable);
  document.getElementById('filter-status')?.addEventListener('change', renderTable);
  document.getElementById('refresh-btn')?.addEventListener('click', () => loadData(true));

  document.querySelectorAll('[data-chart-gear]').forEach(btn => {
    btn.addEventListener('click', () => openChartConfig(btn.dataset.chartGear));
  });
  document.getElementById('chart-config-save')?.addEventListener('click', saveChartConfig);
  document.getElementById('chart-config-cancel')?.addEventListener('click', () => {
    document.getElementById('chart-config-modal').classList.remove('open');
  });
  document.getElementById('chart-config-modal')?.addEventListener('click', (e) => {
    if (e.target.id === 'chart-config-modal') e.currentTarget.classList.remove('open');
  });

  // Seed initial rows from the server-rendered payload so the first paint
  // doesn't need a round trip, then poll for live updates every 30s.
  if (window.INITIAL_DATA?.rows?.length) {
    rawRows = window.INITIAL_DATA.rows;
    render();
    document.getElementById('last-sync').textContent = new Date().toLocaleTimeString();
    setStatus(true, `Live — ${rawRows.length} leads`);
  }
  setInterval(() => loadData(), 30000);
});
