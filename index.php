<?php
require_once 'db.php';

// 각 위치별 최신 데이터 조회
$locations = ['실험실', '사무실', '서버실', '야외'];
$latest = [];
foreach ($locations as $loc) {
    $stmt = $conn->prepare(
        "SELECT * FROM sensor_data WHERE location = ? ORDER BY recorded_at DESC LIMIT 1"
    );
    $stmt->bind_param('s', $loc);
    $stmt->execute();
    $result = $stmt->get_result();
    $latest[$loc] = $result->fetch_assoc();
    $stmt->close();
}

// 최근 20개 기록 조회
$history = [];
$res = $conn->query(
    "SELECT * FROM sensor_data ORDER BY recorded_at DESC LIMIT 20"
);
while ($row = $res->fetch_assoc()) {
    $history[] = $row;
}

$conn->close();

// 상태 판단 헬퍼 함수
function tempStatus($v)  { return $v > 30 ? 'danger'  : ($v > 26 ? 'warning' : 'ok'); }
function humStatus($v)   { return $v > 70 ? 'high'    : ($v < 30 ? 'low'     : 'ok'); }
function co2Status($v)   { return $v > 1000 ? 'danger' : ($v > 800 ? 'warning' : 'ok'); }
function pm25Status($v)  { return $v > 35  ? 'danger'  : ($v > 15  ? 'warning' : 'ok'); }

function statusBadge($status, $value, $unit) {
    $colors = [
        'danger'  => '#ff4d4d',
        'high'    => '#4d9fff',
        'low'     => '#ffb84d',
        'warning' => '#ffcc00',
        'ok'      => '#4dff88',
    ];
    $color = $colors[$status] ?? '#4dff88';
    return "<span style='color:{$color};font-weight:700;font-size:1.5rem;'>{$value}</span>"
         . "<span style='color:#aaa;font-size:0.85rem;margin-left:3px;'>{$unit}</span>";
}

function locationIcon($loc) {
    $icons = ['실험실' => '🔬', '사무실' => '🏢', '서버실' => '🖥️', '야외' => '🌿'];
    return $icons[$loc] ?? '📍';
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>IoT 환경 모니터링 대시보드</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root {
      --bg-main:   #0d1117;
      --bg-card:   #161b22;
      --bg-card2:  #1c2230;
      --border:    #30363d;
      --text-main: #e6edf3;
      --text-muted:#8b949e;
      --accent:    #58a6ff;
      --green:     #3fb950;
      --red:       #f85149;
      --yellow:    #d29922;
      --blue:      #388bfd;
      --orange:    #db6d28;
    }

    * { box-sizing: border-box; }

    body {
      background: var(--bg-main);
      color: var(--text-main);
      font-family: 'Segoe UI', 'Noto Sans KR', sans-serif;
      min-height: 100vh;
      margin: 0;
    }

    /* ── HEADER ── */
    .site-header {
      background: linear-gradient(135deg, #0d1117 0%, #1c2230 50%, #0d1117 100%);
      border-bottom: 1px solid var(--border);
      padding: 18px 0 14px;
      position: sticky;
      top: 0;
      z-index: 100;
      box-shadow: 0 2px 16px rgba(0,0,0,0.5);
    }
    .site-header h1 {
      font-size: 1.6rem;
      font-weight: 700;
      color: var(--accent);
      margin: 0;
      letter-spacing: -0.5px;
    }
    .site-header .subtitle {
      font-size: 0.78rem;
      color: var(--text-muted);
      margin-top: 2px;
    }

    /* ── LIVE BADGE ── */
    .live-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: rgba(63,185,80,0.12);
      border: 1px solid rgba(63,185,80,0.35);
      color: var(--green);
      border-radius: 20px;
      padding: 4px 12px;
      font-size: 0.78rem;
      font-weight: 600;
    }
    .live-dot {
      width: 8px; height: 8px;
      border-radius: 50%;
      background: var(--green);
      animation: blink 1.2s infinite;
    }
    @keyframes blink {
      0%,100% { opacity: 1; }
      50%      { opacity: 0.2; }
    }

    /* ── CLOCK ── */
    #clock {
      font-size: 0.9rem;
      color: var(--text-muted);
      font-family: 'Courier New', monospace;
    }

    /* ── SENSOR CARDS ── */
    .sensor-card {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 20px 22px;
      transition: transform 0.2s, box-shadow 0.2s;
      position: relative;
      overflow: hidden;
    }
    .sensor-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0;
      height: 3px;
      border-radius: 14px 14px 0 0;
    }
    .sensor-card.lab::before    { background: linear-gradient(90deg, #58a6ff, #3fb950); }
    .sensor-card.office::before { background: linear-gradient(90deg, #db6d28, #d29922); }
    .sensor-card.server::before { background: linear-gradient(90deg, #f85149, #db6d28); }
    .sensor-card.outdoor::before{ background: linear-gradient(90deg, #3fb950, #388bfd); }

    .sensor-card:hover {
      transform: translateY(-3px);
      box-shadow: 0 8px 32px rgba(0,0,0,0.4);
    }
    .card-loc-title {
      font-size: 1.05rem;
      font-weight: 700;
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .card-loc-title .icon { font-size: 1.3rem; }

    .metric-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 12px;
    }
    .metric-box {
      background: var(--bg-card2);
      border-radius: 10px;
      padding: 10px 12px;
      border: 1px solid var(--border);
    }
    .metric-label {
      font-size: 0.7rem;
      color: var(--text-muted);
      text-transform: uppercase;
      letter-spacing: 0.5px;
      margin-bottom: 4px;
      display: flex;
      align-items: center;
      gap: 4px;
    }
    .metric-value { line-height: 1; }
    .metric-value .val {
      font-size: 1.55rem;
      font-weight: 700;
    }
    .metric-value .unit {
      font-size: 0.78rem;
      color: var(--text-muted);
      margin-left: 2px;
    }

    /* status colors */
    .c-ok      { color: var(--green); }
    .c-warning { color: var(--yellow); }
    .c-danger  { color: var(--red); }
    .c-high    { color: var(--blue); }
    .c-low     { color: var(--orange); }

    .status-pill {
      display: inline-block;
      padding: 2px 8px;
      border-radius: 20px;
      font-size: 0.65rem;
      font-weight: 600;
      margin-top: 4px;
    }
    .pill-ok      { background: rgba(63,185,80,0.15);  color: var(--green);  border: 1px solid rgba(63,185,80,0.3); }
    .pill-warning { background: rgba(210,153,34,0.15); color: var(--yellow); border: 1px solid rgba(210,153,34,0.3); }
    .pill-danger  { background: rgba(248,81,73,0.15);  color: var(--red);    border: 1px solid rgba(248,81,73,0.3); }
    .pill-high    { background: rgba(56,139,253,0.15); color: var(--blue);   border: 1px solid rgba(56,139,253,0.3); }
    .pill-low     { background: rgba(219,109,40,0.15); color: var(--orange); border: 1px solid rgba(219,109,40,0.3); }

    .card-footer-ts {
      font-size: 0.68rem;
      color: var(--text-muted);
      margin-top: 12px;
      text-align: right;
    }

    /* ── SECTION TITLE ── */
    .section-title {
      font-size: 1.1rem;
      font-weight: 700;
      color: var(--accent);
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .section-title::after {
      content: '';
      flex: 1;
      height: 1px;
      background: var(--border);
      margin-left: 8px;
    }

    /* ── HISTORY TABLE ── */
    .history-wrap {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 14px;
      overflow: hidden;
    }
    .history-wrap table {
      width: 100%;
      border-collapse: collapse;
      font-size: 0.83rem;
    }
    .history-wrap thead th {
      background: var(--bg-card2);
      color: var(--text-muted);
      font-weight: 600;
      text-transform: uppercase;
      font-size: 0.7rem;
      letter-spacing: 0.5px;
      padding: 10px 14px;
      border-bottom: 1px solid var(--border);
      white-space: nowrap;
    }
    .history-wrap tbody tr {
      border-bottom: 1px solid rgba(48,54,61,0.5);
      transition: background 0.15s;
    }
    .history-wrap tbody tr:hover { background: rgba(88,166,255,0.05); }
    .history-wrap tbody tr:last-child { border-bottom: none; }
    .history-wrap tbody td {
      padding: 9px 14px;
      color: var(--text-main);
      white-space: nowrap;
    }
    .loc-tag {
      display: inline-block;
      padding: 2px 9px;
      border-radius: 20px;
      font-size: 0.72rem;
      font-weight: 600;
    }
    .tag-lab    { background: rgba(88,166,255,0.15); color: #58a6ff; }
    .tag-office { background: rgba(219,109,40,0.15); color: #db6d28; }
    .tag-server { background: rgba(248,81,73,0.15);  color: #f85149; }
    .tag-outdoor{ background: rgba(63,185,80,0.15);  color: #3fb950; }

    /* ── STATS BAR ── */
    .stats-bar {
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 14px;
      padding: 16px 22px;
      display: flex;
      gap: 28px;
      flex-wrap: wrap;
      align-items: center;
    }
    .stat-item { text-align: center; }
    .stat-item .s-val {
      font-size: 1.4rem;
      font-weight: 700;
      color: var(--accent);
    }
    .stat-item .s-lbl {
      font-size: 0.68rem;
      color: var(--text-muted);
      text-transform: uppercase;
    }
    .stat-divider {
      width: 1px;
      height: 40px;
      background: var(--border);
    }

    /* ── REFRESH PROGRESS ── */
    #refresh-bar {
      height: 2px;
      background: var(--accent);
      width: 100%;
      position: fixed;
      top: 0;
      left: 0;
      z-index: 9999;
      transition: width 0.1s linear;
    }

    .footer-bar {
      border-top: 1px solid var(--border);
      padding: 14px 0;
      color: var(--text-muted);
      font-size: 0.75rem;
      text-align: center;
      margin-top: 40px;
    }
  </style>
</head>
<body>

<div id="refresh-bar" style="width:0%"></div>

<!-- HEADER -->
<header class="site-header">
  <div class="container-fluid px-4">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div>
        <h1>🌐 IoT 환경 모니터링 대시보드</h1>
        <div class="subtitle">IoT Environmental Sensor Monitoring System &mdash; 4 Locations · Real-time</div>
      </div>
      <div class="d-flex align-items-center gap-3 flex-wrap">
        <div id="clock">--:--:--</div>
        <div class="live-badge">
          <span class="live-dot"></span>
          LIVE &nbsp;3s 갱신
        </div>
      </div>
    </div>
  </div>
</header>

<div class="container-fluid px-4 py-4">

  <!-- SENSOR CARDS -->
  <div class="section-title">📡 실시간 센서 현황</div>
  <div class="row g-3 mb-4" id="sensor-cards">
    <?php
    $cardClasses = ['실험실'=>'lab','사무실'=>'office','서버실'=>'server','야외'=>'outdoor'];
    $tagClasses  = ['실험실'=>'tag-lab','사무실'=>'tag-office','서버실'=>'tag-server','야외'=>'tag-outdoor'];

    foreach ($locations as $loc):
      $d = $latest[$loc] ?? null;
      $cls = $cardClasses[$loc];
    ?>
    <div class="col-xl-3 col-md-6">
      <div class="sensor-card <?= $cls ?>">
        <div class="card-loc-title">
          <span class="icon"><?= locationIcon($loc) ?></span>
          <?= htmlspecialchars($loc) ?>
          <?php if ($d): ?>
            <span class="ms-auto <?= $tagClasses[$loc] ?> loc-tag"><?= $cls ?></span>
          <?php endif; ?>
        </div>

        <?php if ($d): ?>
        <div class="metric-grid">
          <?php
            $ts = tempStatus((float)$d['temperature']);
            $hs = humStatus((float)$d['humidity']);
            $cs = co2Status((int)$d['co2']);
            $ps = pm25Status((float)$d['pm25']);
            $statusLabel = ['ok'=>'정상','warning'=>'주의','danger'=>'위험','high'=>'높음','low'=>'낮음'];
          ?>
          <!-- 온도 -->
          <div class="metric-box">
            <div class="metric-label">🌡️ 온도</div>
            <div class="metric-value">
              <span class="val c-<?= $ts ?>"><?= number_format($d['temperature'],1) ?></span>
              <span class="unit">°C</span>
            </div>
            <div><span class="status-pill pill-<?= $ts ?>"><?= $statusLabel[$ts] ?></span></div>
          </div>
          <!-- 습도 -->
          <div class="metric-box">
            <div class="metric-label">💧 습도</div>
            <div class="metric-value">
              <span class="val c-<?= $hs ?>"><?= number_format($d['humidity'],1) ?></span>
              <span class="unit">%</span>
            </div>
            <div><span class="status-pill pill-<?= $hs ?>"><?= $statusLabel[$hs] ?></span></div>
          </div>
          <!-- CO2 -->
          <div class="metric-box">
            <div class="metric-label">☁️ CO₂</div>
            <div class="metric-value">
              <span class="val c-<?= $cs ?>"><?= number_format($d['co2']) ?></span>
              <span class="unit">ppm</span>
            </div>
            <div><span class="status-pill pill-<?= $cs ?>"><?= $statusLabel[$cs] ?></span></div>
          </div>
          <!-- PM2.5 -->
          <div class="metric-box">
            <div class="metric-label">🌫️ PM2.5</div>
            <div class="metric-value">
              <span class="val c-<?= $ps ?>"><?= number_format($d['pm25'],1) ?></span>
              <span class="unit">µg/m³</span>
            </div>
            <div><span class="status-pill pill-<?= $ps ?>"><?= $statusLabel[$ps] ?></span></div>
          </div>
        </div>
        <div class="card-footer-ts">
          🕐 <?= htmlspecialchars($d['recorded_at']) ?>
        </div>
        <?php else: ?>
          <div style="color:var(--text-muted);font-size:0.85rem;padding:10px 0;">
            데이터 없음 — injector.py를 실행하세요
          </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- STATS BAR -->
  <?php
    $allVals = array_filter($latest);
    if (count($allVals) > 0):
      $avgTemp = array_sum(array_column($allVals,'temperature')) / count($allVals);
      $avgHum  = array_sum(array_column($allVals,'humidity'))    / count($allVals);
      $avgCO2  = array_sum(array_column($allVals,'co2'))         / count($allVals);
      $avgPM   = array_sum(array_column($allVals,'pm25'))        / count($allVals);
  ?>
  <div class="stats-bar mb-4">
    <div class="stat-item">
      <div class="s-lbl">평균 온도</div>
      <div class="s-val"><?= number_format($avgTemp,1) ?>°C</div>
    </div>
    <div class="stat-divider"></div>
    <div class="stat-item">
      <div class="s-lbl">평균 습도</div>
      <div class="s-val"><?= number_format($avgHum,1) ?>%</div>
    </div>
    <div class="stat-divider"></div>
    <div class="stat-item">
      <div class="s-lbl">평균 CO₂</div>
      <div class="s-val"><?= number_format($avgCO2) ?>ppm</div>
    </div>
    <div class="stat-divider"></div>
    <div class="stat-item">
      <div class="s-lbl">평균 PM2.5</div>
      <div class="s-val"><?= number_format($avgPM,1) ?>µg/m³</div>
    </div>
    <div class="stat-divider"></div>
    <div class="stat-item">
      <div class="s-lbl">모니터링 위치</div>
      <div class="s-val"><?= count($allVals) ?>곳</div>
    </div>
    <div class="stat-divider"></div>
    <div class="stat-item">
      <div class="s-lbl">갱신 주기</div>
      <div class="s-val">3s</div>
    </div>
  </div>
  <?php endif; ?>

  <!-- HISTORY TABLE -->
  <div class="section-title">📋 최근 측정 기록 (최신 20개)</div>
  <div class="history-wrap mb-4">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>위치</th>
          <th>🌡️ 온도 (°C)</th>
          <th>💧 습도 (%)</th>
          <th>☁️ CO₂ (ppm)</th>
          <th>🌫️ PM2.5 (µg/m³)</th>
          <th>🕐 기록 시각</th>
        </tr>
      </thead>
      <tbody id="history-body">
        <?php if (empty($history)): ?>
        <tr>
          <td colspan="7" style="text-align:center;color:var(--text-muted);padding:24px;">
            기록이 없습니다. injector.py를 실행하여 데이터를 수집하세요.
          </td>
        </tr>
        <?php else: ?>
        <?php foreach ($history as $i => $row):
          $ts2 = tempStatus((float)$row['temperature']);
          $hs2 = humStatus((float)$row['humidity']);
          $cs2 = co2Status((int)$row['co2']);
          $ps2 = pm25Status((float)$row['pm25']);
          $tag = $tagClasses[$row['location']] ?? 'tag-lab';
        ?>
        <tr>
          <td style="color:var(--text-muted);"><?= $i+1 ?></td>
          <td><span class="loc-tag <?= $tag ?>"><?= htmlspecialchars($row['location']) ?></span></td>
          <td class="c-<?= $ts2 ?>"><?= number_format($row['temperature'],1) ?></td>
          <td class="c-<?= $hs2 ?>"><?= number_format($row['humidity'],1) ?></td>
          <td class="c-<?= $cs2 ?>"><?= number_format($row['co2']) ?></td>
          <td class="c-<?= $ps2 ?>"><?= number_format($row['pm25'],1) ?></td>
          <td style="color:var(--text-muted);"><?= htmlspecialchars($row['recorded_at']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>

<div class="footer-bar">
  IoT 환경 모니터링 시스템 &mdash; MySQL + PHP + Bootstrap 5 &mdash;
  데이터는 3초마다 자동 갱신됩니다
</div>

<script>
// ── 시계 ──
function updateClock() {
  const now = new Date();
  const pad = n => String(n).padStart(2,'0');
  document.getElementById('clock').textContent =
    `${now.getFullYear()}-${pad(now.getMonth()+1)}-${pad(now.getDate())} `
    + `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
}
updateClock();
setInterval(updateClock, 1000);

// ── 진행 바 ──
let progress = 0;
const bar = document.getElementById('refresh-bar');
const INTERVAL = 3000;
const STEP_MS  = 50;

function tickBar() {
  progress = Math.min(progress + (STEP_MS / INTERVAL) * 100, 100);
  bar.style.width = progress + '%';
}
const barTimer = setInterval(tickBar, STEP_MS);

// ── 자동 새로고침 (fetch + 페이지 리로드) ──
setTimeout(function reload() {
  clearInterval(barTimer);
  bar.style.transition = 'none';
  bar.style.width = '100%';
  setTimeout(() => { window.location.reload(); }, 80);
}, INTERVAL);
</script>
</body>
</html>
