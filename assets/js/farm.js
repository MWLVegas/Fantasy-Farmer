console.info('Fantasy Farmer JS loaded: v0.4.9');

let state = null;
let canvas = null;
let ctx = null;
let selectedMode = { type: 'tool', value: 'hoe' };
let activeScreen = 'map';
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
let lastProgressSnapshot = null;
let activeStoryKey = null;

const REP_ICON = '⭐';
const REC_ICON = '🏵️';
const REP_TOOLTIP = '<b>Reputation</b><br><span class="muted-line">Local trust earned from orders.</span>';
const REC_TOOLTIP = '<b>Recognition</b><br><span class="muted-line">World progress from milestones and visitors.</span>';
const RUSH_ICON = '⚡';

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

function setIconHtml(el, icon, className = 'inline-icon') {
  if (!el) return;
  el.innerHTML = renderIcon(icon, className);
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

function mountTooltip() {
  if (!tooltipEl.parentNode && document.body) document.body.appendChild(tooltipEl);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', mountTooltip);
} else {
  mountTooltip();
}

function showTooltipHtml(html, evt) {
  if (!html) return;
  mountTooltip();
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
  const previousProgress = state ? {
    reputation: Number(state.progress?.reputation ?? state.user?.reputation ?? 0),
    recognition: Number(state.progress?.recognition ?? state.user?.recognition ?? 0)
  } : null;

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

  const nextProgress = {
    reputation: Number(data.progress?.reputation ?? data.user?.reputation ?? 0),
    recognition: Number(data.progress?.recognition ?? data.user?.recognition ?? 0)
  };
  if (previousProgress) {
    const repDelta = nextProgress.reputation - previousProgress.reputation;
    const recDelta = nextProgress.recognition - previousProgress.recognition;
    if (repDelta > 0) addDomFloat('#reputationCount', `+${repDelta} ${REP_ICON}`);
    if (recDelta > 0) addDomFloat('#recognitionCount', `+${recDelta} ${REC_ICON}`);
  }
  lastProgressSnapshot = nextProgress;

  localClockBase = {
    receivedAt: performance.now(),
    day: Number(data.clock?.day || 1),
    progress: Number(data.clock?.day_progress || 0),
    dayLength: Number(data.clock?.day_length_seconds || 720),
    sunIcon: data.clock?.sun_icon || '☀️',
    moonIcon: data.clock?.moon_icon || '🌙'
  };

  render();
  maybeOpenStoryEvent();
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
  return activeScreen || 'map';
}

function clearGardenInteractionState() {
  stopRepeat();
  hoverTile = null;
  pointerCanvasPos = null;
  hideTooltip();
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
  canvas.style.cursor = tab === 'garden' ? 'none' : 'default';
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

function inventoryItemByCode(code) {
  return (state?.inventory || []).find(i => i.code === code) || null;
}

function addRewardsLocally(rewards = [], x = null, y = null) {
  const fxX = x ?? canvas?.width / 2 ?? 360;
  const fxY = y ?? canvas?.height / 2 ?? 360;
  rewards.forEach((reward, index) => {
    if (!reward?.code) return;
    const existing = (state.inventory || []).find(i => i.code === reward.code);
    if (existing) existing.quantity = Number(existing.quantity || 0) + Number(reward.quantity || 1);
    else {
      state.inventory = state.inventory || [];
      state.inventory.push({
        code: reward.code,
        name: reward.name || reward.code,
        icon: reward.icon || '✦',
        quantity: Number(reward.quantity || 1),
        item_type: reward.item_type || 'material',
        base_sell_price: 0
      });
    }
    canvasFloatFx.push({ icon: reward.icon || '✦', text: `+${reward.quantity || 1}`, x: fxX + index * 28, y: fxY, createdAt: performance.now() });
  });
}

function removeInventoryCodeLocally(code, quantity = 1) {
  const item = inventoryItemByCode(code);
  if (!item) return;
  item.quantity = Math.max(0, Number(item.quantity || 0) - quantity);
  state.inventory = (state.inventory || []).filter(i => Number(i.quantity || 0) > 0);
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

function relicPosition() {
  if (!state?.relic_pickup) return null;
  return {
    x: 24 + Number(state.relic_pickup.x_ratio || .5) * (canvas.width - 48),
    y: 24 + Number(state.relic_pickup.y_ratio || .5) * (canvas.height - 48)
  };
}

function relicHit(pointer) {
  if (!state?.relic_pickup || !pointer) return false;
  const pos = relicPosition();
  if (!pos) return false;
  const dx = pointer.x - pos.x;
  const dy = pointer.y - pos.y;
  return Math.sqrt(dx * dx + dy * dy) <= 42;
}

function handleCanvasMove(evt) {
  if (isModalOpen()) return;
  setPointerFromEvent(evt);
  const tab = currentTabName();

  if (tab !== 'garden') {
    hoverTile = null;
    stopRepeat();
    const hit = canvasSceneHits.find(h => pointerCanvasPos.x >= h.x && pointerCanvasPos.x <= h.x + h.w && pointerCanvasPos.y >= h.y && pointerCanvasPos.y <= h.y + h.h);
    if (hit?.tooltip) showTooltipHtml(hit.tooltip, evt);
    else hideTooltip();
    return;
  }

  hoverTile = getTileFromEvent(evt);

  if (relicHit(pointerCanvasPos)) {
    stopRepeat();
    showTooltipHtml('<b>Unearthed Relic</b><br><span class="muted-line">Click to inspect it.</span>', evt);
    return;
  }

  if (pouchHit(pointerCanvasPos)) {
    stopRepeat();
    showTooltipHtml('<b>Pick up pouch</b><br><span class="muted-line">Click to open it.</span>', evt);
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
  if (tab !== 'garden') {
    const hit = canvasSceneHits.find(h => pointerCanvasPos.x >= h.x && pointerCanvasPos.x <= h.x + h.w && pointerCanvasPos.y >= h.y && pointerCanvasPos.y <= h.y + h.h);
    if (hit?.action) return hit.action();
    return;
  }

  if (relicHit(pointerCanvasPos)) {
    return openRelicFoundModal(state.relic_pickup);
  }

  if (pouchHit(pointerCanvasPos)) {
    return doAction({ action: 'collect_pouch', pouch_id: Number(state.pouch.pouch_id) }, { kind: 'pouch', x: pointerCanvasPos.x, y: pointerCanvasPos.y });
  }

  const hit = getTileFromEvent(evt);
  if (hit) performTileAction(hit, pointerCanvasPos);
}

function startRepeat(evt) {
  if (isModalOpen() || currentTabName() !== 'garden' || selectedMode.type !== 'tool') return;
  setPointerFromEvent(evt);
  hoverTile = getTileFromEvent(evt);
  if (!hoverTile || pouchHit(pointerCanvasPos) || relicHit(pointerCanvasPos)) return;
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

function drawRelicPickup() {
  if (!state?.relic_pickup) return;
  const pos = relicPosition();
  if (!pos) return;
  const pulse = .42 + Math.sin(performance.now() / 220) * .18;
  ctx.save();
  ctx.globalAlpha = .32 + pulse;
  ctx.fillStyle = '#9de7ff';
  ctx.beginPath();
  ctx.arc(pos.x, pos.y, 26 + pulse * 8, 0, Math.PI * 2);
  ctx.fill();
  ctx.globalAlpha = 1;
  drawIcon(state.relic_pickup.icon || '🔹', pos.x, pos.y, 38);
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
  if (isModalOpen() || currentTabName() !== 'garden' || !pointerCanvasPos) return;
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
    const icon = { water: '💧', till: '💢', plant: '✨', harvest: '✦', dig: '🪨', pouch: '💨', relic: '🔹' }[fx.kind] || '✦';
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
    const canUseBell = item.code === 'fairy_bell' && !hasUnlockKey('helpers_unlocked');
    const tooltip = `<b>${escapeHtml(item.name)}</b><br><span class="muted-line">Quantity ${escapeHtml(item.quantity)}</span>${canUseBell ? '<br><span class="muted-line">Click to ring it.</span>' : ''}`;
    canvasSceneHits.push({ x, y, w: slot, h: slot, tooltip, action: canUseBell ? openFairyBellModal : null });
  }
}

function switchScreen(tab) {
  if (!tab) tab = 'map';
  if (tab !== 'garden') clearGardenInteractionState();
  activeScreen = tab;
  document.querySelectorAll('.tab-button').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.dataset.panel === tab));
  const backBtn = $('#backToMapBtn');
  if (backBtn) backBtn.hidden = tab === 'map';
  const sideBackBtn = document.querySelector('[data-side-map-button]');
  if (sideBackBtn) sideBackBtn.hidden = tab === 'map';
  updateCanvasCursor();
  hideTooltip();
  render();
}

function getMapButtonPositions() {
  const defaults = {
    garden: [canvas.width / 2 - 62, canvas.height / 2 - 62],
    shop: [110, canvas.height / 2 - 70],
    orders: [canvas.width / 2 - 62, 105],
    shed: [canvas.width - 235, canvas.height / 2 - 70],
    market: [canvas.width / 2 - 62, 500],
    caravan: [canvas.width - 250, 510],
    bone_brine: [90, 510],
    helpers: [canvas.width - 245, 125]
  };

  let custom = {};
  const raw = state?.map_config?.button_positions_json || '';
  if (raw) {
    try { custom = JSON.parse(raw); } catch { custom = {}; }
  }

  for (const [key, value] of Object.entries(custom || {})) {
    if (Array.isArray(value) && value.length >= 2) {
      defaults[key] = [Number(value[0]), Number(value[1])];
      continue;
    }
    if (value && typeof value === 'object') {
      if (Number.isFinite(Number(value.x)) && Number.isFinite(Number(value.y))) defaults[key] = [Number(value.x), Number(value.y)];
      if (Number.isFinite(Number(value.center_x)) && Number.isFinite(Number(value.center_y))) defaults[key] = [Number(value.center_x) - 62, Number(value.center_y) - 62];
    }
  }
  return defaults;
}

function drawMapBackground() {
  const bg = state?.map_config?.background_image || '';
  if (!bg || !isImageIcon(bg)) {
    drawPanelBackground('🗺️');
    return;
  }

  ctx.save();
  ctx.fillStyle = '#211a13';
  ctx.strokeStyle = 'rgba(255,255,255,.08)';
  ctx.lineWidth = 2;
  fillStrokeRound(8, 8, canvas.width - 16, canvas.height - 16, 24);

  let img = imageCache[bg];
  if (!img) {
    img = new Image();
    img.src = bg;
    img.onload = () => render();
    imageCache[bg] = img;
  }

  if (img.complete && img.naturalWidth) {
    ctx.save();
    roundedPath(10, 10, canvas.width - 20, canvas.height - 20, 22);
    ctx.clip();
    const scale = Math.max((canvas.width - 20) / img.naturalWidth, (canvas.height - 20) / img.naturalHeight);
    const w = img.naturalWidth * scale;
    const h = img.naturalHeight * scale;
    ctx.globalAlpha = .82;
    ctx.drawImage(img, 10 + ((canvas.width - 20) - w) / 2, 10 + ((canvas.height - 20) - h) / 2, w, h);
    ctx.globalAlpha = .38;
    ctx.fillStyle = '#120f0b';
    ctx.fillRect(10, 10, canvas.width - 20, canvas.height - 20);
    ctx.restore();
  } else {
    drawIcon('🗺️', 52, 46, 38);
  }
  ctx.restore();
}

function drawMapCanvas() {
  canvasSceneHits = [];
  drawMapBackground();
  const locations = state.locations || [];
  const positions = getMapButtonPositions();
  ctx.fillStyle = '#d9a441';
  ctx.font = '800 22px system-ui';
  ctx.textAlign = 'center';
  ctx.fillText('Overhead Map', canvas.width / 2, 62);
  ctx.font = '14px system-ui';
  ctx.fillStyle = '#b8a88f';
  ctx.fillText('A tiny town, a suspicious garden, and several locked problems.', canvas.width / 2, 86);

  for (const loc of locations) {
    const pos = positions[loc.key];
    if (!pos) continue;
    const [x, y] = pos;
    const unlocked = !!loc.unlocked;
    drawCanvasSlot(x, y, 124, 124, { alpha: unlocked ? 1 : .48, stroke: unlocked ? 'rgba(143,196,107,.35)' : 'rgba(255,255,255,.10)' });
    const displayName = unlocked ? loc.name : '???';
    const displayIcon = unlocked ? (loc.icon || '❔') : '?';
    ctx.globalAlpha = unlocked ? 1 : .55;
    drawIcon(displayIcon, x + 62, y + 44, 42);
    ctx.globalAlpha = 1;
    ctx.font = '800 14px system-ui';
    ctx.fillStyle = unlocked ? '#f5ead8' : '#b8a88f';
    ctx.textAlign = 'center';
    ctx.fillText(displayName, x + 62, y + 88);
    if (!unlocked) {
      ctx.font = '12px system-ui';
      ctx.fillStyle = '#b8a88f';
      ctx.fillText('Unknown', x + 62, y + 108);
    }
    const tooltip = unlocked
      ? `<b>${escapeHtml(loc.name)}</b><br><span class="muted-line">${escapeHtml(loc.hint || '')}</span>`
      : '<b>???</b><br><span class="muted-line">An unknown place. Keep building your reputation.</span>';
    canvasSceneHits.push({ x, y, w: 124, h: 124, tooltip, action: unlocked ? () => switchScreen(loc.key === 'bone_brine' ? 'bone_brine' : loc.key) : null });
  }
}

function drawOrdersCanvas() {
  canvasSceneHits = [];
  drawPanelBackground('📜');
  ctx.fillStyle = '#d9a441';
  ctx.font = '800 22px system-ui';
  ctx.textAlign = 'center';
  const confirmed = state.orders || [];
  const available = state.available_orders || [];
  const slotLimit = Number(state?.order_slot_limit || 2);
  const availableLimit = Number(state?.available_order_limit || 5);
  ctx.fillText(`Orders Board`, canvas.width / 2, 58);
  ctx.font = '14px system-ui';
  ctx.fillStyle = '#b8a88f';
  ctx.fillText(`Confirmed Orders: ${confirmed.length}/${slotLimit} · Available Orders: ${available.length}/${availableLimit}`, canvas.width / 2, 82);

  let y = 112;
  ctx.textAlign = 'left';
  ctx.font = '800 17px system-ui';
  ctx.fillStyle = '#f5ead8';
  ctx.fillText('Confirmed Orders', 70, y);
  y += 16;
  if (!confirmed.length) {
    ctx.font = '14px system-ui';
    ctx.fillStyle = '#b8a88f';
    ctx.fillText('No confirmed orders. Accept one below when you are ready to commit.', 70, y + 26);
    y += 54;
  } else {
    confirmed.slice(0, 3).forEach(order => {
      drawOrderBoardCard(order, 70, y, canvas.width - 140, 72, true);
      y += 82;
    });
  }

  y += 16;
  ctx.font = '800 17px system-ui';
  ctx.fillStyle = '#f5ead8';
  ctx.fillText('Available Orders', 70, y);
  y += 16;
  if (!available.length) {
    ctx.font = '14px system-ui';
    ctx.fillStyle = '#b8a88f';
    ctx.fillText('No available orders right now. New requests arrive every few minutes.', 70, y + 26);
    return;
  }
  available.slice(0, 5).forEach(order => {
    drawOrderBoardCard(order, 70, y, canvas.width - 140, 70, false);
    y += 80;
  });
}

function drawOrderBoardCard(order, x, y, w, h, confirmed) {
  const seconds = orderTimeRemaining(order);
  const isRush = order.order_type === 'rush';
  const isLate = confirmed && Number(order.is_late || 0) === 1;
  drawCanvasSlot(x, y, w, h, {
    fill: isRush ? 'rgba(255, 203, 107, .105)' : (isLate ? 'rgba(255,110,90,.09)' : 'rgba(255,255,255,.045)'),
    stroke: isRush ? 'rgba(255,203,107,.42)' : (isLate ? 'rgba(255,110,90,.38)' : 'rgba(255,255,255,.14)')
  });
  ctx.textAlign = 'left';
  ctx.fillStyle = '#f5ead8';
  ctx.font = '800 16px system-ui';
  const title = `${order.customer_name || 'Customer'}${isRush ? ' · Rush Order!' : ''}`;
  ctx.fillText(title, x + 18, y + 22);

  ctx.font = '14px system-ui';
  ctx.fillStyle = '#b8a88f';
  const lines = orderLines(order, { showOwned: confirmed }).join(' · ');
  ctx.fillText(lines || 'Unknown goods', x + 18, y + 43);

  ctx.font = '800 14px system-ui';
  ctx.fillStyle = '#b8a88f';
  ctx.textAlign = 'right';
  const timerLabel = confirmed ? (isLate ? 'Late' : `Due ${formatSeconds(seconds)}`) : formatSeconds(seconds);
  ctx.fillText(timerLabel, x + w - 18, y + 24);

  ctx.textAlign = 'left';
  ctx.fillStyle = isLate ? '#ffb199' : '#9bea74';
  const reward = orderRewardText(order, confirmed, { html: false });
  ctx.fillText(reward, x + 18, y + 63);
  const tooltip = confirmed ? '<b>Confirmed Order</b><br><span class="muted-line">Click to review, complete, or cancel.</span>' : '<b>Available Order</b><br><span class="muted-line">Click to inspect and accept.</span>';
  canvasSceneHits.push({ x, y, w, h, tooltip, action: () => openOrdersModal(order.player_order_id) });
}


function drawHelpersCanvas() {
  canvasSceneHits = [];
  drawPanelBackground('🧚');
  const unlocked = hasUnlockKey('helpers_unlocked');
  ctx.fillStyle = '#d9a441';
  ctx.font = '800 22px system-ui';
  ctx.textAlign = 'center';
  ctx.fillText('Forest Folk', canvas.width / 2, 62);
  ctx.font = '15px system-ui';
  ctx.fillStyle = '#b8a88f';
  ctx.fillText('Helpers are summoned, then equipped with amulets/charms for automation.', canvas.width / 2, 90);
  const x = 110, y = 142, w = canvas.width - 220, h = 150;
  drawCanvasSlot(x, y, w, h, { stroke: unlocked ? 'rgba(143,196,107,.35)' : 'rgba(217,164,65,.28)' });
  drawIcon(unlocked ? '💧' : '🔔', x + 74, y + 75, 58);
  ctx.textAlign = 'left';
  ctx.font = '800 19px system-ui';
  ctx.fillStyle = '#f5ead8';
  ctx.fillText(unlocked ? 'Water Fairy Assigned' : 'First Fairy Bell', x + 140, y + 52);
  ctx.font = '15px system-ui';
  ctx.fillStyle = '#b8a88f';
  const hasBell = !!inventoryItemByCode('fairy_bell');
  const text = unlocked ? 'Aqua Amulet equipped. Water magic: enthusiastic, probably splashy.' : (hasBell ? 'Ring the bell to consume it and summon your first fairy.' : 'Find a relic, meet Madam Rune, and get wildly confused by a bell.');
  wrapCanvasText(text, x + 140, y + 82, w - 170, 20);
  if (!unlocked && hasBell) {
    canvasSceneHits.push({ x, y, w, h, tooltip: '<b>Ring the Fairy Bell</b><br><span class="muted-line">Consumes the bell and summons the first water fairy.</span>', action: openFairyBellModal });
  }
}

function wrapCanvasText(text, x, y, maxWidth, lineHeight) {
  const words = String(text).split(' ');
  let line = '';
  for (const word of words) {
    const test = line ? line + ' ' + word : word;
    if (ctx.measureText(test).width > maxWidth && line) {
      ctx.fillText(line, x, y);
      line = word;
      y += lineHeight;
    } else line = test;
  }
  if (line) ctx.fillText(line, x, y);
}

function hasUnlockKey(key) {
  return (state?.unlocks || []).some(u => u.unlock_key === key);
}

async function openFairyBellModal() {
  const data = await doAction({ action: 'start_fairy_bell_event' }, null, { silent: true });
  if (data?.story_event) {
    state.story_event = data.story_event;
    activeStoryKey = null;
    openDatabaseStoryEvent(data.story_event);
  }
}

function orderLines(order, { showOwned = true } = {}) {
  const items = state?.order_items_by_order?.[String(order.player_order_id)] || state?.order_items_by_order?.[Number(order.player_order_id)] || [];
  return items.map(item => {
    const qty = Number(item.quantity_required || 0);
    const name = escapeHtml(item.name || 'Item');
    return showOwned ? `${name} ${Number(item.owned_quantity || 0)}/${qty}` : `${name} ×${qty}`;
  });
}

function orderTimeRemaining(order) {
  if (!order) return 0;
  if (order.time_remaining_seconds !== undefined) return Math.max(0, Math.min(7200, Number(order.time_remaining_seconds)));
  const expires = new Date(String(order.expires_at).replace(' ', 'T')).getTime();
  return Math.max(0, Math.min(7200, Math.floor((expires - Date.now()) / 1000)));
}

function tooltipIconHtml(icon, tooltipHtml) {
  return `<span class="reward-tooltip-icon" data-tooltip-html="${escapeHtml(tooltipHtml)}">${escapeHtml(icon)}</span>`;
}

function orderRewardText(order, confirmed = true, { html = true } = {}) {
  const late = confirmed && Number(order.is_late || 0) === 1;
  const coins = Number(order.payment_coins || 0);
  const rep = Number(order.reputation_reward || 1);
  const rec = Number(order.recognition_reward || 0);
  const repIcon = html ? tooltipIconHtml(REP_ICON, REP_TOOLTIP) : REP_ICON;
  const recIcon = html ? tooltipIconHtml(REC_ICON, REC_TOOLTIP) : REC_ICON;
  if (late) {
    const lateCoins = Number(order.late_payment_coins || Math.floor(coins * .8));
    const fee = Number(order.late_total_penalty_percent || order.late_fee_percent || 20);
    const label = order.order_type === 'rush' ? 'Missed Rush' : 'Late Fee';
    return `🪙 ${lateCoins} (-${fee}% ${label})`;
  }
  const rushText = order.order_type === 'rush' ? ' (+20% Rush Fee)' : '';
  const repText = rep ? ` · ${repIcon} ${rep}` : '';
  const recText = rec ? ` · ${recIcon} ${rec}` : '';
  return `🪙 ${coins}${rushText}${repText}${recText}`;
}

function weeklySpecialItems() {
  const woodenHoe = (state.all_tools || []).find(t => t.code === 'wooden_hoe');
  const ownsWoodenHoe = (state.tools || []).some(t => t.code === 'wooden_hoe');
  if (woodenHoe && !ownsWoodenHoe) {
    return [{ tool: woodenHoe, icon: woodenHoe.icon, name: 'Weekly Special: Wooden Hoe', price: Number(woodenHoe.upgrade_cost || 75), action: () => buyToolFromCanvas(woodenHoe) }];
  }
  return [{ icon: '🎁', name: "This Week's Special", price: 0, disabled: true, comingSoon: true }];
}

function drawShopCanvas() {
  canvasSceneHits = [];
  drawPanelBackground('🏪');
  const rows = [
    { title: '🌱', y: 58, items: (state.plants || []).map(p => ({ plant: p, icon: p.seed_icon, name: p.seed_name || p.name, price: Number(p.base_buy_price || 0), action: () => buySeedFromCanvas(p) })) },
    { title: '🛠️', y: 248, items: (state.all_machines || []).map(m => ({ machine: m, icon: m.icon, name: m.name, price: Number(m.base_cost || 0), action: () => buyMachineFromCanvas(m) })) },
    { title: '✨', y: 438, items: weeklySpecialItems() }
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
  drawRelicPickup();
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
  if (tab === 'map') drawMapCanvas();
  else if (tab === 'shop') drawShopCanvas();
  else if (tab === 'orders') drawOrdersCanvas();
  else if (tab === 'inventory') drawInventoryCanvas();
  else if (tab === 'shed') drawSimpleScene('Workroom / Shed', '🛖');
  else if (tab === 'helpers') drawHelpersCanvas();
  else if (tab === 'market') drawSimpleScene('Farmer\'s Market', '🎪');
  else if (tab === 'caravan') drawSimpleScene('Caravan Camp', '🔮');
  else if (tab === 'bone_brine') drawSimpleScene('Bone & Brine', '☠️');
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

function buyToolFromCanvas(tool) {
  if (Number(state.user.coins) < Number(tool.upgrade_cost || 0)) return showStatus('Not enough coins.', true);
  const x = pointerCanvasPos?.x || canvas.width / 2, y = pointerCanvasPos?.y || canvas.height / 2;
  state.user.coins -= Number(tool.upgrade_cost || 0);
  state.tools = (state.tools || []).filter(t => t.tool_type !== tool.tool_type || Number(t.level) >= Number(tool.level));
  if (!(state.tools || []).some(t => Number(t.tool_id) === Number(tool.tool_id))) state.tools.push(tool);
  canvasFloatFx.push({ icon: tool.icon || '🛠️', text: 'Upgrade', x, y, createdAt: performance.now() });
  render();
  doAction({ action: 'buy_tool', tool_id: Number(tool.tool_id) }, null, { silent: true });
}

function orderRemainingSeconds(order = state?.order) {
  return orderTimeRemaining(order);
}

function formatSeconds(seconds) {
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}

function renderOrders(selectedOrderId = null) {
  const box = $('#ordersContent');
  if (!box) return;
  const allOrders = [...(state?.orders || []), ...(state?.available_orders || [])];
  const orders = selectedOrderId ? allOrders.filter(o => String(o.player_order_id) === String(selectedOrderId)) : allOrders;

  if (!orders.length) {
    box.dataset.orderListKey = '';
    box.innerHTML = '<p class="hint">No orders are ready.</p>';
    return;
  }

  const stableKey = `${selectedOrderId || 'all'}:` + orders.map(o => {
    const items = state.order_items_by_order?.[String(o.player_order_id)] || state.order_items_by_order?.[Number(o.player_order_id)] || [];
    return `${o.player_order_id}:${o.order_status}:${o.time_remaining_seconds}:${o.is_late}:${items.map(i => `${i.item_id}:${i.owned_quantity}/${i.quantity_required}`).join(',')}`;
  }).join('|');
  if (box.dataset.orderListKey === stableKey) {
    updateOrderModalTimer();
    return;
  }

  box.dataset.orderListKey = stableKey;
  const confirmedCount = (state.orders || []).length;
  const slotLimit = Number(state.order_slot_limit || 2);
  const slotsFull = confirmedCount >= slotLimit;

  box.innerHTML = orders.map(order => {
    const confirmed = order.order_status === 'accepted';
    const available = order.order_status === 'available';
    const items = state.order_items_by_order?.[String(order.player_order_id)] || state.order_items_by_order?.[Number(order.player_order_id)] || [];
    const lines = items.map(item => {
      const have = Number(item.owned_quantity) >= Number(item.quantity_required);
      const qtyText = confirmed ? `${item.owned_quantity}/${item.quantity_required}` : `×${item.quantity_required}`;
      return `<div class="order-line ${confirmed && have ? 'have-item' : 'need-item'}"><span>${renderIcon(item.icon, 'shop-icon')} ${escapeHtml(item.name)}</span><b>${qtyText}</b></div>`;
    }).join('');
    const acceptClass = slotsFull ? 'danger-button' : '';
    const acceptDisabled = slotsFull ? 'disabled' : '';
    const dueLine = confirmed
      ? `<p>⌛ Order due: <b class="order-modal-timer" data-order-timer="${escapeHtml(order.player_order_id)}">${formatSeconds(orderRemainingSeconds(order))}</b>${order.order_type === 'rush' ? ` <b class="rush-label">(${RUSH_ICON} Rush Order!)</b>` : ''}</p>`
      : `<p>⌛ Available for: <b class="order-modal-timer" data-order-timer="${escapeHtml(order.player_order_id)}">${formatSeconds(orderRemainingSeconds(order))}</b></p><p class="hint">Deadline: <b>${Number(order.fulfillment_minutes || 60)} minutes</b>${order.order_type === 'rush' ? ` <b class="rush-label">(${RUSH_ICON} Rush Order!)</b>` : ''}</p>`;
    const actionButtons = available
      ? `<button type="button" class="${acceptClass}" ${acceptDisabled} data-accept-order="${escapeHtml(order.player_order_id)}">${slotsFull ? 'Confirm unavailable — slots full' : 'Confirm Order'}</button>`
      : `<div class="order-actions"><button type="button" data-fulfill-order="${escapeHtml(order.player_order_id)}">✅ Complete</button><button type="button" class="danger-button" data-cancel-order="${escapeHtml(order.player_order_id)}">Cancel (-${order.cancel_reputation_penalty || 1} ${REP_ICON})</button></div>`;
    return `
      <div class="order-card ${order.order_type === 'rush' ? 'is-rush' : ''} ${Number(order.is_late || 0) ? 'is-late' : ''}" data-order-card="${escapeHtml(order.player_order_id)}">
        <p class="order-code">#${escapeHtml(order.order_code)}${order.order_type === 'rush' ? ' · ⚡ Rush Order!' : ''}</p>
        <p>👤 ${escapeHtml(order.customer_name)}</p>
        ${dueLine}
        ${lines}
        <p data-order-reward="${escapeHtml(order.player_order_id)}">${orderRewardText(order, confirmed)}</p>
        ${actionButtons}
      </div>
    `;
  }).join('');

  box.querySelectorAll('[data-accept-order]').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (btn.disabled) return;
      const id = Number(btn.dataset.acceptOrder);
      await doAction({ action: 'accept_order', player_order_id: id }, null, { silent: true });
      closeOrdersModal();
      fetchState();
    });
  });

  box.querySelectorAll('[data-fulfill-order]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = Number(btn.dataset.fulfillOrder);
      const order = (state.orders || []).find(o => Number(o.player_order_id) === id);
      if (order) addDomFloat('#coinCount', `+${Number(order.is_late || 0) ? (order.late_payment_coins || Math.floor(order.payment_coins * .8)) : order.payment_coins} 🪙`);
      await doAction({ action: 'fulfill_order', player_order_id: id }, null, { silent: true });
      closeOrdersModal();
      fetchState();
    });
  });

  box.querySelectorAll('[data-cancel-order]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = Number(btn.dataset.cancelOrder);
      await doAction({ action: 'cancel_order', player_order_id: id }, null, { silent: true });
      addDomFloat('#reputationCount', `-1 ${REP_ICON}`);
      closeOrdersModal();
      fetchState();
    });
  });
}


function updateOrderModalTimer() {
  document.querySelectorAll('[data-order-timer]').forEach(el => {
    const all = [...(state.orders || []), ...(state.available_orders || [])];
    const order = all.find(o => String(o.player_order_id) === String(el.dataset.orderTimer));
    if (order) el.textContent = formatSeconds(orderRemainingSeconds(order));
  });
  document.querySelectorAll('[data-order-reward]').forEach(el => {
    const all = [...(state.orders || []), ...(state.available_orders || [])];
    const order = all.find(o => String(o.player_order_id) === String(el.dataset.orderReward));
    if (order) el.innerHTML = orderRewardText(order, order.order_status === 'accepted');
  });
}


function openOrdersModal(orderId = null) {
  const allOrders = [...(state?.orders || []), ...(state?.available_orders || [])];
  if (!allOrders.length) {
    showStatus('No orders are ready.', true);
    return;
  }
  const selected = orderId ? allOrders.find(o => String(o.player_order_id) === String(orderId)) : allOrders[0];
  orderOpenedId = selected ? String(selected.player_order_id) : null;
  $('#ordersBtn')?.classList.remove('has-new');
  const modal = $('#ordersModal');
  modal.classList.add('is-open');
  document.body.classList.add('modal-open');
  renderOrders(orderOpenedId);
}


function closeOrdersModal() {
  const modal = $('#ordersModal');
  modal.classList.remove('is-open');
  document.body.classList.remove('modal-open');
  hideTooltip();
}

function openStoryModal({ title, body, button = 'Okay', closeable = false, onNext = null }) {
  const modal = $('#storyModal');
  const titleEl = $('#storyTitle');
  const bodyEl = $('#storyContent');
  const btn = $('#storyNextBtn');
  const close = $('#storyCloseBtn');
  if (!modal || !titleEl || !bodyEl || !btn) return;
  titleEl.textContent = title;
  bodyEl.innerHTML = body;
  btn.textContent = button;
  if (close) close.hidden = !closeable;
  btn.onclick = async () => {
    if (onNext) await onNext();
  };
  modal.classList.add('is-open');
  document.body.classList.add('modal-open');
  updateCanvasCursor();
}

function closeStoryModal() {
  const modal = $('#storyModal');
  if (modal) modal.classList.remove('is-open');
  document.body.classList.remove('modal-open');
  updateCanvasCursor();
  hideTooltip();
}

function openRelicFoundModal(relic) {
  if (!relic) return;
  openStoryModal({
    title: 'Something Strange Unearthed',
    body: `<p>While tilling the soil, your hoe strikes something that is neither root nor stone.</p>
      <p>You kneel down and carefully dig the object free, brushing away the dirt with your fingertips. Whatever it is, it’s old — far older than anything that should be buried in an ordinary garden.</p>
      <p>The strange relic is unlike anything you’ve ever seen, and yet it hums with a quiet sort of importance. You’re not sure what it is, but you are absolutely certain of one thing: it would look marvelous on your mantle.</p>`,
    button: 'Take the Relic',
    closeable: true,
    onNext: async () => {
      const pos = relicPosition() || { x: canvas.width / 2, y: canvas.height / 2 };
      const data = await doAction({ action: 'collect_relic', relic_id: Number(relic.relic_id) }, { kind: 'relic', x: pos.x, y: pos.y }, { silent: true });
      if (data?.ok) {
        state.relic_pickup = null;
        addRewardsLocally(data.rewards || [], pos.x, pos.y);
        closeStoryModal();
        fetchState();
      }
    }
  });
}

const MADAM_RUNE_PAGES = [
  {
    title: 'A Caravan Arrives',
    button: '... okay?',
    body: `<p>Around midday, the quiet of your garden is interrupted by the creak of old wheels and the soft clatter of hanging charms.</p>
      <p>A peculiar wagon rolls to a stop nearby, draped in moss, trailing vines, and enough dangling trinkets to worry any sensible horse.</p>
      <p>From within emerges a goblin woman wrapped in layered fabrics, jangling jewelry, and a confidence usually reserved for people who know what they are doing.</p>
      <p>“The hands of Fate have brought a visitor! ... or was it the <em>hams</em> of Fate?”</p>
      <p>She squints at you, then gasps.</p>
      <p>“Someone comes bearing <strong>DESTINY!</strong> ... and perhaps coupons!”</p>
      <p>She pats her pockets, frowns, then looks suddenly delighted.</p>
      <p>“Ah! Yes. Introductions. My name is... Sister Sa— no, no, that was Tuesday. Lady Cri— wait. What day even <em>is</em> it?”</p>
      <p>She throws both hands into the air.</p>
      <p>“Oh! Of course. <strong>I am Madam Rune!</strong>”</p>`
  },
  {
    title: 'Madam Rune Peers at the Relic',
    button: '... um ... okay?',
    body: `<p>Madam Rune leans in toward the relic, her eyes widening until you begin to worry they might simply leave her head.</p>
      <p>“Ohhh. Oh, that is <em>old</em>. Old Empire, unless I am mistaken. And I am only mistaken on Wednesdays, in matters of soup, and once about a goose.”</p>
      <p>She taps the relic with one long fingernail.</p>
      <p>“A vessel, you see. It once held <strong>aetherglimmer</strong> — or perhaps <strong>thrumlight</strong>. No, wait. Aetherglimmer. Definitely aetherglimmer. Probably.”</p>
      <p>She presses it to her ear and smiles.</p>
      <p>“Empty now, of course. But it still hums. Beautifully useless. My favorite kind of important.”</p>
      <p>She rummages through her robes and produces three similar relics, each wrapped in bits of cloth and string.</p>
      <p>“I have three others. Each one hums at a different pitch. But with yours? Ah! A quartet! A divine little quartet of forgotten imperial nonsense!”</p>
      <p>Then she holds up an old bell.</p>
      <p>“I tried using this thing, but it is far too high-pitched, and the fae would not leave it alone. Tiny winged busybodies. Always listening. Always curious. Always asking if mushrooms count as chairs.”</p>
      <p>She places the bell in your hand, then adds a damp-looking crystal beside it.</p>
      <p>“And this! An Aqua Amulet. It has the power to make <strong>anything wet</strong>. Simply place it upon the item you wish to moisten, pour water over it, and behold! Moisture!”</p>
      <p>She nods gravely, as if she has just explained fire.</p>`
  },
  {
    title: 'The Relic Finds Its Place',
    button: 'Okay.',
    body: `<p>Madam Rune carefully takes the relic and carries it to her wagon, where three similarly shaped objects rest on a velvet cloth.</p>
      <p>She sets yours beside them.</p>
      <p>Then moves it slightly.</p>
      <p>Then slightly back.</p>
      <p>Then forward by what cannot possibly be more than a hair’s width.</p>
      <p>Several minutes pass.</p>
      <p>At last, she clasps her hands together and beams.</p>
      <p>“THERE! Beautiful! That sound is... the most clarity-inducing noise I have encountered in all my centuries!”</p>
      <p>You listen closely.</p>
      <p>You hear absolutely nothing.</p>
      <p>Madam Rune, wearing a smile that seems to go on for days, sweeps back into her caravan. The door slams shut, the wheels creak, and the whole thing begins to roll away.</p>
      <p>As she disappears down the road, she hollers back:</p>
      <p>“If you find any more, save them for me! I’ll have a whole choir soon, just like the prophecy foretold!”</p>
      <p>More confused than ever, you stow the bell and the moist rock, then turn back toward your garden.</p>`
  }
];

function applyEventEffectsLocally(effects, x = canvas.width / 2, y = canvas.height / 2) {
  if (!effects) return;
  for (const removed of effects.removed || []) {
    if (removed.code) removeInventoryCodeLocally(removed.code, Number(removed.quantity || 1));
  }
  if ((effects.rewards || []).length) {
    addRewardsLocally(effects.rewards || [], x, y);
  }
}

function openDatabaseStoryEvent(event) {
  if (!event) return;
  openStoryModal({
    title: event.title || event.event_title || 'Event',
    body: event.body_html || '<p>Something happens.</p>',
    button: event.button_text || 'Okay',
    closeable: false,
    onNext: async () => {
      const data = await doAction({ action: 'advance_story_event', event_key: event.event_key || event.key }, null, { silent: true });
      if (!data?.ok) return;
      applyEventEffectsLocally(data.effects, canvas.width / 2, canvas.height / 2);
      if (data.story_event) {
        state.story_event = data.story_event;
        openDatabaseStoryEvent(data.story_event);
        return;
      }
      closeStoryModal();
      activeStoryKey = `${event.event_key || event.key}_done`;
      if ((event.event_key || event.key) === 'fairy_bell_summon') switchScreen('helpers');
      fetchState();
    }
  });
}

function openMadamRuneIntro(pageIndex = 0) {
  if (state?.story_event) openDatabaseStoryEvent(state.story_event);
}

function maybeOpenStoryEvent() {
  if (isModalOpen()) return;
  const event = state?.story_event;
  if (!event?.key || activeStoryKey === `${event.key}:${event.step_order || 1}`) return;
  activeStoryKey = `${event.key}:${event.step_order || 1}`;
  openDatabaseStoryEvent(event);
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
    setIconHtml(orb, (hour >= 6 && hour < 18) ? localClockBase.sunIcon : localClockBase.moonIcon, 'day-orb-icon');
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

function renderLocations() {
  const list = $('#locationList');
  if (!list) return;
  list.innerHTML = '<p class="hint">Use the map canvas to travel. Locked places stay mysterious until the story reveals them.</p>';
}

function renderOrdersButton() {
  const btn = $('#ordersBtn');
  const timer = $('#ordersTimer');
  const badge = $('#ordersBadge');
  if (!btn || !timer) return;
  const confirmed = (state?.orders || []).length;
  const limit = Number(state?.order_slot_limit || 2);
  const available = (state?.available_orders || []).length;
  const availableLimit = Number(state?.available_order_limit || 5);
  timer.textContent = `${confirmed}/${limit}`;
  btn.dataset.tooltipHtml = `<b>Orders Board</b><br><span class="muted-line">Confirmed ${confirmed}/${limit}. Available ${available}/${availableLimit}.</span>`;
  btn.classList.toggle('is-urgent', (state?.orders || []).some(o => Number(o.is_late || 0) === 1));
  if (badge) badge.classList.toggle('visible', available > 0);
}

function renderOrdersBoardList() {
  const list = $('#ordersBoardList');
  if (!list) return;
  const confirmed = (state?.orders || []).length;
  const available = (state?.available_orders || []).length;
  const limit = Number(state?.order_slot_limit || 2);
  const availableLimit = Number(state?.available_order_limit || 5);
  list.innerHTML = `<p class="hint">Confirmed Orders: ${confirmed}/${limit}. Available Orders: ${available}/${availableLimit}. Pick an order directly on the board canvas to review it.</p>`;
}

function renderWorkers() {
  const hint = $('#helperUnlockHint'), workerList = $('#workerList'), plantOrderList = $('#plantOrderList');
  if (hint) hint.textContent = hasUnlockKey('helpers_unlocked') ? 'Your first fairy has water magic through the Aqua Amulet.' : 'Find a relic, meet Madam Rune, and ring the first fairy bell.';
  if (workerList) {
    workerList.innerHTML = '';
    const helpers = state.helpers || [];
    if (!helpers.length) workerList.innerHTML = '<p class="hint">No forest folk have joined yet.</p>';
    for (const helper of helpers) {
      const row = document.createElement('div');
      row.className = 'shop-row helper-row';
      row.innerHTML = `<div class="helper-main"><span class="helper-icon">${escapeHtml(helper.icon || '🧚')}</span><span><b>${escapeHtml(helper.helper_name || helper.name || 'Fairy')}</b><br><small class="muted-line">${escapeHtml(helper.equipment_name || 'No amulet equipped')} · ${escapeHtml(helper.active_task || 'idle')}</small></span></div>`;
      workerList.appendChild(row);
    }
  }
  if (plantOrderList) plantOrderList.innerHTML = '<p class="hint">Future task priority scaffold: water, till, plant, harvest, weed, pest control.</p>';
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
  setTextIfExists('#reputationCount', state.progress?.reputation ?? state.user?.reputation ?? 0);
  setTextIfExists('#recognitionCount', state.progress?.recognition ?? state.user?.recognition ?? 0);
  setTextIfExists('#gardenName', `${state.garden.name} — ${state.garden.garden_type_name}`);
  setTextIfExists('#versionPill', state.version || window.GAME_VERSION || 'v0.4.9');
  renderClock();
  renderOrdersButton();
  renderLocations();
  renderOrdersBoardList();
  renderTools();
  renderSeeds();
  renderShop();
  renderShed();
  renderWorkers();
  renderAdmin();
  if ($('#ordersModal')?.classList.contains('is-open')) renderOrders(orderOpenedId);
}


function setupTabs() {
  document.querySelectorAll('.tab-button').forEach(btn => {
    btn.addEventListener('click', () => switchScreen(btn.dataset.tab));
  });
  $('#backToMapBtn')?.addEventListener('click', () => switchScreen('map'));
  document.querySelector('[data-side-map-button]')?.addEventListener('click', () => switchScreen('map'));
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
    closeStoryModal();
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

  $('#ordersBtn')?.addEventListener('click', () => switchScreen('orders'));
  $('#inventoryBtn')?.addEventListener('click', () => switchScreen('inventory'));

  setupTabs();
  fetchState();
  requestAnimationFrame(draw);
  setInterval(renderClock, 1000);
  setInterval(() => {
    if (state) {
      for (const order of [...(state.orders || []), ...(state.available_orders || [])]) {
        if (order.time_remaining_seconds !== undefined) {
          order.time_remaining_seconds = Math.max(0, Number(order.time_remaining_seconds) - 1);
          if (order.order_status === 'accepted' && order.time_remaining_seconds === 0) order.is_late = 1;
        }
      }
      if (state.order?.time_remaining_seconds !== undefined) state.order.time_remaining_seconds = Math.max(0, Number(state.order.time_remaining_seconds) - 1);
      renderOrdersButton();
      if ($('#ordersModal')?.classList.contains('is-open')) updateOrderModalTimer();
      if (currentTabName() === 'orders') renderOrdersBoardList();
    }
  }, 1000);
  setInterval(fetchState, 15000);
});
