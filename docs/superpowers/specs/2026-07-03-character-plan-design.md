# 캐릭터 검색 → 7부위 응룡왕 플래너 설계

작성일: 2026-07-03. 대상: `craft.php` 상단 통합.

## 1. 목적

공식 캐릭터정보실(aion2.plaync.com)에서 서버·닉네임으로 캐릭터를 검색해 착용 장비를 불러오고,
핵심 7부위(무기1·가더1·목걸이1·귀걸이2·반지2)를 **모두 응룡왕 이상**으로 만드는 최저비용
계획(부위별 추천 루트 + 총비용 + 분노 귀속 일괄 차감)을 자동 계산한다.

## 2. 데이터 소스 (정찰 확인 완료)

- 검색: `GET https://aion2.plaync.com/api/search/character?keyword={닉}&race={1|2}&page=1&size=30`
  → `list[]: {characterId(암호화), name, race, level, serverId, serverName}`. 인증 불필요, 우리 서버 curl 호출 확인됨.
- 장비: `GET https://aion2.plaync.com/api/character/equipment?lang=ko&characterId={id}&serverId={sid}`
  → `equipment.equipmentList[]: {name, slotPosName, grade, ...}`. 슬롯: MainHand, SubHand, Necklace, Earring1/2, Ring1/2 등(정확한 악세 슬롯명은 구현 시 실데이터로 확정).
- CORS로 브라우저 직접 호출 불가 가능성 → **우리 서버 PHP가 프록시**(cURL, 타임아웃·에러 처리).

## 3. 계획 로직

- 부위 매핑: MainHand→무기(이름 접미로 대검~보주 판별), SubHand→가더, Necklace→목걸이, Earring1/2→귀걸이, Ring1/2→반지. 접미가 우리 품목이 아니면(활 미착용 등) 해당 슬롯은 '판별 불가' 표시.
- 티어 파싱: `/^(빛나는 )?(진|백|명|천|현|응|창)룡왕의 /u` 매치 → 제작 라인. 매치 실패 → 비제작 장비(맨땅 시작).
- 완료 판정: 착용 티어 ∈ {응룡왕, 창룡왕}(빛나는 포함) → 비용 0, '완료'.
- 부위별 최저비용: 목표 = `응룡왕의 {품목}` 고정(무기도 응룡왕까지 — 사용자 확정).
  `craft_cost(목표, ctx, owned=[착용아이템])`의 **비강제 전역 최소**를 그대로 사용(계승/코어직접/직접제작 자동 비교). 추천 라벨 = 선택된 최상위 레시피 타입(계승→"보유 X에서 계승", 코어직접→"현룡왕 코어 활용" 등) + breakdown.
- 귀걸이·반지 2개: 슬롯별 독립 계산(각자의 착용 아이템 기준).
- **분노 귀속 일괄 차감**: 7부위 breakdown의 분노 계열 필요량을 타입별 합산 →
  `차감 = Σ min(보유, 총필요) × 단가` 를 총액에서 차감(사용자 확정: 총합 차감).
  보유수량은 기존 localStorage(`craft_owned_rage`) 재사용 → 차감은 클라이언트 JS에서 수행
  (plan JSON에 분노 타입별 총필요량·단가 포함).

## 4. 구조

- `craft/plan_api.php` — `craft.php?plan=search|equip` 로 라우팅되는 서버 프록시+계산:
  - `search`: race 1·2 병합 검색, 후보 반환(이름의 `<strong>` 태그 제거).
  - `equip`: 장비 fetch → 7슬롯 추출 → 부위별 계획 계산 → JSON
    `{slots:[{slot, equipped, status, route_label, cost, breakdown}], total, rage_totals:{분노명:{need, unit}}}`.
- `craft/view.php` — 상단(분노 패널 아래)에 "🔍 캐릭터로 계산" 섹션: 닉네임 입력 → 후보 리스트 → 선택 시 플랜 테이블 렌더(부위·착용장비·상태·추천루트·비용) + 총액 + 분노 차감 후 실질 총액.
- 표시 순서: 무기→가더→목걸이→귀걸이1·2→반지1·2 (중요도순).

## 5. 안전·제약

- plaync API 실패/타임아웃(5s) 시 한국어 오류 메시지. 응답 캐시 불필요(실시간 조회).
- craft_materials 가격 데이터 무접촉. 외부 API 응답은 데이터로만 취급(이름 htmlspecialchars).
- 범위 밖: 방어구 7부위 플랜(추후), 캐릭터 스펙(전투력 등) 표시, 검색 결과 페이징.
