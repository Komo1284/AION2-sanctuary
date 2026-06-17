<?php
// 직접 호출되는 AJAX 엔드포인트용 공용 부트스트랩 (세션 + PDO + 관리자 판별)
// index.php를 거치지 않고 actions/*.php 가 단독 실행될 때 사용한다.

session_start();
date_default_timezone_set('Asia/Seoul');

$db_host = 'localhost';
$db_name = 'budget_manager';
$db_user = 'budget_user';
$db_pass = 'budget2026!';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'db_connect_failed']);
    exit;
}

$is_admin = isset($_SESSION['sanctuary_admin']) && $_SESSION['sanctuary_admin'] === true;

// JSON 응답 후 종료 헬퍼
function json_out(array $payload, int $code = 200): void {
    header('Content-Type: application/json');
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
