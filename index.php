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
  <link rel="stylesheet" href="assets/css/farm.css?v=0.3.15">
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
      <div class="panel-header">
        <h2 id="gardenName">Garden</h2>
        <span class="panel-coin-pill"><span id="coinCount">0</span> 🪙</span>
      </div>

      <div class="day-bar-wrap">
        <div class="day-label" id="dayLabel">Day 1 · 06:00</div>
        <div class="day-track">
          <div class="day-orb" id="dayOrb">☀️</div>
        </div>
      </div>

      <div class="field-actions">
        <button type="button" id="ordersBtn" class="small-button orders-button" data-tooltip-html="<b>No orders are ready</b><br><span class=&quot;muted-line&quot;>Check back soon.</span>">📜 <span id="ordersTimer"></span><b id="ordersBadge" class="order-badge">!</b></button>
      </div>

      <div class="canvas-wrap">
        <canvas id="gardenCanvas" width="720" height="720"></canvas>
      </div>
    </section>

    <aside class="side-panel">
      <nav class="tabs" aria-label="Game tabs">
        <button type="button" class="tab-button active" data-tab="garden" data-tip="Garden">🌱</button>
        <button type="button" class="tab-button" data-tab="shop" data-tip="Shop">🛒</button>
        <button type="button" class="tab-button" data-tab="shed" data-tip="Shed">🛖</button>
        <button type="button" class="tab-button" data-tab="workers" data-tip="Goblins">🧌</button>
        <button type="button" class="tab-button" data-tab="inventory" data-tip="Inventory">🎒</button>
      </nav>

      <section class="tab-panel active" data-panel="garden">
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

      <section class="tab-panel" data-panel="shed">
        <h3>Equipment</h3>
        <div id="machineList" class="shop-list"></div>

        <h3>Processing</h3>
        <div id="processingList" class="shop-list"></div>
      </section>

      <section class="tab-panel" data-panel="workers">
        <h3>Hire Goblins</h3>
        <div id="workerHireList" class="shop-list"></div>
        <h3>Your Crew</h3>
        <div id="workerList" class="shop-list"></div>
        <h3>Plant Order</h3>
        <p class="hint">Planter goblins will try this order from top to bottom.</p>
        <div id="plantOrderList" class="shop-list"></div>
      </section>

      <section class="tab-panel" data-panel="admin">
        <h3>Admin Debug</h3>
        <p class="hint">Visible only to the configured admin user.</p>
        <div class="shop-list">
          <button type="button" id="adminAddCoinsBtn">➕ 1000 🪙</button>
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
        <h2>Orders</h2>
        <button type="button" class="modal-close" data-close-modal>×</button>
      </div>
      <div id="ordersContent"></div>
    </div>
  </div>

  <div id="gameTooltip" class="game-tooltip"></div>
  <div id="statusMessage" class="status-message"></div>
  <div class="version-pill"><?= GAME_VERSION ?></div>

  <script>
    window.GAME_VERSION = <?= json_encode(GAME_VERSION) ?>;
  </script>
  <script src="assets/js/farm.js?v=0.3.15"></script>
</body>
</html>
