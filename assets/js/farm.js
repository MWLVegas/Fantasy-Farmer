console.info('Fantasy Farmer JS loaded: v0.3.15');

let state = null;
let canvas = null;
let ctx = null;
let selectedMode = { type: 'tool', value: 'hoe' };
let tileRects = [];
let pointerCanvasPos = null;
let hoverTile = null;
let isPointerDown = false;
let repeatTimer = null;
let pendingRefresh = null;
let localClockBase = null;
let imageCache = {};
let canvasSceneHits = [];
let lastFx = [];
let canvasFloatFx = [];
let lastSeenOrderId = null;
let orderOpenedId = null;
let lastToolUseAt = 0;

const $ = (selector) => document.querySelector(selector);

function escapeHtml(value) {
  return String(value ?? '').replace(/[&<>'"]/g, char => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
  }[char]));
}

function setTextIfExists(selector, value) {
  const el = $(selector);
  if (el) el.textContent = value;
}

function isImageIcon(icon) {
  return typeof icon === 'string' && icon.includes('.');
}

function iconHtml(icon, className = 'tooltip-inline-icon') {
  if (!icon) return '';
  if (isImageIcon(icon)) {
    return `<img class="${className}" src="${escapeHtml(icon)}" alt="">`;
  }
  return `<span class="${className}">${escapeHtml(icon)}</span>`;
}

function formatMessageIcons(message) {
  const escaped = escapeHtml(message);
  return escaped.replace(/((?:\.\.\/|\.\/)?assets\/[\w\-\/]+\.(?:png|webp|jpg|jpeg|gif|svg))/gi, (match) => {
    return `<img class="status-inline-icon" src="${match}" alt="">`;
  });
}

function renderIcon(icon, className = 'icon') {
  if (!icon) return '';
  if (isImageIcon(icon)) {
    return `<img class="${className}" src="${escapeHtml(icon)}" alt="">`;
  }
  return `<span class="${className} emoji-icon">${escapeHtml(icon)}</span>`;
}

function drawIcon(icon, x, y, size = 42) {
  if (!icon) icon = '❔';

  if (!isImageIcon(icon)) {
    ctx.font = `${Math.floor(size)}px "Segoe UI Emoji", "Apple Color Emoji", system-ui`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(icon, x, y);
    return;
  }

  let img = imageCache[icon];
  if (!img) {
    img = new Image();
    img.src = icon;
    imageCache[icon] = img;
    img.onload = () => {};
  }

  if (!img.complete || !img.naturalWidth) {
    ctx.fillStyle = '#b8a88f';
    ctx.font = `${Math.floor(size * .45)}px system-ui`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('…', x, y);
    return;
  }

  const scale = Math.min(size / img.naturalWidth, size / img.naturalHeight);
  const w = img.naturalWidth * scale;
  const h = img.naturalHeight * scale;
  ctx.drawImage(img, x - w / 2, y - h / 2, w, h);
}

const tooltipEl = document.createElement('div');
tooltipEl.className = 'ff-tooltip hidden';
document.addEventListener('DOMContentLoaded', () => document.body.appendChild(tooltipEl));

function showTooltipHtml(html, evt) {
  if (!html) return;
  tooltipEl.innerHTML = html;
  tooltipEl.classList.remove('hidden');
  positionTooltip(evt);
}

function positionTooltip(evt) {
  if (tooltipEl.classList.contains('hidden')) return;
  const margin = 12;
  const rect = tooltipEl.getBoundingClientRect();
  let x = evt.clientX + 14;
  let y = evt.clientY + 14;

  if (x + rect.width + margin > window.innerWidth) x = evt.clientX - rect.width - 14;
  if (y + rect.height + margin > window.innerHeight) y = evt.clientY - rect.height - 14;

  x = Math.max(margin, Math.min(x, window.innerWidth - rect.width - margin));
  y = Math.max(margin, Math.min(y, window.innerHeight - rect.height - margin));

  tooltipEl.style.left = `${x}px`;
  tooltipEl.style.top = `${y}px`;
}

function hideTooltip() {
  tooltipEl.classList.add('hidden');
}

function bindTooltip(el, html) {
  el.removeAttribute('title');
  el.addEventListener('mouseenter', evt => showTooltipHtml(html, evt));
  el.addEventListener('mousemove', positionTooltip);
  el.addEventListener('mouseleave', hideTooltip);
}

function showStatus(message, isError = false) {
  const box = $('#statusMessage');
  if (!box) return;
  box.innerHTML = formatMessageIcons(message);
  box.className = `status-message visible ${isError ? 'error' : ''}`;
  clearTimeout(showStatus._t);
  showStatus._t = setTimeout(() => box.className = 'status-message', 1600);
}

function addDomFloat(selector, text) {
  const anchor = $(selector);
  if (!anchor) return;
  const rect = anchor.getBoundingClientRect();
  const fx = document.createElement('div');
  fx.className = 'dom-float-fx';
  fx.textContent = text;
  fx.style.left = `${rect.left + rect.width / 2}px`;
  fx.style.top = `${rect.top + rect.height / 2}px`;
  document.body.appendChild(fx);
  setTimeout(() => fx.remove(), 950);
}

function scheduleSync(delay = 700) {
  if (pendingRefresh) clearTimeout(pendingRefresh);
  pendingRefresh = setTimeout(fetchState, delay);
}

async function fetchState() {
  const res = await fetch('api/state.php', { cache: 'no-store' });
  if (res.status === 401) {
    window.location.href = 'login.php';
    return;
  }

  let data;
  try {
    data = await res.json();
  } catch {
    showStatus('Could not load farm state.', true);
    return;
  }

  if (!data.ok) {
    showStatus(data.error || 'Could not load farm.', true);
    return;
  }

  state = data;
  localClockBase = {
    receivedAt: performance.now(),
    day: Number(data.clock?.day || 1),
    progress: Number(data.clock?.day_progress || 0),
    dayLength: Number(data.clock?.day_length_seconds || 720),
    sunIcon: data.clock?.sun_icon || '☀️',
    moonIcon: data.clock?.moon_icon || '🌙'
  };

  render();
}

async function doAction(payload, fx = null, options = {}) {
  if (fx) lastFx.push({ ...fx, createdAt: performance.now() });

  try {
    const res = await fetch('api/action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    const data = await res.json();

    if (!data.ok) {
      showStatus(data.error || 'Action failed.', true);
      scheduleSync(500);
      return null;
    }

    if (!options.silent) showStatus(data.message || 'Done.');
    scheduleSync(650);
    return data;
  } catch {
    showStatus('Action failed.', true);
    scheduleSync(500);
    return null;
  }
}

function roundedPath(x, y, w, h, r) {
  const radius = Math.min(r, w / 2, h / 2);
  ctx.beginPath();
  ctx.moveTo(x + radius, y);
  ctx.lineTo(x + w - radius, y);
  ctx.quadraticCurveTo(x + w, y, x + w, y + radius);
  ctx.lineTo(x + w, y + h - radius);
  ctx.quadraticCurveTo(x + w, y + h, x + w - radius, y + h);
  ctx.lineTo(x + radius, y + h);
  ctx.quadraticCurveTo(x, y + h, x, y + h - radius);
  ctx.lineTo(x, y + radius);
  ctx.quadraticCurveTo(x, y, x + radius, y);
  ctx.closePath();
}

function fillStrokeRound(x, y, w, h, r) {
  roundedPath(x, y, w, h, r);
  ctx.fill();
  ctx.stroke();
}

function currentTabName() {
  return document.querySelector('.tab-button.active')?.dataset?.tab || 'garden';
}

function isModalOpen() {
  return !!document.querySelector('.modal.is-open');
}

function updateCanvasCursor() {
  if (!canvas) return;
  if (isModalOpen()) {
    canvas.style.cursor = 'default';
    return;
  }
  const tab = currentTabName();
  canvas.style.cursor = (tab === 'garden' || tab === 'workers') ? 'none' : 'default';
}

function getTool(type) {
  return state?.tools?.find(t => t.tool_type === type) || null;
}

function getPlot(x, y) {
  return state?.plots?.find(p => Number(p.x_pos) === x && Number(p.y_pos) === y) || null;
}

function cropAt(x, y) {
  if (!state) return null;
  for (const crop of state.crops || []) {
    const ox = Number(crop.origin_x), oy = Number(crop.origin_y);
    const w = Number(crop.width), h = Number(crop.height);
    if (x >= ox && x < ox + w && y >= oy && y < oy + h) return crop;
  }
  return null;
}

function iconForCrop(crop) {
  const step = Number(crop.growth_step_current);
  const max = Number(crop.growth_steps);
  if (step <= 0) return state?.system_icons?.garden_planted_soil || 'assets/icons/garden-planted-soil.png';
  if (step >= max) return crop.mature_icon || '🌾';
  return crop.seed_icon || '🌱';
}

function countInventoryByItemId(itemId) {
  const row = state?.inventory?.find(i => Number(i.item_id) === Number(itemId));
  return row ? Number(row.quantity) : 0;
}

function setPointerFromEvent(evt) {
  const rect = canvas.getBoundingClientRect();
  const sx = canvas.width / rect.width;
  const sy = canvas.height / rect.height;
  pointerCanvasPos = {
    x: (evt.clientX - rect.left) * sx,
    y: (evt.clientY - rect.top) * sy
  };
}

function getTileFromEvent(evt) {
  if (!tileRects.length) return null;
  setPointerFromEvent(evt);
  return tileRects.find(t =>
    pointerCanvasPos.x >= t.x && pointerCanvasPos.x <= t.x + t.size &&
    pointerCanvasPos.y >= t.y && pointerCanvasPos.y <= t.y + t.size
  ) || null;
}

function pouchPosition() {
  if (!state?.pouch) return null;
  return {
    x: 24 + Number(state.pouch.x_ratio) * (canvas.width - 48),
    y: 24 + Number(state.pouch.y_ratio) * (canvas.height - 48)
  };
}

function pouchHit(pointer) {
  if (!state?.pouch || !pointer) return false;
  const pos = pouchPosition();
  if (!pos) return false;
  const dx = pointer.x - pos.x;
  const dy = pointer.y - pos.y;
  return Math.sqrt(dx * dx + dy * dy) <= 38;
}

function handleCanvasMove(evt) {
  if (isModalOpen()) return;
  setPointerFromEvent(evt);
  hoverTile = getTileFromEvent(evt);

  if (pouchHit(pointerCanvasPos)) {
    stopRepeat();
    showTooltipHtml('<b>Pick up pouch</b><br><span class="muted-line">Click to open it.</span>', evt);
    return;
  }

  const tab = currentTabName();
  if ((tab === 'shop' || tab === 'inventory') && pointerCanvasPos) {
    const hit = canvasSceneHits.find(h => pointerCanvasPos.x >= h.x && pointerCanvasPos.x <= h.x + h.w && pointerCanvasPos.y >= h.y && pointerCanvasPos.y <= h.y + h.h);
    if (hit?.tooltip) showTooltipHtml(hit.tooltip, evt);
    else hideTooltip();
    return;
  }

  hideTooltip();
}

function handleCanvasLeave() {
  pointerCanvasPos = null;
  hoverTile = null;
  hideTooltip();
  stopRepeat();
}

function handleCanvasClick(evt) {
  if (isModalOpen()) return;
  setPointerFromEvent(evt);

  const tab = currentTabName();
  if ((tab === 'shop' || tab === 'inventory')) {
    const hit = canvasSceneHits.find(h => pointerCanvasPos.x >= h.x && pointerCanvasPos.x <= h.x + h.w && pointerCanvasPos.y >= h.y && pointerCanvasPos.y <= h.y + h.h);
    if (hit?.action) return hit.action();
  }

  if (pouchHit(pointerCanvasPos)) {
    return doAction({ action: 'collect_pouch', pouch_id: Number(state.pouch.pouch_id) }, { kind: 'pouch', x: pointerCanvasPos.x, y: pointerCanvasPos.y });
  }

  const hit = getTileFromEvent(evt);
  if (hit) performTileAction(hit, pointerCanvasPos);
}

function startRepeat(evt) {
  if (isModalOpen() || selectedMode.type !== 'tool') return;
  setPointerFromEvent(evt);
  hoverTile = getTileFromEvent(evt);
  if (!hoverTile || pouchHit(pointerCanvasPos)) return;
  isPointerDown = true;
  performTileAction(hoverTile, pointerCanvasPos, true);
  repeatTimer = setInterval(() => {
    if (isPointerDown && hoverTile) performTileAction(hoverTile, pointerCanvasPos, true);
  }, 350);
}

function stopRepeat() {
  isPointerDown = false;
  if (repeatTimer) clearInterval(repeatTimer);
  repeatTimer = null;
}

function performTileAction(hit, pointerPos = null, repeating = false) {
  if (!state) return;
  const plot = getPlot(hit.gridX, hit.gridY);
  if (!plot || !Number(plot.is_unlocked)) return showStatus('Locked plot.', true);

  const crop = cropAt(hit.gridX, hit.gridY);
  const fxPoint = { x: pointerPos?.x ?? hit.cx, y: pointerPos?.y ?? hit.cy };

  if (selectedMode.type === 'tool') {
    const now = performance.now();
    if (now - lastToolUseAt < 300) return;
    lastToolUseAt = now;
  }

  if (selectedMode.type === 'tool' && selectedMode.value === 'hoe') {
    plot.till_progress = Math.min(100, Number(plot.till_progress || 0) + Number(getTool('hoe')?.strength || 25));
    plot.is_tilled = Number(plot.till_progress) >= 100 ? 1 : plot.is_tilled;
    doAction({ action: 'till', plot_id: Number(plot.plot_id) }, { kind: 'till', ...fxPoint }, { silent: repeating });
    render();
    return;
  }

  if (selectedMode.type === 'tool' && selectedMode.value === 'watering_can') {
    if (!crop) return showStatus('Nothing to water.', true);
    crop.water_current = Math.min(Number(crop.water_max || 100), Number(crop.water_current || 0) + Number(getTool('watering_can')?.strength || 15));
    doAction({ action: 'water', planted_crop_id: Number(crop.planted_crop_id) }, { kind: 'water', ...fxPoint }, { silent: repeating });
    return;
  }

  if (selectedMode.type === 'tool' && selectedMode.value === 'shovel') {
    if (!crop) return showStatus('Nothing to dig.', true);
    doAction({ action: 'dig', planted_crop_id: Number(crop.planted_crop_id) }, { kind: 'dig', ...fxPoint }, { silent: repeating });
    return;
  }

  if (selectedMode.type === 'seed') {
    if (crop) return showStatus('Something is already growing there.', true);
    doAction({
      action: 'plant',
      garden_id: Number(state.garden.garden_id),
      plant_id: Number(selectedMode.value),
      x: hit.gridX,
      y: hit.gridY
    }, { kind: 'plant', ...fxPoint });
    return;
  }

  if (selectedMode.type === 'harvest') {
    if (!crop) return showStatus('Nothing to harvest.', true);
    doAction({ action: 'harvest', planted_crop_id: Number(crop.planted_crop_id) }, { kind: 'harvest', ...fxPoint });
  }
}

function drawSoilTile(plot, rect) {
  const unlocked = Number(plot.is_unlocked);
  const till = Number(plot.till_progress || 0);
  const tilled = Number(plot.is_tilled);

  ctx.save();
  if (!unlocked) {
    ctx.fillStyle = '#17140f';
    ctx.strokeStyle = 'rgba(255,255,255,.08)';
    fillStrokeRound(rect.x, rect.y, rect.size, rect.size, 10);
    ctx.globalAlpha = .5;
    drawIcon('🔒', rect.cx, rect.cy, rect.size * .22);
    ctx.restore();
    return;
  }

  ctx.fillStyle = tilled ? '#7a5231' : '#684225';
  ctx.strokeStyle = 'rgba(255,255,255,.08)';
  fillStrokeRound(rect.x, rect.y, rect.size, rect.size, 10);

  ctx.globalAlpha = .22 + (till / 100) * .28;
  ctx.strokeStyle = '#d9b071';
  ctx.lineWidth = 2;
  for (let i = 0; i < 7; i++) {
    const yy = rect.y + 14 + i * (rect.size - 28) / 7;
    ctx.beginPath();
    ctx.moveTo(rect.x + 10, yy);
    ctx.quadraticCurveTo(rect.cx, yy + (i % 2 ? 6 : -6), rect.x + rect.size - 10, yy + 2);
    ctx.stroke();
  }

  if (till > 0 && till < 100) {
    ctx.globalAlpha = .85;
    ctx.fillStyle = '#d9a441';
    roundedPath(rect.x + 10, rect.y + rect.size - 12, (rect.size - 20) * (till / 100), 4, 4);
    ctx.fill();
  }
  ctx.restore();
}

function fertilizerForCrop(crop) {
  if (!state?.fertilizers?.length || !crop) return null;
  return state.fertilizers.find(f => Number(f.planted_crop_id) === Number(crop.planted_crop_id)) || null;
}

function drawFertilizerOverlay(crop, rect) {
  const fertilizer = fertilizerForCrop(crop);
  if (!fertilizer) return;

  const icon = fertilizer.visual_icon || fertilizer.icon || '✨';
  ctx.save();
  ctx.globalAlpha = .95;
  ctx.fillStyle = 'rgba(255, 230, 160, .16)';
  roundedPath(rect.x + 12, rect.y + rect.size - 28, rect.size - 24, 10, 8);
  ctx.fill();
  drawIcon(icon, rect.x + rect.size - 22, rect.y + rect.size - 23, 20);
  ctx.restore();
}

function drawCrop(crop, rect) {
  const waterRatio = Math.max(0, Math.min(1, Number(crop.water_current || 0) / Number(crop.water_max || 100)));
  ctx.save();
  drawIcon(iconForCrop(crop), rect.cx, rect.cy - 4, rect.size * .55);

  ctx.globalAlpha = .85;
  ctx.fillStyle = 'rgba(30,70,90,.55)';
  roundedPath(rect.x + 10, rect.y + rect.size - 14, rect.size - 20, 6, 6);
  ctx.fill();
  if (waterRatio > 0) {
    ctx.fillStyle = '#6eb5d9';
    roundedPath(rect.x + 10, rect.y + rect.size - 14, (rect.size - 20) * waterRatio, 6, 6);
    ctx.fill();
  }

  drawFertilizerOverlay(crop, rect);

  if (Number(crop.growth_step_current) >= Number(crop.growth_steps)) {
    ctx.fillStyle = '#ffe6a0';
    ctx.beginPath();
    ctx.arc(rect.x + rect.size - 12, rect.y + 12, 7, 0, Math.PI * 2);
    ctx.fill();
  }
  ctx.restore();
}

function visiblePlotKeys() {
  const keys = new Set();
  const unlocked = (state?.plots || []).filter(p => Number(p.is_unlocked));
  for (const p of unlocked) {
    const x = Number(p.x_pos), y = Number(p.y_pos);
    keys.add(`${x},${y}`);
    for (const [nx, ny] of [[x + 1,y], [x - 1,y], [x,y + 1], [x,y - 1]]) {
      const np = getPlot(nx, ny);
      if (np && !Number(np.is_unlocked)) keys.add(`${nx},${ny}`);
    }
  }
  return keys;
}

function selectedPlant() {
  if (selectedMode.type !== 'seed') return null;
  return state?.plants?.find(p => Number(p.plant_id) === Number(selectedMode.value)) || null;
}

function drawGhost() {
  if (!hoverTile || selectedMode.type !== 'seed' || pouchHit(pointerCanvasPos)) return;
  const plant = selectedPlant();
  if (!plant) return;

  ctx.save();
  for (let y = hoverTile.gridY; y < hoverTile.gridY + Number(plant.height); y++) {
    for (let x = hoverTile.gridX; x < hoverTile.gridX + Number(plant.width); x++) {
      const rect = tileRects.find(t => t.gridX === x && t.gridY === y);
      if (!rect) continue;
      const plot = getPlot(x, y);
      const bad = !plot || !Number(plot.is_unlocked) || !Number(plot.is_tilled) || cropAt(x, y);
      ctx.globalAlpha = .45;
      ctx.fillStyle = bad ? '#d76f5f' : '#8fc46b';
      roundedPath(rect.x + 4, rect.y + 4, rect.size - 8, rect.size - 8, 14);
      ctx.fill();
    }
  }
  drawIcon(plant.seed_icon || '🌱', hoverTile.cx, hoverTile.cy, hoverTile.size * .35);
  ctx.restore();
}

function drawRadiusPreview() {
  if (!isPointerDown || !pointerCanvasPos || selectedMode.type !== 'tool') return;
  const radius = Number(getTool(selectedMode.value)?.radius || 0);
  const pxRadius = radius <= 0 ? 18 : (hoverTile?.size || 100) * (radius + .42);
  ctx.save();
  ctx.globalAlpha = .24;
  ctx.fillStyle = '#9bea74';
  ctx.strokeStyle = '#d9ffbd';
  ctx.lineWidth = 2;
  ctx.beginPath();
  ctx.arc(pointerCanvasPos.x, pointerCanvasPos.y, pxRadius, 0, Math.PI * 2);
  ctx.fill();
  ctx.stroke();
  ctx.restore();
}

function drawPouch() {
  if (!state?.pouch) return;
  const pos = pouchPosition();
  if (!pos) return;
  const pulse = .45 + Math.sin(performance.now() / 180) * .16;
  ctx.save();
  ctx.globalAlpha = .35 + pulse;
  ctx.fillStyle = '#ffe6a0';
  ctx.beginPath();
  ctx.arc(pos.x, pos.y, 24 + pulse * 6, 0, Math.PI * 2);
  ctx.fill();
  ctx.globalAlpha = 1;
  drawIcon(state.clock?.pouch_icon || '👝', pos.x, pos.y, 36);
  ctx.restore();
}

function drawToolCursor() {
  if (isModalOpen() || !pointerCanvasPos) return;
  let icon = null;
  if (selectedMode.type === 'tool') icon = getTool(selectedMode.value)?.icon || '✦';
  if (selectedMode.type === 'harvest') icon = '🧺';
  if (selectedMode.type === 'seed') icon = selectedPlant()?.seed_icon || '🌱';
  if (icon) drawIcon(icon, pointerCanvasPos.x + 20, pointerCanvasPos.y + 20, 30);
}

function drawFx() {
  const now = performance.now();
  lastFx = lastFx.filter(fx => now - fx.createdAt < 650);
  for (const fx of lastFx) {
    const t = (now - fx.createdAt) / 650;
    const icon = { water: '💧', till: '💢', plant: '✨', harvest: '✦', dig: '🪨', pouch: '💨' }[fx.kind] || '✦';
    ctx.save();
    ctx.globalAlpha = 1 - t;
    drawIcon(icon, fx.x, fx.y - t * 32, 30);
    ctx.restore();
  }

  canvasFloatFx = canvasFloatFx.filter(fx => now - fx.createdAt < 900);
  for (const fx of canvasFloatFx) {
    const t = (now - fx.createdAt) / 900;
    ctx.save();
    ctx.globalAlpha = 1 - t;
    drawIcon(fx.icon, fx.x, fx.y - t * 46, 34);
    ctx.font = '800 18px system-ui';
    ctx.fillStyle = '#ffe6a0';
    ctx.textAlign = 'center';
    ctx.fillText(fx.text || '+1', fx.x + 28, fx.y - 18 - t * 46);
    ctx.restore();
  }
}

function drawCanvasSlot(x, y, w, h, options = {}) {
  ctx.save();
  ctx.globalAlpha = options.alpha ?? 1;
  ctx.fillStyle = options.fill || 'rgba(255,255,255,.045)';
  ctx.strokeStyle = options.stroke || 'rgba(255,255,255,.14)';
  ctx.lineWidth = options.lineWidth || 2;
  fillStrokeRound(x, y, w, h, options.radius || 18);
  ctx.restore();
}

function drawInventoryCanvas() {
  canvasSceneHits = [];
  const items = state.inventory || [];
  drawPanelBackground('🎒');
  const cols = 5, slot = 104, gap = 14, startX = 36, startY = 92;
  for (let i = 0; i < Math.max(items.length, 10); i++) {
    const x = startX + (i % cols) * (slot + gap);
    const y = startY + Math.floor(i / cols) * (slot + gap);
    drawCanvasSlot(x, y, slot, slot);
    const item = items[i];
    if (!item) continue;
    drawIcon(item.icon || '❔', x + slot / 2, y + slot / 2 - 8, 42);
    ctx.fillStyle = '#ffe6a0';
    ctx.font = '16px system-ui';
    ctx.textAlign = 'center';
    ctx.fillText(`×${item.quantity}`, x + slot - 24, y + 20);
    canvasSceneHits.push({ x, y, w: slot, h: slot, tooltip: `<b>${escapeHtml(item.name)}</b><br><span class="muted-line">Quantity ${escapeHtml(item.quantity)}</span>` });
  }
}

function drawShopCanvas() {
  canvasSceneHits = [];
  drawPanelBackground('🏪');
  const rows = [
    { title: '🌱', y: 58, items: (state.plants || []).map(p => ({ plant: p, icon: p.seed_icon, name: p.seed_name || p.name, price: Number(p.base_buy_price || 0), action: () => buySeedFromCanvas(p) })) },
    { title: '🛠️', y: 248, items: (state.all_machines || []).map(m => ({ machine: m, icon: m.icon, name: m.name, price: Number(m.base_cost || 0), action: () => buyMachineFromCanvas(m) })) },
    { title: '✨', y: 438, items: [{ icon: '🎁', name: "Today's Special", price: 0, disabled: true, comingSoon: true }] }
  ];
  const slot = 112, gap = 16, startX = 90;
  for (const row of rows) {
    drawIcon(row.title, 44, row.y + slot / 2, 30);
    row.items.slice(0, 5).forEach((item, i) => {
      const x = startX + i * (slot + gap), y = row.y;
      const affordable = Number(state.user.coins) >= item.price;
      const disabled = item.disabled || (!affordable && item.price > 0);
      drawCanvasSlot(x, y, slot, slot, { alpha: disabled ? .58 : 1, stroke: affordable || item.price === 0 ? 'rgba(255,255,255,.16)' : 'rgba(255,135,120,.32)' });
      ctx.globalAlpha = disabled ? .45 : 1;
      drawIcon(item.icon || '❔', x + slot / 2, y + 44, 46);
      ctx.globalAlpha = 1;
      if (item.price > 0) {
        ctx.font = '16px system-ui';
        ctx.textAlign = 'right';
        ctx.fillStyle = affordable ? '#9bea74' : '#ff8778';
        ctx.fillText(`${item.price} 🪙`, x + slot - 12, y + slot - 18);
      } else if (item.comingSoon) {
        ctx.font = '12px system-ui';
        ctx.fillStyle = '#b8a88f';
        ctx.textAlign = 'center';
        ctx.fillText('Coming Soon', x + slot / 2, y + slot - 18);
      }
      const tooltip = item.comingSoon
        ? `<b>Today's Special</b><br><span class="muted-line">Coming soon.</span>`
        : `<b>${escapeHtml(item.name)}</b><br><span class="muted-line">Price ${escapeHtml(item.price)} 🪙</span>${affordable ? '' : '<br><span style="color:#ff8778">Not enough coins</span>'}`;
      canvasSceneHits.push({ x, y, w: slot, h: slot, tooltip, action: (!disabled && item.action) ? item.action : null });
    });
  }
}

function drawPanelBackground(icon) {
  ctx.save();
  ctx.fillStyle = '#211a13';
  ctx.strokeStyle = 'rgba(255,255,255,.08)';
  ctx.lineWidth = 2;
  fillStrokeRound(8, 8, canvas.width - 16, canvas.height - 16, 24);
  drawIcon(icon, 52, 46, 38);
  ctx.restore();
}

function drawSimpleScene(label, icon) {
  canvasSceneHits = [];
  drawPanelBackground(icon);
  ctx.fillStyle = '#b8a88f';
  ctx.font = '24px system-ui';
  ctx.textAlign = 'center';
  ctx.fillText(label, canvas.width / 2, canvas.height / 2 + 42);
}

function drawGardenCanvas() {
  tileRects = [];
  const visibleKeys = visiblePlotKeys();
  const visiblePlots = (state.plots || []).filter(p => visibleKeys.has(`${Number(p.x_pos)},${Number(p.y_pos)}`));
  if (!visiblePlots.length) return;
  const minX = Math.min(...visiblePlots.map(p => Number(p.x_pos)));
  const maxX = Math.max(...visiblePlots.map(p => Number(p.x_pos)));
  const minY = Math.min(...visiblePlots.map(p => Number(p.y_pos)));
  const maxY = Math.max(...visiblePlots.map(p => Number(p.y_pos)));
  const padding = 14, gap = 3;
  const cols = maxX - minX + 1, rows = maxY - minY + 1;
  const usable = Math.min(canvas.width, canvas.height) - padding * 2;
  const size = Math.floor((usable - gap * (Math.max(cols, rows) - 1)) / Math.max(cols, rows));
  const startX = Math.floor((canvas.width - (size * cols + gap * (cols - 1))) / 2);
  const startY = Math.floor((canvas.height - (size * rows + gap * (rows - 1))) / 2);
  drawPanelBackground('');

  for (const plot of visiblePlots) {
    const gridX = Number(plot.x_pos), gridY = Number(plot.y_pos);
    const x = startX + (gridX - minX) * (size + gap);
    const y = startY + (gridY - minY) * (size + gap);
    const rect = { x, y, size, gridX, gridY, cx: x + size / 2, cy: y + size / 2 };
    tileRects.push(rect);
    drawSoilTile(plot, rect);
  }

  for (const crop of state.crops || []) {
    const gridX = Number(crop.origin_x), gridY = Number(crop.origin_y);
    if (!visibleKeys.has(`${gridX},${gridY}`)) continue;
    const w = Number(crop.width), h = Number(crop.height);
    const x = startX + (gridX - minX) * (size + gap);
    const y = startY + (gridY - minY) * (size + gap);
    drawCrop(crop, { x, y, size: Math.min(size * w + gap * (w - 1), size * h + gap * (h - 1)), cx: x + (size * w + gap * (w - 1)) / 2, cy: y + (size * h + gap * (h - 1)) / 2 });
  }

  drawRadiusPreview();
  drawPouch();
  drawGhost();
}

function draw() {
  requestAnimationFrame(draw);
  if (!canvas || !ctx) return;
  ctx.clearRect(0, 0, canvas.width, canvas.height);

  if (!state) {
    ctx.fillStyle = '#211a13';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#b8a88f';
    ctx.font = '24px system-ui';
    ctx.textAlign = 'center';
    ctx.fillText('Loading field...', canvas.width / 2, canvas.height / 2);
    return;
  }

  updateCanvasCursor();
  const tab = currentTabName();
  if (tab === 'shop') drawShopCanvas();
  else if (tab === 'inventory') drawInventoryCanvas();
  else if (tab === 'shed') drawSimpleScene('Shed', '🛖');
  else if (tab === 'workers') drawSimpleScene('Goblins', '🧌');
  else if (tab === 'admin') drawSimpleScene('Admin Debug', '🛠️');
  else drawGardenCanvas();

  drawFx();
  drawToolCursor();
}

function buySeedFromCanvas(plant) {
  if (Number(state.user.coins) < Number(plant.base_buy_price || 0)) return showStatus('Not enough coins.', true);
  const x = pointerCanvasPos?.x || canvas.width / 2, y = pointerCanvasPos?.y || canvas.height / 2;
  state.user.coins -= Number(plant.base_buy_price || 0);
  const existing = state.inventory.find(i => Number(i.item_id) === Number(plant.seed_item_id));
  if (existing) existing.quantity = Number(existing.quantity) + 1;
  else state.inventory.push({ item_id: plant.seed_item_id, name: plant.seed_name || plant.name, item_type: 'seed', icon: plant.seed_icon, quantity: 1, base_sell_price: 0 });
  canvasFloatFx.push({ icon: plant.seed_icon || '🌱', text: '+1', x, y, createdAt: performance.now() });
  render();
  doAction({ action: 'buy_seed', item_id: Number(plant.seed_item_id), quantity: 1 }, null, { silent: true });
}

function buyMachineFromCanvas(machine) {
  if (Number(state.user.coins) < Number(machine.base_cost || 0)) return showStatus('Not enough coins.', true);
  const x = pointerCanvasPos?.x || canvas.width / 2, y = pointerCanvasPos?.y || canvas.height / 2;
  state.user.coins -= Number(machine.base_cost || 0);
  canvasFloatFx.push({ icon: machine.icon || '🛠️', text: '+1', x, y, createdAt: performance.now() });
  render();
  doAction({ action: 'buy_machine', machine_id: Number(machine.machine_id) }, null, { silent: true });
}

function orderRemainingSeconds() {
  if (!state?.order) return 0;
  if (state.order.time_remaining_seconds !== undefined) {
    return Math.max(0, Math.min(3600, Number(state.order.time_remaining_seconds)));
  }
  const expires = new Date(String(state.order.expires_at).replace(' ', 'T')).getTime();
  return Math.max(0, Math.min(3600, Math.floor((expires - Date.now()) / 1000)));
}

function formatSeconds(seconds) {
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}

function renderOrdersButton() {
  const btn = $('#ordersBtn'), timer = $('#ordersTimer'), badge = $('#ordersBadge');
  if (!btn) return;
  if (!state?.order) {
    btn.classList.add('is-empty');
    btn.classList.remove('is-urgent', 'has-new');
    btn.dataset.tooltipHtml = '<b>No orders are ready</b><br><span class="muted-line">Check back soon.</span>';
    if (timer) timer.textContent = '';
    if (badge) badge.style.display = 'none';
    return;
  }
  const orderId = String(state.order.player_order_id);
  if (lastSeenOrderId !== orderId) {
    lastSeenOrderId = orderId;
    if (orderOpenedId !== orderId) btn.classList.add('has-new');
  }
  const seconds = orderRemainingSeconds();
  const text = formatSeconds(seconds);
  if (timer) timer.textContent = text;
  if (badge) badge.style.display = orderOpenedId === orderId ? 'none' : 'grid';
  btn.classList.remove('is-empty');
  btn.classList.toggle('is-urgent', seconds <= 300);
  btn.dataset.tooltipHtml = `<b>Active Order</b><br><span class="muted-line">${escapeHtml(state.order.customer_name)}</span><br><span class="muted-line">${text} remaining</span>`;
}

function updateOrderModalTimer() {
  const timer = $('#orderModalTimer');
  if (timer && state?.order) {
    timer.textContent = formatSeconds(orderRemainingSeconds());
  }
}

function renderOrders() {
  const box = $('#ordersContent');
  if (!box) return;

  if (!state?.order) {
    box.dataset.orderId = '';
    box.innerHTML = '<p class="hint">No active order.</p>';
    return;
  }

  const orderId = String(state.order.player_order_id);
  if (box.dataset.orderId === orderId) {
    updateOrderModalTimer();
    return;
  }

  box.dataset.orderId = orderId;

  const lines = (state.order_items || []).map(item => {
    const have = Number(item.owned_quantity) >= Number(item.quantity_required);
    return `<div class="order-line ${have ? 'have-item' : 'need-item'}"><span>${renderIcon(item.icon, 'shop-icon')} ${escapeHtml(item.name)}</span><b>${item.owned_quantity}/${item.quantity_required}</b></div>`;
  }).join('');

  box.innerHTML = `
    <div class="order-card">
      <p class="order-code">#${escapeHtml(state.order.order_code)}</p>
      <p>👤 ${escapeHtml(state.order.customer_name)}</p>
      <p>⌛ <b id="orderModalTimer" class="order-modal-timer">${formatSeconds(orderRemainingSeconds())}</b></p>
      ${lines}
      <p>💰 ${state.order.payment_coins}</p>
      <button type="button" id="fulfillOrderBtn">✅</button>
    </div>
  `;

  $('#fulfillOrderBtn')?.addEventListener('click', async () => {
    addDomFloat('#coinCount', `+${state.order.payment_coins} 🪙`);
    await doAction({ action: 'fulfill_order', player_order_id: Number(state.order.player_order_id) }, null, { silent: true });
    closeOrdersModal();
    fetchState();
  });
}

function openOrdersModal() {
  if (!state?.order) {
    showStatus('No orders are ready.', true);
    return;
  }
  orderOpenedId = String(state.order.player_order_id);
  $('#ordersBtn')?.classList.remove('has-new');
  const modal = $('#ordersModal');
  modal.classList.add('is-open');
  document.body.classList.add('modal-open');
  renderOrders();
}

function closeOrdersModal() {
  const modal = $('#ordersModal');
  modal.classList.remove('is-open');
  document.body.classList.remove('modal-open');
  hideTooltip();
}

function renderClock() {
  if (!localClockBase) return;
  const elapsed = (performance.now() - localClockBase.receivedAt) / (localClockBase.dayLength * 1000);
  const progress = (localClockBase.progress + elapsed) % 1;
  const day = localClockBase.day + Math.floor(localClockBase.progress + elapsed);
  const halfHour = Math.floor(progress * 48) / 48;
  const minutes = Math.floor(halfHour * 1440);
  const hour = Math.floor(minutes / 60), minute = minutes % 60;
  setTextIfExists('#dayLabel', `Day ${day} · ${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`);
  const orb = $('#dayOrb');
  if (orb) {
    orb.style.left = `${progress * 100}%`;
    orb.textContent = (hour >= 6 && hour < 18) ? localClockBase.sunIcon : localClockBase.moonIcon;
  }
}

function renderTools() {
  const grid = $('#toolGrid');
  if (!grid) return;
  grid.innerHTML = '';
  for (const tool of state.tools || []) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `icon-card ${selectedMode.type === 'tool' && selectedMode.value === tool.tool_type ? 'selected' : ''}`;
    btn.innerHTML = renderIcon(tool.icon, 'big-icon');
    bindTooltip(btn, `<b>${escapeHtml(tool.name)}</b><br><span class="muted-line">Power ${escapeHtml(tool.strength)}</span><br><span class="muted-line">Radius ${escapeHtml(tool.radius)}</span>`);
    btn.addEventListener('click', () => { selectedMode = { type: 'tool', value: tool.tool_type }; render(); });
    grid.appendChild(btn);
  }
  const harvest = document.createElement('button');
  harvest.type = 'button';
  harvest.className = `icon-card ${selectedMode.type === 'harvest' ? 'selected' : ''}`;
  harvest.innerHTML = renderIcon('🧺', 'big-icon');
  bindTooltip(harvest, '<b>Harvest</b><br><span class="muted-line">Harvest ready crops</span>');
  harvest.addEventListener('click', () => { selectedMode = { type: 'harvest', value: 'harvest' }; render(); });
  grid.appendChild(harvest);
}

function renderSeeds() {
  const grid = $('#seedGrid');
  if (!grid) return;
  grid.innerHTML = '';
  for (const plant of state.plants || []) {
    const owned = countInventoryByItemId(plant.seed_item_id);
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `icon-card ${selectedMode.type === 'seed' && Number(selectedMode.value) === Number(plant.plant_id) ? 'selected' : ''}`;
    btn.disabled = owned <= 0;
    btn.innerHTML = `${renderIcon(plant.seed_icon, 'big-icon')}<b class="qty-badge">×${owned}</b>`;
    bindTooltip(btn, `<b>${escapeHtml(plant.name)}</b><br><span class="muted-line">${plant.width}×${plant.height}</span><br><span class="muted-line">Owned ×${owned}</span>`);
    btn.addEventListener('click', () => { selectedMode = { type: 'seed', value: Number(plant.plant_id) }; render(); });
    grid.appendChild(btn);
  }
}

function renderShop() {
  const sells = $('#sellList');
  if (!sells) return;
  sells.innerHTML = '';
  const sellable = (state.inventory || []).filter(item => Number(item.base_sell_price) > 0);
  if (!sellable.length) {
    sells.innerHTML = '<p class="hint">Nothing worth selling yet.</p>';
    return;
  }
  for (const item of sellable) {
    const row = document.createElement('div');
    row.className = 'shop-row';
    row.innerHTML = `<div class="shop-main"><span class="sell-icon-wrap">${renderIcon(item.icon, 'shop-icon')}<b class="sell-qty-badge">×${item.quantity}</b></span><span>${escapeHtml(item.name)}</span></div><button type="button">Sell ${item.base_sell_price} 🪙</button>`;
    row.querySelector('button').addEventListener('click', () => {
      if (Number(item.quantity) <= 0) return;
      item.quantity = Number(item.quantity) - 1;
      state.user.coins = Number(state.user.coins) + Number(item.base_sell_price);
      state.inventory = state.inventory.filter(i => Number(i.quantity) > 0);
      addDomFloat('#coinCount', `+${item.base_sell_price} 🪙`);
      render();
      doAction({ action: 'sell_item', item_id: Number(item.item_id), quantity: 1 }, null, { silent: true });
    });
    sells.appendChild(row);
  }
}

function renderShed() {
  const machines = $('#machineList'), list = $('#processingList');
  if (machines) {
    machines.innerHTML = '';
    for (const m of state.machines || []) {
      const row = document.createElement('div');
      row.className = 'shop-row';
      row.innerHTML = `<div class="shop-main">${renderIcon(m.icon, 'shop-icon')}<span>${escapeHtml(m.name)} ×${m.quantity}</span></div>`;
      machines.appendChild(row);
    }
    if (!state.machines?.length) machines.innerHTML = '<p class="hint">The shed echoes. Dramatically.</p>';
  }
  if (list) list.innerHTML = '<p class="hint">Processing canvas coming soon.</p>';
}

function renderWorkers() {
  const hireList = $('#workerHireList'), workerList = $('#workerList'), plantOrderList = $('#plantOrderList');
  if (hireList) hireList.innerHTML = '<p class="hint">Goblin hiring scaffold.</p>';
  if (workerList) workerList.innerHTML = '';
  if (plantOrderList) plantOrderList.innerHTML = '';
}

function renderAdmin() {
  const tab = $('#adminTabButton');
  if (tab) tab.classList.toggle('hidden', !state?.is_admin);
  const add = $('#adminAddCoinsBtn');
  if (add && !add.dataset.bound) {
    add.dataset.bound = '1';
    add.addEventListener('click', () => {
      state.user.coins = Number(state.user.coins) + 1000;
      addDomFloat('#coinCount', '+1000 🪙');
      render();
      doAction({ action: 'admin_add_coins' }, null, { silent: true });
    });
  }
}

function render() {
  if (!state) return;
  setTextIfExists('#coinCount', state.user.coins);
  setTextIfExists('#gardenName', `${state.garden.name} — ${state.garden.garden_type_name}`);
  renderClock();
  renderOrdersButton();
  renderTools();
  renderSeeds();
  renderShop();
  renderShed();
  renderWorkers();
  renderAdmin();
  renderOrders();
}

function setupTabs() {
  document.querySelectorAll('.tab-button').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
      btn.classList.add('active');
      document.querySelector(`[data-panel="${btn.dataset.tab}"]`)?.classList.add('active');
      updateCanvasCursor();
      hideTooltip();
    });
  });
}

document.addEventListener('mouseover', evt => {
  const el = evt.target.closest?.('[data-tooltip-html], [data-tip]');
  if (!el) return;
  showTooltipHtml(el.dataset.tooltipHtml || escapeHtml(el.dataset.tip || ''), evt);
});
document.addEventListener('mousemove', evt => {
  const el = evt.target.closest?.('[data-tooltip-html], [data-tip]');
  if (el) positionTooltip(evt);
});
document.addEventListener('mouseout', evt => {
  const el = evt.target.closest?.('[data-tooltip-html], [data-tip]');
  if (el) hideTooltip();
});
document.addEventListener('click', evt => {
  if (evt.target.closest?.('[data-close-modal]')) {
    evt.preventDefault();
    closeOrdersModal();
    return;
  }
  if (evt.target?.id === 'ordersModal') closeOrdersModal();
});
document.addEventListener('keydown', evt => {
  if (evt.key === 'Escape') closeOrdersModal();
});

document.addEventListener('DOMContentLoaded', () => {
  canvas = $('#gardenCanvas');
  ctx = canvas.getContext('2d');

  canvas.addEventListener('mousemove', handleCanvasMove);
  canvas.addEventListener('mouseleave', handleCanvasLeave);
  canvas.addEventListener('click', handleCanvasClick);
  canvas.addEventListener('mousedown', startRepeat);
  window.addEventListener('mouseup', stopRepeat);

  $('#ordersBtn')?.addEventListener('click', openOrdersModal);

  setupTabs();
  fetchState();
  requestAnimationFrame(draw);
  setInterval(renderClock, 1000);
  setInterval(() => {
    if (state?.order) {
      if (state.order.time_remaining_seconds !== undefined) {
        state.order.time_remaining_seconds = Math.max(0, Number(state.order.time_remaining_seconds) - 1);
      }
      renderOrdersButton();
      if ($('#ordersModal')?.classList.contains('is-open')) updateOrderModalTimer();
    }
  }, 1000);
  setInterval(fetchState, 15000);
});
