<?php
declare(strict_types=1);
$config = require __DIR__ . '/../config.php';
$e = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= $e($config['temple_name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Be+Vietnam+Pro:wght@400;500;600;700&family=Noto+Serif+Display:ital,wght@0,600;1,500&display=swap">
<link rel="stylesheet" href="css/temple.css?v=<?= $e($config['asset_version']) ?>">
</head>
<body>
<canvas id="scene" aria-label="Temple courtyard"></canvas>
<div id="labels"></div>

<header class="hud tl">
  <div class="seal" aria-hidden="true">An<br>Tâm</div>
  <div>
    <h1>An Tâm Nam Chùa</h1>
    <p class="sub">Chùa trên núi · open day and night</p>
    <div class="stats">
      <span><span class="dot"></span><b id="online">1,284</b> visiting</span>
      <span><b id="here">0</b> in this courtyard</span>
      <span>Incense today <b id="incense">3,417</b></span>
    </div>
    <div class="merit">
      <span class="chip" title="Bodhi leaves earned by good deeds"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2c-4 4-7 8-7 12a7 7 0 0 0 6 6.9V23h2v-2.1A7 7 0 0 0 19 14c0-4-3-8-7-12z" fill="#7fb85a"/><path d="M12 6v14" stroke="#2f5a22" stroke-width="1.4"/></svg><b id="leaves">0</b> lá bồ đề</span>
      <button type="button" class="chip" id="bagBtn" aria-expanded="false" aria-controls="bag"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="9" width="18" height="12" rx="1.5" fill="#c2412b"/><rect x="2" y="6" width="20" height="4" rx="1" fill="#d9aa4a"/><path d="M12 6v15" stroke="#d9aa4a" stroke-width="2"/><path d="M12 6c-2-4-6-4-6-1s6 1 6 1 6 2 6-1-4-3-6 1z" fill="none" stroke="#d9aa4a" stroke-width="1.6"/></svg><b id="giftCount">0</b> gifts</button>
    </div>
  </div>
</header>

<section class="hud" id="bag" hidden aria-label="Temple gifts">
  <h3>Túi phước</h3>
  <p>Good deeds earn Bodhi leaves. Every 12 leaves, the temple gives you a gift.</p>
  <ul id="giftList"></ul>
</section>

<details class="hud tr" open id="settings">
  <summary>Scene</summary>
  <div class="ctl">
    <div class="row">
      <div class="lab"><span>Time of day</span><output id="timeOut">--:--</output></div>
      <input type="range" id="time" min="0" max="24" step="0.05" value="16.5">
      <label class="check"><input type="checkbox" id="live"> Follow my clock</label>
    </div>
    <div class="row">
      <div class="lab"><span>Pixel size</span><output id="pixOut">3</output></div>
      <input type="range" id="pix" min="1" max="6" step="1" value="3">
    </div>
    <div class="row">
      <div class="lab"><span>Visitors</span><output id="crowdOut">18</output></div>
      <input type="range" id="crowd" min="0" max="30" step="1" value="18">
    </div>
    <div class="row">
      <div class="lab"><span>Incense smoke</span></div>
      <div class="seg" id="smokeSeg" role="radiogroup" aria-label="Incense smoke">
        <button type="button" role="radio" data-v="0" aria-checked="false">Off</button>
        <button type="button" role="radio" data-v="1" aria-checked="true">A little</button>
        <button type="button" role="radio" data-v="2" aria-checked="false">Full</button>
      </div>
    </div>
    <button class="btn ghost" id="soundBtn" type="button">Sound: off</button>
  </div>
</details>

<section class="hud bl" id="entry">
  <h2>Enter the temple</h2>
  <div class="field">
    <label for="name">Your name</label>
    <input type="text" id="name" maxlength="18" placeholder="e.g. Linh" autocomplete="off">
  </div>
  <div class="field">
    <label>Clothing</label>
    <div class="outfits" id="outfits">
      <button type="button" data-o="aotrang" aria-pressed="true">Áo tràng</button>
      <button type="button" data-o="aodai" aria-pressed="false">Áo dài</button>
      <button type="button" data-o="baba" aria-pressed="false">Bà ba</button>
      <button type="button" data-o="modern" aria-pressed="false">Everyday</button>
    </div>
  </div>
  <div class="field">
    <label>Colour</label>
    <div class="swatches" id="swatches"></div>
  </div>
  <button class="btn" id="enterBtn" type="button">Walk in · sound on</button>
</section>

<section class="hud bl" id="legend" hidden>
  <h2 id="hello">Welcome</h2>
  <div class="keys" id="keys">
    <button type="button" data-k="Digit1"><kbd>1</kbd>Stand</button>
    <button type="button" data-k="Digit2"><kbd>2</kbd>Chắp tay</button>
    <button type="button" data-k="Digit3"><kbd>3</kbd>Xá (bow)</button>
    <button type="button" data-k="Digit4"><kbd>4</kbd>Quỳ (kneel)</button>
    <button type="button" data-k="Digit5"><kbd>5</kbd>Lạy</button>
    <button type="button" data-k="Digit6"><kbd>6</kbd>Thiền (sit)</button>
    <button type="button" data-k="KeyF"><kbd>F</kbd>Incense</button>
    <button type="button" data-k="KeyB"><kbd>B</kbd>Ring bell</button>
    <button type="button" data-k="KeyG"><kbd>G</kbd>Help out</button>
    <button type="button" data-k="KeyV"><kbd>V</kbd>Eye view</button>
    <button type="button" data-k="KeyN"><kbd>N</kbd>Day / night</button>
  </div>
  <p class="move">WASD or arrows to walk, drag on a phone. Q / E turn. Scroll in all the way for your own eye view. Walk up the stairs into any hall, then press 4 to kneel and pray.</p>
</section>

<div id="toast" data-off></div>
<div id="reward" aria-live="polite"></div>
<script type="importmap">{"imports":{"three":"https://cdn.jsdelivr.net/npm/three@0.165.0/build/three.module.js"}}</script>
<script type="module" src="js/temple.js?v=<?= $e($config['asset_version']) ?>"></script>
</body>
</html>
