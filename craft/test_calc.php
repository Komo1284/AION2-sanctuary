<?php
if (php_sapi_name() !== 'cli') { http_response_code(404); exit; }
// 서버에서 `php craft/test_calc.php` 로 실행하는 검증 스크립트
$db_host='localhost'; $db_name='budget_manager'; $db_user='budget_user'; $db_pass='budget2026!';
$pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
require_once __DIR__ . '/calc.php';
require_once __DIR__ . '/items.php';

// ⚠ 가격은 사용자 데이터다. 이 스크립트는 테스트를 위해 가격을 임시 변경하므로,
//   종료 시(정상/예외/exit 모두) 원래 값으로 반드시 원복한다.
$__price_snapshot = $pdo->query("SELECT id, unit_price, updated_at, updated_ip FROM craft_materials")->fetchAll();
register_shutdown_function(function () use ($pdo, $__price_snapshot) {
    $u = $pdo->prepare("UPDATE craft_materials SET unit_price=?, updated_at=?, updated_ip=? WHERE id=?");
    foreach ($__price_snapshot as $s) { $u->execute([$s['unit_price'], $s['updated_at'], $s['updated_ip'], $s['id']]); }
});

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
$routes = craft_enumerate_routes($ctx2, '목걸이', []);
chk('루트 최소 2개 이상', count($routes) >= 2 ? 1 : 0, 1);
chk('목걸이 루트 3개 유지(장신구는 계승 존재)', count($routes) === 3 ? 1 : 0, 1);
chk('루트는 cost 오름차순', ($routes[0]['cost_fixed'] <= $routes[count($routes)-1]['cost_fixed']) ? 1 : 0, 1);
$hasDirect = false; foreach ($routes as $r) if (mb_strpos($r['label'],'직접제작')!==false) $hasDirect=true;
chk('직접제작 루트 존재', $hasDirect ? 1 : 0, 1);

// 보유 아이템부터 계승: 빛나는 보유가 보유없음보다 저렴(체인 단축) + 보유 루트 플래그
$ownCost = function($rs){ foreach($rs as $r) if(!empty($r['is_owned_route'])) return $r['cost_fixed']; return -1; };
$rNone  = craft_enumerate_routes($ctx2, '목걸이', []);
$rShine = craft_enumerate_routes($ctx2, '목걸이', ['빛나는 현룡왕의 목걸이']);
chk('보유 루트 플래그 존재', ($ownCost($rShine) >= 0) ? 1 : 0, 1);
chk('빛나는 현룡왕 보유 < 보유없음(체인 단축)', ($ownCost($rShine) < $ownCost($rNone)) ? 1 : 0, 1);

// 품목 헬퍼 + 카테고리 주도 계승석 검증
chk('대검은 무기군', craft_item_group('대검') === '무기' ? 1 : 0, 1);
chk('대검 목표 = 창룡왕의 대검', craft_target_for('대검') === '창룡왕의 대검' ? 1 : 0, 1);
chk('투구 목표 = 응룡왕의 투구', craft_target_for('투구') === '응룡왕의 투구' ? 1 : 0, 1);
chk('목걸이 보유상한 = 현룡왕', craft_owned_max_tier('목걸이') === '현룡왕' ? 1 : 0, 1);
chk('대검 보유상한 = 응룡왕', craft_owned_max_tier('대검') === '응룡왕' ? 1 : 0, 1);

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
$pdo->prepare("UPDATE craft_materials SET unit_price=? WHERE name=?")->execute([50, '찬란한 이그드라실 나무']);
$sctx = craft_load_context($pdo, '목걸이');
chk('제작 계승석 = 달인빛나는 3종 최저가(300)', $sctx['price']['제작 계승석: 장신구'], 300);
chk('찬란한 루비 원석 = min(50, 오드20)=20', $sctx['price']['찬란한 루비 원석'], 20);
chk('찬란한 이그드라실 나무 = 오드가(20)', $sctx['price']['찬란한 이그드라실 나무'], 20);
$sm = [];
chk('계승석(영웅) 무료 = 0', craft_cost('계승석: 장신구 (영웅)', $sctx, [], $sm, false)['cost'], 0);

// 무기(대검): 창룡왕 목표 루트 검증
$wctx = craft_load_context($pdo, '대검');
$wroutes = craft_enumerate_routes($wctx, '대검', []);
chk('대검 루트 2개 이상', count($wroutes) >= 2 ? 1 : 0, 1);
$wLabels = implode('|', array_column($wroutes, 'label'));
chk('대검 창룡왕 라벨', mb_strpos($wLabels, '창룡왕') !== false ? 1 : 0, 1);
$wbd = null; foreach ($wroutes as $r) if (!empty($r['is_owned_route'])) $wbd = $r['breakdown'];
chk('대검 보유루트에 폭주한 공포 재료 포함', ($wbd && isset($wbd['폭주한 공포의 사념'])) ? 1 : 0, 1);

// 대검 응룡왕 보유 시 직접제작보다 저렴한지 확인
$dctx = craft_load_context($pdo,'대검');
$dr = craft_enumerate_routes($dctx,'대검',['응룡왕의 대검']);
$d1 = null; $dO = null;
foreach($dr as $r) {
    if(!empty($r['is_owned_route'])) $dO=$r['cost_fixed'];
    elseif($d1===null) $d1=$r['cost_fixed'];
}
chk('대검 응룡왕 보유 → 직접제작보다 저렴', ($dO!==null && $d1!==null && $dO < $d1)?1:0, 1);

// 마법서(구 법서) 품목명 정합: items.php ↔ seed accessory명
$mctx = craft_load_context($pdo, '마법서');
$mroutes = craft_enumerate_routes($mctx, '마법서', []);
chk('마법서 루트 2개 이상', count($mroutes) >= 2 ? 1 : 0, 1);
chk('법서는 품목 아님(마법서로 대체)', in_array('법서', craft_all_items(), true) ? 0 : 1, 1);

// 방어구(투구): 응룡왕 목표 + 계승석 대체가(방어구군)
$actx = craft_load_context($pdo, '투구');
$aroutes = craft_enumerate_routes($actx, '투구', []);
chk('투구 루트 1개(응룡왕 계승 없음 → 중복 제거)', count($aroutes) === 1 ? 1 : 0, 1);
chk('투구 라벨은 응룡왕(창룡왕 아님)', mb_strpos(implode('|', array_column($aroutes,'label')), '창룡왕') === false ? 1 : 0, 1);
chk('제작 계승석: 방어구 = 중간아이템-방어구 최저가(1)', $actx['price']['제작 계승석: 방어구'], 1);

// 방어구 실제 아이템명 정합 (상의→흉갑, 하의→각반, 신발→장화)
foreach (['흉갑','각반','장화'] as $slot) {
    $sctx2 = craft_load_context($pdo, $slot);
    $sr = craft_enumerate_routes($sctx2, $slot, []);
    chk("{$slot} 루트 1개(계승 없음)", count($sr) === 1 ? 1 : 0, 1);
}
chk('상의는 품목 아님(흉갑으로 대체)', in_array('상의', craft_all_items(), true) ? 0 : 1, 1);

// useful tiers 함수 검증
chk('방어구 useful tiers 비어있음', count(craft_useful_owned_tiers($actx,'투구'))===0?1:0,1);
chk('무기 useful tiers = [응룡왕]', craft_useful_owned_tiers($dctx??craft_load_context($pdo,'대검'),'대검')===['응룡왕']?1:0,1);
chk('장신구 useful tiers 5개', count(craft_useful_owned_tiers($ctx2,'목걸이'))===5?1:0,1);

// ── plan_compute 픽스처 테스트 ──────────────────────────────────────
// plan_api.php 함수 정의만 로드 (HTTP 핸들러 건너뜀)
define('PLAN_API_TEST_ONLY', true);
require_once __DIR__ . '/plan_api.php';

// 단가 1 세팅은 이미 위에서 완료됨 (스냅샷 원복은 shutdown에서 처리)
// 픽스처: 슬롯 7개 (slotPosName 은 실제 API에서 확인된 이름 사용)
$fixture = [
    ['slotPosName' => 'MainHand', 'name' => '응룡왕의 전곤'],
    ['slotPosName' => 'SubHand',  'name' => '응룡왕의 가더'],
    ['slotPosName' => 'Earring1', 'name' => '응룡왕의 귀걸이'],
    ['slotPosName' => 'Necklace', 'name' => '천룡왕의 목걸이'],
    ['slotPosName' => 'Earring2', 'name' => '진룡왕의 귀걸이'],
    ['slotPosName' => 'Ring1',    'name' => '진룡왕의 반지'],
    ['slotPosName' => 'Ring2',    'name' => '붉은 강옥의 반지'],
];

$plan = plan_compute($pdo, $fixture);

// 결과 인덱스 헬퍼
$bySlot = [];
foreach ($plan['slots'] as $s) $bySlot[$s['slot']] = $s;

// 완료 3슬롯 cost 0
chk('plan: 무기(전곤) 완료 cost=0',     $bySlot['무기']['cost'],    0);
chk('plan: 가더 완료 cost=0',           $bySlot['가더']['cost'],    0);
chk('plan: 귀걸이1(응룡왕) 완료 cost=0', $bySlot['귀걸이1']['cost'], 0);
chk('plan: 완료 status=완료(무기)',  $bySlot['무기']['status']  === '완료' ? 1 : 0, 1);
chk('plan: 완료 status=완료(가더)',  $bySlot['가더']['status']  === '완료' ? 1 : 0, 1);
chk('plan: 완료 status=완료(귀걸이1)', $bySlot['귀걸이1']['status'] === '완료' ? 1 : 0, 1);

// 목걸이: 천룡왕 보유 cost ≤ 신규(보유없음) cost (owned는 cost를 올리지 않음)
// ※ price=1 세계에서 kina 비용이 크므로 달인빛나는직접이 최적 → 양쪽 같을 수 있음
$memoNeck = [];
$ctxNeck  = craft_load_context($pdo, '목걸이');
$costNeckFresh = craft_cost('응룡왕의 목걸이', $ctxNeck, [], $memoNeck, false)['cost'];
chk('plan: 목걸이 천룡왕보유 cost>0', $bySlot['목걸이']['cost'] > 0 ? 1 : 0, 1);
chk('plan: 목걸이 천룡왕보유 <= 신규', ($bySlot['목걸이']['cost'] <= $costNeckFresh) ? 1 : 0, 1);
chk('plan: 목걸이 status=계승 제작', $bySlot['목걸이']['status'] === '계승 제작' ? 1 : 0, 1);

// 반지2 status '신규 제작' (붉은 강옥 = 제작 티어 아님)
chk('plan: 반지2 status=신규 제작', $bySlot['반지2']['status'] === '신규 제작' ? 1 : 0, 1);

// total = Σ slot costs
$calcTotal = 0.0;
foreach ($plan['slots'] as $s) $calcTotal += $s['cost'];
chk('plan: total = Σ slot costs', abs($plan['total'] - $calcTotal) < 0.001 ? 1 : 0, 1);

// rage_totals: ≥1 분노 재료 need>0
$rageOk = false;
foreach ($plan['rage_totals'] as $rn => $rv) {
    if ($rv['need'] > 0) { $rageOk = true; break; }
}
chk('plan: rage_totals 분노 need>0 존재', $rageOk ? 1 : 0, 1);

// ok 플래그
chk('plan: ok=true', $plan['ok'] ? 1 : 0, 1);

// 빛나는 보유 < 기본 보유: 빛나는 진룡왕 귀걸이 보유 시 계승 체인에서 700000 키나 절약.
// plan_compute는 달인빛나는직접(수백~수천)을 선택하므로 owned 차이가 slot cost에 미반영됨.
// 계승 강제 루트를 비교하는 craft_enumerate_routes 의 is_owned_route 로 검증.
$eCtx   = craft_load_context($pdo, '귀걸이');
$rShine = craft_enumerate_routes($eCtx, '귀걸이', ['빛나는 진룡왕의 귀걸이']);
$rBasic = craft_enumerate_routes($eCtx, '귀걸이', ['진룡왕의 귀걸이']);
$sOwn   = -1; foreach ($rShine as $r) if (!empty($r['is_owned_route'])) $sOwn = $r['cost_fixed'];
$bOwn   = -1; foreach ($rBasic as $r) if (!empty($r['is_owned_route'])) $bOwn = $r['cost_fixed'];
chk('귀걸이: 빛나는 진룡왕 보유 < 기본 보유(계승 체인 단축)', ($sOwn >= 0 && $bOwn >= 0 && $sOwn < $bOwn) ? 1 : 0, 1);
chk('귀걸이: 빛나는-기본 차이 = 700000(진룡왕 승급 키나)', abs($bOwn - $sOwn - 700000) < 0.001 ? 1 : 0, 1);

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
