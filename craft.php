<?php
session_start();
date_default_timezone_set('Asia/Seoul');

$db_host='localhost'; $db_name='budget_manager'; $db_user='budget_user'; $db_pass='budget2026!';
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('<div style="color:red;padding:20px;">DB 연결 실패: '.htmlspecialchars($e->getMessage()).'</div>');
}

require_once __DIR__ . '/craft/schema.php';
craft_init_schema($pdo);

$is_admin = isset($_SESSION['sanctuary_admin']) && $_SESSION['sanctuary_admin'] === true;

require_once __DIR__ . '/craft/calc.php';

$acc = $_GET['acc'] ?? '목걸이';
if (!in_array($acc, craft_all_items(), true)) $acc = '목걸이';
$target = craft_target_for($acc);
$owned_sel = $_GET['owned'] ?? '없음';
$owned = ($owned_sel === '없음') ? [] : [$owned_sel];

require_once __DIR__ . '/craft/actions.php';
$ctx = craft_load_context($pdo, $acc);
$useful_tiers = craft_useful_owned_tiers($ctx, $acc);
// 유효하지 않은 보유 티어 파라미터 초기화 (계승이 없는 품목의 stale GET 방어)
if ($owned_sel !== '없음') {
    $valid = false;
    foreach ($useful_tiers as $t) {
        if (mb_strpos($owned_sel, $t) !== false) { $valid = true; break; }
    }
    if (!$valid) { $owned_sel = '없음'; $owned = []; }
}
$routes = craft_enumerate_routes($ctx, $acc, $owned);

require __DIR__ . '/craft/view.php';
