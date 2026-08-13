<?php
// aion2.plaync.com 캐릭터 조회 하나만 담당한다. DB를 모른다.
// 실패는 예외가 아니라 null로 돌려준다 — 조회 실패가 인원 등록을 막으면 안 된다.

function fc_atul_http_get($url) {
    $ch = curl_init($url);
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
    if ($status !== 200 || !$body) return null;
    $json = json_decode($body, true);
    return is_array($json) ? $json : null;
}

function fc_atul_lookup($name) {
    $name = trim($name);
    if ($name === '') return null;

    // 1단계: 검색 → characterId
    $searchUrl = 'https://aion2.plaync.com/ko-kr/api/search/aion2/search/v2/character?'
        . http_build_query(['keyword' => $name, 'serverId' => '1010', 'page' => '1', 'size' => '30']);
    $search = fc_atul_http_get($searchUrl);
    if ($search === null) return null;

    $characterId = null;
    foreach (($search['list'] ?? []) as $doc) {
        $clean = strip_tags($doc['name'] ?? '');
        if (strtolower($clean) === strtolower($name)) {
            // characterId는 이미 URL 인코딩되어 오므로 한 번 디코딩해서 쓴다
            $characterId = urldecode($doc['characterId'] ?? '');
            break;
        }
    }
    if ($characterId === null || $characterId === '') return null;

    // 2단계: 상세 → combatPower, ItemLevel, className
    $infoUrl = 'https://aion2.plaync.com/api/character/info?lang=ko&serverId=1010&characterId='
        . urlencode($characterId);
    $info = fc_atul_http_get($infoUrl);
    if ($info === null) return null;

    $profile = $info['profile'] ?? [];
    $stats   = isset($info['stat']['statList']) ? $info['stat']['statList'] : [];

    $itemLevel = null;
    foreach ($stats as $st) {
        if (($st['type'] ?? '') === 'ItemLevel') {
            $itemLevel = (int)round((float)($st['value'] ?? 0));
            break;
        }
    }

    $classMap = [
        'Guardian' => '수호성', 'Swordmaster' => '검성', 'Assassin' => '살성', 'Ranger' => '궁성',
        'Templar' => '호법성', 'Spiritmaster' => '정령성', 'Sorcerer' => '마도성', 'Cleric' => '치유성',
    ];
    $class = $profile['className'] ?? '';
    if (isset($classMap[$class])) $class = $classMap[$class];

    return [
        'class'      => (string)$class,
        'atul'       => (int)($profile['combatPower'] ?? 0),
        'item_level' => $itemLevel,
        'legion'     => trim($profile['regionName'] ?? ''),
    ];
}
