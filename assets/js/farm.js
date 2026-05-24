console.info('Fantasy Farmer JS loaded: v0.2.1');
let state = null;
let selectedMode = { type: 'tool', value: 'hoe' };
let canvas = null;
let ctx = null;
let tileRects = [];
let lastFx = [];

const $ = (selector) => document.querySelector(selector);

function escapeHtml(value) {
  return String(value).replace(/[&<>'"]/g, char => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    "'": '&#039;',
    '"': '&quot;'
  }[char]));
}

function iconText(value) {
  return value || '❔';
}

function renderIcon(value, className = 'icon') {
  if (!value) return '';

  if (!value.includes('.')) {
    return `<span class="${className} emoji-icon">${escapeHtml(value)}</span>`;
  }

  return `<img class="${className}" src="${escapeHtml(value)}" alt="">`;
}

function showStatus(message, isError = false) {
  const box = $('#statusMessage');
  box.textContent = message;
  box.className = `status-message visible ${isError ? 'error' : ''}`;

  setTimeout(() => {
    box.className = 'status-message';
  }, 2600);
}

async function fetchState() {
  const res = await fetch('api/state.php');

  if (res.status === 401) {
    window.location.href = 'login.php';
    return;
  }

  let data = null;

  try {
    data = await res.json();
  } catch (_) {
    showStatus('Could not load farm state. Check server logs.', true);
    return;
  }

  if (!data.ok) {
    showStatus(data.error || 'Could not load farm.', true);
    return;
  }

  state = data;
  render();
}

async function doAction(payload, fx = null) {
  const res = await fetch('api/action.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload)
  });

  if (res.status === 401) {
    window.location.href = 'login.php';
    return;
  }

  let data = null;

  try {
    data = await res.json();
  } catch (_) {
    showStatus('Action failed. Check server logs.', true);
    return;
  }

  if (!data.ok) {
    showStatus(data.error || 'Action failed.', true);
    return;
  }

  if (fx) {
    lastFx.push({ ...fx, createdAt: performance.now() });
  }

  showStatus(data.message || 'Done.');
  await fetchState();
}

function cropAt(x, y) {
  if (!state) return null;

  for (const crop of state.crops) {
    const ox = Number(crop.origin_x);
    const oy = Number(crop.origin_y);
    const width = Number(crop.width);
    const height = Number(crop.height);

    if (x >= ox && x < ox + width && y >= oy && y < oy + height) {
      return crop;
    }
  }

  return null;
}

function originCropAt(x, y) {
  const crop = cropAt(x, y);
  if (!crop) return null;

  return Number(crop.origin_x) === x && Number(crop.origin_y) === y ? crop : null;
}

function iconForCrop(crop) {
  let icons = [];

  try {
    icons = crop.stage_icons_json ? JSON.parse(crop.stage_icons_json) : [];
  } catch (_) {
    icons = [];
  }

  const step = Number(crop.growth_step_current);
  const max = Number(crop.growth_steps);

  if (step >= max) {
    return crop.mature_icon || icons[icons.length - 1] || '🌾';
  }

  return icons[step] || '🌱';
}

function getPlot(x, y) {
  return state.plots.find(p => Number(p.x_pos) === x && Number(p.y_pos) === y) || null;
}

function handleCanvasClick(evt) {
  if (!state) return;

  const rect = canvas.getBoundingClientRect();
  const sx = canvas.width / rect.width;
  const sy = canvas.height / rect.height;
  const mx = (evt.clientX - rect.left) * sx;
  const my = (evt.clientY - rect.top) * sy;

  const hit = tileRects.find(t => mx >= t.x && mx <= t.x + t.size && my >= t.y && my <= t.y + t.size);
  if (!hit) return;

  const plot = getPlot(hit.gridX, hit.gridY);
  if (!plot || !Number(plot.is_unlocked)) {
    showStatus('Locked plot.', true);
    return;
  }

  const crop = cropAt(hit.gridX, hit.gridY);

  if (selectedMode.type === 'tool' && selectedMode.value === 'hoe') {
    return doAction({ action: 'till', plot_id: Number(plot.plot_id) }, { kind: 'till', x: hit.cx, y: hit.cy });
  }

  if (selectedMode.type === 'tool' && selectedMode.value === 'watering_can') {
    if (!crop) {
      showStatus('Nothing to water.', true);
      return;
    }

    return doAction({ action: 'water', planted_crop_id: Number(crop.planted_crop_id) }, { kind: 'water', x: hit.cx, y: hit.cy });
  }

  if (selectedMode.type === 'tool' && selectedMode.value === 'shovel') {
    showStatus('Shovel does nothing yet. It remains emotionally bent.', true);
    return;
  }

  if (selectedMode.type === 'seed') {
    if (crop) {
      showStatus('Something is already growing there.', true);
      return;
    }

    return doAction({
      action: 'plant',
      garden_id: Number(state.garden.garden_id),
      plant_id: Number(selectedMode.value),
      x: hit.gridX,
      y: hit.gridY
    }, { kind: 'plant', x: hit.cx, y: hit.cy });
  }

  if (selectedMode.type === 'harvest') {
    if (!crop) {
      showStatus('Nothing to harvest.', true);
      return;
    }

    return doAction({ action: 'harvest', planted_crop_id: Number(crop.planted_crop_id) }, { kind: 'harvest', x: hit.cx, y: hit.cy });
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

function drawRoundedRect(x, y, w, h, r) {
  roundedPath(x, y, w, h, r);
  ctx.fill();
  ctx.stroke();
}

function drawSoilTile(plot, rect) {
  const unlocked = Number(plot.is_unlocked);
  const tilled = Number(plot.is_tilled);

  ctx.save();

  if (!unlocked) {
    ctx.fillStyle = '#17140f';
    ctx.strokeStyle = 'rgba(255,255,255,.08)';
    ctx.lineWidth = 2;
    drawRoundedRect(rect.x, rect.y, rect.size, rect.size, 16);

    ctx.font = `${Math.floor(rect.size * .18)}px system-ui`;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.globalAlpha = .48;
    ctx.fillStyle = '#d6ba79';
    ctx.fillText('🔒', rect.cx, rect.cy);
    ctx.restore();
    return;
  }

  ctx.fillStyle = tilled ? '#7a5231' : '#684225';
  ctx.strokeStyle = tilled ? 'rgba(255,221,166,.22)' : 'rgba(255,255,255,.12)';
  ctx.lineWidth = 2;
  drawRoundedRect(rect.x, rect.y, rect.size, rect.size, 16);

  ctx.globalAlpha = .22;
  ctx.strokeStyle = tilled ? '#d9b071' : '#2c1c11';
  ctx.lineWidth = 2;

  const scratches = tilled ? 7 : 4;
  for (let i = 0; i < scratches; i++) {
    const yy = rect.y + 16 + i * (rect.size - 32) / scratches;
    ctx.beginPath();
    ctx.moveTo(rect.x + 14, yy);
    ctx.quadraticCurveTo(rect.cx, yy + (i % 2 ? 8 : -8), rect.x + rect.size - 14, yy + 3);
    ctx.stroke();
  }

  ctx.globalAlpha = .16;
  ctx.fillStyle = '#120b07';
  for (let i = 0; i < 10; i++) {
    const px = rect.x + 12 + ((i * 29) % (rect.size - 24));
    const py = rect.y + 12 + ((i * 41) % (rect.size - 24));
    ctx.beginPath();
    ctx.arc(px, py, 2 + (i % 3), 0, Math.PI * 2);
    ctx.fill();
  }

  ctx.restore();
}

function drawCrop(crop, rect) {
  const icon = iconForCrop(crop);
  const ready = Number(crop.growth_step_current) >= Number(crop.growth_steps);
  const water = Number(crop.water_current);
  const waterMax = Number(crop.water_max || 100);
  const waterRatio = Math.max(0, Math.min(1, water / waterMax));

  ctx.save();

  ctx.font = `${Math.floor(rect.size * .42)}px "Segoe UI Emoji", "Apple Color Emoji", system-ui`;
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.fillText(icon, rect.cx, rect.cy - 3);

  if (waterRatio > 0) {
    ctx.globalAlpha = .85;
    ctx.fillStyle = '#6eb5d9';
    roundedPath(rect.x + 12, rect.y + rect.size - 14, (rect.size - 24) * waterRatio, 5, 5);
    ctx.fill();
  }

  if (ready) {
    ctx.globalAlpha = .9;
    ctx.fillStyle = '#ffe6a0';
    ctx.beginPath();
    ctx.arc(rect.x + rect.size - 14, rect.y + 14, 7, 0, Math.PI * 2);
    ctx.fill();
  }

  ctx.restore();
}

function drawFx() {
  const now = performance.now();
  lastFx = lastFx.filter(fx => now - fx.createdAt < 700);

  for (const fx of lastFx) {
    const age = now - fx.createdAt;
    const t = age / 700;

    ctx.save();
    ctx.globalAlpha = 1 - t;
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';

    if (fx.kind === 'water') {
      ctx.font = '30px system-ui';
      ctx.fillText('💧', fx.x, fx.y - t * 34);
    } else if (fx.kind === 'till') {
      ctx.font = '28px system-ui';
      ctx.fillText('💢', fx.x, fx.y - t * 24);
    } else if (fx.kind === 'plant') {
      ctx.font = '28px system-ui';
      ctx.fillText('✨', fx.x, fx.y - t * 28);
    } else if (fx.kind === 'harvest') {
      ctx.font = '30px system-ui';
      ctx.fillText('✦', fx.x, fx.y - t * 32);
    }

    ctx.restore();
  }
}

function drawGarden() {
  requestAnimationFrame(drawGarden);

  if (!canvas || !ctx) return;

  ctx.clearRect(0, 0, canvas.width, canvas.height);

  if (!state) {
    ctx.save();
    ctx.fillStyle = '#211a13';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = '#b8a88f';
    ctx.font = '24px system-ui';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText('Loading field...', canvas.width / 2, canvas.height / 2);
    ctx.restore();
    return;
  }
  tileRects = [];

  const padding = 24;
  const gap = 12;
  const cols = Number(state.garden.max_width || 5);
  const rows = Number(state.garden.max_height || 5);
  const usable = Math.min(canvas.width, canvas.height) - padding * 2;
  const size = Math.floor((usable - gap * (cols - 1)) / cols);
  const startX = Math.floor((canvas.width - (size * cols + gap * (cols - 1))) / 2);
  const startY = Math.floor((canvas.height - (size * rows + gap * (rows - 1))) / 2);

  ctx.save();
  ctx.fillStyle = '#211a13';
  ctx.strokeStyle = 'rgba(255,255,255,.08)';
  ctx.lineWidth = 2;
  roundedPath(8, 8, canvas.width - 16, canvas.height - 16, 24);
  ctx.fill();
  ctx.stroke();
  ctx.restore();

  for (const plot of state.plots) {
    const gridX = Number(plot.x_pos);
    const gridY = Number(plot.y_pos);
    const x = startX + (gridX - 1) * (size + gap);
    const y = startY + (gridY - 1) * (size + gap);

    const rect = {
      x,
      y,
      size,
      gridX,
      gridY,
      cx: x + size / 2,
      cy: y + size / 2
    };

    tileRects.push(rect);
    drawSoilTile(plot, rect);
  }

  for (const crop of state.crops) {
    const gridX = Number(crop.origin_x);
    const gridY = Number(crop.origin_y);
    const width = Number(crop.width);
    const height = Number(crop.height);

    const x = startX + (gridX - 1) * (size + gap);
    const y = startY + (gridY - 1) * (size + gap);
    const w = size * width + gap * (width - 1);
    const h = size * height + gap * (height - 1);

    drawCrop(crop, {
      x,
      y,
      size: Math.min(w, h),
      cx: x + w / 2,
      cy: y + h / 2
    });
  }

  drawFx();
}

function countInventoryByItemId(itemId) {
  const row = state.inventory.find(i => Number(i.item_id) === Number(itemId));
  return row ? Number(row.quantity) : 0;
}

function renderTools() {
  const grid = $('#toolGrid');
  grid.innerHTML = '';

  for (const tool of state.tools) {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `icon-card ${selectedMode.type === 'tool' && selectedMode.value === tool.tool_type ? 'selected' : ''}`;
    btn.innerHTML = `
      ${renderIcon(tool.icon, 'big-icon')}
      <span>${escapeHtml(tool.name)}</span>
    `;

    btn.addEventListener('click', () => {
      selectedMode = { type: 'tool', value: tool.tool_type };
      render();
    });

    grid.appendChild(btn);
  }

  const harvest = document.createElement('button');
  harvest.type = 'button';
  harvest.className = `icon-card ${selectedMode.type === 'harvest' ? 'selected' : ''}`;
  harvest.innerHTML = `${renderIcon('🧺', 'big-icon')}<span>Harvest</span>`;
  harvest.addEventListener('click', () => {
    selectedMode = { type: 'harvest', value: 'harvest' };
    render();
  });
  grid.appendChild(harvest);
}

function renderSeeds() {
  const grid = $('#seedGrid');
  grid.innerHTML = '';

  for (const plant of state.plants) {
    const owned = countInventoryByItemId(plant.seed_item_id);
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = `icon-card ${selectedMode.type === 'seed' && Number(selectedMode.value) === Number(plant.plant_id) ? 'selected' : ''}`;
    btn.disabled = owned <= 0;
    btn.innerHTML = `
      ${renderIcon(plant.seed_icon, 'big-icon')}
      <span>${escapeHtml(plant.name)}</span>
      <b class="qty-badge">×${owned}</b>
    `;

    btn.addEventListener('click', () => {
      selectedMode = { type: 'seed', value: Number(plant.plant_id) };
      render();
    });

    grid.appendChild(btn);
  }
}

function renderInventory() {
  const grid = $('#inventoryGrid');
  grid.innerHTML = '';

  if (!state.inventory.length) {
    grid.innerHTML = '<p class="hint">Your pockets contain mostly lint and ambition.</p>';
    return;
  }

  for (const item of state.inventory) {
    const card = document.createElement('div');
    card.className = 'inventory-card';
    card.innerHTML = `
      ${renderIcon(item.icon, 'inventory-icon')}
      <b class="stack-count">×${item.quantity}</b>
      <span>${escapeHtml(item.name)}</span>
    `;
    grid.appendChild(card);
  }
}

function renderShop() {
  const seeds = $('#shopSeedList');
  seeds.innerHTML = '';

  for (const plant of state.plants) {
    const row = document.createElement('div');
    row.className = 'shop-row';
    row.innerHTML = `
      <div class="shop-main">
        ${renderIcon(plant.seed_icon, 'shop-icon')}
        <span>${escapeHtml(plant.seed_name)}</span>
      </div>
      <button type="button">Buy ${plant.base_buy_price} 🪙</button>
    `;
    row.querySelector('button').addEventListener('click', () => {
      doAction({ action: 'buy_seed', item_id: Number(plant.seed_item_id), quantity: 1 });
    });
    seeds.appendChild(row);
  }

  const machines = $('#shopMachineList');
  machines.innerHTML = '';

  for (const machine of state.all_machines) {
    const owned = state.machines.find(m => Number(m.machine_id) === Number(machine.machine_id));
    const row = document.createElement('div');
    row.className = 'shop-row';
    row.innerHTML = `
      <div class="shop-main">
        ${renderIcon(machine.icon, 'shop-icon')}
        <span>${escapeHtml(machine.name)} ${owned ? `<small>owned ×${owned.quantity}</small>` : ''}</span>
      </div>
      <button type="button">Buy ${machine.base_cost} 🪙</button>
    `;
    row.querySelector('button').addEventListener('click', () => {
      doAction({ action: 'buy_machine', machine_id: Number(machine.machine_id) });
    });
    machines.appendChild(row);
  }

  const sells = $('#sellList');
  sells.innerHTML = '';

  const sellable = state.inventory.filter(item => Number(item.base_sell_price) > 0);
  if (!sellable.length) {
    sells.innerHTML = '<p class="hint">Nothing worth selling yet.</p>';
    return;
  }

  for (const item of sellable) {
    const row = document.createElement('div');
    row.className = 'shop-row';
    row.innerHTML = `
      <div class="shop-main">
        ${renderIcon(item.icon, 'shop-icon')}
        <span>${escapeHtml(item.name)} ×${item.quantity}</span>
      </div>
      <button type="button">Sell ${item.base_sell_price} 🪙</button>
    `;
    row.querySelector('button').addEventListener('click', () => {
      doAction({ action: 'sell_item', item_id: Number(item.item_id), quantity: 1 });
    });
    sells.appendChild(row);
  }
}

function renderShed() {
  const machines = $('#machineList');
  machines.innerHTML = '';

  if (!state.machines.length) {
    machines.innerHTML = '<p class="hint">The shed echoes. Dramatically.</p>';
  } else {
    for (const machine of state.machines) {
      const row = document.createElement('div');
      row.className = 'shop-row';
      row.innerHTML = `
        <div class="shop-main">
          ${renderIcon(machine.icon, 'shop-icon')}
          <span>${escapeHtml(machine.name)} ×${machine.quantity}</span>
        </div>
      `;
      machines.appendChild(row);
    }
  }

  const list = $('#processingList');
  list.innerHTML = '';

  for (const recipe of state.recipes) {
    const hasMachine = state.machines.some(m => m.machine_type === recipe.machine_type);
    const owned = countInventoryByItemId(recipe.input_item_id);

    const row = document.createElement('div');
    row.className = 'process-row';
    row.innerHTML = `
      <div class="process-visual">
        ${renderIcon(recipe.input_icon, 'shop-icon')}
        <b>×${recipe.input_quantity}</b>
        <span>→</span>
        <span class="bin-icon">🍯</span>
        <span>→</span>
        ${renderIcon(recipe.output_icon, 'shop-icon')}
      </div>
      <button type="button" ${hasMachine && owned >= Number(recipe.input_quantity) ? '' : 'disabled'}>Start</button>
    `;
    row.querySelector('button').addEventListener('click', () => {
      doAction({ action: 'start_processing', recipe_id: Number(recipe.recipe_id), quantity: 1 });
    });
    list.appendChild(row);
  }

  for (const job of state.jobs) {
    const finished = new Date(job.finishes_at.replace(' ', 'T')).getTime() <= Date.now();
    const row = document.createElement('div');
    row.className = 'process-row';
    row.innerHTML = `
      <div class="process-visual">
        ${renderIcon(job.output_icon, 'shop-icon')}
        <span>${finished ? 'Ready' : 'Working...'}</span>
      </div>
      <button type="button" ${finished ? '' : 'disabled'}>Collect</button>
    `;
    row.querySelector('button').addEventListener('click', () => {
      doAction({ action: 'collect_processing', job_id: Number(job.job_id) });
    });
    list.appendChild(row);
  }
}

function setupTabs() {
  document.querySelectorAll('.tab-button').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.tab-button').forEach(b => b.classList.remove('active'));
      document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));

      btn.classList.add('active');
      document.querySelector(`[data-panel="${btn.dataset.tab}"]`).classList.add('active');
    });
  });
}

function render() {
  $('#coinCount').textContent = state.user.coins;
  $('#gardenName').textContent = `${state.garden.name} — ${state.garden.garden_type_name}`;

  renderTools();
  renderSeeds();
  renderInventory();
  renderShop();
  renderShed();
}

document.addEventListener('DOMContentLoaded', () => {
  canvas = $('#gardenCanvas');
  ctx = canvas.getContext('2d');

  canvas.addEventListener('click', handleCanvasClick);
  $('#refreshBtn').addEventListener('click', fetchState);

  setupTabs();
  fetchState();
  requestAnimationFrame(drawGarden);
  setInterval(fetchState, 15000);
});
