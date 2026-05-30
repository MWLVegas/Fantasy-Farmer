console.info('Fantasy Farmer JS loaded: v0.4.34');

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
let gardenSaveQueue = Promise.resolve();
let gardenSoftRefreshTimer = null;
let helperClientLastActionAt = {};
let helperClientMotion = {};
let localClockBase = null;
let imageCache = {};
let canvasSceneHits = [];
let cropProblemHits = [];
let canvasHoverHitId = null;
let canvasHoverHitStartedAt = 0;
let helperCanvasScroll = 0;
let lastFx = [];
let canvasFloatFx = [];
let lastSeenOrderId = null;
let orderOpenedId = null;
let lastToolUseAt = 0;
let lastProgressSnapshot = null;
let activeStoryKey = null;
let mapMarkerScales = {};
let loadedAppVersion = null;
let versionMismatchShown = false;
let latestServerVersion = null;
let shedEditMode = false;
let shedDrag = null;
let savePendingCount = 0;
let saveStatusTimer = null;
let audioUnlocked = false;
let currentBgmKey = null;
let isCtrlDown = false;
let lastRenderedOrderBoardKey = '';
let helperAccessoryDrag = null;
let marketSceneBackground = '';
let marketSceneBackgroundPrev = '';
let marketSceneTransitionStartedAt = 0;
let calendarModalBound = false;
let marketWanderers = [];
let marketWandererSeed = '';
let panelBackgroundImage = '';
let panelBackgroundPrevImage = '';
let panelBackgroundTransitionStartedAt = 0;
const LOCATION_BACKGROUND_TRANSITION_MS = 1000;
const STATE_BACKGROUND_TRANSITION_MS = 2100;
let panelBackgroundScreen = '';
let panelBackgroundTransitionMs = STATE_BACKGROUND_TRANSITION_MS;
const imagePreloadPromises = {};
const BGM_BY_SCREEN = { map: '', garden: '', shop: '', shed: '', orders: '', helpers: '' };
const SFX_BY_ACTION = { till: '', water: '', plant: '', harvest: '', unlock_plot: '', equip_helper_accessory: '', buy_machine: '' };
let canvasCssSize = 720;
let canvasDpr = 1;
function canvasLogicalWidth() { return canvasCssSize || 720; }
function canvasLogicalHeight() { return canvasCssSize || 720; }


const REP_TOOLTIP = '<b>Reputation</b><br><span class="muted-line">Local trust earned from orders.</span>';
const REC_TOOLTIP = '<b>Recognition</b><br><span class="muted-line">World progress from milestones and visitors.</span>';
const COIN_TOOLTIP = '<b>Coins</b><br><span class="muted-line">Spendable money from farming, sales, and completed orders.</span>';
const MAP_TOOLTIP = '<b>Map</b><br><span class="muted-line">Return to the town map.</span>';
const BACKPACK_TOOLTIP = '<b>Backpack</b><br><span class="muted-line">Open your inventory.</span>';
const ORDER_SIDEBAR_COPY = `<p class="hint">The order board shows your confirmed orders and available requests.</p><p class="hint">Select an available order to review it. If you have an open slot, you can confirm it and earn local reputation when it is completed on time.</p><p class="hint">Late orders pay less. Cancelling a confirmed order costs reputation.</p>`;
const RUSH_ICON = '⚡';

const $ = (selector) => document.querySelector(selector);


function systemIcon(code, fallback = '') {
  const icons = state?.system_icons || {};
  return icons[code] || fallback;
}

function coinIcon() {
  return systemIcon('global_coin', systemIcon('system_coin', '🪙'));
}

function reputationIcon() {
  return systemIcon('global_reputation', systemIcon('system_reputation', '⭐'));
}

function recognitionIcon() {
  return systemIcon('global_recognition', systemIcon('system_recognition', '🏵️'));
}

function mapIcon() {
  return systemIcon('nav_map', systemIcon('global_map', '🗺️'));
}

function backpackIcon() {
  return systemIcon('nav_backpack', systemIcon('global_backpack', '🎒'));
}

function ordersIcon() {
  return systemIcon('nav_orders', systemIcon('global_orders', '📜'));
}

function calendarIcon() {
  return systemIcon('nav_calendar', systemIcon('global_calendar', '📅'));
}

function harvestToolIcon() {
  return systemIcon('tool_harvest', systemIcon('harvest_basket', ''));
}

function inspectToolIcon() {
  return systemIcon('tool_inspect', '');
}

function actionFxIcon(kind) {
  return systemIcon(`fx_${kind}`, '');
}

function questIcon() {
  return systemIcon('quest_available', '!');
}

function plainIconFallback(icon, fallback = '') {
  return isImageIcon(icon) ? fallback : (icon || fallback);
}

function rewardIconHtml(icon, tooltipHtml) {
  return `<span class="reward-tooltip-icon" data-tooltip-html="${escapeHtml(tooltipHtml)}">${renderIcon(icon, 'reward-inline-icon')}</span>`;
}

function coinHtml(className = 'reward-inline-icon') {
  return renderIcon(coinIcon(), className);
}

function moneyText(amount) {
  return `${amount} ${plainIconFallback(coinIcon(), '🪙')}`;
}

function moneyHtml(amount) {
  return `${coinHtml()} ${escapeHtml(amount)}`;
}

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

function assetUrl(path) {
  if (!path || !isImageIcon(path)) return path || '';
  try { return new URL(path, document.baseURI).href; }
  catch { return path; }
}

function cssImageUrl(path) {
  const url = assetUrl(path);
  return url ? `url("${String(url).replace(/\"/g, '%22')}")` : 'none';
}

function preloadImageAsset(src) {
  if (!src || !isImageIcon(src)) return Promise.resolve(null);
  const key = String(src);
  const cached = imageCache[key];
  if (cached?.complete && cached.naturalWidth) return Promise.resolve(cached);
  if (imagePreloadPromises[key]) return imagePreloadPromises[key];

  const img = cached || new Image();
  imageCache[key] = img;

  imagePreloadPromises[key] = new Promise(resolve => {
    const finish = () => {
      const done = () => { render(); resolve(img); };
      if (img.decode) img.decode().catch(() => {}).finally(done);
      else done();
    };
    const fail = () => {
      img.failed = true;
      resolve(null);
    };

    if (!img.src) img.src = assetUrl(key);

    if (img.complete) {
      finish();
      return;
    }

    img.addEventListener('load', finish, { once: true });
    img.addEventListener('error', fail, { once: true });
  });

  return imagePreloadPromises[key];
}

function preloadImageAssets(srcList = []) {
  const unique = [...new Set((srcList || []).filter(src => src && isImageIcon(src)))];
  if (!unique.length) return Promise.resolve([]);
  return Promise.allSettled(unique.map(preloadImageAsset));
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
    preloadImageAsset(icon);
    img = imageCache[icon];
  }

  if (!img || !img.complete || !img.naturalWidth || img.failed) {
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

function showStatus(message, isError = false, options = {}) {
  const box = $('#statusMessage');
  if (!box) return;
  box.innerHTML = formatMessageIcons(message);
  box.className = `status-message visible ${isError ? 'error' : ''} ${options.persistent ? 'persistent' : ''}`;
  clearTimeout(showStatus._t);
  if (!options.persistent) {
    showStatus._t = setTimeout(() => {
      box.className = 'status-message';
      if (versionMismatchShown && latestServerVersion) showPersistentUpdateWarning(latestServerVersion);
    }, Number(options.duration || 1600));
  }
}

function showPersistentUpdateWarning(serverVersion) {
  latestServerVersion = serverVersion;
  showStatus(`New version available: ${serverVersion}. Reload to update.`, true, { persistent: true });
  const vp = $('#versionPill');
  if (vp) {
    vp.classList.add('version-pill--outdated');
    vp.dataset.tooltipHtml = `<b>Update available</b><br>Loaded ${escapeHtml(loadedAppVersion || 'unknown')} · Database ${escapeHtml(serverVersion)}`;
  }
}

function setSaveStatus(status, label = '') {
  const el = $('#saveStatus');
  if (!el) return;
  el.className = `save-status save-status--${status}`;
  el.textContent = status === 'saving' ? '●●●' : (status === 'error' ? '●●!' : '●●');
  if (label) el.dataset.tooltipHtml = `<b>Save Status</b><br>${escapeHtml(label)}`;
}

function markSavePending() {
  savePendingCount += 1;
  setSaveStatus('saving', `${savePendingCount} update${savePendingCount === 1 ? '' : 's'} pending.`);
}

function markSaveComplete(ok = true) {
  savePendingCount = Math.max(0, savePendingCount - 1);
  if (!ok) {
    setSaveStatus('error', 'The last update failed. The next sync will try to reconcile.');
    clearTimeout(saveStatusTimer);
    saveStatusTimer = setTimeout(() => { if (savePendingCount === 0) setSaveStatus('saved', 'Everything is synced.'); }, 3500);
    return;
  }
  if (savePendingCount > 0) setSaveStatus('saving', `${savePendingCount} update${savePendingCount === 1 ? '' : 's'} pending.`);
  else setSaveStatus('saved', 'Everything is synced.');
}

function unlockAudio() {
  if (audioUnlocked) return;
  audioUnlocked = true;
  updateBgmForScreen();
}

function playSfx(action) {
  if (!audioUnlocked) return;
  const src = SFX_BY_ACTION[action] || '';
  if (!src) return;
  try { const a = new Audio(src); a.volume = 0.35; a.play().catch(() => {}); } catch {}
}

function updateBgmForScreen() {
  if (!audioUnlocked) return;
  const key = currentTabName();
  if (currentBgmKey === key) return;
  currentBgmKey = key;
  // Music paths intentionally live in BGM_BY_SCREEN/map config later. Empty paths mean silent scaffold.
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

async function fetchState(options = {}) {
  if (savePendingCount > 0 && !options.force) {
    scheduleSync(1200);
    return;
  }
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

  if (!loadedAppVersion) {
    loadedAppVersion = data.version || window.GAME_VERSION || 'v0.4.16';
  } else if (data.version && data.version !== loadedAppVersion) {
    versionMismatchShown = true;
    latestServerVersion = data.version;
    showPersistentUpdateWarning(data.version);
  }

  state = data;

  const nextProgress = {
    reputation: Number(data.progress?.reputation ?? data.user?.reputation ?? 0),
    recognition: Number(data.progress?.recognition ?? data.user?.recognition ?? 0)
  };
  if (previousProgress) {
    const repDelta = nextProgress.reputation - previousProgress.reputation;
    const recDelta = nextProgress.recognition - previousProgress.recognition;
    if (repDelta > 0) addDomFloat('#reputationCount', `+${repDelta} ${plainIconFallback(reputationIcon(), '⭐')}`);
    if (recDelta > 0) addDomFloat('#recognitionCount', `+${recDelta} ${plainIconFallback(recognitionIcon(), '🏵️')}`);
  }
  lastProgressSnapshot = nextProgress;

  localClockBase = {
    receivedAt: performance.now(),
    year: Number(data.clock?.year || 1),
    day: Number(data.clock?.day || 1),
    absoluteDay: Number(data.clock?.absolute_day || data.clock?.day || 1),
    yearLength: Number(data.clock?.year_length_days || 300),
    progress: Number(data.clock?.day_progress || 0),
    dayLength: Number(data.clock?.day_length_seconds || 720),
    sunIcon: data.clock?.sun_icon || '☀️',
    moonIcon: data.clock?.moon_icon || '🌙'
  };

  preloadLikelyBackgroundAssets();
  render();
  maybeOpenStoryEvent();
}

function scheduleSoftGardenRefresh(delay = 4500) {
  if (gardenSoftRefreshTimer) clearTimeout(gardenSoftRefreshTimer);
  gardenSoftRefreshTimer = setTimeout(() => {
    gardenSoftRefreshTimer = null;
    if (savePendingCount === 0) fetchState();
  }, delay);
}

function reconcileTrustedGardenSave(payload, data) {
  if (!state || !data?.ok) return;
  if (payload.action === 'plant' && payload.local_planted_crop_id && data.planted_crop_id) {
    const crop = (state.crops || []).find(c => String(c.planted_crop_id) === String(payload.local_planted_crop_id));
    if (crop) crop.planted_crop_id = Number(data.planted_crop_id);
  }
}

function queueTrustedGardenSave(payload, options = {}) {
  const queuedPayload = { ...payload, trusted_client: true };
  markSavePending();

  gardenSaveQueue = gardenSaveQueue
    .catch(() => {})
    .then(async () => {
      const res = await fetch('api/action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(queuedPayload)
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Background save failed.');
      reconcileTrustedGardenSave(queuedPayload, data);
      markSaveComplete(true);
      if (['plant', 'harvest', 'dig'].includes(queuedPayload.action)) scheduleSoftGardenRefresh(5000);
      return data;
    })
    .catch(err => {
      console.warn('Garden background save failed:', err);
      markSaveComplete(false);
      scheduleSoftGardenRefresh(1200);
    });

  return { ok: true, optimistic: true };
}

async function doAction(payload, fx = null, options = {}) {
  if (fx) lastFx.push({ ...fx, createdAt: performance.now() });
  const action = payload?.action;
  const trustedGardenWrite = ['till','water','plant','harvest','dig'].includes(action) && options.trustClient !== false;
  const isGardenWrite = trustedGardenWrite || ['unlock_plot'].includes(action);
  playSfx(action);

  if (trustedGardenWrite) {
    return queueTrustedGardenSave(payload, options);
  }

  if (isGardenWrite) markSavePending();

  try {
    const res = await fetch('api/action.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });

    const data = await res.json();

    if (!data.ok) {
      showStatus(data.error || 'Action failed.', true);
      if (isGardenWrite) markSaveComplete(false);
      scheduleSync(500);
      return null;
    }

    if (!options.silent) showStatus(data.message || 'Done.');
    if (isGardenWrite) markSaveComplete(true);
    scheduleSync(650);
    return data;
  } catch {
    showStatus('Action failed.', true);
    if (isGardenWrite) markSaveComplete(false);
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


function snapCanvasCssSize() {
  if (!canvas) return;
  const wrap = canvas.parentElement;
  const max = 720;
  const available = wrap ? Math.floor(wrap.clientWidth) : max;
  // Canvas text gets blurry on high-DPI displays if the backing store is only
  // 1 CSS pixel per pixel. Draw in logical CSS pixels, but back it with DPR.
  canvasCssSize = Math.max(320, Math.min(max, available));
  canvasDpr = Math.max(1, Math.min(3, window.devicePixelRatio || 1));
  const backingSize = Math.round(canvasCssSize * canvasDpr);
  canvas.style.width = `${canvasCssSize}px`;
  canvas.style.height = `${canvasCssSize}px`;
  if (canvas.width !== backingSize || canvas.height !== backingSize) {
    canvas.width = backingSize;
    canvas.height = backingSize;
  }
}

function currentTabName() {
  return activeScreen || 'map';
}

function updateScreenSurfaceVisibility() {
  const board = $('#ordersBoardSurface');
  const wrap = canvas?.parentElement;
  const isOrders = currentTabName() === 'orders';
  if (canvas) canvas.hidden = isOrders;
  if (board) board.hidden = !isOrders;
  if (wrap) wrap.classList.toggle('showing-dom-board', isOrders);
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
  if (tab === 'map' && isCtrlDown) {
    canvas.style.cursor = 'crosshair';
    return;
  }
  if (tab === 'garden' && state?.pouch && pointerCanvasPos && pouchHit(pointerCanvasPos)) {
    canvas.style.cursor = 'grab';
    return;
  }
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

function parseJsonArray(value) {
  if (Array.isArray(value)) return value;
  if (!value || typeof value !== 'string') return [];
  try {
    const parsed = JSON.parse(value);
    return Array.isArray(parsed) ? parsed : [];
  } catch (err) {
    return [];
  }
}

function iconForCrop(crop) {
  const step = Number(crop.growth_step_current || 0);
  if (step <= 0) return systemIcon('garden_planted_soil', 'assets/icons/garden-planted-soil.png');
  const stageIcons = parseJsonArray(crop.stage_icons_json);
  if (stageIcons.length) return stageIcons[Math.min(stageIcons.length - 1, step - 1)] || stageIcons[stageIcons.length - 1];
  return crop.mature_icon || crop.seed_icon || '🌱';
}

function cropIsMature(crop) {
  if (!crop) return false;
  const step = Number(crop.growth_step_current || 0);
  const max = Number(crop.growth_steps || 0);
  return max > 0 && step >= max;
}

function cropNeedsWater(crop) {
  if (!crop || cropIsMature(crop)) return false;
  if (cropHasProblems(crop)) return false;
  const current = Number(crop.water_current || 0);
  const max = Number(crop.water_max || 100);
  return current < max;
}

function cropProblemsForCrop(crop) {
  if (!crop) return [];
  return (state?.crop_problems || []).filter(problem => String(problem.planted_crop_id) === String(crop.planted_crop_id));
}

function cropHasProblems(crop) {
  return cropProblemsForCrop(crop).some(problem => Number(problem.is_resolved || 0) === 0);
}

function problemAtPoint(point) {
  if (!point) return null;
  return cropProblemHits.find(hit =>
    point.x >= hit.x && point.x <= hit.x + hit.w &&
    point.y >= hit.y && point.y <= hit.y + hit.h
  )?.problem || null;
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
  const sx = canvasLogicalWidth() / rect.width;
  const sy = canvasLogicalHeight() / rect.height;
  pointerCanvasPos = {
    x: (evt.clientX - rect.left) * sx,
    y: (evt.clientY - rect.top) * sy
  };
}

function roundedPointerPos() {
  if (!pointerCanvasPos) return { x: 0, y: 0 };
  return { x: Math.round(pointerCanvasPos.x), y: Math.round(pointerCanvasPos.y) };
}

function mapCoordinateTooltipHtml() {
  const pos = roundedPointerPos();
  return `<b>Map pixel</b><br><span class="muted-line">x: ${pos.x}, y: ${pos.y}</span><br><span class="muted-line">map_x/map_y: ${pos.x}, ${pos.y}</span>`;
}

function showMapCoordinateTooltip(evt) {
  if (currentTabName() !== 'map' || !isCtrlDown || !pointerCanvasPos) return false;
  showTooltipHtml(mapCoordinateTooltipHtml(), evt);
  return true;
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
    x: 24 + Number(state.pouch.x_ratio) * (canvasLogicalWidth() - 48),
    y: 24 + Number(state.pouch.y_ratio) * (canvasLogicalHeight() - 48)
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
    x: 24 + Number(state.relic_pickup.x_ratio || .5) * (canvasLogicalWidth() - 48),
    y: 24 + Number(state.relic_pickup.y_ratio || .5) * (canvasLogicalHeight() - 48)
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

  if (tab === 'shed' && shedDrag) {
    hideTooltip();
    return;
  }

  if (tab !== 'garden') {
    hoverTile = null;
    stopRepeat();
    if (showMapCoordinateTooltip(evt)) return;
    const hit = canvasSceneHits.find(h => pointerCanvasPos.x >= h.x && pointerCanvasPos.x <= h.x + h.w && pointerCanvasPos.y >= h.y && pointerCanvasPos.y <= h.y + h.h);
    const nextHover = hit?.id || null;
    if (canvasHoverHitId !== nextHover) {
      canvasHoverHitId = nextHover;
      canvasHoverHitStartedAt = performance.now();
    }
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
  canvasHoverHitId = null;
  canvasHoverHitStartedAt = 0;
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
  if (!plot) return;
  if (!Number(plot.is_unlocked)) {
    if (inventoryItemByCode('land_claim_note')) return confirmUnlockPlot(plot);
    if (selectedMode.type === 'info') return openPlotInfoModal(plot, null);
    return showStatus('Locked plot. Needs a Land Claim Note.', true);
  }

  const crop = cropAt(hit.gridX, hit.gridY);
  if (selectedMode.type === 'info') return openPlotInfoModal(plot, crop);
  const fxPoint = { x: pointerPos?.x ?? hit.cx, y: pointerPos?.y ?? hit.cy };

  if (selectedMode.type === 'tool') {
    const now = performance.now();
    if (now - lastToolUseAt < 300) return;
    lastToolUseAt = now;
  }

  if (selectedMode.type === 'tool' && selectedMode.value === 'hoe') {
    plot.till_progress = Math.min(100, Number(plot.till_progress || 0) + Number(getTool('hoe')?.strength || 25));
    plot.is_tilled = Number(plot.till_progress) >= 100 ? 1 : plot.is_tilled;
    doAction({ action: 'till', plot_id: Number(plot.plot_id), till_progress: Number(plot.till_progress || 0), is_tilled: Number(plot.is_tilled || 0) }, { kind: 'till', ...fxPoint }, { silent: repeating });
    render();
    return;
  }

  if (selectedMode.type === 'tool' && selectedMode.value === 'watering_can') {
    if (!crop) return showStatus('Nothing to water.', true);
    if (cropIsMature(crop)) return showStatus('Fully grown crops do not need water.', true);
    if (cropHasProblems(crop)) return showStatus('Clear pests or weeds first.', true);
    crop.water_current = Math.min(Number(crop.water_max || 100), Number(crop.water_current || 0) + Number(getTool('watering_can')?.strength || 15));
    render();
    doAction({ action: 'water', planted_crop_id: Number(crop.planted_crop_id), water_current: Number(crop.water_current || 0), garden_id: Number(state.garden?.garden_id || crop.garden_id || 0), origin_x: Number(crop.origin_x || 0), origin_y: Number(crop.origin_y || 0) }, { kind: 'water', ...fxPoint }, { silent: repeating });
    return;
  }

  if (selectedMode.type === 'tool' && selectedMode.value === 'shovel') {
    if (!crop) return showStatus('Nothing to dig.', true);
    doAction({ action: 'dig', planted_crop_id: Number(crop.planted_crop_id) }, { kind: 'dig', ...fxPoint }, { silent: repeating });
    return;
  }

  if (selectedMode.type === 'seed') {
    if (crop) return showStatus('Something is already growing there.', true);
    const plant = selectedPlant();
    if (!plant) return;
    const seed = (state.inventory || []).find(i => Number(i.item_id) === Number(plant.seed_item_id));
    if (!seed || Number(seed.quantity || 0) <= 0) return showStatus('You need seeds for that crop.', true);
    for (let yy = hit.gridY; yy < hit.gridY + Number(plant.height || 1); yy++) {
      for (let xx = hit.gridX; xx < hit.gridX + Number(plant.width || 1); xx++) {
        const p = getPlot(xx, yy);
        if (!p || !Number(p.is_unlocked) || !Number(p.is_tilled) || cropAt(xx, yy)) return showStatus('That crop will not fit there.', true);
      }
    }
    seed.quantity = Math.max(0, Number(seed.quantity || 0) - 1);
    state.inventory = (state.inventory || []).filter(i => Number(i.quantity || 0) > 0);
    state.crops = state.crops || [];
    const localCropId = `local-${Date.now()}`;
    state.crops.push({
      planted_crop_id: localCropId,
      garden_id: state.garden.garden_id,
      plant_id: plant.plant_id,
      origin_x: hit.gridX,
      origin_y: hit.gridY,
      width: plant.width,
      height: plant.height,
      name: plant.name,
      code: plant.code,
      growth_steps: plant.growth_steps || 1,
      growth_step_current: 0,
      water_current: 0,
      water_max: plant.water_max || 100,
      harvest_min: plant.harvest_min || '?',
      harvest_max: plant.harvest_max || '?',
      seed_icon: plant.seed_icon,
      mature_icon: plant.mature_icon || plant.seed_icon,
      stage_icons_json: plant.stage_icons_json || '[]',
      has_weeds: 0,
      has_pests: 0
    });
    render();
    doAction({
      action: 'plant',
      local_planted_crop_id: localCropId,
      garden_id: Number(state.garden.garden_id),
      plant_id: Number(selectedMode.value),
      x: hit.gridX,
      y: hit.gridY
    }, { kind: 'plant', ...fxPoint }, { silent: true });
    return;
  }

  if (selectedMode.type === 'harvest') {
    const problem = problemAtPoint(pointerPos);
    if (problem) {
      state.crop_problems = (state.crop_problems || []).filter(p => Number(p.crop_problem_id) !== Number(problem.crop_problem_id));
      const problemCrop = (state.crops || []).find(c => String(c.planted_crop_id) === String(problem.planted_crop_id));
      if (problemCrop) {
        problemCrop.has_weeds = cropProblemsForCrop(problemCrop).some(p => p.problem_type === 'weed') ? 1 : 0;
        problemCrop.has_pests = cropProblemsForCrop(problemCrop).some(p => p.problem_type === 'pest') ? 1 : 0;
      }
      canvasFloatFx.push({ icon: problem.icon || (problem.problem_type === 'pest' ? '🐛' : '🌿'), text: '+1', x: fxPoint.x, y: fxPoint.y, createdAt: performance.now() });
      render();
      doAction({ action: 'harvest_crop_problem', crop_problem_id: Number(problem.crop_problem_id) }, { kind: 'harvest', ...fxPoint }, { silent: true });
      return;
    }
    if (!crop) return showStatus('Nothing to harvest.', true);
    if (Number(crop.growth_step_current || 0) < Number(crop.growth_steps || 0)) return showStatus('Not ready yet.', true);
    state.crops = (state.crops || []).filter(c => String(c.planted_crop_id) !== String(crop.planted_crop_id));
    for (let yy = Number(crop.origin_y || 0); yy < Number(crop.origin_y || 0) + Number(crop.height || 1); yy++) {
      for (let xx = Number(crop.origin_x || 0); xx < Number(crop.origin_x || 0) + Number(crop.width || 1); xx++) {
        const p = getPlot(xx, yy);
        if (p) { p.is_tilled = 0; p.till_progress = 0; }
      }
    }
    canvasFloatFx.push({ icon: crop.mature_icon || '🌾', text: 'Harvest', x: fxPoint.x, y: fxPoint.y, createdAt: performance.now() });
    render();
    doAction({ action: 'harvest', planted_crop_id: Number(crop.planted_crop_id) }, { kind: 'harvest', ...fxPoint }, { silent: true });
  }
}

function runClientHelperAutomation() {
  if (!state || currentTabName() !== 'garden') return;
  const helpers = (state.helpers || []).filter(h => Number(h.is_enabled ?? 1));
  if (!helpers.length) return;

  for (const helper of helpers) {
    const task = helper.active_task || helper.task_type || 'idle';
    if (task !== 'water') continue;

    const helperId = helperClientKey(helper);
    const speed = Math.max(1, Number(helper.speed_rating || 10));
    const effectiveness = Math.max(1, Number(helper.effectiveness_rating || 10));
    const cooldown = Math.max(3500, 9500 - (speed * 420));
    const now = performance.now();
    const motion = ensureHelperMotion(helper);

    let target = null;
    if (motion.targetCropId != null) {
      target = (state.crops || []).find(c => Number(c.planted_crop_id) === Number(motion.targetCropId) && cropNeedsWater(c)) || null;
    }
    if (!target) {
      target = (state.crops || [])
        .filter(cropNeedsWater)
        .sort((a, b) => (Number(a.water_current || 0) / Math.max(1, Number(a.water_max || 100))) - (Number(b.water_current || 0) / Math.max(1, Number(b.water_max || 100))))[0] || null;
    }

    if (!target) {
      motion.targetCropId = null;
      if (motion.targetX == null || motion.targetY == null || helperDistanceToDestination(helper) < 5) {
        const idlePoint = helperOuterWanderPoint(0);
        motion.targetX = idlePoint.x;
        motion.targetY = idlePoint.y;
        motion.arrived = false;
      }
      continue;
    }

    const fxPoint = cropCanvasPoint(target);
    if (motion.targetCropId !== Number(target.planted_crop_id)) {
      setHelperDestination(helper, fxPoint, Number(target.planted_crop_id));
    } else if (motion.targetX == null || motion.targetY == null) {
      setHelperDestination(helper, fxPoint, Number(target.planted_crop_id));
    }

    if (helperDistanceToDestination(helper) > 18) continue;
    if (helperClientLastActionAt[helperId] && now - helperClientLastActionAt[helperId] < cooldown) continue;

    const canStrength = Number(getTool('watering_can')?.strength || 15);
    const amount = Math.max(6, Math.floor((canStrength * 0.65) + (effectiveness * 0.18)));
    target.water_current = Math.min(Number(target.water_max || 100), Number(target.water_current || 0) + amount);
    helperClientLastActionAt[helperId] = now;

    canvasFloatFx.push({ icon: null, text: `+${amount} water`, x: fxPoint.x, y: fxPoint.y - 18, createdAt: performance.now() });
    doAction({
      action: 'water',
      planted_crop_id: Number(target.planted_crop_id),
      water_current: Number(target.water_current || 0),
      garden_id: Number(state.garden?.garden_id || target.garden_id || 0),
      origin_x: Number(target.origin_x || 0),
      origin_y: Number(target.origin_y || 0),
      helper_id: Number(helper.player_helper_id || 0)
    }, { kind: 'water', ...fxPoint }, { silent: true });
    if (cropNeedsWater(target)) setHelperDestination(helper, cropCanvasPoint(target), Number(target.planted_crop_id));
    else motion.targetCropId = null;
    render();
  }
}

function cropFullGrowText(crop) {
  if (!crop) return '—';
  const steps = Number(crop.growth_steps || 0);
  return `${steps} cycle${steps === 1 ? '' : 's'}`;
}

function cropRemainingText(crop) {
  if (!crop) return '—';
  const remaining = Math.max(0, Number(crop.growth_steps || 0) - Number(crop.growth_step_current || 0));
  return remaining <= 0 ? 'Ready now' : `About ${remaining} cycle${remaining === 1 ? '' : 's'}`;
}

function boostsForCrop(crop) {
  const rows = (state.fertilizers || []).filter(f => crop && Number(f.planted_crop_id) === Number(crop.planted_crop_id));
  return rows.length ? rows.map(f => `${iconHtml(f.icon || f.visual_icon || '✨')} ${escapeHtml(f.name || f.effect_type || 'Boost')}`).join('<br>') : 'None';
}

function openPlotInfoModal(plot, crop) {
  const title = crop ? (crop.name || 'Crop') : (Number(plot?.is_unlocked) ? 'Empty Plot' : 'Locked Plot');
  let body = `<p><b>Plot:</b> ${escapeHtml(plot?.x_pos)}, ${escapeHtml(plot?.y_pos)}</p>`;
  if (!crop) {
    body += `<p><b>Status:</b> ${Number(plot?.is_unlocked) ? (Number(plot?.is_tilled) ? 'Tilled and empty' : 'Untilled and empty') : 'Locked'}</p>`;
    if (!Number(plot?.is_unlocked)) body += '<p class="hint">Use a Land Claim Note to unlock this plot.</p>';
  } else {
    body += `<p><b>Name:</b> ${escapeHtml(crop.name || 'Crop')}</p>`;
    body += `<p><b>Quantity:</b> ${escapeHtml(crop.harvest_min || '?')}–${escapeHtml(crop.harvest_max || '?')} projected yield</p>`;
    body += `<p><b>Full grow time:</b> ${cropFullGrowText(crop)}</p>`;
    body += `<p><b>Time left:</b> ${cropRemainingText(crop)}</p>`;
    body += cropIsMature(crop) ? '<p><b>Water:</b> Not needed; ready to harvest.</p>' : `<p><b>Water:</b> ${escapeHtml(crop.water_current || 0)} / ${escapeHtml(crop.water_max || 100)}</p>`;
    body += `<p><b>Boosts:</b><br>${boostsForCrop(crop)}</p>`;
  }
  openStoryModal({ title, body, button: 'Done', closeable: true, onNext: () => closeStoryModal() });
}

function confirmUnlockPlot(plot) {
  openStoryModal({
    title: 'Unlock this plot?',
    body: '<p>Use one <b>Land Claim Note</b> to clear this locked plot?</p>',
    button: 'Unlock Plot',
    closeable: true,
    onNext: async () => {
      closeStoryModal();
      plot.is_unlocked = 1;
      removeInventoryCodeLocally('land_claim_note', 1);
      render();
      await doAction({ action: 'unlock_plot', plot_id: Number(plot.plot_id) }, null, { silent: true });
    }
  });
}

function drawSoilTile(plot, rect) {
  const unlocked = Number(plot.is_unlocked);
  const till = Number(plot.till_progress || 0);
  const tilled = Number(plot.is_tilled);

  ctx.save();
  if (!unlocked) {
    ctx.globalAlpha = .34;
    ctx.fillStyle = '#17140f';
    ctx.strokeStyle = 'rgba(255,255,255,.08)';
    fillStrokeRound(rect.x, rect.y, rect.size, rect.size, 10);
    ctx.globalAlpha = .68;
    drawIcon(state?.ui_config?.locked_plot_icon || '🔒', rect.cx, rect.cy, rect.size * .24);
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

function drawCropProblems(crop, rect) {
  const problems = cropProblemsForCrop(crop).filter(problem => Number(problem.is_resolved || 0) === 0);
  if (!problems.length) return;
  const spots = [
    [rect.x + rect.size * .24, rect.y + rect.size * .26],
    [rect.x + rect.size * .72, rect.y + rect.size * .28],
    [rect.x + rect.size * .30, rect.y + rect.size * .70],
    [rect.x + rect.size * .68, rect.y + rect.size * .68],
    [rect.cx, rect.y + rect.size * .18],
    [rect.cx, rect.y + rect.size * .78]
  ];
  problems.forEach((problem, index) => {
    const [x, y] = spots[index % spots.length];
    const size = Math.max(18, rect.size * .18);
    const bob = Math.sin((Date.now() / 420) + index) * 2;
    ctx.save();
    ctx.shadowColor = problem.problem_type === 'pest' ? 'rgba(255, 210, 120, .65)' : 'rgba(145, 236, 113, .65)';
    ctx.shadowBlur = 8;
    drawIcon(problem.icon || (problem.problem_type === 'pest' ? '🐛' : '🌿'), x, y + bob, size);
    ctx.restore();
    cropProblemHits.push({ problem, x: x - size / 2, y: y + bob - size / 2, w: size, h: size });
  });
}

function drawCrop(crop, rect) {
  const mature = cropIsMature(crop);
  const waterRatio = Math.max(0, Math.min(1, Number(crop.water_current || 0) / Number(crop.water_max || 100)));
  ctx.save();
  if (mature) {
    const pulse = 0.5 + 0.5 * Math.sin(Date.now() / 520);
    ctx.shadowColor = 'rgba(157, 236, 112, .76)';
    ctx.shadowBlur = 10 + pulse * 8;
  }
  drawIcon(iconForCrop(crop), rect.cx, rect.cy - 4, rect.size * .55);
  ctx.shadowBlur = 0;

  if (!mature) {
    ctx.globalAlpha = .85;
    ctx.fillStyle = 'rgba(30,70,90,.55)';
    roundedPath(rect.x + 10, rect.y + rect.size - 14, rect.size - 20, 6, 6);
    ctx.fill();
    if (waterRatio > 0) {
      ctx.fillStyle = '#6eb5d9';
      roundedPath(rect.x + 10, rect.y + rect.size - 14, (rect.size - 20) * waterRatio, 6, 6);
      ctx.fill();
    }
  }

  drawFertilizerOverlay(crop, rect);
  drawCropProblems(crop, rect);

  if (mature) {
    ctx.fillStyle = '#b9ef8e';
    ctx.shadowColor = 'rgba(157, 236, 112, .8)';
    ctx.shadowBlur = 8;
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

function helperClientKey(helper) {
  if (!helper) return 'helper:unknown';
  return String(
    helper.player_helper_id ??
    helper.helper_id ??
    helper.id ??
    helper.helper_name ??
    helper.name ??
    'helper:unknown'
  );
}


function helperGardenBounds() {
  if (!tileRects.length) {
    return { left: 80, top: 80, right: canvasLogicalWidth() - 80, bottom: canvasLogicalHeight() - 80 };
  }
  const left = Math.min(...tileRects.map(t => Number(t.x || 0)));
  const top = Math.min(...tileRects.map(t => Number(t.y || 0)));
  const right = Math.max(...tileRects.map(t => Number(t.x || 0) + Number(t.size || 0)));
  const bottom = Math.max(...tileRects.map(t => Number(t.y || 0) + Number(t.size || 0)));
  return { left, top, right, bottom };
}

function helperOuterWanderPoint(index = 0) {
  const b = helperGardenBounds();
  const canvasW = canvasLogicalWidth();
  const canvasH = canvasLogicalHeight();
  const moat = 24;
  const outer = 74;
  const side = Math.floor(Math.random() * 4);
  if (side === 0) {
    return {
      x: Math.max(34, Math.min(canvasW - 34, b.left - outer + Math.random() * ((b.right - b.left) + outer * 2))),
      y: Math.max(34, b.top - moat - Math.random() * outer)
    };
  }
  if (side === 1) {
    return {
      x: Math.min(canvasW - 34, b.right + moat + Math.random() * outer),
      y: Math.max(34, Math.min(canvasH - 34, b.top - outer + Math.random() * ((b.bottom - b.top) + outer * 2)))
    };
  }
  if (side === 2) {
    return {
      x: Math.max(34, Math.min(canvasW - 34, b.left - outer + Math.random() * ((b.right - b.left) + outer * 2))),
      y: Math.min(canvasH - 34, b.bottom + moat + Math.random() * outer)
    };
  }
  return {
    x: Math.max(34, b.left - moat - Math.random() * outer),
    y: Math.max(34, Math.min(canvasH - 34, b.top - outer + Math.random() * ((b.bottom - b.top) + outer * 2)))
  };
}

function helperHasActiveCropTarget(helper) {
  const motion = ensureHelperMotion(helper);
  if (motion.targetCropId == null) return false;
  return (state.crops || []).some(c => Number(c.planted_crop_id) === Number(motion.targetCropId) && cropNeedsWater(c));
}

function cropCanvasPoint(crop) {
  if (!crop) return { x: canvasLogicalWidth() / 2, y: canvasLogicalHeight() / 2 };

  const gridX = Number(crop.origin_x || 0);
  const gridY = Number(crop.origin_y || 0);
  const rect = tileRects.find(t => Number(t.gridX) === gridX && Number(t.gridY) === gridY);

  if (!rect) {
    return { x: canvasLogicalWidth() / 2, y: canvasLogicalHeight() / 2 };
  }

  const cropW = Math.max(1, Number(crop.width || 1));
  const cropH = Math.max(1, Number(crop.height || 1));
  const gap = 3;

  return {
    x: rect.x + ((rect.size * cropW) + (gap * (cropW - 1))) / 2,
    y: rect.y + ((rect.size * cropH) + (gap * (cropH - 1))) / 2
  };
}

function ensureHelperMotion(helper, index = 0) {
  const key = helperClientKey(helper);
  if (!helperClientMotion[key]) {
    const idleStart = helperOuterWanderPoint(index);
    const baseX = idleStart.x;
    const baseY = idleStart.y;
    helperClientMotion[key] = {
      x: Number(helper?.x ?? helper?.map_x ?? baseX),
      y: Number(helper?.y ?? helper?.map_y ?? baseY),
      targetX: Number(helper?.target_x ?? helper?.x ?? helper?.map_x ?? baseX),
      targetY: Number(helper?.target_y ?? helper?.y ?? helper?.map_y ?? baseY),
      targetCropId: null,
      lastUpdateAt: performance.now(),
      arrived: true
    };
  }

  const motion = helperClientMotion[key];
  if (!Number.isFinite(motion.x)) motion.x = canvasLogicalWidth() / 2;
  if (!Number.isFinite(motion.y)) motion.y = canvasLogicalHeight() / 2;
  if (!Number.isFinite(motion.targetX)) motion.targetX = motion.x;
  if (!Number.isFinite(motion.targetY)) motion.targetY = motion.y;

  return motion;
}

function setHelperDestination(helper, point, targetCropId = null) {
  if (!helper || !point) return;
  const motion = ensureHelperMotion(helper);
  const jitter = targetCropId ? 18 : 0;
  motion.targetX = Number(point.x) + (Math.random() - .5) * jitter;
  motion.targetY = Number(point.y) + (Math.random() - .5) * jitter;
  motion.targetCropId = targetCropId;
  motion.arrived = false;
}


function helperIdleWanderPoint(index = 0) {
  return helperOuterWanderPoint(index);
}

function maybeSetIdleHelperWander(helper, index = 0, now = performance.now()) {
  const task = helper.active_task || helper.task_type || 'idle';
  if (task !== 'idle') return;
  const motion = ensureHelperMotion(helper, index);
  if (!motion.arrived && helperDistanceToDestination(helper) > 3) return;
  if (motion.nextWanderAt && now < motion.nextWanderAt) return;
  const point = helperIdleWanderPoint(index);
  motion.targetX = point.x;
  motion.targetY = point.y;
  motion.targetCropId = null;
  motion.arrived = false;
  motion.nextWanderAt = now + 900 + Math.random() * 2600;
}

function helperDistanceToDestination(helper) {
  const motion = ensureHelperMotion(helper);
  const dx = Number(motion.targetX ?? motion.x) - Number(motion.x);
  const dy = Number(motion.targetY ?? motion.y) - Number(motion.y);
  return Math.sqrt(dx * dx + dy * dy);
}

function updateHelperMotion(helper, index = 0, now = performance.now()) {
  if (!helper) return { x: canvasLogicalWidth() / 2, y: canvasLogicalHeight() / 2 };

  const motion = ensureHelperMotion(helper, index);
  const last = Number(motion.lastUpdateAt || now);
  const dtMs = Math.max(0, Math.min(120, now - last));
  motion.lastUpdateAt = now;

  const speed = Math.max(1, Number(helper.speed_rating ?? helper.speed ?? helper.move_speed ?? 10));
  const pxPerSecond = Math.max(45, Math.min(190, 58 + speed * 8));
  const step = pxPerSecond * (dtMs / 1000);

  const tx = Number(motion.targetX ?? motion.x);
  const ty = Number(motion.targetY ?? motion.y);
  const dx = tx - motion.x;
  const dy = ty - motion.y;
  const dist = Math.sqrt(dx * dx + dy * dy);

  if (dist <= step || dist < 1) {
    motion.x = tx;
    motion.y = ty;
    motion.arrived = true;
  } else {
    motion.x += (dx / dist) * step;
    motion.y += (dy / dist) * step;
    motion.arrived = false;
  }

  return motion;
}


function helperDrawSize() {
  const tileSize = tileRects.length ? Math.min(...tileRects.map(t => Number(t.size || 48))) : 48;
  const bonus = Math.max(0, 58 - tileSize) * .42;
  return Math.round(Math.max(30, Math.min(50, 30 + bonus)));
}

function helperWorkSprite(helper) {
  return helper?.work_sprite || helper?.equipment_work_sprite || helper?.equipment_icon || helper?.equipped_icon || '✨';
}

function drawHelperWorkPops(helper, x, y, now, size) {
  const task = helper.active_task || helper.task_type || 'idle';
  if (task === 'idle' || !helperHasActiveCropTarget(helper)) return;
  const sprite = helperWorkSprite(helper);
  const count = 3;
  for (let i = 0; i < count; i++) {
    const phase = ((now / 900) + i / count) % 1;
    const angle = (i * Math.PI * 2 / count) + now / 1300;
    const px = x + Math.cos(angle) * (size * .55) + Math.sin(now / 300 + i) * 3;
    const py = y - 12 - phase * (size * .9) + Math.sin(angle) * 4;
    ctx.save();
    ctx.globalAlpha = Math.max(0, .82 - phase * .82);
    drawIcon(sprite, px, py, Math.max(16, size * .45));
    ctx.restore();
  }
}

function drawGardenHelpers() {
  const helpers = (state?.helpers || []).filter(h => Number(h.is_enabled ?? 1));
  if (!helpers.length) return;
  const now = performance.now();
  helpers.forEach((helper, index) => {
    maybeSetIdleHelperWander(helper, index, now);
    const motion = updateHelperMotion(helper, index, now);
    const task = helper.active_task || helper.task_type || 'idle';
    const speed = Math.max(6, Number(helper.speed_rating || 10));
    const bob = Math.sin(now / (520 - Math.min(260, speed * 12)) + index) * (task === 'idle' ? 5 : 7);
    const sway = Math.cos(now / (760 - Math.min(300, speed * 12)) + index * 1.7) * (task === 'idle' ? 4 : 6);
    const isWorking = task !== 'idle' && helperHasActiveCropTarget(helper) && helperDistanceToDestination(helper) <= 24;
    ctx.save();
    ctx.globalAlpha = .94;
    ctx.shadowColor = 'rgba(255, 214, 94, .55)';
    ctx.shadowBlur = isWorking ? 13 : 7;
    const drawSize = helperDrawSize();
    drawIcon(helper.icon || '🧚', motion.x + sway, motion.y + bob, drawSize);
    if (isWorking) drawHelperWorkPops(helper, motion.x + sway, motion.y + bob, now, drawSize);
    ctx.restore();
  });
}

function drawPouch() {

  if (!state?.pouch) return;
  const pos = pouchPosition();
  if (!pos) return;
  const pulse = .45 + Math.sin(performance.now() / 180) * .16;
  ctx.save();
  ctx.globalAlpha = 1;
  ctx.shadowColor = 'rgba(255, 230, 160, .86)';
  ctx.shadowBlur = 15 + pulse * 13;
  drawIcon(state.clock?.pouch_icon || '👝', pos.x, pos.y, 40);
  ctx.restore();
}

function drawToolCursor() {
  if (isModalOpen() || currentTabName() !== 'garden' || !pointerCanvasPos) return;
  let icon = null;
  if (selectedMode.type === 'tool') icon = getTool(selectedMode.value)?.icon || '✦';
  if (selectedMode.type === 'harvest') icon = harvestToolIcon();
  if (selectedMode.type === 'seed') icon = selectedPlant()?.seed_icon || '';
  if (selectedMode.type === 'info') icon = inspectToolIcon();
  if (icon) drawIcon(icon, pointerCanvasPos.x + 20, pointerCanvasPos.y + 20, 30);
}

function drawFx() {
  const now = performance.now();
  lastFx = lastFx.filter(fx => now - fx.createdAt < 650);
  for (const fx of lastFx) {
    const t = (now - fx.createdAt) / 650;
    const icon = fx.icon || actionFxIcon(fx.kind);
    if (!icon) continue;
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
    if (fx.icon) {
      drawIcon(fx.icon, fx.x, fx.y - t * 46, 34);
    }
    ctx.font = '800 18px system-ui';
    ctx.fillStyle = '#ffe6a0';
    ctx.textAlign = 'center';
    ctx.fillText(fx.text || '+1', fx.x + (fx.icon ? 28 : 0), fx.y - 18 - t * 46);
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


function triggerScreenTransition() {
  const wrap = document.querySelector('.canvas-wrap');
  if (!wrap) return;
  wrap.classList.remove('screen-transition');
  void wrap.offsetWidth;
  wrap.classList.add('screen-transition');
}

function switchScreen(tab) {
  if (!tab) tab = 'map';
  if (tab !== 'garden') clearGardenInteractionState();
  canvasHoverHitId = null;
  canvasHoverHitStartedAt = 0;
  if (tab !== 'helpers') helperCanvasScroll = 0;
  activeScreen = tab;
  preloadAssetsForScreen(tab);
  triggerScreenTransition();
  updateScreenSurfaceVisibility();
  updateBgmForScreen();
  document.querySelectorAll('.tab-button').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.dataset.panel === tab));
  const backBtn = $('#backToMapBtn');
  if (backBtn) { backBtn.hidden = tab === 'map'; backBtn.innerHTML = `${renderIcon(mapIcon(), 'button-inline-icon')} Map`; backBtn.dataset.tooltipHtml = MAP_TOOLTIP; }
  const sideBackBtn = document.querySelector('[data-side-map-button]');
  if (sideBackBtn) { sideBackBtn.hidden = tab === 'map'; sideBackBtn.innerHTML = `${renderIcon(mapIcon(), 'button-inline-icon')} Map`; sideBackBtn.dataset.tooltipHtml = MAP_TOOLTIP; }
  updateCanvasCursor();
  hideTooltip();
  render();
}

function getMapButtonPositions() {
  const defaults = {
    garden: [canvasLogicalWidth() / 2 - 62, canvasLogicalHeight() / 2 - 62],
    shop: [110, canvasLogicalHeight() / 2 - 70],
    orders: [canvasLogicalWidth() / 2 - 62, 105],
    shed: [canvasLogicalWidth() - 235, canvasLogicalHeight() / 2 - 70],
    market: [canvasLogicalWidth() / 2 - 62, 500],
    caravan: [canvasLogicalWidth() - 250, 510],
    bone_brine: [90, 510],
    helpers: [canvasLogicalWidth() - 245, 125]
  };

  const markers = state?.map_config?.location_markers || {};
  for (const [key, marker] of Object.entries(markers)) {
    if (marker && Number.isFinite(Number(marker.x)) && Number.isFinite(Number(marker.y))) {
      defaults[key] = [Number(marker.x), Number(marker.y)];
    }
  }

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

function mapMarkerAliasesForKey(key) {
  const aliases = {
    shop: ['shop', 'store', 'general_store'],
    helpers: ['helpers', 'forest_folk'],
    market: ['market', 'fae_market'],
    bone_brine: ['bone_brine', 'bone_brine_shop'],
    shed: ['shed', 'workroom']
  };
  return aliases[key] || [key];
}

function markerBackgroundAssets(marker = {}) {
  if (!marker || typeof marker !== 'object') return [];
  return [
    marker.background_image,
    marker.day_background_image,
    marker.background_day_image,
    marker.night_background_image,
    marker.background_night_image,
    marker.active_day_background_image,
    marker.active_night_background_image,
    marker.inactive_day_background_image,
    marker.inactive_night_background_image
  ].filter(Boolean);
}

function firstUsableBackground(candidates = []) {
  const unique = [...new Set((candidates || []).filter(Boolean))];
  return unique.find(src => !imageCache[src]?.failed) || unique[0] || '';
}

function markerCurrentBackgroundAsset(marker = {}, options = {}) {
  const dayBg = marker.day_background_image || marker.background_day_image || marker.background_image || '';
  const nightBg = marker.night_background_image || marker.background_night_image || dayBg;
  return (options.forceDay || isDayPhaseNow()) ? dayBg : (nightBg || dayBg);
}

function gardenBackgroundForNow() {
  const garden = state?.garden || {};
  const typeCode = garden.garden_type_code || 'farm';
  const dayBg = garden.day_background_image || `assets/gardens/garden_day_${typeCode}.png`;
  const nightBg = garden.night_background_image || `assets/gardens/garden_night_${typeCode}.png`;
  const selected = isDayPhaseNow() ? dayBg : nightBg;
  return firstUsableBackground([selected, locationBackgroundForNow('garden'), 'assets/map/garden.png']);
}

function mapBackgroundForNow() {
  const cfg = state?.map_config || {};
  const dayBg = cfg.day_background_image || 'assets/map/map_day.png';
  const nightBg = cfg.night_background_image || 'assets/map/map_night.png';
  const selected = isDayPhaseNow() ? dayBg : nightBg;
  return firstUsableBackground([selected, cfg.background_image, 'assets/map/background.png']);
}

function isCaravanActive() {
  return !!state?.caravan_status?.is_active;
}

function caravanBackgroundForNow() {
  const marker = locationMarkerConfig('caravan');
  const active = isCaravanActive();
  const phase = isDayPhaseNow() ? 'day' : 'night';
  const configured = active
    ? (phase === 'day' ? marker.active_day_background_image : marker.active_night_background_image)
    : (phase === 'day' ? marker.inactive_day_background_image : marker.inactive_night_background_image);
  const generic = markerCurrentBackgroundAsset(marker);
  const named = active
    ? (phase === 'day' ? 'assets/map/caravan_full_day.png' : 'assets/map/caravan_full_night.png')
    : (phase === 'day' ? 'assets/map/caravan_empty_day.png' : 'assets/map/caravan_empty_night.png');
  const fallback = active ? 'assets/map/caravan_full.png' : 'assets/map/caravan_empty.png';
  return firstUsableBackground([configured, named, generic, fallback]);
}

function preloadAssetsForScreen(tab = currentTabName()) {
  if (!state) return;
  const assets = [];
  assets.push(mapBackgroundForNow(), state?.map_config?.background_image || '', state?.map_config?.day_background_image || 'assets/map/map_day.png', state?.map_config?.night_background_image || 'assets/map/map_night.png');

  if (tab === 'map') {
    for (const loc of state.locations || []) {
      const marker = configuredMapMarkerForKey(loc.key);
      assets.push(marker.icon || loc.icon || fallbackMapIconForKey(loc.key), marker.active_map_icon || '', marker.inactive_map_icon || '');
      if (loc.key === 'caravan') assets.push(caravanBackgroundForNow(), 'assets/map/caravan_full_day.png', 'assets/map/caravan_full_night.png', 'assets/map/caravan_empty_day.png', 'assets/map/caravan_empty_night.png', 'assets/map/caravan_full.png', 'assets/map/caravan_empty.png');
      else if (loc.key === 'garden') assets.push(gardenBackgroundForNow());
      else assets.push(markerCurrentBackgroundAsset(marker, { forceDay: loc.key === 'shop' && !hasUnlockKey('location_market') }));
    }
  } else {
    const marker = configuredMapMarkerForKey(tab);
    assets.push(marker.icon || fallbackMapIconForKey(tab), marker.active_map_icon || '', marker.inactive_map_icon || '');
    assets.push(...markerBackgroundAssets(marker));
    if (tab === 'caravan') assets.push(caravanBackgroundForNow(), 'assets/map/caravan_full_day.png', 'assets/map/caravan_full_night.png', 'assets/map/caravan_empty_day.png', 'assets/map/caravan_empty_night.png', 'assets/map/caravan_full.png', 'assets/map/caravan_empty.png');
    if (tab === 'garden') assets.push(gardenBackgroundForNow());
    if (tab === 'market') {
      assets.push('assets/market/closed.png');
      for (let i = 1; i <= marketWandererImageCount(); i++) assets.push(`assets/market/fae/fae${i}.png`);
    }
  }

  preloadImageAssets(assets);
}

function preloadLikelyBackgroundAssets() {
  if (!state) return;
  preloadAssetsForScreen(currentTabName());
}

function configuredMapMarkerForKey(key) {
  const markers = state?.map_config?.location_markers || {};
  for (const markerKey of mapMarkerAliasesForKey(key)) {
    if (markers[markerKey]) return markers[markerKey];
  }
  return {};
}

function fallbackMapIconForKey(key) {
  const fallbackIcons = {
    garden: 'assets/map/garden.png',
    shop: 'assets/map/store.png',
    orders: 'assets/map/orders.png',
    shed: 'assets/map/shed.png',
    market: 'assets/map/market.png',
    caravan: 'assets/map/caravan_empty.png',
    bone_brine: 'assets/map/bone_brine.png',
    helpers: 'assets/map/fairy_folk.png'
  };
  return fallbackIcons[key] || `assets/map/${key}.png`;
}

function mapMarkerForLocation(loc) {
  const marker = configuredMapMarkerForKey(loc.key);
  const configuredIcon = marker.icon || '';
  const dynamicIcon = loc.key === 'caravan'
    ? (isCaravanActive() ? (marker.active_map_icon || loc.icon) : (marker.inactive_map_icon || loc.icon))
    : loc.icon;
  const icon = dynamicIcon || configuredIcon || fallbackMapIconForKey(loc.key) || '❔';
  return {
    icon,
    size: Number(marker.size || marker.icon_size || 78),
    glowColor: isDayPhaseNow() ? (marker.glow_color || 'rgba(255, 214, 94, .78)') : 'rgba(164, 132, 255, .82)'
  };
}

function drawMapMarkerIcon(icon, x, y, size, locked = false) {
  ctx.save();
  if (locked && isImageIcon(icon)) {
    ctx.filter = 'brightness(0) saturate(100%)';
    ctx.globalAlpha = .9;
  } else if (!isDayPhaseNow() && isImageIcon(icon)) {
    ctx.filter = 'brightness(.82) saturate(1.08) hue-rotate(18deg)';
  } else if (locked) {
    ctx.globalAlpha = .35;
    icon = '?';
  }
  if (icon && isImageIcon(icon) && !imageCache[icon]) preloadImageAsset(icon);
  if (icon && isImageIcon(icon) && imageCache[icon]?.failed) icon = '🗺️';
  drawIcon(icon, x, y, size);
  ctx.restore();
}

function drawMapBackground() {
  const bg = mapBackgroundForNow();
  if (!bg || !isImageIcon(bg)) {
    drawPanelBackground('🗺️');
    return;
  }

  ctx.save();
  ctx.fillStyle = '#211a13';
  ctx.strokeStyle = 'rgba(255,255,255,.08)';
  ctx.lineWidth = 2;
  fillStrokeRound(8, 8, canvasLogicalWidth() - 16, canvasLogicalHeight() - 16, 24);

  let img = imageCache[bg];
  if (!img) {
    preloadImageAsset(bg);
    img = imageCache[bg];
  }

  if (img?.complete && img.naturalWidth && !img.failed) {
    ctx.save();
    roundedPath(10, 10, canvasLogicalWidth() - 20, canvasLogicalHeight() - 20, 22);
    ctx.clip();
    const scale = Math.max((canvasLogicalWidth() - 20) / img.naturalWidth, (canvasLogicalHeight() - 20) / img.naturalHeight);
    const w = img.naturalWidth * scale;
    const h = img.naturalHeight * scale;
    ctx.globalAlpha = .82;
    ctx.drawImage(img, 10 + ((canvasLogicalWidth() - 20) - w) / 2, 10 + ((canvasLogicalHeight() - 20) - h) / 2, w, h);
    ctx.globalAlpha = .38;
    ctx.fillStyle = '#120f0b';
    ctx.fillRect(10, 10, canvasLogicalWidth() - 20, canvasLogicalHeight() - 20);
    ctx.restore();
  } else {
    drawIcon('🗺️', 52, 46, 38);
  }
  ctx.restore();
}

function drawMapLabel(text, cx, y, unlocked) {
  ctx.save();
  ctx.font = '800 14px system-ui';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  const metrics = ctx.measureText(text);
  const w = Math.ceil(metrics.width) + 24;
  const h = 25;
  const x = cx - w / 2;
  const r = 12;
  ctx.fillStyle = unlocked ? 'rgba(25, 20, 14, .78)' : 'rgba(8, 7, 6, .72)';
  ctx.strokeStyle = unlocked ? 'rgba(255, 232, 170, .34)' : 'rgba(255, 255, 255, .16)';
  ctx.lineWidth = 1;
  roundedPath(x, y - h / 2, w, h, r);
  ctx.fill();
  ctx.stroke();
  ctx.fillStyle = unlocked ? '#fff7df' : '#d5c7aa';
  ctx.shadowColor = 'rgba(0,0,0,.95)';
  ctx.shadowBlur = 4;
  ctx.fillText(text, cx, y + 1);
  ctx.restore();
}


function locationEventFor(locationKey) {
  return (state?.location_events || []).find(evt => evt.location_key === locationKey) || null;
}

async function startLocationEvent(locationKey) {
  const evt = locationEventFor(locationKey);
  if (!evt) return false;
  const data = await doAction({ action: 'start_location_event', event_key: evt.event_key, location_key: locationKey }, null, { silent: true });
  if (!data?.ok) return false;
  if (data.story_event) {
    state.story_event = data.story_event;
    openDatabaseStoryEvent(data.story_event);
  } else {
    fetchState();
  }
  return true;
}

function drawQuestMarker(cx, cy, evt) {
  const now = Date.now();
  const pulse = 0.5 + 0.5 * Math.sin(now / 420);
  const shakePhase = (now % 3600) / 3600;
  const shake = shakePhase < .18 ? Math.sin(now / 38) * 3 : 0;
  const x = cx + 28 + shake;
  const y = cy - 38;
  ctx.save();
  ctx.shadowColor = 'rgba(255, 214, 94, .82)';
  ctx.shadowBlur = 12 + pulse * 8;
  ctx.fillStyle = 'rgba(92, 66, 20, .96)';
  ctx.strokeStyle = 'rgba(255, 239, 176, .92)';
  ctx.lineWidth = 2;
  roundedPath(x - 15, y - 15, 30, 30, 15);
  ctx.fill();
  ctx.stroke();
  const icon = evt?.icon || questIcon();
  if (isImageIcon(icon)) drawIcon(icon, x, y, 22 + pulse * 2);
  else {
    ctx.font = `900 ${Math.round(19 + pulse * 2)}px system-ui`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillStyle = '#fff7df';
    ctx.fillText(icon, x, y + 1);
  }
  ctx.restore();
}

function drawMapCanvas() {
  canvasSceneHits = [];
  drawMapBackground();
  const locations = state.locations || [];
  const positions = getMapButtonPositions();
  if (isCtrlDown && pointerCanvasPos) {
    const pos = roundedPointerPos();
    ctx.save();
    ctx.font = '800 13px system-ui';
    ctx.textAlign = 'left';
    ctx.fillStyle = 'rgba(20, 16, 11, .86)';
    ctx.strokeStyle = 'rgba(255, 214, 94, .48)';
    ctx.lineWidth = 1;
    fillStrokeRound(18, canvasLogicalHeight() - 48, 152, 30, 12);
    ctx.fillStyle = '#ffe6a0';
    ctx.fillText(`x: ${pos.x}, y: ${pos.y}`, 34, canvasLogicalHeight() - 28);
    ctx.restore();
  }

  for (const loc of locations) {
    const pos = positions[loc.key];
    if (!pos) continue;
    const [x, y] = pos;
    const marker = mapMarkerForLocation(loc);
    const baseSize = Math.max(28, Number(marker.size || 78));
    const hitW = Math.max(84, baseSize + 48);
    const hitH = Math.max(86, baseSize + 42);
    const unlocked = !!loc.unlocked;
    const disabled = !!loc.disabled;
    const actionable = unlocked && !disabled;
    const isHover = canvasHoverHitId === `map:${loc.key}`;
    const targetScale = isHover ? 1.14 : 1;
    const currentScale = mapMarkerScales[loc.key] ?? 1;
    const scale = currentScale + (targetScale - currentScale) * .18;
    mapMarkerScales[loc.key] = scale;
    const cx = x + hitW / 2;
    const cy = y + baseSize * .48;
    const pulse = unlocked ? (0.5 + 0.5 * Math.sin(Date.now() / 520)) : 0;
    const iconSize = (unlocked ? baseSize : baseSize * .92) * scale;

    ctx.save();
    if (actionable) {
      ctx.shadowColor = marker.glowColor || 'rgba(255, 214, 94, .78)';
      ctx.shadowBlur = 15 + pulse * 13;
    }
    drawMapMarkerIcon(marker.icon, cx, cy, iconSize, !unlocked);
    ctx.restore();

    const displayName = unlocked ? loc.name : '???';
    drawMapLabel(displayName, cx, y + baseSize + 18, unlocked);

    const locationEvent = locationEventFor(loc.key);
    if (unlocked && locationEvent) drawQuestMarker(cx, cy, locationEvent);

    const tooltip = unlocked
      ? `<b>${escapeHtml(loc.name)}</b><br><span class="muted-line">${escapeHtml(loc.hint || '')}</span>${locationEvent ? `<br><b>${escapeHtml(locationEvent.title || 'Event available')}</b><br><span class="muted-line">${escapeHtml(locationEvent.tooltip || 'Something needs your attention here.')}</span>` : ''}`
      : '<b>???</b><br><span class="muted-line">An unknown place. Keep building your reputation.</span>';
    canvasSceneHits.push({
      id: `map:${loc.key}`,
      x: x,
      y: y,
      w: hitW,
      h: hitH,
      tooltip,
      action: actionable ? async () => { if (await startLocationEvent(loc.key)) return; switchScreen(loc.key === 'bone_brine' ? 'bone_brine' : loc.key); } : null
    });
  }
}


function shedZonesByKey() {
  const out = {};
  for (const zone of state?.shed?.zones || []) out[zone.zone_key] = zone;
  return out;
}

function shedZoneRect(zone) {
  return {
    x: Number(zone.origin_x || 0),
    y: Number(zone.origin_y || 0),
    w: Number(zone.grid_cols || 1) * Number(zone.cell_width || 48),
    h: Number(zone.grid_rows || 1) * Number(zone.cell_height || 48)
  };
}

function shedObjectFootprint(obj) {
  let w = Number(obj.grid_w || 1);
  let h = Number(obj.grid_h || 1);
  const rot = Number(obj.rotation || 0);
  if (rot === 90 || rot === 270) [w, h] = [h, w];
  return { w, h };
}

function shedObjectRect(obj, override = null) {
  const zones = shedZonesByKey();
  const zone = zones[obj.zone_key];
  if (!zone) return null;
  const fp = shedObjectFootprint(obj);
  const gx = override?.grid_x ?? Number(obj.grid_x || 0);
  const gy = override?.grid_y ?? Number(obj.grid_y || 0);
  const cw = Number(zone.cell_width || 48);
  const ch = Number(zone.cell_height || 48);
  return {
    x: Number(zone.origin_x || 0) + gx * cw,
    y: Number(zone.origin_y || 0) + gy * ch,
    w: fp.w * cw,
    h: fp.h * ch,
    cx: Number(zone.origin_x || 0) + gx * cw + (fp.w * cw) / 2,
    cy: Number(zone.origin_y || 0) + gy * ch + (fp.h * ch) / 2
  };
}

function shedCellFromPoint(zone, px, py, obj = null) {
  const rect = shedZoneRect(zone);
  const cw = Number(zone.cell_width || 48);
  const ch = Number(zone.cell_height || 48);
  const fp = obj ? shedObjectFootprint(obj) : { w: 1, h: 1 };
  const gx = Math.floor((px - rect.x) / cw - (fp.w - 1) / 2);
  const gy = Math.floor((py - rect.y) / ch - (fp.h - 1) / 2);
  return {
    grid_x: Math.max(0, Math.min(Number(zone.grid_cols || 1) - fp.w, gx)),
    grid_y: Math.max(0, Math.min(Number(zone.grid_rows || 1) - fp.h, gy))
  };
}

function shedPlacementValid(obj, gridX, gridY) {
  const zones = shedZonesByKey();
  const zone = zones[obj.zone_key];
  if (!zone) return false;
  const fp = shedObjectFootprint(obj);
  if (gridX < 0 || gridY < 0 || gridX + fp.w > Number(zone.grid_cols) || gridY + fp.h > Number(zone.grid_rows)) return false;
  for (const other of state?.shed?.objects || []) {
    if (Number(other.shed_object_id) === Number(obj.shed_object_id) || other.zone_key !== obj.zone_key) continue;
    const ofp = shedObjectFootprint(other);
    const ox = Number(other.grid_x || 0), oy = Number(other.grid_y || 0);
    if (gridX < ox + ofp.w && gridX + fp.w > ox && gridY < oy + ofp.h && gridY + fp.h > oy) return false;
  }
  return true;
}

function drawShedGrid(zone) {
  const rect = shedZoneRect(zone);
  ctx.save();
  ctx.strokeStyle = zone.zone_key === 'wall' ? 'rgba(172, 216, 255, .26)' : 'rgba(255, 226, 151, .28)';
  ctx.lineWidth = 1;
  for (let c = 0; c <= Number(zone.grid_cols || 0); c++) {
    const x = rect.x + c * Number(zone.cell_width || 48);
    ctx.beginPath(); ctx.moveTo(x, rect.y); ctx.lineTo(x, rect.y + rect.h); ctx.stroke();
  }
  for (let r = 0; r <= Number(zone.grid_rows || 0); r++) {
    const y = rect.y + r * Number(zone.cell_height || 48);
    ctx.beginPath(); ctx.moveTo(rect.x, y); ctx.lineTo(rect.x + rect.w, y); ctx.stroke();
  }
  ctx.strokeStyle = zone.zone_key === 'wall' ? 'rgba(172, 216, 255, .55)' : 'rgba(255, 226, 151, .55)';
  ctx.lineWidth = 2;
  ctx.strokeRect(rect.x, rect.y, rect.w, rect.h);
  ctx.restore();
}

function drawShedObject(obj) {
  let rect = shedObjectRect(obj);
  if (!rect) return;
  let ghost = false;
  if (shedDrag && Number(shedDrag.object.shed_object_id) === Number(obj.shed_object_id)) {
    const zone = shedZonesByKey()[obj.zone_key];
    if (zone && pointerCanvasPos) {
      const cell = shedCellFromPoint(zone, pointerCanvasPos.x, pointerCanvasPos.y, obj);
      const ok = shedPlacementValid(obj, cell.grid_x, cell.grid_y);
      rect = shedObjectRect(obj, cell) || rect;
      ghost = true;
      ctx.save();
      ctx.fillStyle = ok ? 'rgba(94, 199, 126, .22)' : 'rgba(226, 75, 75, .24)';
      ctx.fillRect(rect.x, rect.y, rect.w, rect.h);
      ctx.restore();
    }
  }
  ctx.save();
  ctx.globalAlpha = ghost ? .72 : 1;
  const icon = obj.icon_path || obj.machine_icon || '🧰';
  drawIcon(icon, rect.cx, rect.cy, Math.min(rect.w, rect.h) * .82);
  if (shedEditMode) {
    ctx.strokeStyle = 'rgba(255,255,255,.45)';
    ctx.lineWidth = 2;
    ctx.strokeRect(rect.x + 2, rect.y + 2, rect.w - 4, rect.h - 4);
  }
  ctx.restore();
  canvasSceneHits.push({
    id: `shed:${obj.shed_object_id}`,
    x: rect.x,
    y: rect.y,
    w: rect.w,
    h: rect.h,
    tooltip: `<b>${escapeHtml(obj.display_name || obj.machine_name || 'Shed Object')}</b><br><span class="muted-line">${shedEditMode ? 'Drag to move.' : 'Edit mode lets you rearrange this.'}</span>`,
    object: obj
  });
}

function formatTimeUntil(timestamp) {
  if (!timestamp) return '';
  const ms = new Date(String(timestamp).replace(' ', 'T')).getTime() - Date.now();
  return formatSeconds(Math.max(0, Math.floor(ms / 1000)));
}

function jobsForMachineType(machineType) {
  return (state.jobs || []).filter(j => j.machine_type === machineType && Number(j.is_collected || 0) === 0);
}

function recipesForMachineType(machineType) {
  return (state.recipes || []).filter(r => r.machine_type === machineType);
}

function drawShedStation(station) {
  const qty = Number(station.owned_quantity || 0);
  if (qty <= 0) return;
  const x = Number(station.station_x || station.x || 0);
  const y = Number(station.station_y || station.y || 0);
  const w = Number(station.station_width || station.width || 96);
  const h = Number(station.station_height || station.height || 96);
  const cx = x + w / 2;
  const cy = y + h / 2;
  const isHover = canvasHoverHitId === `shed-station:${station.station_key}`;
  const scale = isHover ? 1.08 : 1;
  const pulse = 0.5 + 0.5 * Math.sin(Date.now() / 560);

  ctx.save();
  ctx.shadowColor = `rgba(255, 214, 94, ${0.62 + pulse * .26})`;
  ctx.shadowBlur = 13 + pulse * 9;
  drawIcon(station.station_icon || station.machine_icon || '🧰', cx, cy, Math.min(w, h) * .84 * scale);
  ctx.restore();

  drawMapLabel(station.display_name || station.machine_name || 'Station', cx, y + h + 15, true);

  canvasSceneHits.push({
    id: `shed-station:${station.station_key}`,
    x, y, w, h: h + 26,
    tooltip: `<b>${escapeHtml(station.display_name || station.machine_name || 'Station')}</b><br><span class="muted-line">Owned ×${qty}. Click to use.</span>`,
    action: () => openMachineStationModal(station)
  });
}

function openMachineStationModal(station) {
  const machineType = station.machine_type;
  const qty = Number(station.owned_quantity || 0);
  const jobs = jobsForMachineType(machineType);
  const recipes = recipesForMachineType(machineType);
  const busy = jobs.length;
  const available = Math.max(0, qty - busy);
  let body = `<p><strong>${escapeHtml(station.display_name || station.machine_name || 'Station')}</strong></p>`;
  body += `<p class="muted-line">Owned: ${qty} · Available: ${available}/${qty}</p>`;

  if (jobs.length) {
    body += '<h3>Working</h3>';
    for (const job of jobs) {
      const ready = new Date(String(job.finishes_at).replace(' ', 'T')).getTime() <= Date.now();
      body += `<div class="machine-slot-row"><span>${renderIcon(job.output_icon || '🍯', 'inline-icon')} ${escapeHtml(job.output_name || 'Output')} ×${Number(job.output_quantity || 1) * Number(job.quantity || 1)}</span><span>${ready ? '<button type="button" class="small-button" data-collect-job="'+Number(job.job_id)+'">Collect</button>' : 'Ready in ' + formatTimeUntil(job.finishes_at)}</span></div>`;
    }
  }

  if (recipes.length) {
    body += '<h3>Start</h3>';
    for (const recipe of recipes) {
      const owned = countInventoryByItemId(recipe.input_item_id);
      const canStart = available > 0 && owned >= Number(recipe.input_quantity || 1);
      body += `<div class="machine-slot-row"><span>${renderIcon(recipe.input_icon || '🌿', 'inline-icon')} ${escapeHtml(recipe.input_name)} ×${recipe.input_quantity} → ${renderIcon(recipe.output_icon || '✨', 'inline-icon')} ${escapeHtml(recipe.output_name)} ×${recipe.output_quantity}</span><button type="button" class="small-button" data-start-recipe="${Number(recipe.recipe_id)}" ${canStart ? '' : 'disabled'}>${available <= 0 ? 'Busy' : 'Start'}</button></div>`;
    }
  } else {
    body += '<p class="hint">Recipes for this station are coming soon.</p>';
  }

  openStoryModal({
    title: station.display_name || station.machine_name || 'Station',
    body,
    button: 'Done',
    closeable: true,
    onNext: async () => closeStoryModal()
  });

  const content = $('#storyContent');
  content?.querySelectorAll('[data-start-recipe]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const recipeId = Number(btn.dataset.startRecipe);
      const data = await doAction({ action: 'start_processing', recipe_id: recipeId, quantity: 1 }, null, { silent: true });
      if (data?.ok) { closeStoryModal(); fetchState(); }
    });
  });
  content?.querySelectorAll('[data-collect-job]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const jobId = Number(btn.dataset.collectJob);
      const data = await doAction({ action: 'collect_processing', job_id: jobId }, null, { silent: true });
      if (data?.ok) { closeStoryModal(); fetchState(); }
    });
  });
}

function drawShedCanvas() {
  canvasSceneHits = [];
  const shedMarker = locationMarkerConfig('shed');
  const bg = state?.shed?.background_image || locationBackgroundForNow('shed');
  drawPanelBackground(shedMarker.icon || '🛖', bg);
  if (!bg) {
    ctx.save();
    ctx.fillStyle = 'rgba(80, 53, 31, .35)';
    ctx.fillRect(70, 92, canvasLogicalWidth() - 140, 210);
    ctx.fillStyle = 'rgba(67, 43, 26, .5)';
    ctx.fillRect(70, 310, canvasLogicalWidth() - 140, 300);
    ctx.restore();
  }

  ctx.save();
  ctx.fillStyle = '#d9a441';
  ctx.font = '800 24px system-ui';
  ctx.textAlign = 'center';
  ctx.shadowColor = 'rgba(0,0,0,.75)';
  ctx.shadowBlur = 5;
  ctx.fillText('Workroom / Shed', canvasLogicalWidth() / 2, 46);
  ctx.restore();

  const stations = (state?.shed?.stations || []).filter(s => Number(s.owned_quantity || 0) > 0);
  for (const station of stations) drawShedStation(station);

  // Wall-decoration placement scaffolding remains in the DB, but functional floor machines are fixed stations.
  if (shedEditMode) {
    for (const zone of (state?.shed?.zones || []).filter(z => z.zone_key === 'wall')) drawShedGrid(zone);
    const objects = [...(state?.shed?.objects || [])].filter(o => o.zone_key === 'wall').sort((a,b) => Number(a.z_index || 0) - Number(b.z_index || 0));
    for (const obj of objects) drawShedObject(obj);
  }

  const btnW = 158, btnH = 36, btnX = canvasLogicalWidth() - btnW - 30, btnY = 24;
  ctx.save();
  ctx.fillStyle = shedEditMode ? 'rgba(217,164,65,.88)' : 'rgba(0,0,0,.45)';
  ctx.strokeStyle = 'rgba(255,255,255,.24)';
  fillStrokeRound(btnX, btnY, btnW, btnH, 18);
  ctx.fillStyle = shedEditMode ? '#21180f' : '#f5ead8';
  ctx.font = '800 13px system-ui';
  ctx.textAlign = 'center';
  ctx.fillText(shedEditMode ? 'Done Wall Editing' : 'Edit Wall Decor', btnX + btnW / 2, btnY + 23);
  ctx.restore();
  canvasSceneHits.push({ id: 'shed-edit-toggle', x: btnX, y: btnY, w: btnW, h: btnH, tooltip: '<b>Wall Decor</b><br><span class="muted-line">Wall decoration placement scaffold.</span>', action: () => { shedEditMode = !shedEditMode; shedDrag = null; render(); } });

  if (!stations.length) {
    ctx.fillStyle = '#f5ead8';
    ctx.font = '800 18px system-ui';
    ctx.textAlign = 'center';
    ctx.fillText('No stations yet.', canvasLogicalWidth() / 2, canvasLogicalHeight() / 2 - 10);
    ctx.font = '14px system-ui';
    ctx.fillStyle = '#b8a88f';
    ctx.fillText('Buy your first machine at the General Store to unlock this workspace.', canvasLogicalWidth() / 2, canvasLogicalHeight() / 2 + 18);
  }
}

function handleShedMouseDown(evt) {
  if (isModalOpen() || currentTabName() !== 'shed' || !shedEditMode) return;
  setPointerFromEvent(evt);
  const hits = canvasSceneHits.filter(h => h.object && pointerCanvasPos.x >= h.x && pointerCanvasPos.x <= h.x + h.w && pointerCanvasPos.y >= h.y && pointerCanvasPos.y <= h.y + h.h);
  const hit = hits[hits.length - 1];
  if (!hit) return;
  evt.preventDefault();
  shedDrag = { object: hit.object };
}

function handleShedMouseUp() {
  if (!shedDrag || currentTabName() !== 'shed') { shedDrag = null; return; }
  const obj = shedDrag.object;
  const zone = shedZonesByKey()[obj.zone_key];
  if (!zone || !pointerCanvasPos) { shedDrag = null; render(); return; }
  const cell = shedCellFromPoint(zone, pointerCanvasPos.x, pointerCanvasPos.y, obj);
  if (!shedPlacementValid(obj, cell.grid_x, cell.grid_y)) {
    shedDrag = null;
    showStatus('That spot is already occupied.', true);
    render();
    return;
  }
  obj.grid_x = cell.grid_x;
  obj.grid_y = cell.grid_y;
  shedDrag = null;
  render();
  doAction({ action: 'move_shed_object', shed_object_id: Number(obj.shed_object_id), grid_x: cell.grid_x, grid_y: cell.grid_y, rotation: Number(obj.rotation || 0) }, null, { silent: true });
}

function drawOrdersCanvas() {
  canvasSceneHits = [];
  drawPanelBackground(ordersIcon(), locationBackgroundForNow('orders'));
  ctx.fillStyle = '#d9a441';
  ctx.font = '800 22px system-ui';
  ctx.textAlign = 'center';
  const confirmed = state.orders || [];
  const available = state.available_orders || [];
  const slotLimit = Number(state?.order_slot_limit || 2);
  const availableLimit = Number(state?.available_order_limit || 5);
  ctx.fillText(`Orders Board`, canvasLogicalWidth() / 2, 58);
  ctx.font = '14px system-ui';
  ctx.fillStyle = '#b8a88f';
  ctx.fillText(`Confirmed Orders: ${confirmed.length}/${slotLimit} · Available Orders: ${available.length}/${availableLimit}`, canvasLogicalWidth() / 2, 82);

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
      drawOrderBoardCard(order, 70, y, canvasLogicalWidth() - 140, 72, true);
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
    drawOrderBoardCard(order, 70, y, canvasLogicalWidth() - 140, 70, false);
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
  drawOrderRewardCanvas(order, confirmed, x + 18, y + 63);
  const tooltip = confirmed ? '<b>Confirmed Order</b><br><span class="muted-line">Click to review, complete, or cancel.</span>' : '<b>Available Order</b><br><span class="muted-line">Click to inspect and accept.</span>';
  canvasSceneHits.push({ x, y, w, h, tooltip, action: () => openOrdersModal(order.player_order_id) });
}


function equipHelperAccessoryLocal(helperId, equipmentId) {
  const helper = (state.helpers || []).find(h => Number(h.player_helper_id) === Number(helperId));
  const equip = (state.helper_accessories || []).find(a => Number(a.helper_equipment_id) === Number(equipmentId));
  if (!helper) return;
  helper.equipped_helper_equipment_id = equipmentId || null;
  helper.equipment_name = equip?.name || null;
  helper.equipment_icon = equip?.icon || equip?.item_icon || null;
  helper.active_task = equip?.task_type || 'idle';
  render();
  doAction({ action: 'equip_helper_accessory', player_helper_id: Number(helperId), helper_equipment_id: equipmentId || null }, null, { silent: true });
}

function drawHelpersCanvas() {
  canvasSceneHits = [];
  drawPanelBackground(locationIconFor('helpers', '🧚', ['forest_folk']), locationBackgroundForNow('helpers', ['forest_folk']));
  const unlocked = hasUnlockKey('helpers_unlocked');

  drawCanvasSlot(118, 48, canvasLogicalWidth() - 236, 58, { fill: 'rgba(18,13,9,.58)', stroke: 'rgba(217,164,65,.28)' });
  ctx.fillStyle = '#d9a441';
  ctx.font = '800 22px system-ui';
  ctx.textAlign = 'center';
  ctx.fillText('Forest Folk', canvasLogicalWidth() / 2, 76);
  ctx.font = '14px system-ui';
  ctx.fillStyle = '#e7d7bd';
  ctx.fillText(unlocked ? 'Drag an owned accessory onto a helper slot to assign a job.' : 'Ring the bell to summon your first helper.', canvasLogicalWidth() / 2, 98);

  if (!unlocked) {
    const hasBell = !!inventoryItemByCode('fairy_bell');
    const x = 110, y = 142, w = canvasLogicalWidth() - 220, h = 150;
    drawCanvasSlot(x, y, w, h, { stroke: hasBell ? 'rgba(143,196,107,.35)' : 'rgba(217,164,65,.28)' });
    drawIcon('🔔', x + 74, y + 75, 58);
    ctx.textAlign = 'left';
    ctx.font = '800 19px system-ui';
    ctx.fillStyle = '#f5ead8';
    ctx.fillText('First Fairy Bell', x + 140, y + 52);
    ctx.font = '15px system-ui';
    ctx.fillStyle = '#b8a88f';
    wrapCanvasText(hasBell ? 'Ring the bell to summon your first fairy.' : 'Find a relic, meet Madam Rune, and get a Fairy Bell.', x + 140, y + 82, w - 170, 20);
    if (hasBell) canvasSceneHits.push({ x, y, w, h, tooltip: '<b>Ring the Fairy Bell</b><br><span class="muted-line">Consumes the bell and summons the first water fairy.</span>', action: openFairyBellModal });
    return;
  }

  const helpers = state.helpers || [];
  const accessories = state.helper_accessories || [];
  const listX = 42, listY = 126, listW = canvasLogicalWidth() - 84, listH = canvasLogicalHeight() - 300;
  drawCanvasSlot(listX, listY, listW, listH, { fill: 'rgba(18,13,9,.34)', stroke: 'rgba(143,196,107,.25)' });

  const cardGap = 12;
  const cardW = Math.floor((listW - 36 - cardGap) / 2);
  const cardH = 112;
  const cols = 2;
  const rows = Math.ceil(helpers.length / cols);
  const contentH = rows * (cardH + cardGap) - cardGap;
  const maxScroll = Math.max(0, contentH - (listH - 28));
  helperCanvasScroll = Math.max(0, Math.min(maxScroll, helperCanvasScroll));

  ctx.save();
  roundedPath(listX + 10, listY + 10, listW - 20, listH - 20, 16);
  ctx.clip();
  helpers.forEach((helper, index) => {
    const col = index % cols;
    const row = Math.floor(index / cols);
    const x = listX + 18 + col * (cardW + cardGap);
    const y = listY + 16 + row * (cardH + cardGap) - helperCanvasScroll;
    if (y > listY + listH || y + cardH < listY) return;
    drawCanvasSlot(x, y, cardW, cardH, { fill: 'rgba(18,13,9,.66)', stroke: 'rgba(143,196,107,.34)' });
    const iconX = x + 44, iconY = y + 56;
    drawIcon(helper.icon || 'assets/icons/helpers/fairy.png', iconX, iconY, 58);
    canvasSceneHits.push({ id: `helper-portrait:${helper.player_helper_id}`, x: iconX - 34, y: iconY - 34, w: 68, h: 68, popoutIcon: helper.icon || 'assets/icons/helpers/fairy.png', tooltip: `<b>${escapeHtml(helper.helper_name || 'Unnamed Helper')}</b><br><span class="muted-line">Hovering gives a closer look.</span>` });
    ctx.textAlign = 'left';
    ctx.font = '800 16px system-ui';
    ctx.fillStyle = '#f5ead8';
    ctx.fillText(helper.helper_name || 'Unnamed Helper', x + 84, y + 32);
    ctx.font = '13px system-ui';
    ctx.fillStyle = '#b8a88f';
    ctx.fillText(`${helper.name || helper.species_key || 'Forest Folk'}`, x + 84, y + 54);
    ctx.fillText(`Job: ${helper.active_task || 'idle'}`, x + 84, y + 74);

    const slot = 54, slotX = x + cardW - slot - 12, slotY = y + cardH - slot - 12;
    drawCanvasSlot(slotX, slotY, slot, slot, { stroke: 'rgba(255,230,160,.28)', fill: 'rgba(255,255,255,.035)' });
    const equippedIcon = helper.equipment_icon || helper.equipped_icon || null;
    if (equippedIcon) drawIcon(equippedIcon, slotX + slot / 2, slotY + slot / 2, 34);
    else {
      ctx.textAlign = 'center';
      ctx.font = '800 24px system-ui';
      ctx.fillStyle = '#b8a88f';
      ctx.fillText('+', slotX + slot / 2, slotY + slot / 2 + 8);
    }
    canvasSceneHits.push({ id: `helper-slot:${helper.player_helper_id}`, x: slotX, y: slotY, w: slot, h: slot, equipHelperId: Number(helper.player_helper_id), tooltip: '<b>Accessory Slot</b><br><span class="muted-line">Drag an owned accessory here to assign it.</span>' });
  });
  ctx.restore();

  if (maxScroll > 0) {
    const trackX = listX + listW - 15, trackY = listY + 18, trackH = listH - 36;
    ctx.fillStyle = 'rgba(255,255,255,.12)';
    fillStrokeRound(trackX, trackY, 5, trackH, 4);
    const thumbH = Math.max(34, trackH * ((listH - 28) / contentH));
    const thumbY = trackY + (trackH - thumbH) * (helperCanvasScroll / maxScroll);
    ctx.fillStyle = 'rgba(217,164,65,.58)';
    fillStrokeRound(trackX, thumbY, 5, thumbH, 4);
  }

  const shelfY = canvasLogicalHeight() - 142;
  drawCanvasSlot(42, shelfY - 42, canvasLogicalWidth() - 84, 122, { fill: 'rgba(18,13,9,.54)', stroke: 'rgba(217,164,65,.30)' });
  ctx.textAlign = 'left';
  ctx.font = '800 16px system-ui';
  ctx.fillStyle = '#ffe6a0';
  ctx.fillText('Owned Accessories', 62, shelfY - 18);
  const slot = 70, gap = 12;
  accessories.forEach((acc, index) => {
    const x = 62 + index * (slot + gap);
    const owned = Number(acc.owned_quantity ?? (acc.code === 'aqua_amulet' ? 1 : 0));
    const unavailable = owned < 1;
    drawCanvasSlot(x, shelfY, slot, slot, { alpha: unavailable ? .42 : 1, stroke: unavailable ? 'rgba(255,255,255,.08)' : 'rgba(217,164,65,.28)' });
    drawIcon(acc.icon || acc.item_icon || '✨', x + slot / 2, shelfY + slot / 2 - 7, 36);
    ctx.textAlign = 'center';
    ctx.font = '10px system-ui';
    ctx.fillStyle = '#f5ead8';
    ctx.fillText(acc.name || 'Accessory', x + slot / 2, shelfY + slot - 8);
    if (!unavailable) canvasSceneHits.push({ id: `accessory:${acc.helper_equipment_id}`, x, y: shelfY, w: slot, h: slot, dragAccessory: { equipmentId: Number(acc.helper_equipment_id), icon: acc.icon || acc.item_icon || '✨', name: acc.name || 'Accessory' }, tooltip: `<b>${escapeHtml(acc.name || 'Accessory')}</b><br><span class="muted-line">Drag onto a helper slot to assign ${escapeHtml(acc.task_type || 'job')}.</span>` });
  });

  drawCanvasHoverPopout();
}

function drawCanvasHoverPopout() {
  if (!canvasHoverHitId) return;
  const hit = canvasSceneHits.find(h => h.id === canvasHoverHitId && h.popoutIcon);
  if (!hit) return;
  const age = performance.now() - (canvasHoverHitStartedAt || performance.now());
  const t = Math.min(1, age / 260);
  const eased = 1 - Math.pow(1 - t, 3);
  const size = 92 + eased * 26;
  const x = Math.min(canvasLogicalWidth() - size / 2 - 28, hit.x + hit.w + 74);
  const y = Math.max(size / 2 + 20, Math.min(canvasLogicalHeight() - size / 2 - 20, hit.y + hit.h / 2));
  ctx.save();
  ctx.globalAlpha = .92 * eased;
  drawCanvasSlot(x - size / 2 - 10, y - size / 2 - 10, size + 20, size + 20, { fill: 'rgba(18,13,9,.72)', stroke: 'rgba(255,230,160,.36)' });
  drawIcon(hit.popoutIcon, x, y, size);
  ctx.restore();
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

function orderRewardText(order, confirmed = true, { html = true } = {}) {
  const late = confirmed && Number(order.is_late || 0) === 1;
  const coins = Number(order.payment_coins || 0);
  const rep = Number(order.reputation_reward || 1);
  const rec = Number(order.recognition_reward || 0);
  const repVisual = reputationIcon();
  const recVisual = recognitionIcon();
  const coinVisual = coinIcon();
  const repIcon = html ? rewardIconHtml(repVisual, REP_TOOLTIP) : plainIconFallback(repVisual, '⭐');
  const recIcon = html ? rewardIconHtml(recVisual, REC_TOOLTIP) : plainIconFallback(recVisual, '🏵️');
  const coin = html ? coinHtml() : plainIconFallback(coinVisual, '🪙');
  if (late) {
    const lateCoins = Number(order.late_payment_coins || Math.floor(coins * .8));
    const fee = Number(order.late_total_penalty_percent || order.late_fee_percent || 20);
    const label = order.order_type === 'rush' ? 'rush expired' : 'late fee';
    return `${coin} ${lateCoins} (incl. -${fee}% ${label})`;
  }
  const rushText = order.order_type === 'rush' ? ' (incl. +20% rush bonus)' : '';
  const repText = rep ? ` · ${repIcon} ${rep}` : '';
  const recText = rec ? ` · ${recIcon} ${rec}` : '';
  return `${coin} ${coins}${rushText}${repText}${recText}`;
}

function drawOrderRewardCanvas(order, confirmed, x, y) {
  const late = confirmed && Number(order.is_late || 0) === 1;
  const coins = Number(order.payment_coins || 0);
  const rep = Number(order.reputation_reward || 1);
  const rec = Number(order.recognition_reward || 0);
  let cursorX = x;

  const drawSmallIcon = (icon) => {
    drawIcon(icon, cursorX + 8, y - 5, 18);
    cursorX += 20;
  };
  const drawText = (text, color = null, weight = '800') => {
    ctx.font = `${weight} 14px system-ui`;
    if (color) ctx.fillStyle = color;
    ctx.fillText(text, cursorX, y);
    cursorX += ctx.measureText(text).width + 8;
  };

  drawSmallIcon(coinIcon());
  if (late) {
    const lateCoins = Number(order.late_payment_coins || Math.floor(coins * .8));
    const fee = Number(order.late_total_penalty_percent || order.late_fee_percent || 20);
    const label = order.order_type === 'rush' ? 'rush expired' : 'late fee';
    drawText(`${lateCoins} (incl. -${fee}% ${label})`, '#ffb199');
    return;
  }

  drawText(String(coins), '#9bea74');
  if (order.order_type === 'rush') drawText('(incl. +20% rush bonus)', '#9bea74');
  if (rep) {
    drawSmallIcon(reputationIcon());
    drawText(String(rep), '#ffe6a0');
  }
  if (rec) {
    drawSmallIcon(recognitionIcon());
    drawText(String(rec), '#ffe6a0');
  }
}

function weeklySpecialItems() {
  const woodenHoe = (state.all_tools || []).find(t => t.code === 'wooden_hoe');
  const ownsWoodenHoe = (state.tools || []).some(t => t.code === 'wooden_hoe');
  if (woodenHoe && !ownsWoodenHoe) {
    return [{ tool: woodenHoe, icon: woodenHoe.icon, name: 'Weekly Special: Wooden Hoe', price: Number(woodenHoe.upgrade_cost || 75), action: () => buyToolFromCanvas(woodenHoe) }];
  }
  const deed = (state.inventory || []).find(i => i.code === 'land_claim_note') || { code: 'land_claim_note', icon: '📜', name: 'Land Claim Note', base_buy_price: 175, quantity: 0 };
  const purchased = Number(state.land_claim_shop_purchases || 0);
  if (purchased >= 3) return [];
  const price = Number(deed.base_buy_price || 175) + (purchased * 75);
  return [{ icon: deed.icon || '📜', name: `Special: Land Claim Note (${3 - purchased} left at shop)`, price, action: () => buySpecialItemFromCanvas('land_claim_note', { ...deed, base_buy_price: price }) }];
}

function drawShopCanvas() {
  canvasSceneHits = [];
  const hideSecretNight = !hasUnlockKey('location_market');
  const shopIcon = locationIconFor('shop', '🏪', ['store', 'general_store']);
  drawPanelBackground(shopIcon, locationBackgroundForNow('shop', ['store', 'general_store'], { forceDay: hideSecretNight }));
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
        const priceText = String(item.price);
        const priceX = x + slot - 12;
        ctx.fillText(priceText, priceX, y + slot - 18);
        const tw = ctx.measureText(priceText).width;
        drawIcon(coinIcon(), priceX - tw - 12, y + slot - 23, 17);
      } else if (item.comingSoon) {
        ctx.font = '12px system-ui';
        ctx.fillStyle = '#b8a88f';
        ctx.textAlign = 'center';
        ctx.fillText('Coming Soon', x + slot / 2, y + slot - 18);
      }
      const tooltip = item.comingSoon
        ? `<b>Today's Special</b><br><span class="muted-line">Coming soon.</span>`
        : `<b>${escapeHtml(item.name)}</b><br><span class="muted-line">Price ${moneyHtml(item.price)}</span>${affordable ? '' : '<br><span style="color:#ff8778">Not enough coins</span>'}`;
      canvasSceneHits.push({ x, y, w: slot, h: slot, tooltip, action: (!disabled && item.action) ? item.action : null });
    });
  }
}

function drawPanelBackgroundLayer(backgroundImage = '', alpha = 1) {
  ctx.save();
  ctx.globalAlpha = alpha;
  ctx.fillStyle = '#211a13';
  ctx.strokeStyle = 'rgba(255,255,255,.08)';
  ctx.lineWidth = 2;
  fillStrokeRound(8, 8, canvasLogicalWidth() - 16, canvasLogicalHeight() - 16, 24);

  if (backgroundImage && isImageIcon(backgroundImage)) {
    let img = imageCache[backgroundImage];
    if (!img) {
      preloadImageAsset(backgroundImage);
      img = imageCache[backgroundImage];
    }
    if (img?.complete && img.naturalWidth && !img.failed) {
      roundedPath(10, 10, canvasLogicalWidth() - 20, canvasLogicalHeight() - 20, 22);
      ctx.clip();
      const scale = Math.max((canvasLogicalWidth() - 20) / img.naturalWidth, (canvasLogicalHeight() - 20) / img.naturalHeight);
      const w = img.naturalWidth * scale;
      const h = img.naturalHeight * scale;
      ctx.globalAlpha = alpha * .86;
      ctx.drawImage(img, 10 + ((canvasLogicalWidth() - 20) - w) / 2, 10 + ((canvasLogicalHeight() - 20) - h) / 2, w, h);
      ctx.globalAlpha = alpha * .32;
      ctx.fillStyle = '#120f0b';
      ctx.fillRect(10, 10, canvasLogicalWidth() - 20, canvasLogicalHeight() - 20);
    }
  }
  ctx.restore();
}

function drawPanelBackground(icon, backgroundImage = '') {
  if (backgroundImage !== panelBackgroundImage) {
    const sameScreen = panelBackgroundScreen === currentTabName();
    panelBackgroundPrevImage = panelBackgroundImage;
    panelBackgroundImage = backgroundImage;
    panelBackgroundScreen = currentTabName();
    panelBackgroundTransitionMs = sameScreen ? STATE_BACKGROUND_TRANSITION_MS : LOCATION_BACKGROUND_TRANSITION_MS;
    panelBackgroundTransitionStartedAt = performance.now();
  }

  if (panelBackgroundPrevImage && panelBackgroundTransitionStartedAt) {
    drawPanelBackgroundLayer(panelBackgroundPrevImage, 1);
    const alpha = Math.min(1, (performance.now() - panelBackgroundTransitionStartedAt) / panelBackgroundTransitionMs);
    drawPanelBackgroundLayer(panelBackgroundImage, alpha);
    if (alpha >= 1) panelBackgroundPrevImage = '';
  } else {
    drawPanelBackgroundLayer(panelBackgroundImage, 1);
  }

  ctx.save();
  drawIcon(icon, 52, 46, 38);
  ctx.restore();
}

function locationMarkerConfig(key) {
  return configuredMapMarkerForKey(key);
}

function isDayPhaseNow() {
  const snap = gameClockSnapshot?.();
  const hour = Number(snap?.hour ?? 12);
  return hour >= 6 && hour < 18;
}

function locationBackgroundForNow(key, aliases = [], options = {}) {
  if (key === 'caravan' || (Array.isArray(aliases) && aliases.includes('caravan'))) return caravanBackgroundForNow();
  const keys = [key].concat(Array.isArray(aliases) ? aliases : [aliases]).filter(Boolean);
  const forceDay = !!options.forceDay;
  for (const locKey of keys) {
    const marker = locationMarkerConfig(locKey);
    if (!marker || !Object.keys(marker).length) continue;
    const dayBg = marker.day_background_image || marker.background_day_image || marker.background_image || '';
    const nightBg = marker.night_background_image || marker.background_night_image || dayBg;
    const selected = (forceDay || isDayPhaseNow()) ? dayBg : (nightBg || dayBg);
    if (selected) return selected;
  }
  return '';
}

function locationIconFor(key, fallback = '❔', aliases = []) {
  const keys = [key].concat(Array.isArray(aliases) ? aliases : [aliases]).filter(Boolean);
  for (const locKey of keys) {
    const marker = locationMarkerConfig(locKey);
    if (marker?.icon) return marker.icon;
  }
  return fallback;
}

function marketBackgroundForNow() {
  if (!state?.market_status?.is_open) return 'assets/market/closed.png';
  const marker = locationMarkerConfig('market');
  const dayBg = marker.day_background_image || marker.background_day_image || marker.background_image || '';
  const nightBg = marker.night_background_image || marker.background_night_image || dayBg;
  const phase = state?.market_status?.phase || (isDayPhaseNow() ? 'day' : 'night');
  return phase === 'night' ? (nightBg || dayBg) : dayBg;
}


function marketWandererCount() {
  return Math.max(0, Math.min(10, Number(state?.ui_config?.fae_market_wanderer_count ?? 5)));
}

function marketWandererImageCount() {
  return Math.max(1, Math.min(40, Number(state?.ui_config?.fae_market_wanderer_image_count ?? 18)));
}

function marketWandererSizeScale() {
  return Math.max(.5, Math.min(2.5, Number(state?.ui_config?.fae_market_wanderer_size ?? 1.18)));
}

function marketWandererBaseAlpha() {
  return Math.max(.15, Math.min(1, Number(state?.ui_config?.fae_market_wanderer_alpha ?? .84)));
}

function marketWandererHueShiftEnabled() {
  return Number(state?.ui_config?.fae_market_wanderer_hue_shift_enabled ?? 1) === 1 || state?.ui_config?.fae_market_wanderer_hue_shift_enabled === true;
}

function randomMarketWandererIcon(imageCount = marketWandererImageCount()) {
  return `assets/market/fae/fae${1 + Math.floor(Math.random() * imageCount)}.png`;
}

function resetMarketWanderer(f, imageCount = marketWandererImageCount()) {
  const w = canvasLogicalWidth(), h = canvasLogicalHeight();
  const scale = marketWandererSizeScale();
  f.icon = randomMarketWandererIcon(imageCount);
  f.mode = 'enter';
  f.x = 80 + Math.random() * Math.max(1, w - 160);
  f.y = h + 70 + Math.random() * 90;
  f.targetY = 135 + Math.random() * Math.max(1, h - 255);
  f.vx = (Math.random() - .5) * .28;
  f.vy = -(.42 + Math.random() * .28);
  f.size = (48 + Math.random() * 34) * scale;
  f.hue = Math.floor(Math.random() * 360);
  f.alpha = marketWandererBaseAlpha();
  f.flip = Math.random() < .5 ? -1 : 1;
  f.nextTurnAt = performance.now() + 900 + Math.random() * 2600;
  f.recycleAt = performance.now() + 28000 + Math.random() * 42000;
}

function beginMarketWandererExit(f) {
  const now = performance.now();
  f.mode = 'exit';
  f.exitStartedAt = now;
  f.vx = (Math.random() - .5) * .18;
  f.vy = .52 + Math.random() * .34;
  f.recycleAt = now + 3000;
}

function ensureMarketWanderers() {
  const count = marketWandererCount();
  const imageCount = marketWandererImageCount();
  const seed = `${count}:${imageCount}:${marketWandererSizeScale()}:${marketWandererBaseAlpha()}:${marketWandererHueShiftEnabled()}:${state?.clock?.absolute_day || 0}:${state?.market_status?.phase || 'day'}:${state?.market_status?.is_open ? 'open' : 'closed'}`;
  if (marketWandererSeed === seed && marketWanderers.length === count) return;
  marketWandererSeed = seed;
  marketWanderers = [];
  for (let i = 0; i < count; i++) {
    const f = {};
    resetMarketWanderer(f, imageCount);
    f.y = f.targetY + (Math.random() - .5) * 80;
    f.mode = 'wander';
    marketWanderers.push(f);
  }
}

function drawMarketWanderers() {
  if (!state?.market_status?.is_open) return;
  ensureMarketWanderers();
  const now = performance.now();
  const w = canvasLogicalWidth(), h = canvasLogicalHeight();
  const imageCount = marketWandererImageCount();
  const baseAlpha = marketWandererBaseAlpha();
  for (const f of marketWanderers) {
    if (f.mode === 'enter') {
      f.x += f.vx * 2.2;
      f.y += f.vy * 2.2;
      if (f.y <= f.targetY) {
        f.mode = 'wander';
        f.vy = (Math.random() - .5) * .28;
        f.vx = (Math.random() - .5) * .34;
        f.recycleAt = now + 26000 + Math.random() * 42000;
      }
    } else if (f.mode === 'exit') {
      f.x += f.vx * 2.2;
      f.y += f.vy * 2.2;
      if (f.y > h + 120 || now > f.recycleAt) resetMarketWanderer(f, imageCount);
    } else {
      f.x += f.vx * 2.2;
      f.y += f.vy * 2.2;
      if (now > f.recycleAt) beginMarketWandererExit(f);
      if (now > f.nextTurnAt) {
        f.vx = (Math.random() - .5) * .34;
        f.vy = (Math.random() - .5) * .34;
        f.nextTurnAt = now + 900 + Math.random() * 2800;
      }
      if (f.x < 45 || f.x > w - 45) f.vx *= -1;
      if (f.y < 95 || f.y > h - 85) f.vy *= -1;
      f.x = Math.max(45, Math.min(w - 45, f.x));
      f.y = Math.max(95, Math.min(h - 85, f.y));
    }
    const enterFade = f.mode === 'enter' ? Math.max(0, Math.min(1, (h + 80 - f.y) / 120)) : 1;
    const exitFade = f.mode === 'exit' ? Math.max(0, Math.min(1, 1 - ((f.y - h + 15) / 120))) : 1;
    ctx.save();
    ctx.globalAlpha = baseAlpha * enterFade * exitFade;
    ctx.filter = marketWandererHueShiftEnabled() ? `hue-rotate(${f.hue || 0}deg)` : 'none';
    ctx.translate(f.x, f.y);
    ctx.scale(f.flip || 1, 1);
    drawIcon(f.icon, 0, 0, f.size);
    ctx.restore();
  }
}

function drawMarketBuyablesCanvas() {
  const items = state?.market_inventory || [];
  const isOpen = !!state?.market_status?.is_open;
  const x0 = 72, y0 = 104;
  ctx.save();
  ctx.textAlign = 'left';
  ctx.font = '900 22px system-ui';
  ctx.fillStyle = '#ffe6a0';
  ctx.fillText('Market Finds', x0, y0 - 22);
  if (!isOpen) {
    ctx.font = '700 16px system-ui';
    ctx.fillStyle = '#f5d1a0';
    ctx.fillText('The Fae Market stalls are closed right now.', x0, y0 + 12);
    ctx.restore();
    return;
  }
  if (!items.length) {
    ctx.font = '700 16px system-ui';
    ctx.fillStyle = '#f5d1a0';
    ctx.fillText('No market finds are available right now.', x0, y0 + 12);
    ctx.restore();
    return;
  }
  const cols = 3, cardW = 168, cardH = 126, gap = 18;
  items.slice(0, 9).forEach((item, index) => {
    const col = index % cols;
    const row = Math.floor(index / cols);
    const x = x0 + col * (cardW + gap);
    const y = y0 + row * (cardH + gap);
    const qty = Number(item.bundle_quantity || 1);
    const price = Number(item.market_price || item.base_buy_price || 0);
    const affordable = Number(state?.user?.coins || 0) >= price;
    drawCanvasSlot(x, y, cardW, cardH, { fill: 'rgba(22,17,12,.76)', stroke: affordable ? 'rgba(255,230,160,.26)' : 'rgba(255,120,100,.22)' });
    drawIcon(item.icon || '✨', x + 46, y + 46, 58);
    if (qty > 1) {
      ctx.font = '900 17px system-ui';
      ctx.fillStyle = '#fff8dd';
      ctx.textAlign = 'center';
      ctx.fillText(`×${qty}`, x + 82, y + 28);
    }
    ctx.font = '800 14px system-ui';
    ctx.fillStyle = '#f5ead8';
    ctx.textAlign = 'left';
    wrapCanvasText(item.name || 'Market Item', x + 18, y + 90, cardW - 36, 16);
    ctx.font = '900 14px system-ui';
    ctx.fillStyle = affordable ? '#ffe6a0' : '#ff9c8b';
    ctx.textAlign = 'right';
    ctx.fillText(String(price), x + cardW - 18, y + cardH - 18);
    drawIcon(coinIcon(), x + cardW - 47, y + cardH - 22, 17);
    canvasSceneHits.push({
      id: `market-buy:${item.market_inventory_id || item.fae_market_inventory_id || index}`,
      x, y, w: cardW, h: cardH,
      tooltip: `<b>${escapeHtml(item.name || 'Market Item')}</b><br><span class="muted-line">Buy ×${qty} for ${moneyHtml(price)}</span>${affordable ? '' : '<br><span style="color:#ff8778">Not enough coins</span>'}`,
      action: affordable ? () => buyMarketItemLocal(item, x + cardW / 2, y + cardH / 2) : null
    });
  });
  ctx.restore();
}

function drawMarketScene() {
  canvasSceneHits = [];
  const bg = marketBackgroundForNow();
  const icon = locationMarkerConfig('market').icon || '🎪';
  drawPanelBackground(icon, bg);
  if (state?.market_status?.is_open) drawMarketWanderers();
  drawMarketBuyablesCanvas();
}

function drawSimpleScene(label, icon, backgroundImage = '', locationKey = '') {
  canvasSceneHits = [];
  const bg = backgroundImage || (locationKey ? locationBackgroundForNow(locationKey) : '');
  drawPanelBackground(icon, bg);
}

function drawGardenCanvas() {
  tileRects = [];
  cropProblemHits = [];
  const visibleKeys = visiblePlotKeys();
  const visiblePlots = (state.plots || []).filter(p => visibleKeys.has(`${Number(p.x_pos)},${Number(p.y_pos)}`));
  if (!visiblePlots.length) return;
  const minX = Math.min(...visiblePlots.map(p => Number(p.x_pos)));
  const maxX = Math.max(...visiblePlots.map(p => Number(p.x_pos)));
  const minY = Math.min(...visiblePlots.map(p => Number(p.y_pos)));
  const maxY = Math.max(...visiblePlots.map(p => Number(p.y_pos)));
  const padding = 14, gap = 3;
  const cols = maxX - minX + 1, rows = maxY - minY + 1;
  const usable = Math.min(canvasLogicalWidth(), canvasLogicalHeight()) - padding * 2;
  const size = Math.floor((usable - gap * (Math.max(cols, rows) - 1)) / Math.max(cols, rows));
  const startX = Math.floor((canvasLogicalWidth() - (size * cols + gap * (cols - 1))) / 2);
  const startY = Math.floor((canvasLogicalHeight() - (size * rows + gap * (rows - 1))) / 2);
  const gardenMarker = locationMarkerConfig('garden');
  const gardenBg = gardenBackgroundForNow();
  drawPanelBackground(gardenMarker.icon || state?.garden?.garden_type_icon || '🌱', gardenBg);

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
  drawGardenHelpers();
  drawGhost();
}

function draw() {
  requestAnimationFrame(draw);
  if (!canvas || !ctx) return;
  ctx.setTransform(1, 0, 0, 1, 0, 0);
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  ctx.setTransform(canvasDpr, 0, 0, canvasDpr, 0, 0);

  if (!state) {
    ctx.fillStyle = '#211a13';
    ctx.fillRect(0, 0, canvasLogicalWidth(), canvasLogicalHeight());
    ctx.fillStyle = '#b8a88f';
    ctx.font = '24px system-ui';
    ctx.textAlign = 'center';
    ctx.fillText('Loading field...', canvasLogicalWidth() / 2, canvasLogicalHeight() / 2);
    return;
  }

  updateCanvasCursor();
  const tab = currentTabName();
  if (tab === 'map') drawMapCanvas();
  else if (tab === 'shop') drawShopCanvas();
  else if (tab === 'orders') { canvasSceneHits = []; return; }
  else if (tab === 'inventory') drawInventoryCanvas();
  else if (tab === 'shed') drawShedCanvas();
  else if (tab === 'helpers') drawHelpersCanvas();
  else if (tab === 'market') drawMarketScene();
  else if (tab === 'caravan') drawSimpleScene('Caravan Camp', locationIconFor('caravan', '🔮', ['caravan_camp']), locationBackgroundForNow('caravan', ['caravan_camp']), 'caravan');
  else if (tab === 'bone_brine') drawSimpleScene('Bone & Brine', locationIconFor('bone_brine', '☠️'), locationBackgroundForNow('bone_brine'), 'bone_brine');
  else if (tab === 'admin') drawSimpleScene('Admin Debug', locationIconFor('admin', '🛠️'), locationBackgroundForNow('admin'), 'admin');
  else drawGardenCanvas();

  if (helperAccessoryDrag && pointerCanvasPos && currentTabName() === 'helpers') {
    ctx.save();
    ctx.globalAlpha = .82;
    drawIcon(helperAccessoryDrag.icon || '✨', pointerCanvasPos.x, pointerCanvasPos.y, 42);
    ctx.restore();
  }

  drawFx();
  drawToolCursor();
}

function buySeedFromCanvas(plant) {
  if (Number(state.user.coins) < Number(plant.base_buy_price || 0)) return showStatus('Not enough coins.', true);
  const x = pointerCanvasPos?.x || canvasLogicalWidth() / 2, y = pointerCanvasPos?.y || canvasLogicalHeight() / 2;
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
  const x = pointerCanvasPos?.x || canvasLogicalWidth() / 2, y = pointerCanvasPos?.y || canvasLogicalHeight() / 2;
  state.user.coins -= Number(machine.base_cost || 0);
  canvasFloatFx.push({ icon: machine.icon || '🛠️', text: '+1', x, y, createdAt: performance.now() });
  render();
  doAction({ action: 'buy_machine', machine_id: Number(machine.machine_id) }, null, { silent: true });
}

function buySpecialItemFromCanvas(code, item) {
  if (Number(state.user.coins) < Number(item.base_buy_price || 175)) return showStatus('Not enough coins.', true);
  const x = pointerCanvasPos?.x || canvasLogicalWidth() / 2, y = pointerCanvasPos?.y || canvasLogicalHeight() / 2;
  const price = Number(item.base_buy_price || 175);
  state.user.coins -= price;
  const existing = state.inventory.find(i => i.code === code);
  if (existing) existing.quantity = Number(existing.quantity || 0) + 1;
  else state.inventory.push({ code, name: item.name || 'Land Claim Note', item_type: 'material', icon: item.icon || '📜', quantity: 1, base_sell_price: 0, base_buy_price: price });
  canvasFloatFx.push({ icon: item.icon || '📜', text: '+1', x, y, createdAt: performance.now() });
  render();
  doAction({ action: 'buy_special_item', code }, null, { silent: true });
}

function buyToolFromCanvas(tool) {
  if (Number(state.user.coins) < Number(tool.upgrade_cost || 0)) return showStatus('Not enough coins.', true);
  const x = pointerCanvasPos?.x || canvasLogicalWidth() / 2, y = pointerCanvasPos?.y || canvasLogicalHeight() / 2;
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
    return `${o.player_order_id}:${o.order_status}:${o.is_late}:${items.map(i => `${i.item_id}:${i.owned_quantity}/${i.quantity_required}`).join(',')}`;
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
    const hasRequiredItems = items.length > 0 && items.every(item => Number(item.owned_quantity || 0) >= Number(item.quantity_required || 0));
    const canFulfill = confirmed && hasRequiredItems;
    const lines = items.map(item => {
      const have = Number(item.owned_quantity) >= Number(item.quantity_required);
      const qtyText = confirmed ? `${item.owned_quantity}/${item.quantity_required}` : `×${item.quantity_required}`;
      return `<div class="order-line ${have ? 'have-item' : 'need-item'}"><span>${renderIcon(item.icon, 'shop-icon')} ${escapeHtml(item.name)}</span><b>${qtyText}</b></div>`;
    }).join('');
    const acceptClass = slotsFull ? 'danger-button' : '';
    const acceptDisabled = slotsFull ? 'disabled' : '';
    const dueLine = confirmed
      ? `<p>⌛ Order due: <b class="order-modal-timer" data-order-timer="${escapeHtml(order.player_order_id)}">${formatSeconds(orderRemainingSeconds(order))}</b>${order.order_type === 'rush' ? ` <b class="rush-label">(${RUSH_ICON} Rush Order!)</b>` : ''}</p>`
      : `<p>⌛ Available for: <b class="order-modal-timer" data-order-timer="${escapeHtml(order.player_order_id)}">${formatSeconds(orderRemainingSeconds(order))}</b></p><p class="hint">Deadline: <b>${Number(order.fulfillment_minutes || 60)} minutes</b>${order.order_type === 'rush' ? ` <b class="rush-label">(${RUSH_ICON} Rush Order!)</b>` : ''}</p>`;
    const actionButtons = available
      ? `<button type="button" class="${acceptClass}" ${acceptDisabled} data-accept-order="${escapeHtml(order.player_order_id)}">${slotsFull ? 'Confirm unavailable — slots full' : 'Confirm Order'}</button>`
      : `<div class="order-actions"><button type="button" ${canFulfill ? '' : 'disabled'} data-fulfill-order="${escapeHtml(order.player_order_id)}">✅ Complete</button><button type="button" class="danger-button" data-cancel-order="${escapeHtml(order.player_order_id)}">Cancel (-${order.cancel_reputation_penalty || 1} ${plainIconFallback(reputationIcon(), '⭐')})</button></div>${canFulfill ? '' : '<p class="hint">You do not have enough items to complete this order yet.</p>'}`;
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
      const card = btn.closest('[data-order-card]');
      if (card) card.classList.add('is-leaving-left');
      setTimeout(() => {
        acceptOrderLocally(id);
        render();
        openOrdersModal(id);
      }, card ? 140 : 0);
      const data = await doAction({ action: 'accept_order', player_order_id: id }, null, { silent: true });
      if (!data?.ok) fetchState();
      else scheduleSync(900);
    });
  });

  box.querySelectorAll('[data-fulfill-order]').forEach(btn => {
    btn.addEventListener('click', async () => {
      if (btn.disabled) return;
      const id = Number(btn.dataset.fulfillOrder);
      const order = (state.orders || []).find(o => Number(o.player_order_id) === id);
      const items = state.order_items_by_order?.[String(id)] || state.order_items_by_order?.[Number(id)] || [];
      const canFulfill = items.length > 0 && items.every(item => Number(item.owned_quantity || 0) >= Number(item.quantity_required || 0));
      if (!canFulfill) return showStatus('You do not have everything for this order yet.', true);
      const data = await doAction({ action: 'fulfill_order', player_order_id: id }, null, { silent: true });
      if (data?.ok && order) addDomFloat('#coinCount', `+${moneyText(Number(data.payment ?? (Number(order.is_late || 0) ? (order.late_payment_coins || Math.floor(order.payment_coins * .8)) : order.payment_coins)))}`);
      closeOrdersModal();
      fetchState();
    });
  });

  box.querySelectorAll('[data-cancel-order]').forEach(btn => {
    btn.addEventListener('click', async () => {
      const id = Number(btn.dataset.cancelOrder);
      const ok = window.confirm('Cancel this accepted order? The townsfolk will notice, and you will lose reputation.');
      if (!ok) return;
      await doAction({ action: 'cancel_order', player_order_id: id }, null, { silent: true });
      addDomFloat('#reputationCount', `-1 ${plainIconFallback(reputationIcon(), '⭐')}`);
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
      const pos = relicPosition() || { x: canvasLogicalWidth() / 2, y: canvasLogicalHeight() / 2 };
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

function applyEventEffectsLocally(effects, x = canvasLogicalWidth() / 2, y = canvasLogicalHeight() / 2) {
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
      applyEventEffectsLocally(data.effects, canvasLogicalWidth() / 2, canvasLogicalHeight() / 2);
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


function gameClockSnapshot() {
  if (!localClockBase) return null;
  const elapsed = (performance.now() - localClockBase.receivedAt) / (localClockBase.dayLength * 1000);
  const totalDayFloat = (localClockBase.absoluteDay - 1) + localClockBase.progress + elapsed;
  const absoluteDay = Math.floor(totalDayFloat) + 1;
  const yearLength = Math.max(30, Number(localClockBase.yearLength || 300));
  const year = Math.floor((absoluteDay - 1) / yearLength) + 1;
  const day = ((absoluteDay - 1) % yearLength) + 1;
  const progress = totalDayFloat - Math.floor(totalDayFloat);
  const halfHour = Math.floor(progress * 48) / 48;
  const minutes = Math.floor(halfHour * 1440);
  const hour = Math.floor(minutes / 60), minute = minutes % 60;
  const weekIndex = (absoluteDay - 1) % 7;
  return { absoluteDay, year, day, progress, hour, minute, weekIndex };
}

const HUMAN_WEEK_DAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const FAE_WEEK_DAYS = ['Day of Mists', 'Day of Roots', 'Day of Bells', 'Day of Lanterns', 'Day of Stones', 'Day of Moonwater', 'Day of Leaves'];

function calendarLabelForIndex(index) {
  const human = HUMAN_WEEK_DAYS[index] || 'Day';
  if (!hasUnlockKey('location_market')) return human;
  return `${human} · ${FAE_WEEK_DAYS[index] || 'Fae Day'}`;
}

function bindCalendarClick() {
  const label = $('#dayLabel');
  const orb = $('#dayOrb');
  [label, orb].forEach(el => {
    if (!el || el.dataset.calendarBound === '1') return;
    el.dataset.calendarBound = '1';
    el.classList.add('calendar-click-target');
    el.addEventListener('click', openCalendarModal);
  });
}

function openCalendarModal() {
  const snap = gameClockSnapshot();
  if (!snap) return;
  const existing = $('#calendarModal');
  const modal = existing || document.createElement('div');
  if (!existing) {
    modal.id = 'calendarModal';
    modal.className = 'modal closeableModal';
    modal.innerHTML = `<div class="modal-content modal-content--calendar"><div class="modal-header"><h2>${renderIcon(calendarIcon(), 'modal-title-icon')} Calendar</h2><button type="button" class="modal-close" data-close-modal>×</button></div><div id="calendarContent"></div></div>`;
    document.body.appendChild(modal);
    modal.querySelector('[data-close-modal]')?.addEventListener('click', () => closeCalendarModal());
    modal.addEventListener('click', (event) => { if (event.target === modal) closeCalendarModal(); });
  }
  const content = modal.querySelector('#calendarContent');
  const unlocked = hasUnlockKey('location_market');
  content.innerHTML = `
    <p class="hint">Year ${snap.year}, Day ${snap.day} · ${calendarLabelForIndex(snap.weekIndex)} · ${String(snap.hour).padStart(2, '0')}:${String(snap.minute).padStart(2, '0')}</p>
    <div class="calendar-grid ${unlocked ? 'has-fae-days' : ''}">
      ${HUMAN_WEEK_DAYS.map((day, index) => `
        <div class="calendar-day ${index === snap.weekIndex ? 'is-today' : ''}">
          <b>${escapeHtml(day)}</b>
          ${unlocked ? `<span>${escapeHtml(FAE_WEEK_DAYS[index])}</span>` : ''}
        </div>
      `).join('')}
    </div>
    ${unlocked ? '<p class="hint">The Fae Market runs from Saturday at 6:00 through Sunday at 18:00.</p>' : '<p class="hint">More calendar notes may appear as the town trusts you with stranger schedules.</p>'}
  `;
  modal.classList.add('is-open');
  document.body.classList.add('modal-open');
}


function closeCalendarModal() {
  const modal = $('#calendarModal');
  if (modal) modal.classList.remove('is-open');
  if (!$('#ordersModal')?.classList.contains('is-open') && !$('#storyModal')?.classList.contains('is-open')) {
    document.body.classList.remove('modal-open');
  }
  hideTooltip();
}

function renderClock() {
  const snap = gameClockSnapshot();
  if (!snap) return;
  const dayLabel = $('#dayLabel');
  if (dayLabel) {
    dayLabel.innerHTML = `${renderIcon(calendarIcon(), 'calendar-label-icon')} Year ${escapeHtml(snap.year)}, Day ${escapeHtml(snap.day)} · ${escapeHtml(calendarLabelForIndex(snap.weekIndex))} · ${escapeHtml(String(snap.hour).padStart(2, '0'))}:${escapeHtml(String(snap.minute).padStart(2, '0'))}`;
  }
  const orb = $('#dayOrb');
  if (orb) {
    orb.style.left = `${snap.progress * 100}%`;
    setIconHtml(orb, (snap.hour >= 6 && snap.hour < 18) ? localClockBase.sunIcon : localClockBase.moonIcon, 'day-orb-icon');
    bindTooltip(orb, '<b>Calendar</b><br><span class="muted-line">Open the week calendar.</span>');
  }
  const label = $('#dayLabel');
  if (label) bindTooltip(label, '<b>Calendar</b><br><span class="muted-line">Open the week calendar.</span>');
  bindCalendarClick();
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
  harvest.innerHTML = renderIcon(harvestToolIcon(), 'big-icon');
  bindTooltip(harvest, '<b>Harvest</b><br><span class="muted-line">Harvest ready crops</span>');
  harvest.addEventListener('click', () => { selectedMode = { type: 'harvest', value: 'harvest' }; render(); });
  grid.appendChild(harvest);

  const info = document.createElement('button');
  info.type = 'button';
  info.className = `icon-card ${selectedMode.type === 'info' ? 'selected' : ''}`;
  info.innerHTML = renderIcon(inspectToolIcon(), 'big-icon');
  bindTooltip(info, '<b>Inspect</b><br><span class="muted-line">Show crop and plot details.</span>');
  info.addEventListener('click', () => { selectedMode = { type: 'info', value: 'info' }; render(); });
  grid.appendChild(info);
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
    const allowed = plant.allowed_garden_type_names || plant.allowed_garden_types_label || plant.allowed_garden_type_code || '';
    bindTooltip(btn, `<b>${escapeHtml(plant.name)}</b><br><span class="muted-line">${plant.width}×${plant.height}</span><br><span class="muted-line">Owned ×${owned}</span>${allowed ? `<br><span class="muted-line">Garden: ${escapeHtml(allowed)}</span>` : ''}`);
    btn.addEventListener('click', () => { selectedMode = { type: 'seed', value: Number(plant.plant_id) }; render(); });
    grid.appendChild(btn);
  }
}

function addInventoryLocal(itemId, quantity, itemData = {}) {
  const id = Number(itemId || itemData.item_id || 0);
  const qty = Number(quantity || 1);
  if (!id || qty <= 0) return;
  const existing = (state.inventory || []).find(i => Number(i.item_id) === id);
  if (existing) existing.quantity = Number(existing.quantity || 0) + qty;
  else {
    if (!Array.isArray(state.inventory)) state.inventory = [];
    state.inventory.push({
      item_id: id,
      code: itemData.code || '',
      name: itemData.name || 'Item',
      item_type: itemData.item_type || 'seed',
      icon: itemData.icon || '🌱',
      quantity: qty,
      base_sell_price: Number(itemData.base_sell_price || 0),
      base_buy_price: Number(itemData.base_buy_price || 0),
      shop_row_icon: itemData.shop_row_icon || ''
    });
  }
}

function buyMarketItemLocal(item, fxX = canvasLogicalWidth() / 2, fxY = canvasLogicalHeight() / 2) {
  const qty = Number(item.bundle_quantity || 1);
  const price = Number(item.market_price || item.base_buy_price || 0);
  if (!state?.market_status?.is_open) return showStatus('The Fae Market is not open right now.', true);
  if (Number(state.user.coins || 0) < price) return showStatus('Not enough coins.', true);
  state.user.coins = Number(state.user.coins || 0) - price;
  addInventoryLocal(item.item_id, qty, item);
  canvasFloatFx.push({ icon: item.icon || '✨', text: `+${qty}`, x: fxX, y: fxY, createdAt: performance.now() });
  addDomFloat('#coinCount', `-${moneyText(price)}`);
  render();
  doAction({ action: 'market_buy_item', market_inventory_id: Number(item.market_inventory_id || item.fae_market_inventory_id || 0) }, null, { silent: true });
}

function renderMarket() {
  const sells = $('#marketSellList') || $('#sellList');
  if (!sells || currentTabName() !== 'market') return;
  sells.innerHTML = '';
  const isOpen = !!state?.market_status?.is_open;
  if (!isOpen) { sells.innerHTML = '<p class="hint">The Fae Market is not open right now.</p>'; return; }
  const sellable = (state.inventory || []).filter(item => !['system','helper_equipment','relic'].includes(item.item_type) && Number(item.base_sell_price) > 0 && Number(item.quantity) > 0);
  const title = document.createElement('h4');
  title.textContent = 'Sell to the Market';
  sells.appendChild(title);
  if (!sellable.length) { sells.insertAdjacentHTML('beforeend', '<p class="hint">Nothing sellable in your backpack right now.</p>'); return; }
  for (const item of sellable) {
    const row = document.createElement('div');
    row.className = 'shop-row';
    row.innerHTML = `<div class="shop-main"><span class="sell-icon-wrap">${renderIcon(item.icon, 'shop-icon')}<b class="sell-qty-badge">×${item.quantity}</b></span><span>${escapeHtml(item.name)}</span></div><button type="button">Sell ${moneyHtml(item.base_sell_price)}</button>`;
    row.querySelector('button').addEventListener('click', () => {
      if (Number(item.quantity) <= 0) return;
      item.quantity = Number(item.quantity) - 1;
      state.user.coins = Number(state.user.coins) + Number(item.base_sell_price);
      state.inventory = state.inventory.filter(i => Number(i.quantity) > 0);
      addDomFloat('#coinCount', `+${moneyText(item.base_sell_price)}`);
      render();
      doAction({ action: 'sell_item', item_id: Number(item.item_id), quantity: 1, sale_context: 'market' }, null, { silent: true });
    });
    sells.appendChild(row);
  }
}

function renderShop() {
  if (currentTabName() !== 'shop') return;
  const sells = $('#sellList');
  if (!sells) return;
  sells.innerHTML = '';
  const limitByItem = {};
  for (const row of state.shop_sell_limits || []) limitByItem[String(row.item_id)] = row;
  const sellable = (state.inventory || []).filter(item => Number(item.base_sell_price) > 0 && limitByItem[String(item.item_id)] && Number(limitByItem[String(item.item_id)].remaining_quantity || 0) > 0);
  if (!sellable.length) {
    sells.innerHTML = '<p class="hint">The shop is not buying anything else from your backpack today.</p>';
    return;
  }
  for (const item of sellable) {
    const row = document.createElement('div');
    row.className = 'shop-row shop-row--sell has-row-bg';
    const limit = limitByItem[String(item.item_id)] || {};
    const rowIcon = limit.shop_row_icon || item.shop_row_icon || item.icon || '';
    if (rowIcon) row.style.setProperty('--shop-row-bg', cssImageUrl(rowIcon));
    const remaining = Math.max(0, Number(limit.remaining_quantity || 0));
    row.innerHTML = `<div class="shop-main"><span class="sell-icon-wrap">${renderIcon(item.icon, 'shop-icon')}<b class="sell-qty-badge">×${item.quantity}</b></span><span>${escapeHtml(item.name)} <small class="muted-line">Limit: ${remaining}</small></span></div><button type="button">Sell ${moneyHtml(item.base_sell_price)}</button>`;
    row.querySelector('button').addEventListener('click', () => {
      if (Number(item.quantity) <= 0) return;
      item.quantity = Number(item.quantity) - 1;
      const localLimit = limitByItem[String(item.item_id)];
      if (localLimit) {
        localLimit.remaining_quantity = Math.max(0, Number(localLimit.remaining_quantity || 0) - 1);
        localLimit.quantity_sold = Number(localLimit.quantity_sold || 0) + 1;
      }
      state.user.coins = Number(state.user.coins) + Number(item.base_sell_price);
      state.inventory = state.inventory.filter(i => Number(i.quantity) > 0);
      addDomFloat('#coinCount', `+${moneyText(item.base_sell_price)}`);
      render();
      doAction({ action: 'sell_item', item_id: Number(item.item_id), quantity: 1, sale_context: 'shop' }, null, { silent: true });
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
    if (!state.machines?.length) machines.innerHTML = '<p class="hint">The shed echoes. Dramatically. Buy a preserves bin to unlock your workspace.</p>'; 
  }
  if (list) list.innerHTML = '<p class="hint">Processing canvas coming soon.</p>';
}

function renderLocations() {
  const list = $('#locationList');
  if (!list) return;
  const side = state?.map_config?.side_menu || '';
  const timers = getMapEventTimersHtml();
  list.innerHTML = `${side ? side : '<p class="hint">Use the map to travel around town. Locked places stay mysterious until the story reveals them.</p>'}${timers}`;
}

function secondsUntilGameHour(targetHour) {
  if (!localClockBase) return 0;
  const elapsed = (performance.now() - localClockBase.receivedAt) / (localClockBase.dayLength * 1000);
  const progress = ((localClockBase.progress + elapsed) % 1 + 1) % 1;
  const targetProgress = Math.max(0, Math.min(23.999, Number(targetHour))) / 24;
  let dayFraction = targetProgress - progress;
  if (dayFraction < 0) dayFraction += 1;
  return Math.round(dayFraction * Number(localClockBase.dayLength || 720));
}

function getLocalMarketStatus() {
  if (state?.market_status) return state.market_status;
  return { is_open: false, label: 'Fae Market opens', seconds_remaining: secondsUntilGameHour(6), phase: 'day' };
}

function getMapEventTimersHtml() {
  const shopSeconds = state?.shop_refresh?.seconds_remaining ?? secondsUntilGameHour(7);
  const marketUnlocked = hasUnlockKey('location_market');
  const marketStatus = getLocalMarketStatus();
  let html = '<div class="side-timer-list">';
  html += `<div class="side-timer-row"><span>Shop refresh</span><b data-map-timer="shop">${formatSeconds(Math.max(0, Number(shopSeconds || 0)))}</b></div>`;
  if (marketUnlocked) {
    html += `<div class="side-timer-row"><span>${escapeHtml(marketStatus.label || 'Market opens')}</span><b data-map-timer="market">${formatSeconds(Math.max(0, Number(marketStatus.seconds_remaining || 0)))}</b></div>`;
  }
  const caravanStatus = state?.caravan_status || null;
  if (caravanStatus) {
    html += `<div class="side-timer-row"><span>${escapeHtml(caravanStatus.label || 'Caravan arrives')}</span><b data-map-timer="caravan">${formatSeconds(Math.max(0, Number(caravanStatus.seconds_remaining || 0)))}</b></div>`;
  } else {
    html += '<div class="side-timer-row muted"><span>Caravan</span><b>Not scheduled</b></div>';
  }
  html += '</div>';
  return html;
}

function isMarketOpenNow() {
  return !!state?.market_status?.is_open;
}

function updateMapSideTimers() {
  if (currentTabName() !== 'map') return;
  const shop = document.querySelector('[data-map-timer="shop"]');
  if (shop) shop.textContent = formatSeconds(Math.max(0, Number(state?.shop_refresh?.seconds_remaining || secondsUntilGameHour(7))));
  const market = document.querySelector('[data-map-timer="market"]');
  if (market) market.textContent = formatSeconds(Math.max(0, Number(state?.market_status?.seconds_remaining || 0)));
  const caravan = document.querySelector('[data-map-timer="caravan"]');
  if (caravan) caravan.textContent = formatSeconds(Math.max(0, Number(state?.caravan_status?.seconds_remaining || 0)));
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


function acceptOrderLocally(orderId) {
  if (!state) return null;
  const idx = (state.available_orders || []).findIndex(o => Number(o.player_order_id) === Number(orderId));
  if (idx < 0) return null;
  const [order] = state.available_orders.splice(idx, 1);
  order.order_status = 'accepted';
  order.accepted_at = new Date().toISOString();
  order.time_remaining_seconds = Math.max(60, Number(order.fulfillment_minutes || 60) * 60);
  order.is_late = 0;
  state.orders = state.orders || [];
  state.orders.push(order);
  state.orders.sort((a, b) => Number(a.time_remaining_seconds || 0) - Number(b.time_remaining_seconds || 0));
  return order;
}

function orderBoardRequirementLines(order, confirmed) {
  const items = state?.order_items_by_order?.[String(order.player_order_id)] || state?.order_items_by_order?.[Number(order.player_order_id)] || [];
  if (!items.length) return '<div class="order-board-lines muted-line">Unknown goods</div>';
  return `<div class="order-board-lines">${items.map(item => {
    const have = Number(item.owned_quantity || 0) >= Number(item.quantity_required || 0);
    const qty = confirmed ? `${Number(item.owned_quantity || 0)}/${Number(item.quantity_required || 0)}` : `×${Number(item.quantity_required || 0)}`;
    return `<span class="order-board-item ${have ? 'have-item' : 'need-item'}">${renderIcon(item.icon, 'order-board-item-icon')} ${escapeHtml(item.name || 'Item')} <b>${qty}</b></span>`;
  }).join('')}</div>`;
}

function orderBoardCardHtml(order, confirmed) {
  const seconds = orderTimeRemaining(order);
  const isRush = order.order_type === 'rush';
  const isLate = confirmed && Number(order.is_late || 0) === 1;
  const timerLabel = confirmed ? (isLate ? 'Late' : `Due ${formatSeconds(seconds)}`) : formatSeconds(seconds);
  const title = `${escapeHtml(order.customer_name || 'Customer')}${isRush ? ' · Rush Order!' : ''}`;
  return `
    <button type="button" class="order-board-card ${isRush ? 'is-rush' : ''} ${isLate ? 'is-late' : ''}" data-open-order="${escapeHtml(order.player_order_id)}">
      <span class="order-board-card-top"><b>${title}</b><strong data-board-order-timer="${escapeHtml(order.player_order_id)}">${escapeHtml(timerLabel)}</strong></span>
      ${orderBoardRequirementLines(order, confirmed)}
      <span class="order-board-card-bottom" data-board-order-reward="${escapeHtml(order.player_order_id)}">${orderRewardText(order, confirmed)}</span>
    </button>
  `;
}

function bindOrderBoardClicks(root) {
  root?.querySelectorAll('[data-open-order]').forEach(btn => {
    if (btn.dataset.bound) return;
    btn.dataset.bound = '1';
    btn.addEventListener('click', () => openOrdersModal(btn.dataset.openOrder));
  });
}


function updateOrdersBoardTimers() {
  document.querySelectorAll('[data-board-order-timer]').forEach(el => {
    const all = [...(state?.orders || []), ...(state?.available_orders || [])];
    const order = all.find(o => String(o.player_order_id) === String(el.dataset.boardOrderTimer));
    if (!order) return;
    const confirmed = order.order_status === 'accepted';
    const isLate = confirmed && Number(order.is_late || 0) === 1;
    el.textContent = confirmed ? (isLate ? 'Late' : `Due ${formatSeconds(orderRemainingSeconds(order))}`) : formatSeconds(orderRemainingSeconds(order));
  });
  document.querySelectorAll('[data-board-order-reward]').forEach(el => {
    const all = [...(state?.orders || []), ...(state?.available_orders || [])];
    const order = all.find(o => String(o.player_order_id) === String(el.dataset.boardOrderReward));
    if (order) el.innerHTML = orderRewardText(order, order.order_status === 'accepted');
  });
}


function orderInventorySummaryHtml() {
  const items = (state?.inventory || []).filter(i => ['seed','produce'].includes(i.item_type));
  if (!items.length) return '<p class="hint">Backpack: no seeds or crops right now.</p>';
  const rows = items.map(item => `<div class="order-side-inv-row">${renderIcon(item.icon || '✦', 'side-inv-icon')}<span>${escapeHtml(item.name || item.code || 'Item')}</span><b>×${Number(item.quantity || 0)}</b></div>`).join('');
  return `<div class="order-side-inventory"><h4>Backpack Seeds & Crops</h4>${rows}</div>`;
}

function syncOrdersBoardBackground(surface) {
  if (!surface) return;
  const bg = locationBackgroundForNow('orders');
  const icon = ordersIcon();
  surface.style.setProperty('--orders-board-bg', bg && isImageIcon(bg) ? cssImageUrl(bg) : 'none');
  surface.style.setProperty('--orders-board-icon', 'none');
  const dom = surface.querySelector('.orders-board-dom');
  if (dom) {
    dom.classList.toggle('has-bg-image', !!bg && isImageIcon(bg));
  }
  const iconEl = surface.querySelector('.orders-board-dom-icon');
  if (iconEl) iconEl.innerHTML = renderIcon(icon, 'orders-board-title-icon');
}

function renderOrdersBoardList() {
  const sideList = $('#ordersBoardList');
  const surface = $('#ordersBoardSurface');
  syncOrdersBoardBackground(surface);
  const confirmedOrders = state?.orders || [];
  const availableOrders = state?.available_orders || [];
  const confirmed = confirmedOrders.length;
  const available = availableOrders.length;
  const limit = Number(state?.order_slot_limit || 2);
  const availableLimit = Number(state?.available_order_limit || 5);

  if (sideList) {
    const side = state?.map_config?.side_menus?.orders || state?.map_config?.side_menu_orders || ORDER_SIDEBAR_COPY;
    sideList.innerHTML = `${side}<p class="hint"><b>Confirmed:</b> ${confirmed}/${limit} · <b>Available:</b> ${available}/${availableLimit}</p>${orderInventorySummaryHtml()}`;
  }

  if (!surface) return;
  const boardKey = [...confirmedOrders, ...availableOrders].map(order => {
    const items = state?.order_items_by_order?.[String(order.player_order_id)] || state?.order_items_by_order?.[Number(order.player_order_id)] || [];
    return `${order.player_order_id}:${order.order_status}:${order.is_late}:${items.map(i => `${i.item_id}:${i.owned_quantity}/${i.quantity_required}`).join(',')}`;
  }).join('|') + `:${confirmed}/${limit}:${available}/${availableLimit}`;
  if (surface.dataset.orderBoardKey === boardKey) {
    updateOrdersBoardTimers();
    return;
  }
  surface.dataset.orderBoardKey = boardKey;

  const confirmedHtml = confirmedOrders.length
    ? confirmedOrders.map(order => orderBoardCardHtml(order, true)).join('')
    : '<p class="hint order-board-empty">No confirmed orders. Accept one below when you are ready to commit.</p>';
  const availableHtml = availableOrders.length
    ? availableOrders.map(order => orderBoardCardHtml(order, false)).join('')
    : '<p class="hint order-board-empty">No available orders right now. New requests arrive about once a minute while the board has room.</p>';

  surface.innerHTML = `
    <div class="orders-board-dom">
      <div class="orders-board-dom-header">
        <div class="orders-board-dom-icon">${renderIcon(ordersIcon(), 'orders-board-title-icon')}</div>
        <h2>Orders Board</h2>
        <p>Confirmed Orders: ${confirmed}/${limit} · Available Orders: ${available}/${availableLimit}</p>
      </div>
      <section class="orders-board-section">
        <h3>Confirmed Orders</h3>
        <div class="orders-board-card-list">${confirmedHtml}</div>
      </section>
      <section class="orders-board-section">
        <h3>Available Orders</h3>
        <div class="orders-board-card-list">${availableHtml}</div>
      </section>
    </div>
  `;
  syncOrdersBoardBackground(surface);
  bindOrderBoardClicks(surface);
}

function helperSummonInventoryItems() {
  return (state?.inventory || []).filter(item => Number(item.quantity || 0) > 0 && ['fairy_bell'].includes(item.code));
}

function renderWorkers() {
  const hint = $('#helperUnlockHint'), workerList = $('#workerList'), plantOrderList = $('#plantOrderList');
  if (hint) hint.textContent = hasUnlockKey('helpers_unlocked')
    ? 'Helper accessories are assigned directly on the Forest Folk canvas.'
    : 'Find a relic, meet Madam Rune, and ring the first fairy bell.';
  if (workerList) {
    const summons = helperSummonInventoryItems();
    workerList.innerHTML = '';
    if (!summons.length) {
      workerList.innerHTML = '<p class="hint">No helper summon items are waiting in your pack.</p>';
    } else {
      for (const item of summons) {
        const row = document.createElement('div');
        row.className = 'shop-row helper-row forest-helper-row';
        row.innerHTML = `<div class="helper-main"><span class="helper-icon">${renderIcon(item.icon || '🔔', 'helper-inline-icon')}</span><span><b>${escapeHtml(item.name || 'Summon Item')}</b><br><small class="muted-line">Quantity ${escapeHtml(item.quantity || 1)}</small></span></div><button type="button" data-use-helper-summon="${escapeHtml(item.code)}">Use</button>`;
        workerList.appendChild(row);
      }
      workerList.querySelectorAll('[data-use-helper-summon]').forEach(btn => {
        if (btn.dataset.bound) return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', () => {
          if (btn.dataset.useHelperSummon === 'fairy_bell') openFairyBellModal();
        });
      });
    }
  }
  if (plantOrderList) plantOrderList.innerHTML = '<p class="hint">Your helpers and accessories now live on the Forest Folk canvas. Drag an owned accessory onto a helper slot to change their job.</p>';
}


function renderAdmin() {
  const tab = $('#adminTabButton');
  if (tab) tab.classList.toggle('hidden', !state?.is_admin);
  const add = $('#adminAddCoinsBtn');
  if (add) {
    const icon = add.querySelector('.admin-coin-icon');
    if (icon) icon.innerHTML = renderIcon(coinIcon(), 'button-inline-icon');
  }
  if (add && !add.dataset.bound) {
    add.dataset.bound = '1';
    add.addEventListener('click', () => {
      state.user.coins = Number(state.user.coins) + 1000;
      addDomFloat('#coinCount', `+${moneyText(1000)}`);
      render();
      doAction({ action: 'admin_add_coins' }, null, { silent: true });
    });
  }
}

function render() {
  if (!state) return;
  setTextIfExists('#coinCount', state.user.coins);
  setIconHtml($('#coinIcon'), coinIcon(), 'header-stat-icon');
  const coinPill = document.querySelector('.panel-coin-pill');
  if (coinPill) coinPill.dataset.tooltipHtml = COIN_TOOLTIP;
  const invBtn = $('#inventoryBtn');
  if (invBtn) { invBtn.innerHTML = `${renderIcon(backpackIcon(), 'button-inline-icon')} Backpack`; invBtn.dataset.tooltipHtml = BACKPACK_TOOLTIP; }
  const backBtn = $('#backToMapBtn');
  if (backBtn) { backBtn.innerHTML = `${renderIcon(mapIcon(), 'button-inline-icon')} Map`; backBtn.dataset.tooltipHtml = MAP_TOOLTIP; }
  const sideBackBtn = document.querySelector('[data-side-map-button]');
  if (sideBackBtn) { sideBackBtn.innerHTML = `${renderIcon(mapIcon(), 'button-inline-icon')} Map`; sideBackBtn.dataset.tooltipHtml = MAP_TOOLTIP; }
  const ordersBtn = $('#ordersBtn');
  if (ordersBtn) {
    const currentText = $('#ordersTimer')?.textContent || '0/0';
    const badgeVisible = $('#ordersBadge')?.classList.contains('visible');
    ordersBtn.innerHTML = `${renderIcon(ordersIcon(), 'button-inline-icon')} Orders: <span id="ordersTimer">${escapeHtml(currentText)}</span><b id="ordersBadge" class="order-badge ${badgeVisible ? 'visible' : ''}">!</b>`;
  }
  setTextIfExists('#reputationCount', state.progress?.reputation ?? state.user?.reputation ?? 0);
  setIconHtml($('#reputationIcon'), reputationIcon(), 'header-stat-icon');
  setTextIfExists('#recognitionCount', state.progress?.recognition ?? state.user?.recognition ?? 0);
  setIconHtml($('#recognitionIcon'), recognitionIcon(), 'header-stat-icon');
  setTextIfExists('#gardenName', `${state.garden.name} — ${state.garden.garden_type_name}`);
  if (!$('#versionPill')?.dataset.loadedVersion) { const vp = $('#versionPill'); if (vp) { vp.dataset.loadedVersion = loadedAppVersion || state.version || 'v0.4.16'; vp.textContent = vp.dataset.loadedVersion; } }
  setTextIfExists('#mapPanelTitle', state.map_config?.title || 'Town');
  renderClock();
  renderOrdersButton();
  renderLocations();
  renderOrdersBoardList();
  renderTools();
  renderSeeds();
  renderShop();
  renderMarket();
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
    closeCalendarModal();
    return;
  }
  if (evt.target?.id === 'ordersModal') closeOrdersModal();
  if (evt.target?.id === 'calendarModal') closeCalendarModal();
});
document.addEventListener('keydown', evt => {
  if (evt.key === 'Escape') { closeOrdersModal(); closeCalendarModal(); }
});


function handleHelperAccessoryMouseDown(evt) {
  if (isModalOpen() || currentTabName() !== 'helpers') return;
  setPointerFromEvent(evt);
  const hit = canvasSceneHits.find(h => h.dragAccessory && pointerCanvasPos.x >= h.x && pointerCanvasPos.x <= h.x + h.w && pointerCanvasPos.y >= h.y && pointerCanvasPos.y <= h.y + h.h);
  if (!hit) return;
  helperAccessoryDrag = { ...hit.dragAccessory };
  evt.preventDefault();
  hideTooltip();
}

function handleHelperAccessoryMouseUp(evt) {
  if (!helperAccessoryDrag) return;
  setPointerFromEvent(evt);
  const hit = canvasSceneHits.find(h => h.equipHelperId && pointerCanvasPos.x >= h.x && pointerCanvasPos.x <= h.x + h.w && pointerCanvasPos.y >= h.y && pointerCanvasPos.y <= h.y + h.h);
  const drag = helperAccessoryDrag;
  helperAccessoryDrag = null;
  if (hit?.equipHelperId) equipHelperAccessoryLocal(hit.equipHelperId, drag.equipmentId);
  else render();
}


function handleCanvasWheel(evt) {
  if (isModalOpen() || currentTabName() !== 'helpers') return;
  const helpers = state?.helpers || [];
  const listH = canvasLogicalHeight() - 300;
  const cardH = 112, cardGap = 12, cols = 2;
  const rows = Math.ceil(helpers.length / cols);
  const contentH = rows * (cardH + cardGap) - cardGap;
  const maxScroll = Math.max(0, contentH - (listH - 28));
  if (maxScroll <= 0) return;
  helperCanvasScroll = Math.max(0, Math.min(maxScroll, helperCanvasScroll + evt.deltaY));
  evt.preventDefault();
}

document.addEventListener('DOMContentLoaded', () => {
  canvas = $('#gardenCanvas');
  ctx = canvas.getContext('2d');
  updateScreenSurfaceVisibility();
  snapCanvasCssSize();
  window.addEventListener('resize', snapCanvasCssSize);

  canvas.addEventListener('mousemove', handleCanvasMove);
  canvas.addEventListener('mouseleave', handleCanvasLeave);
  canvas.addEventListener('click', handleCanvasClick);
  canvas.addEventListener('wheel', handleCanvasWheel, { passive: false });
  canvas.addEventListener('mousedown', handleShedMouseDown);
  canvas.addEventListener('mousedown', handleHelperAccessoryMouseDown);
  canvas.addEventListener('mousedown', startRepeat);
  window.addEventListener('mouseup', stopRepeat);
  window.addEventListener('mouseup', handleShedMouseUp);
  window.addEventListener('mouseup', handleHelperAccessoryMouseUp);

  $('#ordersBtn')?.addEventListener('click', () => switchScreen('orders'));
  $('#inventoryBtn')?.addEventListener('click', () => switchScreen('inventory'));

  setupTabs();
  window.addEventListener('pointerdown', unlockAudio, { once: true });
  window.addEventListener('keydown', unlockAudio, { once: true });
  window.addEventListener('keydown', evt => {
    if (evt.key === 'Control') {
      isCtrlDown = true;
      updateCanvasCursor();
    }
  });
  window.addEventListener('keyup', evt => {
    if (evt.key === 'Control') {
      isCtrlDown = false;
      updateCanvasCursor();
      hideTooltip();
    }
  });
  window.addEventListener('blur', () => {
    isCtrlDown = false;
    updateCanvasCursor();
    hideTooltip();
  });
  setSaveStatus('saved', 'Everything is synced.');
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
      if (state.shop_refresh?.seconds_remaining !== undefined) state.shop_refresh.seconds_remaining = Math.max(0, Number(state.shop_refresh.seconds_remaining) - 1);
      renderOrdersButton();
      updateMapSideTimers();
      if ($('#ordersModal')?.classList.contains('is-open')) updateOrderModalTimer();
      if (currentTabName() === 'orders') updateOrdersBoardTimers();
    }
  }, 1000);
  setInterval(runClientHelperAutomation, 1000);
  setInterval(() => { if (savePendingCount === 0) fetchState(); }, 15000);
});
