<?php
// plan_api.php — 캐릭터 플랜 API (plaync 프록시 + 7부위 비용 계산)
// craft.php 에서 include됨. $pdo 사용 가능.
// 테스트 시: define('PLAN_API_TEST_ONLY', true); 후 require_once.

function plan_fetch(string $url): ?array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_USERAGENT      => 'Mozilla/5.0',
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($ch);
    $err  = curl_errno($ch);
    curl_close($ch);
    if ($err || $body === false || $body === '') return null;
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * 순수 계산 함수: equipmentList(원시 API 배열) → 플랜 결과 배열.
 * HTTP 의존성 없음 — 테스트 픽스처로 직접 호출 가능.
 */
function plan_compute(PDO $pdo, array $equipmentList): array {
    // slotPosName → [slot 레이블, itemType (null=MainHand 전용: 이름에서 결정)]
    $slotDef = [
        'MainHand' => ['slot' => '무기',    'itemType' => null],
        'SubHand'  => ['slot' => '가더',    'itemType' => '가더'],
        'Necklace' => ['slot' => '목걸이',  'itemType' => '목걸이'],
        'Earring1' => ['slot' => '귀걸이1', 'itemType' => '귀걸이'],
        'Earring2' => ['slot' => '귀걸이2', 'itemType' => '귀걸이'],
        'Ring1'    => ['slot' => '반지1',   'itemType' => '반지'],
        'Ring2'    => ['slot' => '반지2',   'itemType' => '반지'],
    ];
    $weaponSuffixes = ['대검','장검','단검','전곤','활','법봉','마법서','보주'];
    $doneTiers      = ['응룡왕','창룡왕'];
    $routeLabel     = [
        '계승'           => '보유 장비에서 계승 제작',
        '코어직접'       => '코어 직접제작',
        '달인빛나는직접' => '달인의 빛나는 직접제작',
        '보유'           => '완료',
    ];

    // equipmentList → slotPosName 인덱스
    $bySlot = [];
    foreach ($equipmentList as $e) {
        if (isset($e['slotPosName'])) $bySlot[$e['slotPosName']] = $e;
    }

    // ctx 캐시 (귀걸이/반지 2회씩 호출 방지)
    $ctxCache = [];

    // 분노 재료명 + 단가 (rage_totals 집계용)
    $rageMap = [];
    foreach ($pdo->query("SELECT name, unit_price FROM craft_materials WHERE category = '분노'") as $rm) {
        $rageMap[$rm['name']] = (int)$rm['unit_price'];
    }

    $slots   = [];
    $rageTotals = [];

    foreach ($slotDef as $slotPos => $def) {
        $slotLabel = $def['slot'];
        $equip     = $bySlot[$slotPos] ?? null;

        // 미착용
        if ($equip === null || !isset($equip['name']) || $equip['name'] === '') {
            $slots[] = ['slot' => $slotLabel, 'equipped' => null,
                        'status' => '미착용', 'cost' => 0, 'route' => null, 'breakdown' => []];
            continue;
        }

        $name = $equip['name'];

        // MainHand: 무기 타입을 이름 접미사로 결정
        $itemType = $def['itemType'];
        if ($slotPos === 'MainHand') {
            foreach ($weaponSuffixes as $ws) {
                if (mb_substr($name, -mb_strlen($ws)) === $ws) { $itemType = $ws; break; }
            }
            if ($itemType === null) {
                $slots[] = ['slot' => $slotLabel, 'equipped' => $name,
                            'status' => '판별 불가', 'cost' => 0, 'route' => null, 'breakdown' => []];
                continue;
            }
        }

        // 티어 파싱
        $tier = null;
        if (preg_match('/^(빛나는 )?(진룡왕|백룡왕|명룡왕|천룡왕|현룡왕|응룡왕|창룡왕)의 /u', $name, $m)) {
            $tier = $m[2];
        }

        // 완료 (응룡왕·창룡왕 이상)
        if ($tier !== null && in_array($tier, $doneTiers, true)) {
            $slots[] = ['slot' => $slotLabel, 'equipped' => $name,
                        'status' => '완료', 'cost' => 0, 'route' => '완료', 'breakdown' => []];
            continue;
        }

        // ctx 로드 (캐시)
        if (!isset($ctxCache[$itemType])) {
            $ctxCache[$itemType] = craft_load_context($pdo, $itemType);
        }
        $ctx = $ctxCache[$itemType];

        // owned 설정: 제작 가능 하위 티어면 보유로 처리
        $owned  = [];
        $status = '신규 제작';
        if ($tier !== null) {
            // 티어 파싱 성공 = 제작 가능 아이템 → 보유로 처리
            $owned  = [$name];
            $status = '계승 제작';
        }

        $target = "응룡왕의 {$itemType}";
        $memo   = [];
        $r      = craft_cost($target, $ctx, $owned, $memo, false);
        $cost   = $r['cost'];
        $route  = $routeLabel[$r['via']] ?? $r['via'];

        // breakdown 전개
        $bd = [];
        $mm = $memo;
        craft_breakdown($target, $ctx, $owned, false, 1.0, $bd, $mm);

        $breakdown = [];
        foreach ($bd as $matName => $matData) {
            $qty = round($matData['qty']);
            $breakdown[] = [
                'name' => $matName,
                'qty'  => $qty,
                'unit' => $matData['unit'],
                'core' => $matData['core'],
            ];
            // rage_totals 집계
            if (isset($rageMap[$matName])) {
                if (!isset($rageTotals[$matName])) {
                    $rageTotals[$matName] = ['need' => 0, 'unit' => $rageMap[$matName]];
                }
                $rageTotals[$matName]['need'] += $qty;
            }
        }

        $slots[] = [
            'slot'      => $slotLabel,
            'equipped'  => $name,
            'status'    => $status,
            'cost'      => $cost,
            'route'     => $route,
            'breakdown' => $breakdown,
        ];
    }

    $total = array_sum(array_column($slots, 'cost'));

    return [
        'ok'          => true,
        'slots'       => $slots,
        'total'       => $total,
        'rage_totals' => $rageTotals,
    ];
}

// ─────────────────────────────────────────────────────────────────
// HTTP 핸들러 (테스트 시 PLAN_API_TEST_ONLY 상수로 건너뜀)
// ─────────────────────────────────────────────────────────────────
if (!defined('PLAN_API_TEST_ONLY')) {
    header('Content-Type: application/json; charset=utf-8');

    if (($_GET['plan'] ?? '') === 'search') {
        $nick = trim($_GET['nick'] ?? '');
        if ($nick === '' || mb_strlen($nick) > 30) {
            echo json_encode(['ok' => false, 'error' => '캐릭터 이름을 입력해주세요.'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
            exit;
        }
        $base = 'https://aion2.plaync.com/api/search/character?keyword=' . rawurlencode($nick) . '&page=1&size=30&race=';
        $list = [];
        $failed = false;
        foreach ([1, 2] as $race) {
            $data = plan_fetch($base . $race);
            if ($data === null) { $failed = true; break; }
            foreach ($data['list'] ?? [] as $c) {
                $list[] = [
                    'characterId' => $c['characterId'],
                    'name'        => strip_tags($c['name']),
                    'level'       => $c['level'] ?? 0,
                    'serverId'    => $c['serverId'],
                    'serverName'  => $c['serverName'],
                    'race'        => $c['race'],
                ];
            }
        }
        if ($failed) {
            echo json_encode(['ok' => false, 'error' => '캐릭터 검색에 실패했습니다. 잠시 후 다시 시도해주세요.'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
            exit;
        }
        echo json_encode(['ok' => true, 'list' => array_slice($list, 0, 30)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        exit;
    }

    if (($_GET['plan'] ?? '') === 'equip') {
        $characterId = $_GET['characterId'] ?? '';
        $serverId    = (int)($_GET['serverId'] ?? 0);
        if ($characterId === '' || $serverId === 0) {
            echo json_encode(['ok' => false, 'error' => '잘못된 요청입니다.'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
            exit;
        }
        // $_GET은 URL-decoded → rawurlencode로 재인코딩 후 API에 전달
        $url = 'https://aion2.plaync.com/api/character/equipment?lang=ko'
             . '&characterId=' . rawurlencode($characterId)
             . '&serverId=' . $serverId;
        $data = plan_fetch($url);
        if ($data === null || !isset($data['equipment']['equipmentList'])) {
            echo json_encode(['ok' => false, 'error' => '장비 정보를 불러오지 못했습니다. 잠시 후 다시 시도해주세요.'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
            exit;
        }
        $result = plan_compute($pdo, $data['equipment']['equipmentList']);
        // 선택적 캐릭터 메타 전달
        $charName   = isset($_GET['charName'])   ? mb_substr(strip_tags($_GET['charName']), 0, 40) : null;
        $serverName = isset($_GET['serverName']) ? strip_tags($_GET['serverName'])                  : null;
        if ($charName !== null || $serverName !== null) {
            $result['character'] = ['name' => $charName, 'serverName' => $serverName];
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => '알 수 없는 요청입니다.'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
    exit;
}
