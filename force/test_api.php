<?php
// 서버에서 `php force/test_api.php` 로 실행하는 스모크 테스트.
// 만드는 데이터는 전부 zzTest_ 접두사를 쓰고 스스로 지운다.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/api.php';

$T_pass = 0;
$T_fail = 0;

function t_section($name) {
    echo "\n\033[1m== $name ==\033[0m\n";
}

function t_ok($cond, $label) {
    global $T_pass, $T_fail;
    if ($cond) { $T_pass++; echo "  \033[32m✓\033[0m $label\n"; }
    else       { $T_fail++; echo "  \033[31m✗\033[0m $label\n"; }
}

function t_eq($actual, $expected, $label) {
    $same = ($actual === $expected);
    t_ok($same, $same ? $label
        : $label . ' — 기대 ' . var_export($expected, true) . ', 실제 ' . var_export($actual, true));
}

function t_summary() {
    global $T_pass, $T_fail;
    echo "\n" . ($T_fail === 0 ? "\033[32m전체 통과" : "\033[31m실패 있음")
       . "\033[0m — 통과 $T_pass / 실패 $T_fail\n";
    return $T_fail === 0 ? 0 : 1;
}

$pdo = fc_pdo();

t_section('스키마');
fc_init_schema($pdo);
fc_init_schema($pdo); // 멱등성: 두 번 돌려도 예외가 없어야 한다
t_ok(true, 'fc_init_schema를 두 번 실행해도 예외가 없다');

$tables = ['fc_players', 'fc_characters', 'fc_raids', 'fc_forces', 'fc_slots', 'fc_meta'];
foreach ($tables as $tbl) {
    $found = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tbl))->fetch();
    t_ok($found !== false, "$tbl 테이블이 존재한다");
}

$rev = $pdo->query("SELECT v FROM fc_meta WHERE k = 'revision'")->fetchColumn();
t_ok($rev !== false, 'fc_meta에 revision 행이 초기화되어 있다');

fc_cleanup_test_data($pdo);

t_section('인원 등록');

$rev0 = fc_revision($pdo);
$pid  = fc_create_player($pdo, 'zzTest_본캐', ['zzTest_부캐A', 'zzTest_부캐B']);
t_ok($pid > 0, '플레이어가 생성되고 id를 돌려준다');
t_ok(fc_revision($pdo) > $rev0, '쓰기 후 revision이 증가한다');

$chars = $pdo->query("SELECT char_name, is_main, sort_order FROM fc_characters
                      WHERE player_id = $pid ORDER BY sort_order")->fetchAll();
t_eq(count($chars), 3, '본캐 1 + 부캐 2 = 캐릭터 3개가 등록된다');
t_eq($chars[0]['char_name'], 'zzTest_본캐', '첫 행이 본캐다');
t_eq((int)$chars[0]['is_main'], 1, '본캐의 is_main이 1이다');
t_eq((int)$chars[1]['is_main'], 0, '부캐의 is_main이 0이다');
t_eq((int)$chars[2]['is_main'], 0, '두 번째 부캐의 is_main도 0이다');

t_section('캐릭명 중복 거부');

$dup_rejected = false;
try {
    fc_create_player($pdo, 'zzTest_부캐A');
} catch (RuntimeException $e) {
    $dup_rejected = (strpos($e->getMessage(), 'duplicate_name') !== false);
}
t_ok($dup_rejected, '이미 있는 캐릭명으로 등록하면 duplicate_name 예외가 난다');

$leftover = (int)$pdo->query("SELECT COUNT(*) FROM fc_players")->fetchColumn();
$pdo->query("SELECT 1"); // no-op
t_ok($leftover >= 1, '중복 실패가 다른 플레이어를 지우지 않는다');

t_section('임시 캐릭터');

$always_lookup = function ($name) {
    return ['class' => '궁성', 'atul' => 39000, 'item_level' => 3400];
};

$phId = fc_add_character($pdo, $pid, 'zzTest_부캐1', $always_lookup, true);
$ph = $pdo->query("SELECT char_class, atul_score, is_placeholder FROM fc_characters WHERE id = $phId")->fetch();
t_eq((int)$ph['is_placeholder'], 1, '임시 캐릭터는 is_placeholder가 1이다');
t_eq($ph['char_class'], '', '임시 캐릭터는 lookup을 건너뛰어 직업이 비어 있다');
t_ok($ph['atul_score'] === null, '임시 캐릭터는 아툴점수가 NULL이다');

// 같은 임시명을 다른 플레이어 밑에 등록할 수 있어야 한다 — "부캐1"은 누구나 쓴다
$pid2ph = fc_create_player($pdo, 'zzTest_다른본캐');
$phId2  = fc_add_character($pdo, $pid2ph, 'zzTest_부캐1', null, true);
t_ok($phId2 > 0, '같은 임시명을 다른 플레이어 밑에 등록할 수 있다');

$same_player_dup = false;
try {
    fc_add_character($pdo, $pid, 'zzTest_부캐1', null, true);
} catch (RuntimeException $e) {
    $same_player_dup = (strpos($e->getMessage(), 'duplicate_name') !== false);
}
t_ok($same_player_dup, '같은 플레이어 밑에 같은 임시명은 거부된다');

// 실제 캐릭명은 임시명과 달리 전역에서 유일해야 한다
$real_vs_ph = false;
try {
    fc_add_character($pdo, $pid2ph, 'zzTest_부캐A', null, false);
} catch (RuntimeException $e) {
    $real_vs_ph = (strpos($e->getMessage(), 'duplicate_name') !== false);
}
t_ok($real_vs_ph, '실제 캐릭명은 다른 플레이어 밑이어도 중복이 거부된다');

t_section('임시 → 실제 전환');

fc_update_character($pdo, $phId, ['name' => 'zzTest_확정캐릭', 'is_placeholder' => 0,
                                  'class' => '마도성', 'atul' => 40100]);
$promoted = $pdo->query("SELECT char_name, char_class, atul_score, is_placeholder
                         FROM fc_characters WHERE id = $phId")->fetch();
t_eq($promoted['char_name'], 'zzTest_확정캐릭', '전환하면 이름이 바뀐다');
t_eq((int)$promoted['is_placeholder'], 0, '전환하면 is_placeholder가 0이 된다');
t_eq($promoted['char_class'], '마도성', '전환하면 직업이 채워진다');
t_eq((int)$promoted['atul_score'], 40100, '전환하면 아툴점수가 채워진다');

fc_delete_player($pdo, $pid2ph);

// 리그레션: name을 안 바꾸고 is_placeholder만 바꾸는 승격도 전역 유일성 검사를 지나야 한다.
// (name 분기 안에서만 검사하면 이 경로가 통째로 빠져나가 같은 이름의 실제 캐릭터가 중복 생긴다)
$collideA = fc_create_player($pdo, 'zzTest_충돌A');
$phCollideId = fc_add_character($pdo, $collideA, 'zzTest_이름충돌', null, true);
$collideB = fc_create_player($pdo, 'zzTest_충돌B', ['zzTest_이름충돌']);

$promote_noname_dup = false;
try {
    fc_update_character($pdo, $phCollideId, ['is_placeholder' => 0]);
} catch (RuntimeException $e) {
    $promote_noname_dup = (strpos($e->getMessage(), 'duplicate_name') !== false);
}
t_ok($promote_noname_dup, '이름을 바꾸지 않는 승격도 같은 이름의 실제 캐릭터가 있으면 duplicate_name 예외가 난다');

$still_ph = (int)$pdo->query("SELECT is_placeholder FROM fc_characters WHERE id = $phCollideId")->fetchColumn();
t_eq($still_ph, 1, '중복 승격이 거부되면 is_placeholder는 그대로 임시로 남는다');

fc_delete_player($pdo, $collideB);

fc_update_character($pdo, $phCollideId, ['is_placeholder' => 0]);
$noname_promoted = $pdo->query("SELECT char_name, is_placeholder FROM fc_characters WHERE id = $phCollideId")->fetch();
t_eq($noname_promoted['char_name'], 'zzTest_이름충돌', '충돌이 없으면 이름 변경 없는 승격도 이름이 그대로 유지된다');
t_eq((int)$noname_promoted['is_placeholder'], 0, '충돌이 없으면 이름 변경 없는 승격이 성공한다');

fc_delete_player($pdo, $collideA);

t_section('아툴 조회 주입');

$fake_lookup = function ($name) {
    return ['class' => '검성', 'atul' => 41200, 'item_level' => 3520];
};
$cid = fc_add_character($pdo, $pid, 'zzTest_부캐C', $fake_lookup);
$row = $pdo->query("SELECT char_class, atul_score, item_level FROM fc_characters WHERE id = $cid")->fetch();
t_eq($row['char_class'], '검성', 'lookup 결과로 직업이 채워진다');
t_eq((int)$row['atul_score'], 41200, 'lookup 결과로 아툴점수가 채워진다');
t_eq((int)$row['item_level'], 3520, 'lookup 결과로 아이템레벨이 채워진다');

$null_lookup = function ($name) { return null; };
$cid2 = fc_add_character($pdo, $pid, 'zzTest_부캐D', $null_lookup);
$row2 = $pdo->query("SELECT char_class, atul_score FROM fc_characters WHERE id = $cid2")->fetch();
t_eq($row2['char_class'], '', 'lookup 실패해도 등록은 되고 직업은 빈 값이다');
t_ok($row2['atul_score'] === null, 'lookup 실패 시 아툴점수는 NULL이다');

t_section('캐릭터 수정과 삭제');

fc_update_character($pdo, $cid2, ['class' => '치유성', 'atul' => 38000]);
$row3 = $pdo->query("SELECT char_class, atul_score FROM fc_characters WHERE id = $cid2")->fetch();
t_eq($row3['char_class'], '치유성', '직업을 손으로 고칠 수 있다');
t_eq((int)$row3['atul_score'], 38000, '아툴점수를 손으로 고칠 수 있다');

fc_delete_character($pdo, $cid2);
$gone = $pdo->query("SELECT COUNT(*) FROM fc_characters WHERE id = $cid2")->fetchColumn();
t_eq((int)$gone, 0, '캐릭터가 삭제된다');

fc_delete_player($pdo, $pid);
$left = $pdo->query("SELECT COUNT(*) FROM fc_characters WHERE player_id = $pid")->fetchColumn();
t_eq((int)$left, 0, '플레이어를 지우면 소속 캐릭터가 전부 사라진다');

t_section('레이드와 포스');

$rid = fc_create_raid($pdo, 'zzTest_루드라');
t_ok($rid > 0, '레이드가 생성된다');

$fid1 = fc_create_force($pdo, $rid, '토', '19:30', '남는자리 새싹');
$fid2 = fc_create_force($pdo, $rid, '토', '19:40', '');
t_ok($fid1 > 0 && $fid2 > 0, '포스가 두 개 생성된다');

$nos = $pdo->query("SELECT force_no FROM fc_forces WHERE raid_id = $rid ORDER BY force_no")
           ->fetchAll(PDO::FETCH_COLUMN);
t_eq(array_map('intval', $nos), [1, 2], '포스 번호가 1, 2로 자동 부여된다');

$slotCount = (int)$pdo->query("SELECT COUNT(*) FROM fc_slots WHERE force_id = $fid1")->fetchColumn();
t_eq($slotCount, 10, '포스 생성 시 빈 슬롯이 정확히 10행 생긴다');

$shape = $pdo->query("SELECT party_no, COUNT(*) c FROM fc_slots WHERE force_id = $fid1
                      GROUP BY party_no ORDER BY party_no")->fetchAll();
t_eq(count($shape), 2, '슬롯이 두 파티로 나뉜다');
t_eq((int)$shape[0]['c'], 5, '1파티가 5슬롯이다');
t_eq((int)$shape[1]['c'], 5, '2파티가 5슬롯이다');

$empty = (int)$pdo->query("SELECT COUNT(*) FROM fc_slots WHERE force_id = $fid1 AND character_id IS NULL")
                  ->fetchColumn();
t_eq($empty, 10, '새 포스의 슬롯은 전부 비어 있다');

$f1 = $pdo->query("SELECT day_of_week, start_time, memo FROM fc_forces WHERE id = $fid1")->fetch();
t_eq($f1['day_of_week'], '토', '요일이 저장된다');
t_eq($f1['start_time'], '19:30', '시각이 저장된다');
t_eq($f1['memo'], '남는자리 새싹', '메모가 저장된다');

fc_update_force($pdo, $fid1, ['day_of_week' => '일', 'start_time' => '20:00']);
$f1b = $pdo->query("SELECT day_of_week, start_time FROM fc_forces WHERE id = $fid1")->fetch();
t_eq($f1b['day_of_week'], '일', '요일을 수정할 수 있다');
t_eq($f1b['start_time'], '20:00', '시각을 수정할 수 있다');

t_section('포스 삭제 시 번호를 다시 매기지 않는다');

fc_delete_force($pdo, $fid1);
$slotsGone = (int)$pdo->query("SELECT COUNT(*) FROM fc_slots WHERE force_id = $fid1")->fetchColumn();
t_eq($slotsGone, 0, '포스를 지우면 슬롯 10행도 함께 사라진다');

$remain = $pdo->query("SELECT force_no FROM fc_forces WHERE raid_id = $rid")->fetchAll(PDO::FETCH_COLUMN);
t_eq(array_map('intval', $remain), [2], '남은 포스의 번호는 2 그대로다 (재부여 없음)');

$fid3 = fc_create_force($pdo, $rid, '월', '21:00', '');
$no3 = (int)$pdo->query("SELECT force_no FROM fc_forces WHERE id = $fid3")->fetchColumn();
t_eq($no3, 3, '새 포스는 MAX+1인 3번을 받는다');

t_section('레이드 삭제');

fc_update_raid($pdo, $rid, ['memo' => '토요일 고정']);
$memo = $pdo->query("SELECT memo FROM fc_raids WHERE id = $rid")->fetchColumn();
t_eq($memo, '토요일 고정', '레이드 메모를 수정할 수 있다');

fc_delete_raid($pdo, $rid);
t_eq((int)$pdo->query("SELECT COUNT(*) FROM fc_raids WHERE id = $rid")->fetchColumn(), 0, '레이드가 삭제된다');
t_eq((int)$pdo->query("SELECT COUNT(*) FROM fc_forces WHERE raid_id = $rid")->fetchColumn(), 0, '소속 포스가 함께 삭제된다');
t_eq((int)$pdo->query("SELECT COUNT(*) FROM fc_slots WHERE force_id IN ($fid2, $fid3)")->fetchColumn(), 0, '소속 포스의 슬롯도 함께 삭제된다');

t_section('배치와 자리 교체');

$pid2 = fc_create_player($pdo, 'zzTest_대섬', ['zzTest_대섬부캐']);
$cids = $pdo->query("SELECT id FROM fc_characters WHERE player_id = $pid2 ORDER BY sort_order")
            ->fetchAll(PDO::FETCH_COLUMN);
$cMain = (int)$cids[0];
$cSub  = (int)$cids[1];

$rid2  = fc_create_raid($pdo, 'zzTest_침식');
$fidA  = fc_create_force($pdo, $rid2, '토', '19:30', '');
$slots = fc_slot_ids($pdo, $fidA);
t_eq(count($slots), 10, 'fc_slot_ids가 슬롯 10개를 돌려준다');
t_eq((int)$slots[0]['party_no'], 1, '첫 슬롯은 1파티다');
t_eq((int)$slots[0]['slot_no'], 1, '첫 슬롯은 1번 칸이다');
t_eq((int)$slots[5]['party_no'], 2, '여섯 번째 슬롯부터 2파티다');

fc_assign_slot($pdo, (int)$slots[0]['id'], $cMain);
$got = $pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[0]['id'])->fetchColumn();
t_eq((int)$got, $cMain, '배치 후 조회하면 캐릭터가 남아 있다');

fc_assign_slot($pdo, (int)$slots[5]['id'], $cSub);
fc_swap_slots($pdo, (int)$slots[0]['id'], (int)$slots[5]['id']);
$a = (int)$pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[0]['id'])->fetchColumn();
$b = (int)$pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[5]['id'])->fetchColumn();
t_eq($a, $cSub, 'swap 후 A슬롯에 B의 캐릭터가 있다');
t_eq($b, $cMain, 'swap 후 B슬롯에 A의 캐릭터가 있다');

fc_swap_slots($pdo, (int)$slots[1]['id'], (int)$slots[0]['id']);
$emptyNow = $pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[0]['id'])->fetchColumn();
$movedTo  = (int)$pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[1]['id'])->fetchColumn();
t_ok($emptyNow === null, '빈 슬롯과 swap하면 원래 자리가 빈다');
t_eq($movedTo, $cSub, '빈 슬롯과 swap하면 캐릭터가 그 자리로 옮겨간다');

fc_assign_slot($pdo, (int)$slots[1]['id'], null);
$cleared = $pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[1]['id'])->fetchColumn();
t_ok($cleared === null, 'character_id에 null을 넣으면 슬롯이 비워진다');

t_section('캐릭터 삭제 시 참조 정리');

fc_assign_slot($pdo, (int)$slots[2]['id'], $cMain);
fc_delete_character($pdo, $cMain);
$after = $pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[2]['id'])->fetchColumn();
t_ok($after === null, '캐릭터를 지우면 배치된 슬롯이 비워진다');
t_eq((int)$pdo->query("SELECT COUNT(*) FROM fc_slots WHERE force_id = $fidA")->fetchColumn(), 10,
     '캐릭터를 지워도 슬롯 행 10개는 그대로 남는다');

t_section('중복 감지 (순수 함수)');

$fForces = [
    ['id' => 100, 'raid_id' => 1], ['id' => 101, 'raid_id' => 1], ['id' => 102, 'raid_id' => 2],
];
$fSlots = [
    ['id' => 1, 'force_id' => 100, 'character_id' => 7],
    ['id' => 2, 'force_id' => 101, 'character_id' => 7],   // 같은 레이드 1 안에서 중복
    ['id' => 3, 'force_id' => 100, 'character_id' => 8],
    ['id' => 4, 'force_id' => 102, 'character_id' => 7],   // 레이드 2 — 중복 아님
    ['id' => 5, 'force_id' => 100, 'character_id' => null],
];
$dups = fc_duplicates($fForces, $fSlots);
t_ok(isset($dups['1']), '중복이 있는 레이드 1의 키가 존재한다');
t_eq(count($dups['1']), 1, '레이드 1의 중복 캐릭터는 1명이다');
t_eq((int)$dups['1'][0]['character_id'], 7, '중복된 캐릭터는 7번이다');
t_eq(array_map('intval', $dups['1'][0]['force_ids']), [100, 101], '중복된 포스 id 두 개가 잡힌다');
t_ok(!isset($dups['2']), '레이드가 다르면 중복으로 잡지 않는다');

t_section('state 스냅샷');

$pid3 = fc_create_player($pdo, 'zzTest_상태본캐', ['zzTest_상태부캐']);
$rid3 = fc_create_raid($pdo, 'zzTest_상태레이드');
$fid4 = fc_create_force($pdo, $rid3, '수', '22:00', '메모테스트');
$sl   = fc_slot_ids($pdo, $fid4);
$cid3 = (int)$pdo->query("SELECT id FROM fc_characters WHERE char_name = 'zzTest_상태본캐'")->fetchColumn();
fc_assign_slot($pdo, (int)$sl[0]['id'], $cid3);
fc_assign_slot($pdo, (int)$sl[6]['id'], $cid3);   // 같은 레이드 같은 포스 안 중복

$state = fc_state($pdo);
t_ok(is_int($state['revision']), 'state에 revision이 정수로 들어 있다');

$names = array_column($state['characters'], 'name');
t_ok(in_array('zzTest_상태본캐', $names, true), 'state에 캐릭터가 들어 있다');

$myChar = null;
foreach ($state['characters'] as $c) { if ($c['name'] === 'zzTest_상태본캐') { $myChar = $c; break; } }
t_eq((int)$myChar['is_main'], 1, 'state의 캐릭터에 is_main이 담긴다');
t_ok(array_key_exists('atul', $myChar), 'state의 캐릭터 키는 atul이다 (atul_score가 아님)');

$myForce = null;
foreach ($state['forces'] as $f) { if ((int)$f['id'] === $fid4) { $myForce = $f; break; } }
t_eq($myForce['day_of_week'], '수', 'state에 포스 요일이 담긴다');
t_eq((int)$myForce['force_no'], 1, 'state에 포스 번호가 담긴다');

$mySlots = array_values(array_filter($state['slots'], function ($s) use ($fid4) {
    return (int)$s['force_id'] === $fid4;
}));
t_eq(count($mySlots), 10, 'state에 그 포스의 슬롯 10개가 담긴다');

t_ok(isset($state['duplicates'][(string)$rid3]), '같은 포스 안 중복도 duplicates에 잡힌다');
t_eq((int)$state['duplicates'][(string)$rid3][0]['character_id'], $cid3, '중복된 캐릭터 id가 맞다');

t_section('API 디스패처');

$noLookup = function ($name) { return null; };
$call = function ($req) use ($pdo, $noLookup) {
    return fc_api_dispatch($pdo, $req, $noLookup);
};

$res = $call(['action' => 'player.create', 'main_name' => 'zzTest_API본캐', 'subs' => ['zzTest_API부캐']]);
t_ok(isset($res['player_id']) && $res['player_id'] > 0, 'player.create가 player_id를 돌려준다');
$apiPid = (int)$res['player_id'];

$res2 = $call(['action' => 'raid.create', 'name' => 'zzTest_API레이드']);
$apiRid = (int)$res2['raid_id'];
t_ok($apiRid > 0, 'raid.create가 raid_id를 돌려준다');

$res3 = $call(['action' => 'force.create', 'raid_id' => $apiRid,
               'day_of_week' => '금', 'start_time' => '21:30', 'memo' => '']);
$apiFid = (int)$res3['force_id'];
t_ok($apiFid > 0, 'force.create가 force_id를 돌려준다');

$st8 = $call(['action' => 'state']);
t_ok(isset($st8['revision']) && isset($st8['slots']), 'state가 스냅샷을 돌려준다');

$apiSlots = array_values(array_filter($st8['slots'], function ($s) use ($apiFid) {
    return (int)$s['force_id'] === $apiFid;
}));
t_eq(count($apiSlots), 10, 'state의 슬롯에 새 포스 10칸이 보인다');

$apiCid = 0;
foreach ($st8['characters'] as $c) { if ($c['name'] === 'zzTest_API본캐') { $apiCid = (int)$c['id']; } }
$call(['action' => 'slot.assign', 'slot_id' => (int)$apiSlots[0]['id'], 'character_id' => $apiCid]);
$after = $pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$apiSlots[0]['id'])->fetchColumn();
t_eq((int)$after, $apiCid, 'slot.assign이 배치를 저장한다');

$call(['action' => 'slot.swap', 'slot_id_a' => (int)$apiSlots[0]['id'], 'slot_id_b' => (int)$apiSlots[9]['id']]);
$moved = $pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$apiSlots[9]['id'])->fetchColumn();
t_eq((int)$moved, $apiCid, 'slot.swap이 자리를 바꾼다');

t_section('API 임시 캐릭터');

$phRes = $call(['action' => 'character.add', 'player_id' => $apiPid,
                'name' => 'zzTest_API부캐1', 'is_placeholder' => true]);
$apiPhId = (int)$phRes['character_id'];
$phRow = $pdo->query("SELECT is_placeholder, char_class FROM fc_characters WHERE id = $apiPhId")->fetch();
t_eq((int)$phRow['is_placeholder'], 1, 'character.add가 임시 캐릭터를 만든다');
t_eq($phRow['char_class'], '', '임시 캐릭터는 직업이 비어 있다');

// 임시 캐릭터를 슬롯에 배치한 뒤 확정해도 자리가 유지되어야 한다
$call(['action' => 'slot.assign', 'slot_id' => (int)$apiSlots[3]['id'], 'character_id' => $apiPhId]);
$promoteRes = $call(['action' => 'character.promote', 'character_id' => $apiPhId, 'name' => 'zzTest_API확정']);
t_ok(array_key_exists('looked_up', $promoteRes), 'character.promote가 looked_up을 알려준다');

$promotedRow = $pdo->query("SELECT char_name, is_placeholder FROM fc_characters WHERE id = $apiPhId")->fetch();
t_eq($promotedRow['char_name'], 'zzTest_API확정', 'promote가 이름을 바꾼다');
t_eq((int)$promotedRow['is_placeholder'], 0, 'promote가 임시 표시를 없앤다');

$stillThere = $pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$apiSlots[3]['id'])->fetchColumn();
t_eq((int)$stillThere, $apiPhId, 'promote 후에도 배치된 슬롯이 그대로 유지된다');

t_section('API 오류 처리');

$err = '';
try { $call(['action' => 'nope.nope']); }
catch (RuntimeException $e) { $err = $e->getMessage(); }
t_eq($err, 'unknown_action', '모르는 action은 unknown_action 예외를 던진다');

$err2 = '';
try { $call(['action' => 'player.create', 'main_name' => 'zzTest_API본캐']); }
catch (RuntimeException $e) { $err2 = $e->getMessage(); }
t_ok(strpos($err2, 'duplicate_name') === 0, '중복 캐릭명은 duplicate_name 예외로 나온다');

$err3 = '';
try { $call(['action' => 'force.create', 'raid_id' => 0, 'day_of_week' => '', 'start_time' => '']); }
catch (RuntimeException $e) { $err3 = $e->getMessage(); }
t_eq($err3, 'bad_request', 'raid_id가 없으면 bad_request다');

$call(['action' => 'raid.delete', 'raid_id' => $apiRid]);
$call(['action' => 'player.delete', 'player_id' => $apiPid]);
t_eq((int)$pdo->query("SELECT COUNT(*) FROM fc_raids WHERE id = $apiRid")->fetchColumn(), 0,
     'raid.delete가 레이드를 지운다');

fc_cleanup_test_data($pdo);

exit(t_summary());
