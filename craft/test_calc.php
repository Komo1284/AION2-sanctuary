<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
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

// EV(COMBO) 모드: 빛나는승급 키나를 콤보율(0.25)만큼 할인 → 700000*(1-0.25)=525000 → 37+525000=525037
$memo = [];
$ev = craft_cost('빛나는 진룡왕의 목걸이', $ctx, [], $memo, true);
chk('EV 빛나는 진룡왕 = 37 + 525000', $ev['cost'], 525037);
chk('EV cost < 확정 cost', $ev['cost'] < $rs['cost'] ? 1 : 0, 1);

// 보유 아이템: 현룡왕 보유 시 현룡왕 cost=0
$memo = [];
$ro = craft_cost('현룡왕의 목걸이', $ctx, ['현룡왕의 목걸이'], $memo, false);
chk('현룡왕 보유 → 0', $ro['cost'], 0);

$ctx2 = craft_load_context($pdo, '목걸이');
$routes = craft_enumerate_routes($ctx2, '응룡왕의 목걸이', []);
chk('루트 최소 2개 이상', count($routes) >= 2 ? 1 : 0, 1);
chk('루트는 cost 오름차순', ($routes[0]['cost_fixed'] <= $routes[count($routes)-1]['cost_fixed']) ? 1 : 0, 1);
$hasDirect = false; foreach ($routes as $r) if (mb_strpos($r['label'],'직접제작')!==false) $hasDirect=true;
chk('직접제작 루트 존재', $hasDirect ? 1 : 0, 1);

// 순환 참조 종료 검증 (DB 무관, 인메모리 ctx)
$cyc = ['price'=>['키나(통합)'=>0], 'core'=>[], 'recipes'=>[
  'A'=>[['type'=>'x','kina'=>0,'combo'=>0.25,'estimated'=>0,'inputs'=>[['B',1]]]],
  'B'=>[['type'=>'x','kina'=>0,'combo'=>0.25,'estimated'=>0,'inputs'=>[['A',1]]]],
]];
$mc=[]; $rc = craft_cost('A', $cyc, [], $mc, false);
chk('순환참조 종료(무한루프 없음)', is_numeric($rc['cost']) ? 1 : 0, 1);
$bd=[]; $mm=$mc; craft_breakdown('A', $cyc, [], false, 1.0, $bd, $mm);
chk('순환 breakdown 종료', 1, 1);

// 교환(대체가) 규칙 검증: 특정 가격 세팅 후 실질가격 min 확인
$pdo->prepare("UPDATE craft_materials SET unit_price=? WHERE name=?")->execute([500, '달인의 빛나는 루비 목걸이']);
$pdo->prepare("UPDATE craft_materials SET unit_price=? WHERE name=?")->execute([300, '달인의 빛나는 다이아몬드 귀걸이']);
$pdo->prepare("UPDATE craft_materials SET unit_price=? WHERE name=?")->execute([700, '달인의 빛나는 사파이어 반지']);
$pdo->prepare("UPDATE craft_materials SET unit_price=? WHERE name=?")->execute([50, '찬란한 루비 원석']);
$pdo->prepare("UPDATE craft_materials SET unit_price=? WHERE name=?")->execute([20, '찬란한 오드']);
$sctx = craft_load_context($pdo, '목걸이');
chk('제작 계승석 = 달인빛나는 3종 최저가(300)', $sctx['price']['제작 계승석: 장신구'], 300);
chk('찬란한 루비 원석 = min(50, 오드20)=20', $sctx['price']['찬란한 루비 원석'], 20);
$sm = [];
chk('계승석(영웅) 무료 = 0', craft_cost('계승석: 장신구 (영웅)', $sctx, [], $sm, false)['cost'], 0);

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
