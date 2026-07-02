<?php
// 아이온2 제작 seed 데이터 병합자. 품목군별 데이터는 craft/seed/*.php 에.
// ⚠ 레시피를 바꾸면 이 버전을 올려야 재적용됨.
//   재료 가격(craft_materials.unit_price)은 사용자 데이터라 절대 재시딩/DROP 하지 않는다.
define('CRAFT_SEED_VERSION', '2026-07-02.1-seed-split');

require_once __DIR__ . '/seed/accessories.php';

function craft_seed_data(): array {
    $parts = [craft_seed_accessories()];
    if (function_exists('craft_seed_weapons')) $parts[] = craft_seed_weapons();
    if (function_exists('craft_seed_armor'))   $parts[] = craft_seed_armor();
    $materials = []; $recipes = [];
    foreach ($parts as $p) { $materials = array_merge($materials, $p['materials']); $recipes = array_merge($recipes, $p['recipes']); }
    return ['materials' => $materials, 'recipes' => $recipes];
}
