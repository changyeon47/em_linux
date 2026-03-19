<?php
/**
 * api.php — IoT 환경 모니터링 JSON API
 * 각 위치별 최신 데이터 및 최근 20개 기록 반환
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store, no-cache, must-revalidate');

require_once 'db.php';

$locations = ['실험실', '사무실', '서버실', '야외'];

// ── 위치별 최신 데이터 ──
$latest = [];
foreach ($locations as $loc) {
    $stmt = $conn->prepare(
        "SELECT id, location, temperature, humidity, co2, pm25, recorded_at
         FROM sensor_data
         WHERE location = ?
         ORDER BY recorded_at DESC
         LIMIT 1"
    );
    $stmt->bind_param('s', $loc);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    if ($row) {
        $row['temperature'] = (float)$row['temperature'];
        $row['humidity']    = (float)$row['humidity'];
        $row['co2']         = (int)$row['co2'];
        $row['pm25']        = (float)$row['pm25'];
        $row['status'] = [
            'temperature' => getTemperatureStatus((float)$row['temperature']),
            'humidity'    => getHumidityStatus((float)$row['humidity']),
            'co2'         => getCO2Status((int)$row['co2']),
            'pm25'        => getPM25Status((float)$row['pm25']),
        ];
        $latest[$loc] = $row;
    } else {
        $latest[$loc] = null;
    }
}

// ── 최근 20개 기록 ──
$history = [];
$res = $conn->query(
    "SELECT id, location, temperature, humidity, co2, pm25, recorded_at
     FROM sensor_data
     ORDER BY recorded_at DESC
     LIMIT 20"
);
while ($row = $res->fetch_assoc()) {
    $row['temperature'] = (float)$row['temperature'];
    $row['humidity']    = (float)$row['humidity'];
    $row['co2']         = (int)$row['co2'];
    $row['pm25']        = (float)$row['pm25'];
    $history[] = $row;
}

// ── 전체 통계 ──
$stats = [];
$statRes = $conn->query(
    "SELECT
       COUNT(*) AS total_records,
       AVG(temperature) AS avg_temp,
       AVG(humidity)    AS avg_hum,
       AVG(co2)         AS avg_co2,
       AVG(pm25)        AS avg_pm25,
       MIN(recorded_at) AS first_record,
       MAX(recorded_at) AS last_record
     FROM sensor_data"
);
if ($statRow = $statRes->fetch_assoc()) {
    $stats = [
        'total_records' => (int)$statRow['total_records'],
        'avg_temperature' => round((float)$statRow['avg_temp'], 2),
        'avg_humidity'    => round((float)$statRow['avg_hum'], 2),
        'avg_co2'         => round((float)$statRow['avg_co2'], 1),
        'avg_pm25'        => round((float)$statRow['avg_pm25'], 2),
        'first_record'    => $statRow['first_record'],
        'last_record'     => $statRow['last_record'],
    ];
}

$conn->close();

// ── 응답 조립 ──
$response = [
    'success'   => true,
    'timestamp' => date('Y-m-d H:i:s'),
    'locations' => $latest,
    'history'   => $history,
    'stats'     => $stats,
];

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

// ── 상태 판단 함수 ──
function getTemperatureStatus(float $v): string {
    if ($v > 30) return 'danger';
    if ($v > 26) return 'warning';
    return 'ok';
}
function getHumidityStatus(float $v): string {
    if ($v > 70) return 'high';
    if ($v < 30) return 'low';
    return 'ok';
}
function getCO2Status(int $v): string {
    if ($v > 1000) return 'danger';
    if ($v > 800)  return 'warning';
    return 'ok';
}
function getPM25Status(float $v): string {
    if ($v > 35) return 'danger';
    if ($v > 15) return 'warning';
    return 'ok';
}
