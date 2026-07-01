<?php
// 서버에서 `php craft/test_calc.php` 로 실행하는 검증 스크립트
$db_host='localhost'; $db_name='budget_manager'; $db_user='budget_user'; $db_pass='budget2026!';
$pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
require_once __DIR__ . '/calc.php';

$fail = 0;
function chk($name, $got, $exp) {
    global $fail;
    $ok = abs($got - $exp) < 0.001;
    if (!$ok) $fail++;
    printf("[%s] %s  got=%s exp=%s\n", $ok?'PASS':'FAIL', $name, $got, $exp);
}

// 모든 재료 단가 1로 설정(코어/키나 제외) → 재료 1개=1
$pdo->exec("UPDATE craft_materials SET unit_price = 1 WHERE is_core = 0 AND category <> '산출물'");
$ctx = craft_load_context($pdo, '목걸이');
$memo = [];

// 진룡왕 코어직접: 코어(0) + 4+14+6+8+5 = 37 재료
$r = craft_cost('진룡왕의 목걸이', $ctx, [], $memo, false);
chk('진룡왕 코어직접 최소', $r['cost'], 37);

// 코어는 0원인지: 코어직접이 달인빛나는직접(달인의빛나는=1 포함 → 1+37=38)보다 싸야 함 → 37 선택
chk('진룡왕 via 코어직접', $r['via'] === '코어직접' ? 1 : 0, 1);

// 빛나는 진룡왕 = 진룡왕(37) + 키나 700000
$memo = [];
$rs = craft_cost('빛나는 진룡왕의 목걸이', $ctx, [], $memo, false);
chk('빛나는 진룡왕 = 37 + 700000', $rs['cost'], 700037);

// 보유 아이템: 현룡왕 보유 시 현룡왕 cost=0
$memo = [];
$ro = craft_cost('현룡왕의 목걸이', $ctx, ['현룡왕의 목걸이'], $memo, false);
chk('현룡왕 보유 → 0', $ro['cost'], 0);

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
