<?php
// 루드라 레이드 초기 편성 시딩 — 기존 엑셀 표를 그대로 옮긴 1회성 스크립트.
// CLI 전용이며, 루드라에 이미 포스가 있으면 아무것도 하지 않고 중단한다
// (두 번 돌려서 포스가 14개가 되는 사고를 막는다).
//
// 엑셀에는 있지만 명단에 등록되지 않은 캐릭터는 이번에 빠지기로 한 인원이므로
// 그 칸은 빈 슬롯으로 둔다. 어떤 이름이 빠졌는지는 실행 로그에 남긴다.
//
//   php force/seed_rudra.php

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("cli only\n");
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/store.php';

$RAID_NAME = '루드라';
$DAY       = '수';

$MEMO_FIRST = '남는자리 새싹';
$MEMO_REST  = '남는자리 새싹, 전포스 끝나는대로 바로 진행';

// 엑셀 표 그대로. '' 는 빈 칸.
// p1 = 왼쪽 주황 블록(1파티) 5칸, p2 = 오른쪽 파랑 블록(2파티) 5칸.
// 주석의 이름은 엑셀에 적혀 있으나 명단에 없는 캐릭터다.
$GRID = [
    ['time' => '19:30',
     'p1' => ['댐', '', '검쥬', '새림', ''],
     'p2' => ['', '정령왕코모', '뮈', '지카엘', '권유']],           // p2-1 김떡시루
    ['time' => '19:40',
     'p1' => ['댱댱', '', '', '채링', '광천대성'],
     'p2' => ['', '간호사유리', '뺍', 'Ares', '뚬뚬']],             // p2-1 형빙
    ['time' => '19:50',
     'p1' => ['쓕', '리미', '퀸시유리', '햇닝', ''],
     'p2' => ['', '유리오야붕', '웈', '세비스', '뚱뚱']],           // p2-1 툭툭
    ['time' => '20:00',
     'p1' => ['댱', '유러브', '책읽는유리', '챌', ''],
     'p2' => ['', 'Komorebi', '퉤투퉤', '엘림', '유아빠']],         // p2-1 형빈
    ['time' => '20:10',
     'p1' => ['검아리', '성리미', 'YURI', '뿌이', '우리님'],
     'p2' => ['', '유리꼬붕', '', '마델린', '우낌']],               // p2-1 문덕팔
    ['time' => '20:20',
     'p1' => ['백상아리덮밥', '어제처럼', '정령왕유리', '뿐', '우니님'],
     'p2' => ['', '유리히메', '낼', '', '']],                       // p2-1 형빅
    ['time' => '20:30',
     'p1' => ['염상', '', '유리쨩', '', '우우님'],                   // p1-4 컹용
     'p2' => ['', '유리전용버프', '닙', '', '']],                   // p2-1 얄루
];

$pdo = fc_pdo();
fc_init_schema($pdo);

$st = $pdo->prepare("SELECT id FROM fc_raids WHERE name = ?");
$st->execute([$RAID_NAME]);
$raidId = $st->fetchColumn();
if ($raidId === false) {
    exit("레이드 '{$RAID_NAME}' 를 찾을 수 없다. 먼저 화면에서 만들어라.\n");
}
$raidId = (int)$raidId;

$st = $pdo->prepare("SELECT COUNT(*) FROM fc_forces WHERE raid_id = ?");
$st->execute([$raidId]);
if ((int)$st->fetchColumn() > 0) {
    exit("레이드 '{$RAID_NAME}'(id={$raidId})에 이미 포스가 있다 — 중단한다.\n");
}

// 이름 → character_id. 실제 캐릭터만 대상으로 한다.
$byName = [];
foreach ($pdo->query("SELECT id, char_name FROM fc_characters WHERE is_placeholder = 0") as $row) {
    $byName[$row['char_name']] = (int)$row['id'];
}

$missing  = [];
$assigned = 0;

foreach ($GRID as $i => $row) {
    $memo    = $i === 0 ? $MEMO_FIRST : $MEMO_REST;
    $forceId = fc_create_force($pdo, $raidId, $DAY, $row['time'], $memo);

    // fc_slot_ids()는 party_no, slot_no 순으로 돌려주므로 0~4가 1파티, 5~9가 2파티다.
    $slots = fc_slot_ids($pdo, $forceId);

    foreach ([1 => 'p1', 2 => 'p2'] as $partyNo => $key) {
        foreach ($row[$key] as $idx => $name) {
            if ($name === '') continue;
            if (!isset($byName[$name])) {
                $missing[] = ($i + 1) . "포스 {$partyNo}파티 " . ($idx + 1) . "번: {$name}";
                continue;
            }
            $slot = $slots[($partyNo - 1) * 5 + $idx];
            fc_assign_slot($pdo, (int)$slot['id'], $byName[$name]);
            $assigned++;
        }
    }

    echo ($i + 1) . "포스 생성 (id={$forceId}, {$DAY} {$row['time']})\n";
}

echo "\n배치 완료: {$assigned}명\n";
if ($missing) {
    echo "명단에 없어 건너뛴 칸 " . count($missing) . "개:\n";
    foreach ($missing as $m) echo "  - {$m}\n";
}
