#!/usr/bin/env php
<?php
/**
 * 전투력/아이템레벨 자동 업데이트 크론 스크립트
 * - 매분 실행되며 KST 06:00 이후에만 동작
 * - 오늘 업데이트 안 된 캐릭터 1개씩 처리
 * - 처리 대상: 레기온 멤버 + 활성 시즌의 포스 신청 캐릭터(sanctuary_characters)
 * - AION2 공식 2단계 API 사용 (search → characterInfo)
 *
 * crontab 등록:
 *   * * * * * /usr/bin/php /var/www/html/sanctuary/cron/update_atul.php >> /var/log/sanctuary_atul_update.log 2>&1
 */

date_default_timezone_set('Asia/Seoul');

$kst_hour = (int)date('G');
if ($kst_hour < 6) {
    exit(0);
}

$today = date('Y-m-d');

// ── DB 연결 ────────────────────────────────────────────────────────────────
$pdo = new PDO(
    'mysql:host=localhost;dbname=budget_manager;charset=utf8mb4',
    'budget_user',
    'budget2026!',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

// ── 컬럼 없으면 자동 추가 ─────────────────────────────────────────────────────
$migrations = [
    "sanctuary_legion_members"     => ["atul_updated_at" => "DATETIME NULL DEFAULT NULL", "main_item_level" => "INT DEFAULT NULL"],
    "sanctuary_legion_member_subs" => ["atul_updated_at" => "DATETIME NULL DEFAULT NULL", "sub_item_level"  => "INT DEFAULT NULL"],
    "sanctuary_characters"         => ["atul_updated_at" => "DATETIME NULL DEFAULT NULL", "item_level"      => "INT DEFAULT NULL"],
    "sanctuary_buddies"            => ["buddy_item_level" => "INT DEFAULT NULL"],
];
foreach ($migrations as $table => $cols) {
    foreach ($cols as $col => $def) {
        if (!$pdo->query("SHOW COLUMNS FROM {$table} LIKE '{$col}'")->fetch()) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
        }
    }
}

// ── 오늘 업데이트 안 된 캐릭터 1개 선택 ─────────────────────────────────────
// 레기온 멤버 + 활성 시즌의 신청 캐릭터를 통합 큐로 처리
$stmt = $pdo->prepare("
    SELECT source, id, char_name, atul_updated_at FROM (
        SELECT 'legion_main' AS source, id, main_name AS char_name, atul_updated_at
        FROM sanctuary_legion_members
        WHERE atul_updated_at IS NULL OR DATE(atul_updated_at) < :d1

        UNION ALL

        SELECT 'legion_sub' AS source, id, sub_name AS char_name, atul_updated_at
        FROM sanctuary_legion_member_subs
        WHERE sub_name != '' AND (atul_updated_at IS NULL OR DATE(atul_updated_at) < :d2)

        UNION ALL

        SELECT 'sc' AS source, sc.id, sc.char_name, sc.atul_updated_at
        FROM sanctuary_characters sc
        JOIN sanctuary_applications sa ON sa.id = sc.application_id
        JOIN sanctuary_seasons ss ON ss.id = sa.season_id
        WHERE ss.status IN ('구성중', '모집종료')
          AND (sc.atul_updated_at IS NULL OR DATE(sc.atul_updated_at) < :d3)
    ) combined
    ORDER BY atul_updated_at ASC
    LIMIT 1
");
$stmt->execute([':d1' => $today, ':d2' => $today, ':d3' => $today]);
$target = $stmt->fetch();

if (!$target) {
    echo date('[Y-m-d H:i:s]') . " 오늘 모든 캐릭터 업데이트 완료 — 종료\n";
    exit(0);
}

// ── AION2 공식 API 2단계 호출 ────────────────────────────────────────────────
function fetchFromOfficialApi(string $name): ?array
{
    $search_url = 'https://aion2.plaync.com/ko-kr/api/search/aion2/search/v2/character?'
        . http_build_query(['keyword' => $name, 'serverId' => '1010', 'page' => '1', 'size' => '30']);

    $ch = curl_init($search_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            'Referer: https://aion2.plaync.com/',
        ],
    ]);
    $body   = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status !== 200 || !$body) return null;

    $doc_list     = json_decode($body, true)['list'] ?? [];
    $character_id = null;
    foreach ($doc_list as $doc) {
        if (strtolower(strip_tags($doc['name'] ?? '')) === strtolower($name)) {
            $character_id = urldecode($doc['characterId'] ?? '');
            break;
        }
    }
    if (!$character_id) return null;

    $ch2 = curl_init('https://aion2.plaync.com/api/character/info?lang=ko&serverId=1010&characterId=' . urlencode($character_id));
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
            'Referer: https://aion2.plaync.com/',
        ],
    ]);
    $body2   = curl_exec($ch2);
    $status2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
    curl_close($ch2);

    if ($status2 !== 200 || !$body2) return null;

    $info       = json_decode($body2, true);
    $profile    = $info['profile']          ?? [];
    $stat_list  = $info['stat']['statList'] ?? [];
    $class_name = $profile['className']     ?? '';

    if ($class_name === '') return null;

    $item_level = null;
    foreach ($stat_list as $item) {
        if (($item['type'] ?? '') === 'ItemLevel') {
            $item_level = (int)round((float)($item['value'] ?? 0));
            break;
        }
    }

    $class_map = [
        'Guardian' => '수호성', 'Swordmaster' => '검성', 'Assassin'    => '살성',   'Ranger'   => '궁성',
        'Templar'  => '호법성', 'Spiritmaster' => '정령성', 'Sorcerer' => '마도성', 'Cleric'   => '치유성',
    ];
    if (isset($class_map[$class_name])) $class_name = $class_map[$class_name];

    return ['score' => (int)($profile['combatPower'] ?? 0), 'item_level' => $item_level, 'job' => $class_name];
}

$now    = date('Y-m-d H:i:s');
$result = fetchFromOfficialApi($target['char_name']);

if (!$result) {
    // 조회 실패 — updated_at만 갱신해서 무한 재시도 방지
    if ($target['source'] === 'legion_main') {
        $pdo->prepare("UPDATE sanctuary_legion_members SET atul_updated_at=? WHERE id=?")->execute([$now, $target['id']]);
    } elseif ($target['source'] === 'legion_sub') {
        $pdo->prepare("UPDATE sanctuary_legion_member_subs SET atul_updated_at=? WHERE id=?")->execute([$now, $target['id']]);
    } else {
        $pdo->prepare("UPDATE sanctuary_characters SET atul_updated_at=? WHERE id=?")->execute([$now, $target['id']]);
    }
    echo date('[Y-m-d H:i:s]') . " [{$target['source']}] {$target['char_name']}: API 조회 실패\n";
    exit(0);
}

$score      = $result['score'];
$item_level = $result['item_level'];
$job        = $result['job'];

// ── 소스 테이블 업데이트 (ID 기준 — 확실하게) ────────────────────────────────
if ($target['source'] === 'legion_main') {
    $pdo->prepare("UPDATE sanctuary_legion_members SET main_atul=?, main_class=?, main_item_level=?, atul_updated_at=? WHERE id=?")
        ->execute([$score, $job, $item_level, $now, $target['id']]);
} elseif ($target['source'] === 'legion_sub') {
    $pdo->prepare("UPDATE sanctuary_legion_member_subs SET sub_atul=?, sub_class=?, sub_item_level=?, atul_updated_at=? WHERE id=?")
        ->execute([$score, $job, $item_level, $now, $target['id']]);
} else {
    // sanctuary_characters 직접 ID 기준 업데이트
    $pdo->prepare("UPDATE sanctuary_characters SET atul_score=?, char_class=?, item_level=?, atul_updated_at=? WHERE id=?")
        ->execute([$score, $job, $item_level, $now, $target['id']]);
}

// ── 관련 테이블 전체 이름 기준 동기화 ────────────────────────────────────────
// 어느 소스든 이름이 같은 레코드는 모두 갱신
$pdo->prepare("UPDATE sanctuary_legion_members SET main_atul=?, main_class=?, main_item_level=?, atul_updated_at=? WHERE main_name=?")
    ->execute([$score, $job, $item_level, $now, $target['char_name']]);

$pdo->prepare("UPDATE sanctuary_legion_member_subs SET sub_atul=?, sub_class=?, sub_item_level=?, atul_updated_at=? WHERE sub_name=?")
    ->execute([$score, $job, $item_level, $now, $target['char_name']]);

$pdo->prepare("UPDATE sanctuary_characters SET atul_score=?, char_class=?, item_level=?, atul_updated_at=? WHERE char_name=?")
    ->execute([$score, $job, $item_level, $now, $target['char_name']]);

$pdo->prepare("UPDATE sanctuary_buddies SET buddy_score=?, buddy_class=?, buddy_item_level=? WHERE buddy_name=?")
    ->execute([$score, $job, $item_level, $target['char_name']]);

// ── 파티/포스 평균 전투력 재계산 ──────────────────────────────────────────────
$pdo->prepare("
    UPDATE sanctuary_parties p
    SET avg_atul = (
        SELECT ROUND(AVG(sc.atul_score))
        FROM sanctuary_party_members pm
        JOIN sanctuary_characters sc ON sc.id = pm.character_id
        WHERE pm.party_id = p.id
    )
    WHERE p.id IN (
        SELECT pm2.party_id FROM sanctuary_party_members pm2
        JOIN sanctuary_characters sc2 ON sc2.id = pm2.character_id
        WHERE sc2.char_name = ?
    )
")->execute([$target['char_name']]);

$pdo->prepare("
    UPDATE sanctuary_forces f
    SET avg_atul = (
        SELECT ROUND(AVG(sc.atul_score))
        FROM sanctuary_parties p
        JOIN sanctuary_party_members pm ON pm.party_id = p.id
        JOIN sanctuary_characters sc ON sc.id = pm.character_id
        WHERE p.force_id = f.id
    )
    WHERE f.id IN (
        SELECT DISTINCT p2.force_id FROM sanctuary_parties p2
        JOIN sanctuary_party_members pm2 ON pm2.party_id = p2.id
        JOIN sanctuary_characters sc2 ON sc2.id = pm2.character_id
        WHERE sc2.char_name = ?
    )
")->execute([$target['char_name']]);

$il_text = $item_level ? " / Lv{$item_level}" : '';
echo date('[Y-m-d H:i:s]') . " [{$target['source']}] {$target['char_name']}: 전투력 {$score}{$il_text} ({$job}) 완료\n";
exit(0);
