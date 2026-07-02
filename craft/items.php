<?php
// 품목 분류·목표 티어 헬퍼. DB에는 분류가 없고 여기 상수가 유일한 소스.
const CRAFT_CATEGORIES = [
    '무기'   => ['대검','장검','단검','전곤','가더','활','법봉','마법서','보주'],
    '방어구' => ['투구','견갑','상의','하의','장갑','신발','망토'],
    '장신구' => ['목걸이','귀걸이','반지'],
];

function craft_item_group(string $item): ?string {
    foreach (CRAFT_CATEGORIES as $g => $items) if (in_array($item, $items, true)) return $g;
    return null;
}
function craft_all_items(): array {
    return array_merge(...array_values(CRAFT_CATEGORIES));
}
// 무기만 창룡왕이 최종 티어
function craft_top_tier(string $item): string {
    return craft_item_group($item) === '무기' ? '창룡왕' : '응룡왕';
}
function craft_target_for(string $item): string {
    return craft_top_tier($item) . '의 ' . $item;
}
// 보유 선택 상한: 목표 직전 티어까지
function craft_owned_max_tier(string $item): string {
    return craft_top_tier($item) === '창룡왕' ? '응룡왕' : '현룡왕';
}
