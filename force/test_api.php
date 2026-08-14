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

// 같은 포스에는 같은 사람이 두 번 들어갈 수 없으므로 교체 상대는 다른 플레이어여야 한다
$pidNb = fc_create_player($pdo, 'zzTest_이웃');
$cNb   = (int)$pdo->query("SELECT id FROM fc_characters WHERE player_id = $pidNb")->fetchColumn();

fc_assign_slot($pdo, (int)$slots[5]['id'], $cNb);
fc_swap_slots($pdo, (int)$slots[0]['id'], (int)$slots[5]['id']);
$a = (int)$pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[0]['id'])->fetchColumn();
$b = (int)$pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[5]['id'])->fetchColumn();
t_eq($a, $cNb, 'swap 후 A슬롯에 B의 캐릭터가 있다');
t_eq($b, $cMain, 'swap 후 B슬롯에 A의 캐릭터가 있다');

fc_swap_slots($pdo, (int)$slots[1]['id'], (int)$slots[0]['id']);
$emptyNow = $pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[0]['id'])->fetchColumn();
$movedTo  = (int)$pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[1]['id'])->fetchColumn();
t_ok($emptyNow === null, '빈 슬롯과 swap하면 원래 자리가 빈다');
t_eq($movedTo, $cNb, '빈 슬롯과 swap하면 캐릭터가 그 자리로 옮겨간다');

fc_assign_slot($pdo, (int)$slots[1]['id'], null);
$cleared = $pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[1]['id'])->fetchColumn();
t_ok($cleared === null, 'character_id에 null을 넣으면 슬롯이 비워진다');

t_section('캐릭터 삭제 시 참조 정리');

fc_assign_slot($pdo, (int)$slots[5]['id'], null);   // $cMain을 비워 같은 포스 중복을 피한다
fc_assign_slot($pdo, (int)$slots[2]['id'], $cMain);
fc_delete_character($pdo, $cMain);
$after = $pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[2]['id'])->fetchColumn();
t_ok($after === null, '캐릭터를 지우면 배치된 슬롯이 비워진다');
t_eq((int)$pdo->query("SELECT COUNT(*) FROM fc_slots WHERE force_id = $fidA")->fetchColumn(), 10,
     '캐릭터를 지워도 슬롯 행 10개는 그대로 남는다');

t_section('본캐 삭제 시 승계');

$pidM = fc_create_player($pdo, 'zzTest_승계본캐', ['zzTest_승계부캐A', 'zzTest_승계부캐B']);
$charsM = $pdo->query("SELECT id, is_main, sort_order FROM fc_characters
                       WHERE player_id = $pidM ORDER BY sort_order")->fetchAll();
$mainIdM = (int)$charsM[0]['id'];   // sort_order 0, is_main=1
$subAId  = (int)$charsM[1]['id'];   // sort_order 1
$subBId  = (int)$charsM[2]['id'];   // sort_order 2

fc_delete_character($pdo, $mainIdM);

$afterMainDel = $pdo->query("SELECT id, is_main FROM fc_characters
                             WHERE player_id = $pidM ORDER BY sort_order")->fetchAll();
t_eq(count($afterMainDel), 2, '본캐 삭제 후에도 부캐 2개는 그대로 남아 있다');
t_eq((int)$afterMainDel[0]['id'], $subAId, '남은 것 중 sort_order 최소는 부캐A다');
t_eq((int)$afterMainDel[0]['is_main'], 1, 'sort_order 최소인 부캐A가 is_main=1로 승계된다');
t_eq((int)$afterMainDel[1]['is_main'], 0, '승계되지 않은 부캐B는 is_main=0 그대로다');

$playerStillThere = $pdo->query("SELECT COUNT(*) FROM fc_players WHERE id = $pidM")->fetchColumn();
t_ok((int)$playerStillThere === 1, '승계자가 있으면 fc_players 행이 남아 있다');

fc_delete_character($pdo, $subBId);
fc_delete_character($pdo, $subAId); // 마지막 캐릭터 — 이것도 is_main=1이었다

$playerGone = $pdo->query("SELECT COUNT(*) FROM fc_players WHERE id = $pidM")->fetchColumn();
t_ok((int)$playerGone === 0, '마지막 캐릭터를 지우면 fc_players 행도 함께 사라진다');
$charsGone = $pdo->query("SELECT COUNT(*) FROM fc_characters WHERE player_id = $pidM")->fetchColumn();
t_eq((int)$charsGone, 0, '마지막 캐릭터를 지우면 캐릭터도 0개가 된다');

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
$fid4b = fc_create_force($pdo, $rid3, '목', '22:00', '');
$sl   = fc_slot_ids($pdo, $fid4);
$sl4b = fc_slot_ids($pdo, $fid4b);
$cid3 = (int)$pdo->query("SELECT id FROM fc_characters WHERE char_name = 'zzTest_상태본캐'")->fetchColumn();
// 같은 레이드의 서로 다른 포스에 같은 캐릭터를 넣어 중복 경고를 만든다.
// 한 포스 안에 같은 사람을 두 번 넣는 것은 이제 차단되므로 포스를 나눈다.
fc_assign_slot($pdo, (int)$sl[0]['id'], $cid3);
fc_assign_slot($pdo, (int)$sl4b[0]['id'], $cid3);

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

t_ok(isset($state['duplicates'][(string)$rid3]), '같은 레이드의 다른 포스 중복이 duplicates에 잡힌다');
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

// 같은 사람이 한 포스에 두 번 들어갈 수 없으므로, API 경계에서도 차단되는지 먼저 확인한다
$apiBlocked = '';
try { $call(['action' => 'slot.assign', 'slot_id' => (int)$apiSlots[3]['id'], 'character_id' => $apiPhId]); }
catch (RuntimeException $e) { $apiBlocked = $e->getMessage(); }
t_ok(strpos($apiBlocked, 'player_conflict:') === 0, 'slot.assign이 같은 포스 중복을 player_conflict로 막는다');

// 임시 캐릭터를 슬롯에 배치한 뒤 확정해도 자리가 유지되어야 한다
$call(['action' => 'slot.assign', 'slot_id' => (int)$apiSlots[9]['id'], 'character_id' => null]);
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

// 배열/객체가 스칼라 필드에 섞여 들어와도 조용히 덮어쓰지 않고 bad_request로 막혀야 한다
$beforeRow = $pdo->query("SELECT char_name, atul_score FROM fc_characters WHERE id = $apiCid")->fetch();

$err4 = '';
try { $call(['action' => 'character.update', 'character_id' => $apiCid, 'name' => ['a', 'b']]); }
catch (RuntimeException $e) { $err4 = $e->getMessage(); }
t_eq($err4, 'bad_request', 'character.update에 배열 name을 넣으면 bad_request다');

$err5 = '';
try { $call(['action' => 'character.update', 'character_id' => $apiCid, 'atul' => ['x' => 1]]); }
catch (RuntimeException $e) { $err5 = $e->getMessage(); }
t_eq($err5, 'bad_request', 'character.update에 배열/객체 atul을 넣으면 bad_request다');

$afterRow = $pdo->query("SELECT char_name, atul_score FROM fc_characters WHERE id = $apiCid")->fetch();
t_eq($afterRow['char_name'], $beforeRow['char_name'], '실패 후에도 char_name이 원래 값 그대로 남아 있다 (부분 적용 없음)');
t_eq($afterRow['atul_score'], $beforeRow['atul_score'], '실패 후에도 atul_score가 원래 값 그대로 남아 있다 (부분 적용 없음)');

// 정상 스칼라 값과 null은 여전히 통과해야 한다
$call(['action' => 'character.update', 'character_id' => $apiCid, 'name' => 'zzTest_API본캐수정', 'atul' => 1234]);
$normalRow = $pdo->query("SELECT char_name, atul_score FROM fc_characters WHERE id = $apiCid")->fetch();
t_eq($normalRow['char_name'], 'zzTest_API본캐수정', '정상 문자열 name은 여전히 반영된다');
t_eq((int)$normalRow['atul_score'], 1234, '정상 정수 atul은 여전히 반영된다');

$call(['action' => 'character.update', 'character_id' => $apiCid, 'atul' => null]);
$nullRow = $pdo->query("SELECT atul_score FROM fc_characters WHERE id = $apiCid")->fetch();
t_ok($nullRow['atul_score'] === null, 'null 값은 여전히 값 비우기로 허용된다');

// (int)[99999]는 PHP에서 1이 되어 엉뚱한 1번 포스를 지우는 사고로 이어질 뻔했다.
// fc_req_int가 배열/객체를 명시적으로 막아야 한다.
$err6 = '';
try { $call(['action' => 'force.delete', 'force_id' => [99999]]); }
catch (RuntimeException $e) { $err6 = $e->getMessage(); }
t_eq($err6, 'bad_request', 'force.delete에 배열 force_id를 넣으면 bad_request다 (int로 조용히 캐스팅되지 않는다)');

$call(['action' => 'raid.delete', 'raid_id' => $apiRid]);
$call(['action' => 'player.delete', 'player_id' => $apiPid]);
t_eq((int)$pdo->query("SELECT COUNT(*) FROM fc_raids WHERE id = $apiRid")->fetchColumn(), 0,
     'raid.delete가 레이드를 지운다');

t_section('전투력 매일 갱신');

// 앞선 섹션들이 남긴 zzTest_ 데이터를 먼저 정리해서, fc_refresh_all_atul()이 훑는
// 전체 캐릭터 목록을 이 섹션이 만드는 것만으로 예측 가능하게 만든다.
fc_cleanup_test_data($pdo);

$preReal = (int)$pdo->query("SELECT COUNT(*) FROM fc_characters WHERE is_placeholder = 0")->fetchColumn();
$prePh   = (int)$pdo->query("SELECT COUNT(*) FROM fc_characters WHERE is_placeholder = 1")->fetchColumn();

$refreshPid = fc_create_player($pdo, 'zzTest_아툴갱신본캐', ['zzTest_아툴갱신실패']);
$refreshPhId = fc_add_character($pdo, $refreshPid, 'zzTest_아툴갱신임시', null, true);

$refreshRows = $pdo->query("SELECT id, char_name FROM fc_characters WHERE player_id = $refreshPid")->fetchAll();
$refreshIdByName = [];
foreach ($refreshRows as $r) { $refreshIdByName[$r['char_name']] = (int)$r['id']; }
$successId = $refreshIdByName['zzTest_아툴갱신본캐'];
$failId    = $refreshIdByName['zzTest_아툴갱신실패'];

// 실패 케이스는 "기존 값이 그대로 남는다"를 확인해야 하므로 미리 알려진 값을 심어둔다.
// (기존 값을 UPDATE로 직접 심는 이유: fc_create_player는 lookup 없이 만들어 기본값이 비어 있다)
$pdo->prepare("UPDATE fc_characters SET char_class = ?, atul_score = ?, item_level = ? WHERE id = ?")
    ->execute(['수호성', 55555, 4000, $failId]);

$refreshLookupCalls = [];
$refresh_lookup = function ($name) use (&$refreshLookupCalls) {
    $refreshLookupCalls[] = $name;
    if ($name === 'zzTest_아툴갱신본캐') {
        return ['class' => '검성', 'atul' => 99999, 'item_level' => 3600];
    }
    return null; // 그 외(우리 실패 테스트 캐릭 포함, 다른 잔존 실캐릭이 있다면 그것도)는 조회 실패
};

$revBeforeRefresh = fc_revision($pdo);
$refreshResult = fc_refresh_all_atul($pdo, $refresh_lookup, 0); // sleepMs=0 — 테스트를 느리게 만들지 않는다
$revAfterRefresh = fc_revision($pdo);

$successRow = $pdo->query("SELECT char_class, atul_score, item_level, atul_updated_at
                           FROM fc_characters WHERE id = $successId")->fetch();
t_eq($successRow['char_class'], '검성', '조회 성공 시 char_class가 갱신된다');
t_eq((int)$successRow['atul_score'], 99999, '조회 성공 시 atul_score가 갱신된다');
t_eq((int)$successRow['item_level'], 3600, '조회 성공 시 item_level이 갱신된다');
t_ok($successRow['atul_updated_at'] !== null, '조회 성공 시 atul_updated_at이 채워진다');

$failRow = $pdo->query("SELECT char_class, atul_score, item_level FROM fc_characters WHERE id = $failId")->fetch();
t_eq($failRow['char_class'], '수호성', '조회 실패 시 char_class는 기존 값 그대로 남는다');
t_eq((int)$failRow['atul_score'], 55555, '조회 실패 시 atul_score는 기존 값 그대로 남는다');
t_eq((int)$failRow['item_level'], 4000, '조회 실패 시 item_level은 기존 값 그대로 남는다');

t_ok(!in_array('zzTest_아툴갱신임시', $refreshLookupCalls, true), '임시 캐릭터는 조회 함수가 호출되지 않는다');

t_eq($revAfterRefresh, $revBeforeRefresh + 1, '전체 실행 후 revision이 정확히 1 증가한다 (캐릭터마다가 아니라 한 번만)');

$expectUpdated = 1;
$expectFailed  = ($preReal + 2) - $expectUpdated; // 우리가 만든 실캐릭 2명(성공1+실패1) + 기존 실캐릭 중 성공 1명 제외
$expectSkipped = $prePh + 1;
t_eq($refreshResult['updated'], $expectUpdated, 'updated 개수가 실제 갱신 성공 건수와 일치한다');
t_eq($refreshResult['failed'], $expectFailed, 'failed 개수가 실제 갱신 실패 건수와 일치한다');
t_eq($refreshResult['skipped'], $expectSkipped, 'skipped 개수가 실제 건너뛴 임시 캐릭터 수와 일치한다');

t_section('전투력 갱신 — combatPower 0/누락 방어');

// "200 OK + profile.combatPower가 비어 있음" 같은 응답은 배열이지만 신뢰할 수 없다.
// fc_refresh_all_atul()이 "배열이면 성공"으로만 보면 기존의 좋은 값을 0으로 덮어써 버린다.
// 이 섹션도 앞선 섹션이 남긴 zzTest_를 먼저 치워 전체 카운트를 예측 가능하게 만든다.
fc_cleanup_test_data($pdo);

$zeroPreReal = (int)$pdo->query("SELECT COUNT(*) FROM fc_characters WHERE is_placeholder = 0")->fetchColumn();
$zeroPrePh   = (int)$pdo->query("SELECT COUNT(*) FROM fc_characters WHERE is_placeholder = 1")->fetchColumn();

$zeroPid = fc_create_player($pdo, 'zzTest_제로방어본캐', ['zzTest_제로방어무키', 'zzTest_제로방어정상']);
$zeroRowsAll = $pdo->query("SELECT id, char_name FROM fc_characters WHERE player_id = $zeroPid")->fetchAll();
$zeroIdByName = [];
foreach ($zeroRowsAll as $r) { $zeroIdByName[$r['char_name']] = (int)$r['id']; }
$zeroId  = $zeroIdByName['zzTest_제로방어본캐'];  // atul=>0 인 배열 (200 + combatPower 빈 값 재현)
$noKeyId = $zeroIdByName['zzTest_제로방어무키'];  // atul 키가 아예 없는 배열
$posId   = $zeroIdByName['zzTest_제로방어정상'];  // 정상값(1 이상) — 과잉 차단 여부 확인용

$pdo->prepare("UPDATE fc_characters SET char_class = ?, atul_score = ?, item_level = ? WHERE id = ?")
    ->execute(['수호성', 77777, 4100, $zeroId]);
$pdo->prepare("UPDATE fc_characters SET char_class = ?, atul_score = ?, item_level = ? WHERE id = ?")
    ->execute(['궁성', 88888, 4200, $noKeyId]);
$pdo->prepare("UPDATE fc_characters SET char_class = ?, atul_score = ?, item_level = ? WHERE id = ?")
    ->execute(['마도성', 1, 3000, $posId]);

$zero_lookup = function ($name) {
    if ($name === 'zzTest_제로방어본캐') return ['class' => '검성', 'atul' => 0, 'item_level' => 3500];
    if ($name === 'zzTest_제로방어무키') return ['class' => '검성', 'item_level' => 3500]; // atul 키 자체가 없음
    if ($name === 'zzTest_제로방어정상') return ['class' => '살성', 'atul' => 2, 'item_level' => 3600];
    return null;
};

$zeroResult = fc_refresh_all_atul($pdo, $zero_lookup, 0);

$zeroRow = $pdo->query("SELECT char_class, atul_score, item_level FROM fc_characters WHERE id = $zeroId")->fetch();
t_eq((int)$zeroRow['atul_score'], 77777, 'atul=>0 응답에도 기존 atul_score가 그대로 유지된다');
t_eq($zeroRow['char_class'], '수호성', 'atul=>0 응답이면 char_class 등 다른 필드도 갱신되지 않는다 (실패로 취급)');

$noKeyRow = $pdo->query("SELECT char_class, atul_score FROM fc_characters WHERE id = $noKeyId")->fetch();
t_eq((int)$noKeyRow['atul_score'], 88888, 'atul 키가 없는 응답에도 기존 atul_score가 그대로 유지된다');
t_eq($noKeyRow['char_class'], '궁성', 'atul 키가 없는 응답이면 char_class도 갱신되지 않는다');

$posRow = $pdo->query("SELECT char_class, atul_score, item_level FROM fc_characters WHERE id = $posId")->fetch();
t_eq((int)$posRow['atul_score'], 2, '정상값(1 이상) 응답은 여전히 갱신된다 — 과잉 차단이 아니다');
t_eq($posRow['char_class'], '살성', '정상값 응답이면 char_class도 함께 갱신된다');

$zeroExpectUpdated = 1;
$zeroExpectFailed  = ($zeroPreReal + 3) - $zeroExpectUpdated; // 우리가 만든 실캐릭 3명(성공1+실패2) + 기존 실캐릭은 전부 실패
$zeroExpectSkipped = $zeroPrePh;
t_eq($zeroResult['updated'], $zeroExpectUpdated, 'updated 개수 — 정상값 1건만 성공으로 집계된다');
t_eq($zeroResult['failed'], $zeroExpectFailed, 'failed 개수 — atul<=0/키없음이 실패로 집계된다 (updated가 아니다)');
t_eq($zeroResult['skipped'], $zeroExpectSkipped, 'skipped 개수는 임시 캐릭터 수와 일치한다');

t_section('같은 플레이어의 같은 포스 중복 배치 차단');

$pcPid   = fc_create_player($pdo, 'zzTest_충돌본캐', ['zzTest_충돌부캐']);
$pcChars = $pdo->query("SELECT id FROM fc_characters WHERE player_id = $pcPid ORDER BY sort_order")
                ->fetchAll(PDO::FETCH_COLUMN);
$pcMain  = (int)$pcChars[0];
$pcSub   = (int)$pcChars[1];
$pcPh    = fc_add_character($pdo, $pcPid, 'zzTest_충돌임시', null, true);

$pcOtherPid  = fc_create_player($pdo, 'zzTest_충돌남');
$pcOtherChar = (int)$pdo->query("SELECT id FROM fc_characters WHERE player_id = $pcOtherPid")->fetchColumn();

$pcRaid  = fc_create_raid($pdo, 'zzTest_충돌레이드');
$pcF1    = fc_create_force($pdo, $pcRaid, '토', '20:00', '');
$pcF2    = fc_create_force($pdo, $pcRaid, '일', '20:00', '');
$pcS1    = fc_slot_ids($pdo, $pcF1);
$pcS2    = fc_slot_ids($pdo, $pcF2);

fc_assign_slot($pdo, (int)$pcS1[0]['id'], $pcMain);
t_ok(true, '첫 캐릭터는 정상 배치된다');

$blocked = '';
try { fc_assign_slot($pdo, (int)$pcS1[1]['id'], $pcSub); }
catch (RuntimeException $e) { $blocked = $e->getMessage(); }
t_ok(strpos($blocked, 'player_conflict:') === 0, '같은 플레이어의 부캐를 같은 포스에 넣으면 차단된다');
t_ok(strpos($blocked, 'zzTest_충돌본캐') !== false, '차단 메시지에 이미 앉아 있는 캐릭터명이 담긴다');
t_ok($pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$pcS1[1]['id'])->fetchColumn() === null,
     '차단된 슬롯은 비어 있는 채로 남는다');

$blockedPh = '';
try { fc_assign_slot($pdo, (int)$pcS1[2]['id'], $pcPh); }
catch (RuntimeException $e) { $blockedPh = $e->getMessage(); }
t_ok(strpos($blockedPh, 'player_conflict:') === 0, '임시 캐릭터도 같은 규칙으로 차단된다');

fc_assign_slot($pdo, (int)$pcS1[3]['id'], $pcOtherChar);
t_eq((int)$pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$pcS1[3]['id'])->fetchColumn(),
     $pcOtherChar, '다른 플레이어는 같은 포스에 정상 배치된다');

fc_assign_slot($pdo, (int)$pcS2[0]['id'], $pcSub);
t_eq((int)$pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$pcS2[0]['id'])->fetchColumn(),
     $pcSub, '다른 포스에는 같은 플레이어의 부캐가 들어간다');

fc_assign_slot($pdo, (int)$pcS1[0]['id'], $pcSub);
t_eq((int)$pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$pcS1[0]['id'])->fetchColumn(),
     $pcSub, '이미 그 플레이어가 앉은 자리를 같은 플레이어 캐릭터로 교체하는 것은 허용된다');
fc_assign_slot($pdo, (int)$pcS1[0]['id'], $pcMain);

t_section('자리 교체 시 중복 차단');

// 1포스에 본캐, 2포스에 부캐가 있는 상태에서 두 자리를 맞바꾸는 것은 허용된다
$swapOk = true;
try { fc_swap_slots($pdo, (int)$pcS1[0]['id'], (int)$pcS2[0]['id']); }
catch (RuntimeException $e) { $swapOk = false; }
t_ok($swapOk, '같은 플레이어끼리 서로 자리를 맞바꾸는 것은 허용된다');
fc_swap_slots($pdo, (int)$pcS1[0]['id'], (int)$pcS2[0]['id']);

// 2포스의 부캐를 1포스의 빈칸으로 옮기면 1포스에 본캐가 이미 있으므로 차단
$swapBlocked = '';
try { fc_swap_slots($pdo, (int)$pcS2[0]['id'], (int)$pcS1[4]['id']); }
catch (RuntimeException $e) { $swapBlocked = $e->getMessage(); }
t_ok(strpos($swapBlocked, 'player_conflict:') === 0, '빈칸으로 옮겨도 같은 포스에 같은 플레이어가 있으면 차단된다');
t_eq((int)$pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$pcS2[0]['id'])->fetchColumn(),
     $pcSub, '차단된 교체는 원래 자리를 그대로 둔다');

// 같은 포스 안에서 자리만 바꾸는 것은 구성원이 그대로라 항상 허용
$sameForceOk = true;
try { fc_swap_slots($pdo, (int)$pcS1[0]['id'], (int)$pcS1[5]['id']); }
catch (RuntimeException $e) { $sameForceOk = false; }
t_ok($sameForceOk, '같은 포스 안에서 자리만 바꾸는 것은 허용된다');

t_section('전체 갱신 나눠 돌리기');

$btPid = fc_create_player($pdo, 'zzTest_배치본캐', ['zzTest_배치부캐1', 'zzTest_배치부캐2']);
fc_add_character($pdo, $btPid, 'zzTest_배치임시', null, true);

$btTotal = fc_atul_target_count($pdo);
t_ok($btTotal >= 4, 'fc_atul_target_count가 전체 캐릭터 수를 돌려준다');

// 조회된 이름을 전부 기록해 슬라이스가 겹치거나 빠뜨리는지 본다
$btSeen = [];
$btLookup = function ($name) use (&$btSeen) {
    $btSeen[] = $name;
    return ['class' => '검성', 'atul' => 12345, 'item_level' => 100];
};

$btRevBefore = fc_revision($pdo);
$btA = fc_refresh_all_atul($pdo, $btLookup, 0, 0, 2);
$btB = fc_refresh_all_atul($pdo, $btLookup, 0, 2, 2);

t_eq(count($btSeen), count(array_unique($btSeen)), '슬라이스가 같은 캐릭터를 두 번 조회하지 않는다');
t_ok(($btA['updated'] + $btA['failed'] + $btA['skipped']) === 2, '첫 배치는 정확히 2건을 처리한다');
t_ok(($btB['updated'] + $btB['failed'] + $btB['skipped']) === 2, '두 번째 배치도 정확히 2건을 처리한다');
t_ok(fc_revision($pdo) > $btRevBefore, '배치마다 revision이 올라간다');

// offset/limit를 주지 않으면 크론처럼 전체를 돈다
$btSeen = [];
fc_refresh_all_atul($pdo, $btLookup, 0);
t_eq(count($btSeen), $btTotal - (int)$pdo->query("SELECT COUNT(*) FROM fc_characters WHERE is_placeholder = 1")->fetchColumn(),
     'offset/limit 없이 부르면 임시 캐릭터를 뺀 전원을 조회한다');

// 전체를 2건씩 끝까지 돌면 임시 캐릭터를 뺀 전원이 정확히 한 번씩 조회된다
$btSeen = [];
for ($off = 0; $off < $btTotal; $off += 2) {
    fc_refresh_all_atul($pdo, $btLookup, 0, $off, 2);
}
$btReal = (int)$pdo->query("SELECT COUNT(*) FROM fc_characters WHERE is_placeholder = 0")->fetchColumn();
t_eq(count($btSeen), $btReal, '끝까지 나눠 돌면 실제 캐릭터 전원이 조회된다');
t_eq(count($btSeen), count(array_unique($btSeen)), '끝까지 나눠 돌아도 중복 조회가 없다');

t_section('전체 갱신 API');

$raRes = $call(['action' => 'atul.refresh_all', 'offset' => 0, 'limit' => 2]);
t_ok(isset($raRes['total']) && isset($raRes['done']), 'atul.refresh_all이 total과 done을 돌려준다');
t_eq($raRes['total'], $btTotal, 'total이 전체 캐릭터 수와 같다');
t_ok($raRes['next_offset'] === 2 || $raRes['done'] === true, '다음 offset을 알려주거나 done을 세운다');

$raLast = $call(['action' => 'atul.refresh_all', 'offset' => max(0, $btTotal - 1), 'limit' => 15]);
t_ok($raLast['done'] === true, '마지막 배치는 done이 true다');
t_ok($raLast['next_offset'] === null, '마지막 배치는 next_offset이 null이다');

$raClamp = $call(['action' => 'atul.refresh_all', 'offset' => -5, 'limit' => 999]);
t_ok(isset($raClamp['total']), '비정상 offset/limit도 보정되어 정상 응답한다');

fc_cleanup_test_data($pdo);

exit(t_summary());
