<?php
// 비용 계산 엔진 — 재료를 노드로 보는 재귀 메모이제이션 min-cost

function craft_load_context(PDO $pdo, string $accessory): array {
    $price = []; $core = [];
    foreach ($pdo->query("SELECT name,unit_price,is_core FROM craft_materials") as $m) {
        $price[$m['name']] = (int)$m['unit_price'];
        $core[$m['name']]  = (int)$m['is_core'] === 1;
    }
    $recipes = [];
    $rs = $pdo->prepare("SELECT * FROM craft_recipes WHERE accessory = ? ORDER BY id");
    $rs->execute([$accessory]);
    $inpStmt = $pdo->prepare("SELECT material_name, qty FROM craft_recipe_inputs WHERE recipe_id = ?");
    foreach ($rs->fetchAll() as $r) {
        $inpStmt->execute([$r['id']]);
        $inputs = [];
        foreach ($inpStmt->fetchAll() as $i) { $inputs[] = [$i['material_name'], (int)$i['qty']]; }
        $recipes[$r['output_name']][] = [
            'type' => $r['recipe_type'], 'kina' => (int)$r['kina_cost'],
            'combo' => (float)$r['combo_rate'], 'estimated' => (int)$r['is_estimated'] === 1,
            'inputs' => $inputs,
        ];
    }
    // 교환 가능 재료: 실질가격 = min(자기 시세>0, 대체재 시세>0)
    //  - 제작 계승석: 장신구 → 달인의 빛나는 악세 3종 중 최저가로 1:1 교환(직접구매 불가)
    //  - 찬란한 ○○ 원석 → 찬란한 오드와 1:1 교환 (더 싼 쪽)
    // (계승석: 장신구 (영웅)은 is_core=1 처리로 항상 무료)
    $subs = [
        '제작 계승석: 장신구' => ['달인의 빛나는 루비 목걸이', '달인의 빛나는 다이아몬드 귀걸이', '달인의 빛나는 사파이어 반지'],
        '찬란한 루비 원석'     => ['찬란한 오드'],
        '찬란한 다이아몬드 원석' => ['찬란한 오드'],
        '찬란한 사파이어 원석'   => ['찬란한 오드'],
    ];
    foreach ($subs as $item => $alts) {
        if (!array_key_exists($item, $price)) continue;
        $cands = [];
        if ($price[$item] > 0) $cands[] = $price[$item];
        foreach ($alts as $a) { if (isset($price[$a]) && $price[$a] > 0) $cands[] = $price[$a]; }
        $price[$item] = $cands ? min($cands) : 0;
    }
    return ['price' => $price, 'core' => $core, 'recipes' => $recipes];
}

// 아이템 최소 비용. $ev=true 면 COMBO 기대값 반영(빛나는승급 키나를 combo만큼 절감).
function craft_cost(string $item, array $ctx, array $owned, array &$memo, bool $ev): array {
    if (in_array($item, $owned, true)) return ['cost' => 0.0, 'recipe' => null, 'via' => '보유'];
    $key = $item . '|' . ($ev ? 'ev' : 'fix');
    if (isset($memo[$key])) return $memo[$key];

    $candidates = [];
    // 코어는 항상 0
    if (!empty($ctx['core'][$item])) {
        $res = ['cost' => 0.0, 'recipe' => null, 'via' => '코어'];
        $memo[$key] = $res; return $res;
    }
    // 시장가 잎(레시피 없이 가격이 매겨진 재료). 산출물이 아닌 leaf 재료는 여기서 확정.
    $hasRecipe = isset($ctx['recipes'][$item]);
    if (isset($ctx['price'][$item]) && !$hasRecipe) {
        $res = ['cost' => (float)$ctx['price'][$item], 'recipe' => null, 'via' => '시장'];
        $memo[$key] = $res; return $res;
    }
    // 순환 참조 가드: 이 아이템을 계산 중에 재진입하면 MAX 비용 반환
    $memo[$key] = ['cost' => PHP_FLOAT_MAX, 'recipe' => null, 'via' => '순환'];
    // 크래프트 가능하면 각 레시피 비용 계산
    if ($hasRecipe) {
        foreach ($ctx['recipes'][$item] as $r) {
            $sum = (float)$r['kina'];
            // 빛나는승급의 COMBO 기대값: 하위 티어를 제작할 때 25% 확률로 빛나는이 공짜로 나오므로
            // 승급 키나를 combo 비율만큼 기대값 절감
            if ($ev && $r['type'] === '빛나는승급') { $sum = (float)$r['kina'] * (1 - $r['combo']); }
            foreach ($r['inputs'] as [$mat, $qty]) {
                $sub = craft_cost($mat, $ctx, $owned, $memo, $ev);
                $sum += $sub['cost'] * $qty;
            }
            $candidates[] = ['cost' => $sum, 'recipe' => $r, 'via' => $r['type']];
        }
    }
    // 시장가도 있고 레시피도 있으면 둘 다 후보
    if (isset($ctx['price'][$item]) && $ctx['price'][$item] > 0) {
        $candidates[] = ['cost' => (float)$ctx['price'][$item], 'recipe' => null, 'via' => '시장'];
    }
    if (empty($candidates)) {
        // 가격 미입력 leaf → 0 (UI에서 미입력 경고)
        $res = ['cost' => 0.0, 'recipe' => null, 'via' => '미입력'];
        $memo[$key] = $res; return $res;
    }
    usort($candidates, fn($a,$b) => $a['cost'] <=> $b['cost']);
    $memo[$key] = $candidates[0];
    return $candidates[0];
}

// 목표까지의 재료 총소모 breakdown(잎 재료 단위로 펼침). 반환: [name => ['qty'=>, 'unit'=>, 'core'=>bool]]
function craft_breakdown(string $item, array $ctx, array $owned, bool $ev, float $mult, array &$acc, array &$memo, array $seen = []): void {
    if (isset($seen[$item])) return;
    $seen[$item] = true;
    if (in_array($item, $owned, true)) return;
    if (!empty($ctx['core'][$item])) {
        $acc[$item]['qty'] = ($acc[$item]['qty'] ?? 0) + $mult;
        $acc[$item]['unit'] = 0; $acc[$item]['core'] = true; return;
    }
    $r = craft_cost($item, $ctx, $owned, $memo, $ev)['recipe'];
    if ($r === null) { // leaf
        $acc[$item]['qty'] = ($acc[$item]['qty'] ?? 0) + $mult;
        $acc[$item]['unit'] = $ctx['price'][$item] ?? 0; $acc[$item]['core'] = false;
        return;
    }
    if ($r['kina'] > 0) {
        $kmult = ($ev && $r['type']==='빛나는승급') ? $mult*(1-$r['combo']) : $mult;
        $acc['키나(통합)']['qty'] = ($acc['키나(통합)']['qty'] ?? 0) + $r['kina']*$kmult;
        $acc['키나(통합)']['unit'] = 1; $acc['키나(통합)']['core'] = false;
    }
    foreach ($r['inputs'] as [$mat, $qty]) {
        craft_breakdown($mat, $ctx, $owned, $ev, $mult*$qty, $acc, $memo, $seen);
    }
}

// 진입티어 base를 만든 뒤 상위는 계승만 사용하는 강제 경로 비용/브레이크다운.
// $entry = 진입 티어 output_name(예 '천룡왕의 목걸이'), $entryType = '코어직접'|'달인빛나는직접'
function craft_route_from_entry(array $ctx, string $target, string $entry, string $entryType, array $owned): ?array {
    // 티어 순서 확보
    $order = ['진룡왕','백룡왕','명룡왕','천룡왕','현룡왕','응룡왕'];
    // output_name → 티어 prefix
    $prefixOf = function($name) use ($order) {
        foreach ($order as $p) if (mb_strpos($name, $p) === 0) return $p;
        return null;
    };
    // 강제 레시피 선택 클로저: 티어별로 원하는 타입만 남긴 임시 ctx
    $forced = $ctx;
    foreach ($forced['recipes'] as $out => &$rs) {
        $p = $prefixOf($out);
        if ($p === null) continue;               // 승급 등은 그대로
        if ($out === $entry) {
            $rs = array_values(array_filter($rs, fn($r) => $r['type'] === $entryType));
        } elseif (in_array($p, $order, true)) {
            $idxEntry = array_search($prefixOf($entry), $order, true);
            $idxThis  = array_search($p, $order, true);
            if ($idxThis > $idxEntry) {
                $has = array_filter($rs, fn($r) => $r['type'] === '계승');
                if ($has) $rs = array_values($has);  // 상위는 계승 강제(있으면)
            } elseif ($idxThis < $idxEntry) {
                $rs = [];                            // 진입 아래 티어는 사용 안 함
            }
        }
    }
    unset($rs);
    if (empty($forced['recipes'][$target])) return null;
    $mF = []; $mE = [];
    $cf = craft_cost($target, $forced, $owned, $mF, false);
    $ce = craft_cost($target, $forced, $owned, $mE, true);
    if ($cf['recipe'] === null && $cf['via'] !== '보유') return null;
    $bd = []; $mm = $mF; craft_breakdown($target, $forced, $owned, false, 1.0, $bd, $mm);
    return ['cost_fixed' => $cf['cost'], 'cost_ev' => $ce['cost'], 'breakdown' => $bd];
}

function craft_enumerate_routes(array $ctx, string $target, array $owned): array {
    $routes = [];
    // 1) 직접제작(달인의 빛나는)
    $direct = craft_route_from_entry($ctx, $target, $target, '달인빛나는직접', $owned);
    if ($direct) $routes[] = ['label' => '응룡왕 직접제작 (달인의 빛나는)'] + $direct;
    // 2) 진입티어별 코어직접 → 상위 계승
    $entries = ['현룡왕의 목걸이'=>'현룡왕 코어직접 → 응룡왕 계승',
                '천룡왕의 목걸이'=>'천룡왕 코어직접 → 계승체인',
                '진룡왕의 목걸이'=>'전체 계승 (진룡왕 코어직접부터)'];
    // 악세서리 접두만 목걸이가 아니면 output_name 치환 필요 → 호출부에서 실제 target 접미 사용
    foreach ($entries as $entry => $label) {
        $entryName = craft_localize_entry($entry, $target);   // 목걸이/귀걸이/반지 접미 맞춤
        $r = craft_route_from_entry($ctx, $target, $entryName, '코어직접', $owned);
        if ($r) $routes[] = ['label' => $label] + $r;
    }
    usort($routes, fn($a,$b) => $a['cost_fixed'] <=> $b['cost_fixed']);
    return $routes;
}

// '현룡왕의 목걸이' 형태 → target 접미(목걸이/귀걸이/반지)로 치환
function craft_localize_entry(string $entry, string $target): string {
    foreach (['목걸이','귀걸이','반지'] as $suf) {
        if (mb_substr($target, -mb_strlen($suf)) === $suf) {
            return preg_replace('/(목걸이|귀걸이|반지)$/u', $suf, $entry);
        }
    }
    return $entry;
}
