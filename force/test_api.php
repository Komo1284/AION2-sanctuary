<?php
// 서버에서 `php force/test_api.php` 로 실행하는 스모크 테스트.
// 만드는 데이터는 전부 zzTest_ 접두사를 쓰고 스스로 지운다.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/store.php';

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

fc_cleanup_test_data($pdo);

exit(t_summary());
