<?php
// 일회용 마이그레이션: play_times가 '20,21,22,23'인 신청자에 19시 추가 → '19,20,21,22,23'
// 실행 후 삭제할 것

$db_host = 'localhost';
$db_name = 'budget_manager';
$db_user = 'budget_user';
$db_pass = 'budget2026!';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $pdo->prepare("UPDATE sanctuary_applications SET play_times = '19,20,21,22,23' WHERE play_times = '20,21,22,23'");
    $stmt->execute();
    $count = $stmt->rowCount();

    echo "완료: {$count}건의 신청 데이터가 '20,21,22,23' → '19,20,21,22,23'으로 업데이트되었습니다.\n";
} catch (Exception $e) {
    echo "오류: " . $e->getMessage() . "\n";
}
