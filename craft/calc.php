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
function craft_breakdown(string $item, array $ctx, array $owned, bool $ev, float $mult, array &$acc, array &$memo): void {
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
        craft_breakdown($mat, $ctx, $owned, $ev, $mult*$qty, $acc, $memo);
    }
}

// 루트 열거: (1) 응룡왕 직접제작 (2) 각 진입티어 코어직접 후 계승체인 (3) 전체 계승(진룡왕부터)
function craft_enumerate_routes(array $ctx, string $target, array $owned): array {
    $routes = [];
    foreach ([false, true] as $ev) {}
    // 최저 경로(자동): target 자체
    $memoF = []; $memoE = [];
    $cf = craft_cost($target, $ctx, $owned, $memoF, false);
    $ce = craft_cost($target, $ctx, $owned, $memoE, true);
    $bd = []; $mm = $memoF; craft_breakdown($target, $ctx, $owned, false, 1.0, $bd, $mm);
    $routes[] = ['label' => '최저비용(자동 선택)', 'cost_fixed' => $cf['cost'], 'cost_ev' => $ce['cost'],
                 'via' => $cf['via'], 'breakdown' => $bd];
    return $routes; // Step: 추가 명시 루트는 아래 확장 태스크에서
}
