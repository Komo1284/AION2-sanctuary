<?php
// 아툴 점수 조회 프록시 (AION2 공식 API 2단계)
header('Content-Type: application/json');
header('Cache-Control: no-cache');

$name = trim($_GET['name'] ?? '');
if (!$name) {
    echo json_encode(['success' => false, 'error' => 'name_required']);
    exit;
}

// ── Step 1: 캐릭터 검색 → characterId 획득 ──────────────────────────────────
$search_url = 'https://aion2.plaync.com/ko-kr/api/search/aion2/search/v2/character?'
    . http_build_query(['keyword' => $name, 'serverId' => '1010', 'page' => '1', 'size' => '30']);

$ch = curl_init($search_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
        'Referer: https://aion2.plaync.com/',
    ],
]);
$body   = curl_exec($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($status !== 200 || !$body) {
    echo json_encode(['success' => false, 'error' => 'search_api_error', 'status' => $status]);
    exit;
}

$search_data = json_decode($body, true);
$doc_list    = $search_data['list'] ?? [];

// <strong>태그를 제거하고 정확히 일치하는 캐릭터 찾기
$character_id = null;
foreach ($doc_list as $doc) {
    $clean_name = strip_tags($doc['name'] ?? '');
    if (strtolower($clean_name) === strtolower($name)) {
        // characterId는 이미 URL 인코딩된 상태로 오므로 디코딩 후 사용
        $character_id = urldecode($doc['characterId'] ?? '');
        break;
    }
}

if (!$character_id) {
    echo json_encode(['success' => false, 'error' => 'not_found']);
    exit;
}

// ── Step 2: 캐릭터 상세정보 → combatPower, itemLevel, className ──────────────
$info_url = 'https://aion2.plaync.com/api/character/info?lang=ko&serverId=1010&characterId=' . urlencode($character_id);

$ch2 = curl_init($info_url);
curl_setopt_array($ch2, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36',
        'Referer: https://aion2.plaync.com/',
    ],
]);
$body2   = curl_exec($ch2);
$status2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
curl_close($ch2);

if ($status2 !== 200 || !$body2) {
    echo json_encode(['success' => false, 'error' => 'info_api_error', 'status' => $status2]);
    exit;
}

$info = json_decode($body2, true);
$profile     = $info['profile'] ?? [];
$stat        = $info['stat']    ?? [];
$stat_list   = $stat['statList'] ?? [];

$combat_power = (int)($profile['combatPower'] ?? 0);
$class_name   = $profile['className'] ?? '';

// 레기온(길드)명 — API는 'regionName' 키로 내려줌
$legion_name = trim($profile['regionName'] ?? '');

// ItemLevel 찾기
$item_level = null;
foreach ($stat_list as $stat_item) {
    if (($stat_item['type'] ?? '') === 'ItemLevel') {
        $item_level = (int)round((float)($stat_item['value'] ?? 0));
        break;
    }
}

// 직업명 한국어 매핑 (API가 영문으로 올 수 있는 경우 대비)
$class_map = [
    'Guardian'   => '수호성',
    'Swordmaster' => '검성',
    'Assassin'   => '살성',
    'Ranger'     => '궁성',
    'Templar'    => '호법성',
    'Spiritmaster' => '정령성',
    'Sorcerer'   => '마도성',
    'Cleric'     => '치유성',
];
if (isset($class_map[$class_name])) {
    $class_name = $class_map[$class_name];
}

$response = [
    'success'    => true,
    'name'       => $name,
    'score'      => $combat_power,
    'item_level' => $item_level,
    'job'        => $class_name,
    'legion'     => $legion_name,
];

// ?debug=1 로 호출하면 profile 원본 키/값을 반환해 실제 API 필드명 확인용
if (isset($_GET['debug'])) {
    $response['_debug_profile'] = $profile;
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
