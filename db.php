<?php
define('DB_HOST', 'localhost');
define('DB_USER', 'library_user');
define('DB_PASS', 'library_pass123');
define('DB_NAME', 'library_db');

$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

if ($conn->connect_error) {
    die('<div style="color:red;padding:20px;">DB 연결 실패: ' . htmlspecialchars($conn->connect_error) . '<br>setup.sh를 먼저 실행하세요.</div>');
}
$conn->set_charset('utf8mb4');
