// ── Map ───────────────────────────────────────────────────────
const map = L.map('map', { zoomControl: true, scrollWheelZoom: true })
  .setView([0, 0], 2);

L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', {
  attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> · CARTO',
  maxZoom: 19, subdomains: 'abcd',
}).addTo(map);

// ── Shared state ──────────────────────────────────────────────
let PHOTOS = [], NO_GPS = [], TRIPS = [];
let markers = [], markerCluster = null;
let lbCurrent = { list: 'gps', i: -1 };
let activeTrip = null;
const tripCollapsed = new Set();

// ── Utilities ─────────────────────────────────────────────────
const esc = s => String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

function fmtDateTime(s) {
  if (!s || s === '—') return '—';
  const exif = s.match(/^(\d{4}):(\d{2}):(\d{2})\s(\d{2}):(\d{2})/);
  if (exif) return `${exif[1]}/${exif[2]}/${exif[3]} ${exif[4]}:${exif[5]}`;
  const iso = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
  if (iso) return iso[4] ? `${iso[1]}/${iso[2]}/${iso[3]} ${iso[4]}:${iso[5]}` : `${iso[1]}/${iso[2]}/${iso[3]}`;
  return s;
}

// ── Sidebar virtual scroll ────────────────────────────────────
const $body = document.getElementById('sidebarBody');
const ROW_H = 62, LABEL_H = 36, TRIP_H = 48, BUFFER = 8;

let FLAT = [], TOPS = null, TOTAL_H = 0;
let _rvStart = -1, _rvEnd = -1, _rvActiveKind = null, _rvActiveI = -1;

function itemHeight(item) {
  if (item.type === 'trip_header') return TRIP_H;
  if (item.type === 'label')       return LABEL_H;
  if (item.type === 'empty')       return LABEL_H;
  return ROW_H;
}

function rebuildFlat() {
  FLAT = [];
  const slugsToShow = activeTrip ? [activeTrip] : TRIPS.map(t => t.slug);

  for (const slug of slugsToShow) {
    const trip = TRIPS.find(t => t.slug === slug);
    if (!trip) continue;

    const tripGps   = PHOTOS.map((p, i) => ({ p, i })).filter(({ p }) => p.trip === slug);
    const tripNoGps = NO_GPS.map((p, i) => ({ p, i })).filter(({ p }) => p.trip === slug);
    if (!tripGps.length && !tripNoGps.length) continue;

    const collapsed = tripCollapsed.has(slug);
    FLAT.push({ type: 'trip_header', slug, label: trip.label, color: trip.color,
                gpsCount: tripGps.length, noGpsCount: tripNoGps.length, collapsed });

    if (!collapsed) {
      for (const { p, i } of tripGps)   FLAT.push({ type: 'row', kind: 'gps',   idx: i, p });
      for (const { p, i } of tripNoGps) FLAT.push({ type: 'row', kind: 'nogps', idx: i, p });
    }
  }

  if (!FLAT.length) FLAT.push({ type: 'empty' });

  const tops = new Int32Array(FLAT.length + 1);
  for (let i = 0; i < FLAT.length; i++) tops[i + 1] = tops[i] + itemHeight(FLAT[i]);
  TOPS    = tops;
  TOTAL_H = tops[FLAT.length];
  _rvStart = _rvEnd = -1;
}

function renderItem(item) {
  if (item.type === 'trip_header') {
    const cls = item.collapsed ? ' is-collapsed' : '';
    return `<div class="trip-header${cls}" data-slug="${esc(item.slug)}">` +
      `<span class="trip-indicator" style="background:${esc(item.color)}"></span>` +
      `<span class="trip-name">${esc(item.label)}</span>` +
      `<span class="trip-count">${item.gpsCount + item.noGpsCount}</span>` +
      `<span class="trip-chevron">▾</span></div>`;
  }
  if (item.type === 'label') {
    return `<div class="section-label"><span class="num">${item.count.toString().padStart(2,'0')}</span><span>${item.text}</span><span class="line"></span></div>`;
  }
  if (item.type === 'empty') {
    return `<p style="padding:10px;font-size:11px;color:var(--ink-600)">No photos found.</p>`;
  }
  const { kind, idx, p } = item;
  const active = lbCurrent.list === kind && lbCurrent.i === idx ? ' is-active' : '';
  const dateStr = fmtDateTime(p.date);
  let locHtml;
  if (kind === 'gps') {
    locHtml = p.loc ? `<span>${esc(p.loc)}</span>`
                    : `<span><span class="gps-dot">◉</span> ${p.lat.toFixed(4)}, ${p.lng.toFixed(4)}</span>`;
  } else {
    locHtml = '<span>no GPS</span>';
  }
  return `<div class="photo-row${kind === 'nogps' ? ' no-gps' : ''}${active}" data-kind="${kind}" data-idx="${idx}" style="--tc:${esc(p.trip_color)}">` +
    `<div class="thumb" style="background-image:url('${encodeURI(p.thumb)}')"></div>` +
    `<div class="info"><div class="name">${esc(dateStr)}</div><div class="meta">${locHtml}</div></div>` +
    `</div>`;
}

function renderVirtual() {
  if (!TOPS) return;
  const st = $body.scrollTop;
  const ch = $body.clientHeight || 400;

  let lo = 0, hi = FLAT.length;
  while (lo < hi) {
    const mid = (lo + hi) >> 1;
    if (TOPS[mid + 1] <= st) lo = mid + 1; else hi = mid;
  }
  const start = Math.max(0, lo - BUFFER);
  let end = lo;
  while (end < FLAT.length && TOPS[end] < st + ch) end++;
  end = Math.min(FLAT.length, end + BUFFER);

  if (start === _rvStart && end === _rvEnd
      && lbCurrent.list === _rvActiveKind && lbCurrent.i === _rvActiveI) return;
  _rvStart = start; _rvEnd = end;
  _rvActiveKind = lbCurrent.list; _rvActiveI = lbCurrent.i;

  let html = `<div style="height:${TOPS[start]}px" aria-hidden="true"></div>`;
  for (let i = start; i < end; i++) html += renderItem(FLAT[i]);
  html += `<div style="height:${Math.max(0, TOTAL_H - TOPS[end])}px" aria-hidden="true"></div>`;
  $body.innerHTML = html;
}

// Event delegation for sidebar clicks
$body.addEventListener('click', e => {
  const header = e.target.closest('.trip-header');
  if (header) {
    const slug = header.dataset.slug;
    if (tripCollapsed.has(slug)) tripCollapsed.delete(slug);
    else tripCollapsed.add(slug);
    rebuildFlat();
    renderVirtual();
    return;
  }
  const row = e.target.closest('.photo-row');
  if (row) {
    const kind = row.dataset.kind, idx = +row.dataset.idx;
    if (kind === 'gps') {
      markerCluster.zoomToShowLayer(markers[idx], () => {});
      openLightbox(idx, false);
    } else {
      openLightbox(idx, true);
    }
  }
});

function scrollSidebarTo(kind, i) {
  const fi = FLAT.findIndex(it => it.type === 'row' && it.kind === kind && it.idx === i);
  if (fi >= 0) {
    const top = TOPS[fi], bot = TOPS[fi + 1], st = $body.scrollTop, ch = $body.clientHeight;
    if (top < st + 8 || bot > st + ch - 8) $body.scrollTop = Math.max(0, top - 60);
  }
  renderVirtual();
}

$body.addEventListener('scroll', renderVirtual, { passive: true });

document.getElementById('sidebarClose').addEventListener('click', () =>
  document.getElementById('sidebar').classList.add('is-collapsed'));
document.getElementById('sidebarOpen').addEventListener('click', () => {
  document.getElementById('sidebar').classList.remove('is-collapsed');
  renderVirtual();
});

// ── Trip pill-bar ─────────────────────────────────────────────
const $pills = document.getElementById('tripPills');

function buildPillBar(trips, initialActive) {
  $pills.innerHTML = '';
  if (!trips.length) { $pills.style.display = 'none'; return; }

  const allBtn = document.createElement('button');
  allBtn.className = 'trip-pill' + (!initialActive ? ' is-active' : '');
  allBtn.dataset.trip = '';
  allBtn.textContent = 'All';
  allBtn.addEventListener('click', () => applyTripFilter(null));
  $pills.appendChild(allBtn);

  for (const trip of trips) {
    const btn = document.createElement('button');
    btn.className = 'trip-pill' + (initialActive === trip.slug ? ' is-active' : '');
    btn.dataset.trip = trip.slug;

    const dot = document.createElement('span');
    dot.className = 'pill-dot';
    dot.style.background = trip.color;

    const label = document.createElement('span');
    label.textContent = trip.label;

    const copyBtn = document.createElement('span');
    copyBtn.className = 'pill-copy';
    copyBtn.role = 'button';
    copyBtn.tabIndex = 0;
    copyBtn.title = 'Copy link to this trip';
    copyBtn.innerHTML = '<svg viewBox="0 0 24 24" width="11" height="11" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 01-2-2V4a2 2 0 012-2h9a2 2 0 012 2v1"/></svg>';
    const doCopy = e => {
      e.stopPropagation();
      const url = new URL(location.href);
      url.searchParams.set('trip', trip.slug);
      navigator.clipboard.writeText(url.toString()).catch(() => {});
      copyBtn.title = 'Copied!';
      setTimeout(() => { copyBtn.title = 'Copy link to this trip'; }, 1500);
    };
    copyBtn.addEventListener('click', doCopy);
    copyBtn.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); doCopy(e); } });

    btn.addEventListener('click', () => applyTripFilter(activeTrip === trip.slug ? null : trip.slug));
    btn.appendChild(dot);
    btn.appendChild(label);
    btn.appendChild(copyBtn);
    $pills.appendChild(btn);
  }
}

function applyTripFilter(slug) {
  activeTrip = slug;

  $pills.querySelectorAll('.trip-pill').forEach(btn => {
    btn.classList.toggle('is-active', btn.dataset.trip === (slug ?? ''));
  });

  markerCluster.clearLayers();
  markers.forEach((m, i) => {
    if (!slug || PHOTOS[i].trip === slug) markerCluster.addLayer(m);
  });

  const gps = slug ? PHOTOS.filter(p => p.trip === slug) : PHOTOS;
  if (gps.length > 0) {
    map.fitBounds(L.latLngBounds(gps.map(p => [p.lat, p.lng])), {
      paddingTopLeft: [20, 96], paddingBottomRight: [364, 24],
    });
  }

  rebuildFlat();
  renderVirtual();
}

// ── Lightbox ──────────────────────────────────────────────────
const $lb         = document.getElementById('lightbox');
const $lbMedia    = document.getElementById('lbMedia');
const $lbImage    = document.getElementById('lbImage');
const $lbNow      = document.getElementById('lbNow');
const $lbTotal    = document.getElementById('lbTotal');
const $lbThumbs   = document.getElementById('lbThumbs');
const $lbLocation = document.getElementById('lbLocation');
let activeMarkerEl = null;

const getList = kind => kind === 'gps' ? PHOTOS : NO_GPS;

const THUMB_WINDOW = 9;

function renderThumbs(kind, activeIdx) {
  const list  = getList(kind);
  const half  = Math.floor(THUMB_WINDOW / 2);
  const start = Math.max(0, Math.min(activeIdx - half, list.length - THUMB_WINDOW));
  const end   = Math.min(list.length, start + THUMB_WINDOW);
  $lbThumbs.innerHTML = '';
  for (let i = start; i < end; i++) {
    const div = document.createElement('div');
    div.className = 't' + (i === activeIdx ? ' is-active' : '');
    div.dataset.i = i;
    div.style.backgroundImage = `url('${encodeURI(list[i].thumb)}')`;
    div.addEventListener('click', () => showAt(lbCurrent.list, i));
    $lbThumbs.appendChild(div);
  }
}

function showAt(kind, i) {
  const list = getList(kind);
  if (!list.length) return;
  i = (i + list.length) % list.length;
  lbCurrent = { list: kind, i };
  const p = list[i];

  $lbMedia.classList.remove('loaded');
  $lbMedia._errEl = $lbMedia._errEl || (() => {
    const el = document.createElement('p');
    el.style.cssText = 'position:absolute;inset:0;display:none;align-items:center;justify-content:center;color:var(--ink-600);font-size:12px;margin:0';
    el.textContent = 'Could not load image';
    $lbMedia.appendChild(el);
    return el;
  })();
  $lbImage.onload  = () => { $lbMedia._errEl.style.display = 'none'; $lbMedia.classList.add('loaded'); };
  $lbImage.onerror = () => { $lbMedia._errEl.style.display = 'flex'; $lbMedia.classList.add('loaded'); };
  $lbImage.src = p.path;

  $lbNow.textContent   = (i+1).toString().padStart(2,'0');
  $lbTotal.textContent = list.length.toString().padStart(2,'0');

  if (kind === 'gps') {
    $lbLocation.textContent = p.loc || `${p.lat.toFixed(4)}, ${p.lng.toFixed(4)}`;
    $lbLocation.style.display = '';
  } else {
    $lbLocation.style.display = 'none';
  }

  if (activeMarkerEl) activeMarkerEl.classList.remove('is-active');
  activeMarkerEl = kind === 'gps'
    ? document.querySelector(`.photo-marker[data-idx="${i}"]`) : null;
  if (activeMarkerEl) activeMarkerEl.classList.add('is-active');
  scrollSidebarTo(kind, i);
  renderThumbs(kind, i);
}

function openLightbox(i, isNoGps = false) {
  const kind = isNoGps ? 'nogps' : 'gps';
  $lb.style.display = 'flex';
  requestAnimationFrame(() => $lb.classList.add('is-open'));
  $lb.setAttribute('aria-hidden', 'false');
  showAt(kind, i);
}

function closeLightbox() {
  $lb.classList.remove('is-open');
  $lb.setAttribute('aria-hidden', 'true');
  $lb.addEventListener('transitionend', () => { $lb.style.display = ''; }, { once: true });
  if (activeMarkerEl) { activeMarkerEl.classList.remove('is-active'); activeMarkerEl = null; }
  lbCurrent = { list: lbCurrent.list, i: -1 };
  _rvActiveI = -1;
  renderVirtual();
}

function nudge(d) {
  showAt(lbCurrent.list, lbCurrent.i + d);
  if (lbCurrent.list === 'gps') {
    const p = PHOTOS[lbCurrent.i];
    map.panTo([p.lat, p.lng], { animate: true, duration: .5 });
  }
}

document.getElementById('lbPrev').addEventListener('click', () => nudge(-1));
document.getElementById('lbNext').addEventListener('click', () => nudge(+1));
document.getElementById('lbClose').addEventListener('click', closeLightbox);
$lb.addEventListener('click', e => { if (e.target === $lb) closeLightbox(); });
document.addEventListener('keydown', e => {
  if (!$lb.classList.contains('is-open')) return;
  if (e.key === 'Escape')     closeLightbox();
  if (e.key === 'ArrowLeft')  nudge(-1);
  if (e.key === 'ArrowRight') nudge(+1);
});

// ── App init ──────────────────────────────────────────────────
function initApp(photos, noGps, trips, initialTrip) {
  PHOTOS = photos;
  NO_GPS = noGps;
  TRIPS  = trips;
  activeTrip = initialTrip ?? null;

  buildPillBar(trips, activeTrip);

  markerCluster = L.markerClusterGroup({
    maxClusterRadius: 60,
    showCoverageOnHover: false,
    iconCreateFunction(cluster) {
      const n = cluster.getChildCount();
      return L.divIcon({ html: `<div class="ci">${n}</div>`, className: 'photo-cluster', iconSize: [44, 44], iconAnchor: [22, 22] });
    },
  });

  markers = PHOTOS.map((p, i) => {
    const icon = L.divIcon({
      className: 'photo-marker-wrap',
      html: `<div class="photo-marker" data-idx="${i}" style="--mc:${p.trip_color}"><span class="thumb" style="background-image:url('${encodeURI(p.thumb)}')"></span></div>`,
      iconSize: [42, 42], iconAnchor: [21, 21],
    });
    const m = L.marker([p.lat, p.lng], { icon });
    m.on('click', () => openLightbox(i, false));
    return m;
  });

  // Add only markers for the active trip (or all if no filter)
  markers.forEach((m, i) => {
    if (!activeTrip || PHOTOS[i].trip === activeTrip) markerCluster.addLayer(m);
  });
  map.addLayer(markerCluster);

  // fitBounds to active trip or all photos
  const gpsForBounds = activeTrip ? PHOTOS.filter(p => p.trip === activeTrip) : PHOTOS;
  if (gpsForBounds.length > 0) {
    map.fitBounds(L.latLngBounds(gpsForBounds.map(p => [p.lat, p.lng])), {
      paddingTopLeft:     [20, 96],
      paddingBottomRight: [364, 24],
    });
  }

  rebuildFlat();
  renderVirtual();
}

fetch('?api=photos')
  .then(r => { if (!r.ok) throw new Error(`HTTP ${r.status}`); return r.json(); })
  .then(d => {
    initApp(d.photos, d.no_gps, d.trips ?? [], d.active_trip ?? null);
  })
  .catch(err => {
    const p = document.createElement('p');
    p.style.cssText = 'padding:20px 10px;font-size:11px;color:var(--ink-600)';
    p.textContent = `Error loading photos: ${err.message}`;
    $body.replaceChildren(p);
  });