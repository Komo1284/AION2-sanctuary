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

// 임시 디버그(다음 태스크에서 제거): seed 확인
if (isset($_GET['debug'])) {
    $rc = (int)$pdo->query("SELECT COUNT(*) FROM craft_recipes")->fetchColumn();
    $mc = (int)$pdo->query("SELECT COUNT(*) FROM craft_materials")->fetchColumn();
    header('Content-Type: text/plain');
    echo "recipes=$rc materials=$mc\n";
    exit;
}
echo "craft init OK";
