/* ==========================================================
   WORKER dashboard — chart drawing engine
   Zero external dependencies (no Chart.js). Pure SVG + vanilla JS.
   Drawing primitives ported verbatim from the standalone prototype.
   ========================================================== */

export const COLORS = {
  mint:'#4FE3A6', mintDeep:'#1C7A5C', amber:'#EE9F4E', coral:'#E2694F',
  ink:'#12241F', inkSoft:'#5B6B64', panelAlt:'#EFEAD9'
};
export const PALETTE = ['#4FE3A6','#EE9F4E','#E2694F','#7FA396','#2E9E76','#C97A2A','#8FBFAE','#D98A6F'];


export function groupBy(data, key){
  const map = {};
  data.forEach(d=>{ map[d[key]] = (map[d[key]]||0)+1; });
  return map;
}

export const SVGNS = 'http://www.w3.org/2000/svg';
export function svgEl(tag, attrs){
  const el = document.createElementNS(SVGNS, tag);
  for(const k in attrs) el.setAttribute(k, attrs[k]);
  return el;
}
export function fmtMoney(v){ return '$'+Math.round(v).toLocaleString(); }
export function chartWidth(container){
  return (container && container.clientWidth) ? container.clientWidth : 600;
}
export function fmtMoneyShort(v){
  const n = Math.round(v);
  if (Math.abs(n) >= 1000) return '$'+Math.round(n/1000)+'k';
  return '$'+n;
}
export function truncateLabel(s, n){ s = String(s); return s.length > n ? s.slice(0, n-1)+'…' : s; }

// Groups a dataset by `key`, keeps the top N categories by count, and folds
// everything else into a single "Other" bucket — keeps charts/legends readable
// regardless of how many distinct industries/statuses/etc. are in the sheet.
export function bucketTopCategories(data, key, n=8){
  const counts = groupBy(data, key);
  const sorted = Object.entries(counts).sort((a,b)=>b[1]-a[1]);
  const topLabels = sorted.slice(0,n).map(e=>e[0]);
  const hasOther = sorted.length > n;
  const labelFor = (val) => topLabels.includes(val) ? val : (hasOther ? 'Other' : val);
  const orderedLabels = hasOther ? [...topLabels, 'Other'] : topLabels;
  return { labels: orderedLabels, labelFor };
}

export function emptyState(container, msg){
  container.innerHTML = `<div style="display:flex;align-items:center;justify-content:center;height:100%;min-height:160px;color:var(--ink-soft);font-size:12px;">${msg || 'No data yet'}</div>`;
}

// Simple vertical/horizontal single-series bar chart
export function svgBarChart(containerId, {labels, data, color=COLORS.mint, horizontal=false, format=(v)=>v, height=230}){
  const container = document.getElementById(containerId);
  container.innerHTML = '';
  if(!labels.length || data.every(v=>!v)){ return emptyState(container, 'No data yet'); }
  const w = chartWidth(container);
  const max = Math.max(1, ...data);
  const n = labels.length;
  const svg = svgEl('svg', {viewBox:`0 0 ${w} ${height}`, width:'100%', height, style:'overflow:visible'});

  if(horizontal){
    const padL = 108, padR = 46, padTB = 8;
    const rowH = (height - padTB*2) / n;
    const barH = Math.min(26, rowH*0.6);
    labels.forEach((lab,i)=>{
      const val = data[i];
      const barW = (val/max) * (w - padL - padR);
      const y = padTB + i*rowH + (rowH-barH)/2;
      const rect = svgEl('rect', {x:padL, y, width:Math.max(barW,1.5), height:barH, rx:5, fill:color});
      rect.appendChild(Object.assign(svgEl('title'), {textContent:`${lab}: ${format(val)}`}));
      svg.appendChild(rect);
      const label = svgEl('text', {x:padL-8, y:y+barH/2+4, 'text-anchor':'end', 'font-size':10.5, fill:'var(--ink-soft)', 'font-family':'IBM Plex Mono, monospace'});
      label.textContent = truncateLabel(lab, 16);
      svg.appendChild(label);
      const valText = svgEl('text', {x:padL+barW+8, y:y+barH/2+4, 'font-size':10.5, fill:'var(--ink)', 'font-family':'IBM Plex Mono, monospace', 'font-weight':'600'});
      valText.textContent = format(val);
      svg.appendChild(valText);
    });
  } else {
    const padL = 30, padR = 10, padT = 20, padB = 30;
    const colW = (w - padL - padR) / n;
    const barW = Math.min(52, colW*0.55);
    labels.forEach((lab,i)=>{
      const val = data[i];
      const barH = (val/max) * (height - padT - padB);
      const x = padL + i*colW + (colW-barW)/2;
      const y = height - padB - barH;
      const rect = svgEl('rect', {x, y, width:barW, height:Math.max(barH,1.5), rx:5, fill:color});
      rect.appendChild(Object.assign(svgEl('title'), {textContent:`${lab}: ${format(val)}`}));
      svg.appendChild(rect);
      const label = svgEl('text', {x:x+barW/2, y:height-padB+14, 'text-anchor':'middle', 'font-size':9.5, fill:'var(--ink-soft)', 'font-family':'IBM Plex Mono, monospace'});
      label.textContent = truncateLabel(lab, 11);
      svg.appendChild(label);
      const valText = svgEl('text', {x:x+barW/2, y:y-6, 'text-anchor':'middle', 'font-size':10.5, fill:'var(--ink)', 'font-family':'IBM Plex Mono, monospace', 'font-weight':'600'});
      valText.textContent = format(val);
      svg.appendChild(valText);
    });
  }
  container.appendChild(svg);
}

// Grouped multi-series bar chart (vertical or horizontal), with a legend
export function svgGroupedBarChart(containerId, {labels, datasets, horizontal=false, height=240}){
  const container = document.getElementById(containerId);
  container.innerHTML = '';
  const allVals = datasets.flatMap(ds=>ds.data);
  if(!labels.length || allVals.every(v=>!v)){ return emptyState(container, 'No data yet'); }
  const w = chartWidth(container);
  const max = Math.max(1, ...allVals);
  const n = labels.length;
  const s = datasets.length;
  const legendH = 26;
  const svg = svgEl('svg', {viewBox:`0 0 ${w} ${height}`, width:'100%', height, style:'overflow:visible'});

  if(horizontal){
    const padL = 100, padR = 46, padTB = 8, padBottom = padTB + legendH;
    const rowH = (height - padTB - padBottom) / n;
    const groupBarH = Math.min(9, (rowH*0.72)/s);
    labels.forEach((lab,i)=>{
      const groupY = padTB + i*rowH + (rowH - groupBarH*s)/2;
      datasets.forEach((ds,j)=>{
        const val = ds.data[i];
        const barW = (val/max) * (w - padL - padR);
        const y = groupY + j*groupBarH;
        const rect = svgEl('rect', {x:padL, y, width:Math.max(barW,1.5), height:groupBarH-1.5, rx:2.5, fill:ds.color});
        rect.appendChild(Object.assign(svgEl('title'), {textContent:`${lab} — ${ds.label}: ${val}`}));
        svg.appendChild(rect);
      });
      const label = svgEl('text', {x:padL-8, y:groupY+groupBarH*s/2+4, 'text-anchor':'end', 'font-size':10, fill:'var(--ink-soft)', 'font-family':'IBM Plex Mono, monospace'});
      label.textContent = truncateLabel(lab, 14);
      svg.appendChild(label);
    });
  } else {
    const padL = 26, padR = 10, padT = 16, padB = 46 + legendH;
    const colW = (w - padL - padR) / n;
    const groupBarW = Math.min(10, (colW*0.7)/s);
    labels.forEach((lab,i)=>{
      const groupX = padL + i*colW + (colW - groupBarW*s)/2;
      datasets.forEach((ds,j)=>{
        const val = ds.data[i];
        const barH = (val/max) * (height - padT - padB);
        const x = groupX + j*groupBarW;
        const y = height - padB - barH;
        const rect = svgEl('rect', {x, y, width:groupBarW-1, height:Math.max(barH,1.5), rx:2, fill:ds.color});
        rect.appendChild(Object.assign(svgEl('title'), {textContent:`${lab} — ${ds.label}: ${val}`}));
        svg.appendChild(rect);
      });
      const lx = groupX+groupBarW*s/2, ly = height-padB+12;
      const label = svgEl('text', {x:lx, y:ly, 'text-anchor':'end', 'font-size':9, fill:'var(--ink-soft)', 'font-family':'IBM Plex Mono, monospace', transform:`rotate(-40 ${lx} ${ly})`});
      label.textContent = truncateLabel(lab, 14);
      svg.appendChild(label);
    });
  }

  // legend
  let lx = 0;
  const legendY = height - legendH + 8;
  datasets.forEach(ds=>{
    const dot = svgEl('circle', {cx:lx+6, cy:legendY, r:4.5, fill:ds.color});
    svg.appendChild(dot);
    const t = svgEl('text', {x:lx+15, y:legendY+4, 'font-size':10.5, fill:'var(--ink-soft)', 'font-family':'Inter, sans-serif'});
    t.textContent = ds.label;
    svg.appendChild(t);
    lx += 15 + ds.label.length*6.2 + 18;
  });

  container.appendChild(svg);
}

// Donut chart with legend
export function svgDonutChart(containerId, {labels, data, colors=PALETTE, height=230}){
  const container = document.getElementById(containerId);
  container.innerHTML = '';
  const total = data.reduce((s,v)=>s+v,0);
  if(!labels.length || total===0){ return emptyState(container, 'No data yet'); }
  const w = chartWidth(container);
  const cx = 130, cy = height/2 - 10, rOuter = 78, rInner = 48;
  const svg = svgEl('svg', {viewBox:`0 0 ${w} ${height}`, width:'100%', height, style:'overflow:visible'});

  let angle = -Math.PI/2;
  labels.forEach((lab,i)=>{
    const val = data[i];
    const frac = val/total;
    const nextAngle = angle + frac*2*Math.PI;
    const x1o = cx + rOuter*Math.cos(angle), y1o = cy + rOuter*Math.sin(angle);
    const x2o = cx + rOuter*Math.cos(nextAngle), y2o = cy + rOuter*Math.sin(nextAngle);
    const x1i = cx + rInner*Math.cos(nextAngle), y1i = cy + rInner*Math.sin(nextAngle);
    const x2i = cx + rInner*Math.cos(angle), y2i = cy + rInner*Math.sin(angle);
    const largeArc = frac > 0.5 ? 1 : 0;
    const d = `M ${x1o} ${y1o} A ${rOuter} ${rOuter} 0 ${largeArc} 1 ${x2o} ${y2o} L ${x1i} ${y1i} A ${rInner} ${rInner} 0 ${largeArc} 0 ${x2i} ${y2i} Z`;
    const path = svgEl('path', {d, fill: colors[i % colors.length]});
    path.appendChild(Object.assign(svgEl('title'), {textContent:`${lab}: ${val} (${Math.round(frac*100)}%)`}));
    svg.appendChild(path);
    angle = nextAngle;
  });

  const centerText = svgEl('text', {x:cx, y:cy-2, 'text-anchor':'middle', 'font-size':20, 'font-weight':'700', fill:'var(--ink)', 'font-family':'Space Grotesk, sans-serif'});
  centerText.textContent = total;
  svg.appendChild(centerText);
  const centerLabel = svgEl('text', {x:cx, y:cy+16, 'text-anchor':'middle', 'font-size':9.5, fill:'var(--ink-soft)', 'font-family':'Inter, sans-serif'});
  centerLabel.textContent = 'total';
  svg.appendChild(centerLabel);

  // legend on the right
  let ly = 20;
  labels.forEach((lab,i)=>{
    const dot = svgEl('circle', {cx:260, cy:ly, r:5, fill:colors[i % colors.length]});
    svg.appendChild(dot);
    const t = svgEl('text', {x:272, y:ly+4, 'font-size':11, fill:'var(--ink)', 'font-family':'Inter, sans-serif'});
    t.textContent = `${truncateLabel(lab, 26)} (${data[i]})`;
    svg.appendChild(t);
    ly += 22;
  });

  container.appendChild(svg);
}

// Horizontal range bar (min–max), for salary ranges
export function svgRangeBarChart(containerId, {labels, ranges, color=COLORS.amber, height=230}){
  const container = document.getElementById(containerId);
  container.innerHTML = '';
  if(!labels.length){ return emptyState(container, 'No salary data available yet'); }
  const w = chartWidth(container);
  const padL = 108, padR = 92, padTB = 8;
  const maxVal = Math.max(1, ...ranges.map(r=>r[1]));
  const n = labels.length;
  const rowH = (height - padTB*2) / n;
  const barH = Math.min(20, rowH*0.55);
  const svg = svgEl('svg', {viewBox:`0 0 ${w} ${height}`, width:'100%', height, style:'overflow:visible'});

  labels.forEach((lab,i)=>{
    const [lo,hi] = ranges[i];
    const x1 = padL + (lo/maxVal)*(w-padL-padR);
    const x2 = padL + (hi/maxVal)*(w-padL-padR);
    const y = padTB + i*rowH + (rowH-barH)/2;
    const rect = svgEl('rect', {x:x1, y, width:Math.max(x2-x1,2), height:barH, rx:5, fill:color});
    rect.appendChild(Object.assign(svgEl('title'), {textContent:`${lab}: ${fmtMoney(lo)} – ${fmtMoney(hi)}`}));
    svg.appendChild(rect);
    const label = svgEl('text', {x:padL-8, y:y+barH/2+4, 'text-anchor':'end', 'font-size':10, fill:'var(--ink-soft)', 'font-family':'IBM Plex Mono, monospace'});
    label.textContent = truncateLabel(lab, 15);
    svg.appendChild(label);
    const valText = svgEl('text', {x:x2+8, y:y+barH/2+4, 'font-size':9.5, fill:'var(--ink)', 'font-family':'IBM Plex Mono, monospace'});
    valText.textContent = `${fmtMoneyShort(lo)}–${fmtMoneyShort(hi)}`;
    svg.appendChild(valText);
  });
  container.appendChild(svg);
}

// Bubble/scatter chart
export function svgBubbleChart(containerId, {points, xLabel='', yLabel='', height=240}){
  const container = document.getElementById(containerId);
  container.innerHTML = '';
  if(!points.length){ return emptyState(container, 'No data yet'); }
  const w = chartWidth(container);
  const padL = 56, padR = 20, padT = 16, padB = 46;
  const maxX = Math.max(1, ...points.map(p=>p.x));
  const maxY = Math.max(1, ...points.map(p=>p.y));
  const svg = svgEl('svg', {viewBox:`0 0 ${w} ${height}`, width:'100%', height, style:'overflow:visible'});

  // axes
  svg.appendChild(svgEl('line', {x1:padL, y1:height-padB, x2:w-padR, y2:height-padB, stroke:'#DED6C0', 'stroke-width':1}));
  svg.appendChild(svgEl('line', {x1:padL, y1:padT, x2:padL, y2:height-padB, stroke:'#DED6C0', 'stroke-width':1}));

  // axis tick numbers (0, mid, max) — dedupe rounded values so tiny ranges
  // (e.g. max=1) don't show the same number twice, like "0, 1, 1"
  const seenX = new Set(), seenY = new Set();
  [0, 0.5, 1].forEach(f=>{
    const xv = Math.round(maxX*f);
    if(!seenX.has(xv)){
      seenX.add(xv);
      const xPix = padL + f*(w-padL-padR);
      svg.appendChild(svgEl('line', {x1:xPix, y1:height-padB, x2:xPix, y2:height-padB+4, stroke:'#DED6C0', 'stroke-width':1}));
      const xt2 = svgEl('text', {x:xPix, y:height-padB+16, 'text-anchor':'middle', 'font-size':9, fill:'var(--ink-soft)', 'font-family':'IBM Plex Mono, monospace'});
      xt2.textContent = xv;
      svg.appendChild(xt2);
    }

    const yv = Math.round(maxY*f);
    if(!seenY.has(yv)){
      seenY.add(yv);
      const yPix = (height-padB) - f*(height-padT-padB);
      svg.appendChild(svgEl('line', {x1:padL-4, y1:yPix, x2:padL, y2:yPix, stroke:'#DED6C0', 'stroke-width':1}));
      const yt2 = svgEl('text', {x:padL-8, y:yPix+3, 'text-anchor':'end', 'font-size':9, fill:'var(--ink-soft)', 'font-family':'IBM Plex Mono, monospace'});
      yt2.textContent = yv;
      svg.appendChild(yt2);
    }
  });

  points.forEach(p=>{
    const cx = padL + (p.x/maxX)*(w-padL-padR);
    const cy = (height-padB) - (p.y/maxY)*(height-padT-padB);
    const circle = svgEl('circle', {cx, cy, r:p.r, fill:p.color+'CC', stroke:p.color, 'stroke-width':1.5});
    circle.appendChild(Object.assign(svgEl('title'), {textContent:`${p.label}: ${p.x} messaged, ${p.y} booked`}));
    svg.appendChild(circle);
  });

  const xt = svgEl('text', {x:(padL+w-padR)/2, y:height-6, 'text-anchor':'middle', 'font-size':10, fill:'var(--ink-soft)', 'font-family':'Inter, sans-serif'});
  xt.textContent = xLabel;
  svg.appendChild(xt);
  const yt = svgEl('text', {x:14, y:(padT+height-padB)/2, 'text-anchor':'middle', 'font-size':10, fill:'var(--ink-soft)', 'font-family':'Inter, sans-serif', transform:`rotate(-90 14 ${(padT+height-padB)/2})`});
  yt.textContent = yLabel;
  svg.appendChild(yt);

  container.appendChild(svg);

  // legend mapping each bubble's color to its label and values — this is what
  // actually makes the chart readable, since color alone isn't identifiable.
  const legend = document.createElement('div');
  legend.style.cssText = 'display:flex;flex-wrap:wrap;gap:8px 14px;margin-top:10px;';
  points.forEach(p=>{
    const item = document.createElement('span');
    item.style.cssText = 'display:inline-flex;align-items:center;gap:5px;font-size:11px;color:var(--ink-soft);';
    item.innerHTML = `<span style="width:8px;height:8px;border-radius:50%;background:${p.color};display:inline-block;flex-shrink:0;"></span>${p.label} <span style="color:var(--ink);font-family:'IBM Plex Mono',monospace;">(${p.x}→${p.y})</span>`;
    legend.appendChild(item);
  });
  container.appendChild(legend);
}

export function pearson(x,y){
  const n = x.length;
  if(n===0) return 0;
  const mx = x.reduce((s,v)=>s+v,0)/n, my = y.reduce((s,v)=>s+v,0)/n;
  let num=0, dx=0, dy=0;
  for(let i=0;i<n;i++){ num += (x[i]-mx)*(y[i]-my); dx += (x[i]-mx)**2; dy += (y[i]-my)**2; }
  const denom = Math.sqrt(dx*dy);
  return denom === 0 ? 0 : num/denom;
}
