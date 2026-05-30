<?php
require_once __DIR__ . '/includes/bootstrap.php';
$userId = requireLogin();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Fantasy Farmer</title>
  <link rel="stylesheet" href="assets/css/farm.css?v=0.4.16k">
</head>
<body>
  <header class="topbar">
    <div class="brand">
      <div class="brand-mark">🌱</div>
      <div>
        <h1>Fantasy Farmer</h1>
        <p class="subtitle">Start with dirt. End with suspiciously magical jam.</p>
      </div>
    </div>

    <div class="player-box">
      <a href="logout.php">Logout</a>
    </div>
  </header>

  <main class="game-shell">
    <section class="play-panel">
      <div class="day-bar-wrap">
        <div class="day-label" id="dayLabel">Day 1 · 06:00</div>
        <div class="day-track">
          <div class="day-orb" id="dayOrb">☀️</div>
        </div>
      </div>

      <div class="field-actions">
        <div class="field-stats" aria-label="Farm totals">
          <span class="panel-coin-pill" data-tooltip-html="<b>Coins</b><br><span class=&quot;muted-line&quot;>Spendable money from farming, sales, and completed orders.</span>"><span id="coinIcon" class="header-stat-icon">🪙</span><span id="coinCount">0</span></span>
          <span class="panel-stat-pill" data-tooltip-html="<b>Reputation</b><br><span class=&quot;muted-line&quot;>Local trust earned from orders.</span>"><span id="reputationIcon" class="header-stat-icon">⭐</span> <b id="reputationCount">0</b></span>
          <span class="panel-stat-pill" data-tooltip-html="<b>Recognition</b><br><span class=&quot;muted-line&quot;>World progress from milestones, relics, and visitors.</span>"><span id="recognitionIcon" class="header-stat-icon">🏵️</span> <b id="recognitionCount">0</b></span>
        </div>
        <div class="field-nav-actions">
          <button type="button" id="backToMapBtn" class="small-button back-map-button" hidden>🗺️ Map</button>
          <button type="button" id="inventoryBtn" class="small-button" data-tooltip-html="<b>Inventory</b><br><span class=&quot;muted-line&quot;>Open your backpack.</span>">🎒 Backpack</button>
          <button type="button" id="ordersBtn" class="small-button orders-button" data-tooltip-html="<b>Orders Board</b><br><span class=&quot;muted-line&quot;>No active orders right now.</span>">📜 Orders: <span id="ordersTimer">0/2</span><b id="ordersBadge" class="order-badge">!</b></button>
        </div>
      </div>

      <div class="canvas-wrap">
        <canvas id="gardenCanvas" width="720" height="720"></canvas>
        <div id="ordersBoardSurface" class="orders-board-surface" hidden></div>
      </div>
    </section>

    <aside class="side-panel">
      <div class="side-actions">
        <button type="button" class="small-button back-map-button" data-side-map-button hidden>🗺️ Map</button>
      </div>

      <section class="tab-panel active" data-panel="map">
        <h3 id="mapPanelTitle">Town</h3>
        <p class="hint">Travel between unlocked places. The canvas is now the world hub.</p>
        <div id="locationList" class="shop-list canvas-only-note"></div>
      </section>

      <section class="tab-panel" data-panel="garden">
        <h3>Tools</h3>
        <div id="toolGrid" class="icon-grid"></div>

        <h3>Seeds</h3>
        <div id="seedGrid" class="icon-grid"></div>

        <p class="hint">Pick a tool or seed, then click the field.</p>
      </section>

      <section class="tab-panel" data-panel="shop">
        <h3>Sell Goods</h3>
        <div id="sellList" class="shop-list"></div>
      </section>

      <section class="tab-panel" data-panel="market">
        <h3>Sell Crops</h3>
        <p class="hint">The Fae Market buys any crop while the gates are open.</p>
        <div id="marketSellList" class="shop-list"></div>
      </section>

      <section class="tab-panel" data-panel="shed">
        <h3>Equipment</h3>
        <div id="machineList" class="shop-list"></div>

        <h3>Processing</h3>
        <div id="processingList" class="shop-list"></div>
      </section>

      <section class="tab-panel" data-panel="orders">
        <h3>Orders Board</h3>
        <p class="hint">Multiple requests can be active at once. Shorter timers pay a rush bonus.</p>
        <div id="ordersBoardList" class="shop-list canvas-only-note"></div>
      </section>

      <section class="tab-panel" data-panel="helpers">
        <h3>Forest Folk</h3>
        <div id="helperUnlockHint" class="hint"></div>
        <h3>Your Helpers</h3>
        <div id="workerList" class="shop-list"></div>
        <div id="plantOrderList" class="shop-list"></div>
      </section>

      <section class="tab-panel" data-panel="admin">
        <h3>Admin Debug</h3>
        <p class="hint">Visible only to the configured admin user.</p>
        <div class="shop-list">
          <button type="button" id="adminAddCoinsBtn">➕ 1000 <span class="admin-coin-icon">🪙</span></button>
        </div>
      </section>

      <section class="tab-panel" data-panel="inventory">
        <p class="hint">Your backpack is shown on the left.</p>
      </section>
    </aside>
  </main>

  <div id="ordersModal" class="modal closeableModal">
    <div class="modal-content modal-content--orders">
      <div class="modal-header">
        <h2>Order Details</h2>
        <button type="button" class="modal-close" data-close-modal>×</button>
      </div>
      <div id="ordersContent"></div>
    </div>
  </div>


  <div id="storyModal" class="modal closeableModal">
    <div class="modal-content modal-content--story">
      <div class="modal-header">
        <h2 id="storyTitle">Story</h2>
        <button type="button" id="storyCloseBtn" class="modal-close" data-close-modal hidden>×</button>
      </div>
      <div id="storyContent" class="story-content"></div>
      <div class="story-actions">
        <button type="button" id="storyNextBtn" class="button primary">Okay</button>
      </div>
    </div>
  </div>

  <div id="gameTooltip" class="game-tooltip"></div>
  <div id="statusMessage" class="status-message"></div>
  <div id="saveStatus" class="save-status save-status--saved" data-tooltip-html="<b>Save Status</b><br>Everything is synced.">●●</div>
  <div class="version-pill" id="versionPill"><?= htmlspecialchars(getAppVersion($db), ENT_QUOTES) ?></div>

  <script>
    window.GAME_VERSION = <?= json_encode(getAppVersion($db)) ?>;
  </script>
  <script src="assets/js/farm.js?v=0.4.28"></script>
</body>
</html>
