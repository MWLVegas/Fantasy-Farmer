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
  <link rel="stylesheet" href="assets/css/farm.css?v=0.2.1">
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
      <span class="coin-pill"><span id="coinCount">0</span> 🪙</span>
      <a href="logout.php">Logout</a>
    </div>
  </header>

  <main class="game-shell">
    <section class="play-panel">
      <div class="panel-header">
        <h2 id="gardenName">Garden</h2>
        <button type="button" id="refreshBtn" class="small-button">Refresh</button>
      </div>

      <div class="canvas-wrap">
        <canvas id="gardenCanvas" width="720" height="720"></canvas>
      </div>
    </section>

    <aside class="side-panel">
      <nav class="tabs" aria-label="Game tabs">
        <button type="button" class="tab-button active" data-tab="garden">Garden</button>
        <button type="button" class="tab-button" data-tab="shop">Shop</button>
        <button type="button" class="tab-button" data-tab="shed">Shed</button>
        <button type="button" class="tab-button" data-tab="inventory">Inventory</button>
      </nav>

      <section class="tab-panel active" data-panel="garden">
        <h3>Tools</h3>
        <div id="toolGrid" class="icon-grid"></div>

        <h3>Seeds</h3>
        <div id="seedGrid" class="icon-grid"></div>

        <p class="hint">Pick a tool or seed, then click the field.</p>
      </section>

      <section class="tab-panel" data-panel="shop">
        <h3>Buy Seeds</h3>
        <div id="shopSeedList" class="shop-list"></div>

        <h3>Buy Equipment</h3>
        <div id="shopMachineList" class="shop-list"></div>

        <h3>Sell Goods</h3>
        <div id="sellList" class="shop-list"></div>
      </section>

      <section class="tab-panel" data-panel="shed">
        <h3>Equipment</h3>
        <div id="machineList" class="shop-list"></div>

        <h3>Processing</h3>
        <div id="processingList" class="shop-list"></div>
      </section>

      <section class="tab-panel" data-panel="inventory">
        <h3>Inventory</h3>
        <div id="inventoryGrid" class="inventory-grid"></div>
      </section>
    </aside>
  </main>

  <div id="statusMessage" class="status-message"></div>
  <div class="version-pill"><?= GAME_VERSION ?></div>

  <script>
    window.GAME_VERSION = <?= json_encode(GAME_VERSION) ?>;
  </script>
  <script src="assets/js/farm.js?v=0.2.1"></script>
</body>
</html>
