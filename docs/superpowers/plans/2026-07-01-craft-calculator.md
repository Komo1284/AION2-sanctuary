# 응룡왕 제작 최저비용 계산기 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 비밀번호 없이 접근하는 `craft.php`에서 아이온2 응룡왕 악세서리(목걸이/귀걸이/반지)를 만들 때 루트별 최저 재화를 계산·비교해주고, 누구나 재료 시세를 갱신할 수 있는 웹 도구를 만든다.

**Architecture:** 기존 앱(순수 PHP + PDO/MySQL, 빌드툴 없음)의 standalone 페이지 패턴을 따르되 사이트 비밀번호 게이트는 두지 않는다. `craft.php`가 진입점으로 DB연결·테이블 자동생성·seed·POST 액션 디스패치·뷰 렌더를 담당하고, 계산 로직/스키마/뷰/액션을 `craft/` 하위 파일로 분리한다. 비용 계산은 재료를 노드로 보는 재귀 메모이제이션 min-cost 방식.

**Tech Stack:** PHP 7.4+ (PDO/MySQL), MySQL `budget_manager` DB, 프론트는 서버렌더 HTML + 약간의 인라인 JS. 테스트는 서버에서 실행하는 PHP CLI 검증 스크립트 + browse 브라우저 확인.

## Global Constraints

- DB 접속정보는 `index.php`와 동일: host `localhost`, db `budget_manager`, user `budget_user`, pass `budget2026!`.
- 테이블은 `CREATE TABLE IF NOT EXISTS` + "비어있으면 seed" 패턴으로 자동 생성 (기존 `index.php` 마이그레이션 스타일).
- `craft.php`에는 **사이트 비밀번호 게이트를 넣지 않는다**(비번 없이 접근).
- 재료 가격 편집은 **공개**(비밀번호 불요). 레시피 편집은 관리자(`$_SESSION['sanctuary_admin']`)만.
- 코어(`is_core=1`) 아이템은 비용 계산 시 항상 0원.
- 키나는 총비용에 포함(kina_cost).
- 최종 목표 아이템: 응룡왕(기본). 빛나는 응룡왕은 키나 비용만 옵션 표기.
- 모든 문자열/UI는 한국어. 기존 다크+골드 디자인 토큰 재사용.
- 응룡왕 계승 레시피는 `is_estimated=1` 임시 추정치로 넣는다(23:00 확정 후 관리자 수정).
- 통화 표기: 원화 아님. 게임 내 "키나" 단위. 숫자는 천단위 콤마.
- **배포 규칙**: 각 태스크 커밋 후 `git push origin main` → 서버 `git pull origin main` → 서버에서 검증(`php -l`, `php craft/test_calc.php`, browse). CLAUDE.md의 Deployment 섹션 준수.

---

## File Structure

- Create: `craft.php` — 진입점. DB연결, `craft/schema.php` include, POST 디스패치, GET 렌더.
- Create: `craft/schema.php` — 3개 테이블 CREATE IF NOT EXISTS + 비어있으면 `craft/seed_data.php`로 seed.
- Create: `craft/seed_data.php` — 목걸이/귀걸이/반지 재료·레시피 seed 데이터를 PHP 배열로 반환.
- Create: `craft/calc.php` — 순수 함수 계산엔진(effective_cost, 루트 열거, COMBO 확정/기대값).
- Create: `craft/test_calc.php` — 서버에서 실행하는 CLI 검증 스크립트(assert).
- Create: `craft/actions.php` — POST 핸들러(공개 시세갱신, 관리자 레시피편집).
- Create: `craft/view.php` — 계산 결과 + 시세편집표 + (관리자)레시피편집 HTML 렌더.

각 파일은 `craft.php`와 같은 스코프에서 `require`되어 `$pdo` 등을 공유한다(기존 `actions/`, `sections/` 패턴과 동일).

---

### Task 1: DB 스키마 + 목걸이 seed 데이터 + 진입점 스캐폴드

**Files:**
- Create: `craft/seed_data.php`
- Create: `craft/schema.php`
- Create: `craft.php`
- Test: 서버에서 `php -l` + 행 카운트 확인

**Interfaces:**
- Produces: `craft_seed_data(): array` — `['materials'=>[[name,is_core,category],...], 'recipes'=>[[accessory,output_name,recipe_type,tier,kina_cost,is_estimated,inputs=>[[material,qty],...]],...]]`
- Produces: `craft_init_schema(PDO $pdo): void` — 테이블 생성 + 비어있으면 seed
- Produces: 전역 `$pdo` (craft.php 안)

- [ ] **Step 1: seed 데이터 파일 작성**

`craft/seed_data.php` 작성. 목걸이(303) 전체 + 응룡왕 계승 추정치. 재료의 `is_core`는 "○○의 코어"만 1, 나머지 0. category는 표시용 그룹.

```php
<?php
// 아이온2 응룡왕 악세서리 제작 seed 데이터 (inven 스크레이핑 기준, 2026-07-01)
function craft_seed_data(): array {
    // 공통 재료(코어/키나 외)는 category로 그룹화. is_core=1 은 항상 0원.
    $materials = [
        // 중간 완제품(시세 입력 대상)
        ['달인의 빛나는 루비 목걸이', 0, '중간아이템'],
        // 계승석
        ['제작 계승석: 장신구', 0, '계승석'],
        // 제련석/비늘/원석/오드
        ['달인의 최상급 제련석', 0, '제련석'],
        ['강화된 두꺼운 용족의 비늘', 0, '비늘'],
        ['강화된 단단한 용족의 비늘', 0, '비늘'],
        ['강화된 견고한 용족의 비늘', 0, '비늘'],
        ['강화된 예리한 용족의 비늘', 0, '비늘'],
        ['강화된 정밀한 용족의 비늘', 0, '비늘'],
        ['강화된 강고한 용족의 비늘', 0, '비늘'],
        ['찬란한 루비 원석', 0, '원석'],
        ['찬란한 오드', 0, '오드'],
        // 분노 시리즈
        ['분노의 사념', 0, '분노'],
        ['분노의 의지', 0, '분노'],
        ['분노의 자아', 0, '분노'],
        ['분노의 염원', 0, '분노'],
        ['분노의 승화', 0, '분노'],
        ['분노의 신념', 0, '분노'],
        ['분노의 성화', 0, '분노'],
        ['광기에 찬 분노의 무고', 0, '분노'],
        // 키나(가격=수량이므로 unit_price=1 고정, 편집 불가 취급)
        ['키나(통합)', 0, '키나'],
        // 코어 (항상 0원)
        ['진룡왕의 코어: 장신구', 1, '코어'],
        ['백룡왕의 코어: 장신구', 1, '코어'],
        ['명룡왕의 코어: 장신구', 1, '코어'],
        ['천룡왕의 코어: 장신구', 1, '코어'],
        ['현룡왕의 코어: 장신구', 1, '코어'],
        ['응룡왕의 코어: 장신구', 1, '코어'],
    ];

    $A = '목걸이';
    $recipes = [
        // 진룡왕 (계승 없음). tier 번호: 진1 백2 명3 천4 현5 응6
        [$A,'진룡왕의 목걸이','코어직접',1,0,0,[
            ['진룡왕의 코어: 장신구',1],['달인의 최상급 제련석',4],['강화된 두꺼운 용족의 비늘',14],
            ['분노의 사념',6],['찬란한 루비 원석',8],['찬란한 오드',5]]],
        [$A,'진룡왕의 목걸이','달인빛나는직접',1,0,0,[
            ['달인의 빛나는 루비 목걸이',1],['달인의 최상급 제련석',4],['강화된 두꺼운 용족의 비늘',14],
            ['분노의 사념',6],['찬란한 루비 원석',8],['찬란한 오드',5]]],
        // 백룡왕
        [$A,'백룡왕의 목걸이','계승',2,0,0,[
            ['빛나는 진룡왕의 목걸이',1],['제작 계승석: 장신구',1],['백룡왕의 코어: 장신구',1],
            ['달인의 최상급 제련석',2],['강화된 단단한 용족의 비늘',19],['분노의 의지',9],
            ['찬란한 루비 원석',6],['찬란한 오드',2]]],
        [$A,'백룡왕의 목걸이','코어직접',2,0,0,[
            ['백룡왕의 코어: 장신구',2],['달인의 최상급 제련석',6],['강화된 단단한 용족의 비늘',19],
            ['분노의 사념',5],['분노의 의지',9],['찬란한 루비 원석',14],['찬란한 오드',7]]],
        [$A,'백룡왕의 목걸이','달인빛나는직접',2,0,0,[
            ['달인의 빛나는 루비 목걸이',2],['달인의 최상급 제련석',6],['강화된 단단한 용족의 비늘',19],
            ['분노의 사념',5],['분노의 의지',9],['찬란한 루비 원석',14],['찬란한 오드',7]]],
        // 명룡왕
        [$A,'명룡왕의 목걸이','계승',3,0,0,[
            ['빛나는 백룡왕의 목걸이',1],['제작 계승석: 장신구',1],['명룡왕의 코어: 장신구',1],
            ['달인의 최상급 제련석',4],['강화된 견고한 용족의 비늘',26],['분노의 자아',14],
            ['찬란한 루비 원석',8],['찬란한 오드',7]]],
        [$A,'명룡왕의 목걸이','코어직접',3,0,0,[
            ['명룡왕의 코어: 장신구',3],['달인의 최상급 제련석',10],['강화된 견고한 용족의 비늘',26],
            ['분노의 사념',4],['분노의 의지',7],['분노의 자아',14],['찬란한 루비 원석',22],['찬란한 오드',14]]],
        [$A,'명룡왕의 목걸이','달인빛나는직접',3,0,0,[
            ['달인의 빛나는 루비 목걸이',3],['달인의 최상급 제련석',10],['강화된 견고한 용족의 비늘',26],
            ['분노의 사념',4],['분노의 의지',7],['분노의 자아',14],['찬란한 루비 원석',22],['찬란한 오드',14]]],
        // 천룡왕
        [$A,'천룡왕의 목걸이','계승',4,0,0,[
            ['빛나는 명룡왕의 목걸이',1],['제작 계승석: 장신구',1],['천룡왕의 코어: 장신구',1],
            ['달인의 최상급 제련석',4],['강화된 예리한 용족의 비늘',35],['분노의 염원',10],['분노의 승화',10],
            ['찬란한 루비 원석',9],['찬란한 오드',5]]],
        [$A,'천룡왕의 목걸이','코어직접',4,0,0,[
            ['천룡왕의 코어: 장신구',4],['달인의 최상급 제련석',14],['강화된 예리한 용족의 비늘',35],
            ['분노의 사념',2],['분노의 의지',5],['분노의 자아',9],['분노의 염원',10],['분노의 승화',10],
            ['찬란한 루비 원석',31],['찬란한 오드',19]]],
        [$A,'천룡왕의 목걸이','달인빛나는직접',4,0,0,[
            ['달인의 빛나는 루비 목걸이',4],['달인의 최상급 제련석',14],['강화된 예리한 용족의 비늘',35],
            ['분노의 사념',2],['분노의 의지',5],['분노의 자아',9],['분노의 염원',10],['분노의 승화',10],
            ['찬란한 루비 원석',31],['찬란한 오드',19]]],
        // 현룡왕
        [$A,'현룡왕의 목걸이','계승',5,0,0,[
            ['빛나는 천룡왕의 목걸이',1],['제작 계승석: 장신구',1],['현룡왕의 코어: 장신구',1],
            ['달인의 최상급 제련석',7],['강화된 정밀한 용족의 비늘',47],['분노의 신념',10],['분노의 성화',10],
            ['찬란한 루비 원석',17],['찬란한 오드',10]]],
        [$A,'현룡왕의 목걸이','코어직접',5,0,0,[
            ['현룡왕의 코어: 장신구',5],['달인의 최상급 제련석',21],['강화된 정밀한 용족의 비늘',47],
            ['분노의 염원',3],['분노의 승화',3],['분노의 신념',10],['분노의 성화',10],
            ['찬란한 루비 원석',48],['찬란한 오드',29]]],
        [$A,'현룡왕의 목걸이','달인빛나는직접',5,0,0,[
            ['달인의 빛나는 루비 목걸이',5],['달인의 최상급 제련석',21],['강화된 정밀한 용족의 비늘',47],
            ['분노의 염원',3],['분노의 승화',3],['분노의 신념',10],['분노의 성화',10],
            ['찬란한 루비 원석',48],['찬란한 오드',29]]],
        // 응룡왕 직접제작 (확정)
        [$A,'응룡왕의 목걸이','달인빛나는직접',6,0,0,[
            ['달인의 빛나는 루비 목걸이',5],['달인의 최상급 제련석',31],['강화된 강고한 용족의 비늘',76],
            ['분노의 사념',11],['분노의 의지',18],['분노의 자아',26],['광기에 찬 분노의 무고',13],
            ['찬란한 루비 원석',76],['찬란한 오드',44]]],
        // 응룡왕 계승 (추정치 is_estimated=1 → 23:00 확정 후 수정)
        [$A,'응룡왕의 목걸이','계승',6,0,1,[
            ['빛나는 현룡왕의 목걸이',1],['제작 계승석: 장신구',1],['응룡왕의 코어: 장신구',1],
            ['달인의 최상급 제련석',10],['강화된 강고한 용족의 비늘',64],['분노의 사념',14],['분노의 의지',14],
            ['광기에 찬 분노의 무고',5],['찬란한 루비 원석',24],['찬란한 오드',14]]],
        // 빛나는 승급 (기본 + 키나). kina_cost 사용, 재료는 키나 수량으로도 표현
        [$A,'빛나는 진룡왕의 목걸이','빛나는승급',1,700000,0,[['진룡왕의 목걸이',1]]],
        [$A,'빛나는 백룡왕의 목걸이','빛나는승급',2,3500000,0,[['백룡왕의 목걸이',1]]],
        [$A,'빛나는 명룡왕의 목걸이','빛나는승급',3,7000000,0,[['명룡왕의 목걸이',1]]],
        [$A,'빛나는 천룡왕의 목걸이','빛나는승급',4,20000000,0,[['천룡왕의 목걸이',1]]],
        [$A,'빛나는 현룡왕의 목걸이','빛나는승급',5,35000000,0,[['현룡왕의 목걸이',1]]],
        [$A,'빛나는 응룡왕의 목걸이','빛나는승급',6,60000000,0,[['응룡왕의 목걸이',1]]],
    ];
    return ['materials' => $materials, 'recipes' => $recipes];
}
```

- [ ] **Step 2: 스키마 파일 작성**

`craft/schema.php`:

```php
<?php
require_once __DIR__ . '/seed_data.php';

function craft_init_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS craft_materials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL UNIQUE,
        unit_price BIGINT NOT NULL DEFAULT 0,
        is_core TINYINT NOT NULL DEFAULT 0,
        category VARCHAR(40) DEFAULT '',
        updated_at DATETIME NULL DEFAULT NULL,
        updated_ip VARCHAR(64) DEFAULT ''
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS craft_recipes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        accessory VARCHAR(20) NOT NULL,
        output_name VARCHAR(120) NOT NULL,
        recipe_type VARCHAR(30) NOT NULL,
        tier INT NOT NULL DEFAULT 0,
        kina_cost BIGINT NOT NULL DEFAULT 0,
        combo_rate DECIMAL(4,3) NOT NULL DEFAULT 0.250,
        is_estimated TINYINT NOT NULL DEFAULT 0,
        note VARCHAR(255) DEFAULT ''
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS craft_recipe_inputs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        recipe_id INT NOT NULL,
        material_name VARCHAR(120) NOT NULL,
        qty INT NOT NULL DEFAULT 1
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // 비어있을 때만 seed
    $count = (int)$pdo->query("SELECT COUNT(*) FROM craft_recipes")->fetchColumn();
    if ($count > 0) return;

    $data = craft_seed_data();
    $insMat = $pdo->prepare("INSERT IGNORE INTO craft_materials (name,is_core,category) VALUES (?,?,?)");
    foreach ($data['materials'] as $m) { $insMat->execute([$m[0], $m[1], $m[2]]); }

    $insRec = $pdo->prepare("INSERT INTO craft_recipes (accessory,output_name,recipe_type,tier,kina_cost,is_estimated) VALUES (?,?,?,?,?,?)");
    $insInp = $pdo->prepare("INSERT INTO craft_recipe_inputs (recipe_id,material_name,qty) VALUES (?,?,?)");
    foreach ($data['recipes'] as $r) {
        $insRec->execute([$r[0], $r[1], $r[2], $r[3], $r[4], $r[5]]);
        $rid = (int)$pdo->lastInsertId();
        foreach ($r[6] as $inp) { $insInp->execute([$rid, $inp[0], (int)$inp[1]]); }
    }
    // 크래프트 결과물(중간산출 아이템)도 materials에 없으면 등록(가격0, 비코어) → 조회 편의
    $outNames = array_unique(array_map(fn($r)=>$r[1], $data['recipes']));
    foreach ($outNames as $on) { $insMat->execute([$on, 0, '산출물']); }
}
```

- [ ] **Step 3: 진입점 스캐폴드 작성**

`craft.php` (비번 게이트 없음):

```php
<?php
session_start();
date_default_timezone_set('Asia/Seoul');

$db_host='localhost'; $db_name='budget_manager'; $db_user='budget_user'; $db_pass='budget2026!';
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('<div style="color:red;padding:20px;">DB 연결 실패: '.htmlspecialchars($e->getMessage()).'</div>');
}

require_once __DIR__ . '/craft/schema.php';
craft_init_schema($pdo);

$is_admin = isset($_SESSION['sanctuary_admin']) && $_SESSION['sanctuary_admin'] === true;

// 임시 디버그(다음 태스크에서 제거): seed 확인
if (isset($_GET['debug'])) {
    $rc = (int)$pdo->query("SELECT COUNT(*) FROM craft_recipes")->fetchColumn();
    $mc = (int)$pdo->query("SELECT COUNT(*) FROM craft_materials")->fetchColumn();
    header('Content-Type: text/plain');
    echo "recipes=$rc materials=$mc\n";
    exit;
}
echo "craft init OK";
```

- [ ] **Step 4: 커밋 & 배포**

```bash
git add craft.php craft/seed_data.php craft/schema.php
git commit -m "제작계산기: DB 스키마 + 목걸이 seed + 진입점 스캐폴드"
git push origin main
```

- [ ] **Step 5: 서버에서 검증**

```bash
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull origin main && php -l craft.php && php -l craft/schema.php && php -l craft/seed_data.php'
```
Expected: `No syntax errors detected` ×3.

브라우저 확인:
```bash
B="$HOME/.claude/skills/gstack/browse/dist/browse"
$B goto "http://14.63.164.109/sanctuary/craft.php?debug=1"
$B text
```
Expected: `recipes=21 materials=` (21개 목걸이 레시피 + 재료 다수). 응룡왕 계승 포함 확인.

---

### Task 2: 비용 계산 엔진 + 서버 검증 스크립트

**Files:**
- Create: `craft/calc.php`
- Create: `craft/test_calc.php`

**Interfaces:**
- Consumes: `$pdo`, craft_materials/craft_recipes/craft_recipe_inputs 테이블
- Produces: `craft_load_context(PDO $pdo, string $accessory): array` — `['price'=>[name=>int], 'core'=>[name=>bool], 'recipes'=>[outName=>[recipe,...]]]` (recipe = `['type','kina','combo','estimated','inputs'=>[[material,qty]]]`)
- Produces: `craft_cost(string $item, array $ctx, array $owned, array &$memo, bool $ev): array` — `['cost'=>float, 'recipe'=>?array, 'via'=>string]`
- Produces: `craft_enumerate_routes(array $ctx, string $target, array $owned): array` — 루트 리스트 `[['label','cost_fixed','cost_ev','steps'=>[...], 'breakdown'=>[name=>['qty','unit','sub']]],...]` cost 오름차순

- [ ] **Step 1: 검증 스크립트(실패 테스트) 작성**

`craft/test_calc.php` — 손계산 기대값으로 assert. 코어=0, 키나 포함, min 선택 검증.

```php
<?php
// 서버에서 `php craft/test_calc.php` 로 실행하는 검증 스크립트
$db_host='localhost'; $db_name='budget_manager'; $db_user='budget_user'; $db_pass='budget2026!';
$pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
require_once __DIR__ . '/calc.php';

$fail = 0;
function chk($name, $got, $exp) {
    global $fail;
    $ok = abs($got - $exp) < 0.001;
    if (!$ok) $fail++;
    printf("[%s] %s  got=%s exp=%s\n", $ok?'PASS':'FAIL', $name, $got, $exp);
}

// 모든 재료 단가 1로 설정(코어/키나 제외) → 재료 1개=1
$pdo->exec("UPDATE craft_materials SET unit_price = 1 WHERE is_core = 0 AND category <> '산출물'");
$ctx = craft_load_context($pdo, '목걸이');
$memo = [];

// 진룡왕 코어직접: 코어(0) + 4+14+6+8+5 = 37 재료
$r = craft_cost('진룡왕의 목걸이', $ctx, [], $memo, false);
chk('진룡왕 코어직접 최소', $r['cost'], 37);

// 코어는 0원인지: 코어직접이 달인빛나는직접(달인의빛나는=1 포함 → 1+37=38)보다 싸야 함 → 37 선택
chk('진룡왕 via 코어직접', $r['via'] === '코어직접' ? 1 : 0, 1);

// 빛나는 진룡왕 = 진룡왕(37) + 키나 700000
$memo = [];
$rs = craft_cost('빛나는 진룡왕의 목걸이', $ctx, [], $memo, false);
chk('빛나는 진룡왕 = 37 + 700000', $rs['cost'], 700037);

// 보유 아이템: 현룡왕 보유 시 현룡왕 cost=0
$memo = [];
$ro = craft_cost('현룡왕의 목걸이', $ctx, ['현룡왕의 목걸이'], $memo, false);
chk('현룡왕 보유 → 0', $ro['cost'], 0);

echo $fail === 0 ? "\nALL PASS\n" : "\n$fail FAILED\n";
exit($fail === 0 ? 0 : 1);
```

- [ ] **Step 2: 실패 확인**

```bash
git add craft/test_calc.php && git commit -m "제작계산기: 계산엔진 검증 스크립트(실패)" && git push origin main
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull origin main && php craft/test_calc.php'
```
Expected: FATAL (calc.php 없음 / craft_load_context 미정의).

- [ ] **Step 3: 계산엔진 구현**

`craft/calc.php`:

```php
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
```

- [ ] **Step 4: 통과 확인**

```bash
git add craft/calc.php && git commit -m "제작계산기: 비용 계산엔진 구현" && git push origin main
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull origin main && php craft/test_calc.php'
```
Expected: `ALL PASS`.

주의: 검증 스크립트가 `unit_price=1`로 덮어쓰므로, 끝나면 0으로 복구:
```bash
ssh aion-sanctuary "cd /var/www/html/sanctuary && php -r \"\\\$p=new PDO('mysql:host=localhost;dbname=budget_manager;charset=utf8mb4','budget_user','budget2026!'); \\\$p->exec(\\\"UPDATE craft_materials SET unit_price=0 WHERE category<>'산출물'\\\");\""
```

---

### Task 3: 명시적 루트 비교 (직접제작 / 진입티어별 계승)

**Files:**
- Modify: `craft/calc.php` (craft_enumerate_routes 확장 + 강제 레시피 선택 헬퍼)
- Modify: `craft/test_calc.php` (루트 개수/라벨 검증 추가)

**Interfaces:**
- Consumes: `craft_load_context`, `craft_breakdown`
- Produces: `craft_cost_forced(...)` — 특정 티어 진입/레시피타입을 강제해 경로 비용 산출. `craft_enumerate_routes`가 아래 루트를 반환:
  - `응룡왕 직접제작(달인의 빛나는×5)`
  - `현룡왕 코어직접 → 응룡왕 계승`
  - `천룡왕 코어직접 → 계승체인`
  - `전체 계승(진룡왕 코어직접부터)`
  - 각각 cost_fixed/cost_ev/breakdown 포함, cost_fixed 오름차순 정렬

- [ ] **Step 1: 강제 경로 헬퍼 + 루트 확장 구현**

`craft/calc.php`의 `craft_enumerate_routes`를 교체. 진입 티어 T에서 코어직접으로 base를 만들고, T+1..응룡왕은 계승 레시피만 사용하도록 강제하는 `craft_cost_forced`를 추가.

```php
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
```

- [ ] **Step 2: 루트 검증 추가**

`craft/test_calc.php` 끝(‑ `echo $fail` 앞)에 추가:

```php
$ctx2 = craft_load_context($pdo, '목걸이');
$routes = craft_enumerate_routes($ctx2, '응룡왕의 목걸이', []);
chk('루트 최소 2개 이상', count($routes) >= 2 ? 1 : 0, 1);
chk('루트는 cost 오름차순', ($routes[0]['cost_fixed'] <= $routes[count($routes)-1]['cost_fixed']) ? 1 : 0, 1);
$hasDirect = false; foreach ($routes as $r) if (mb_strpos($r['label'],'직접제작')!==false) $hasDirect=true;
chk('직접제작 루트 존재', $hasDirect ? 1 : 0, 1);
```

- [ ] **Step 3: 통과 확인 + 가격 복구**

```bash
git add craft/calc.php craft/test_calc.php && git commit -m "제작계산기: 명시적 루트 비교 엔진" && git push origin main
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull origin main && php craft/test_calc.php'
```
Expected: `ALL PASS`. 이후 가격 0 복구(Task2 Step4 명령 재실행).

---

### Task 4: 공개 뷰 — 악세/보유아이템 선택 + 루트 비교 표시

**Files:**
- Create: `craft/view.php`
- Modify: `craft.php` (debug 제거, 뷰 렌더 연결)

**Interfaces:**
- Consumes: `craft_load_context`, `craft_enumerate_routes`, `$is_admin`
- Produces: HTML 페이지. GET 파라미터 `acc`(목걸이|귀걸이|반지, 기본 목걸이), `owned`(없음|진룡왕의 목걸이|…, 기본 없음)

- [ ] **Step 1: craft.php 렌더 연결**

`craft.php`의 debug 블록과 `echo "craft init OK"`를 아래로 교체:

```php
require_once __DIR__ . '/craft/calc.php';

$acc = $_GET['acc'] ?? '목걸이';
if (!in_array($acc, ['목걸이','귀걸이','반지'], true)) $acc = '목걸이';
$target = "응룡왕의 {$acc}";
$owned_sel = $_GET['owned'] ?? '없음';
$owned = ($owned_sel === '없음') ? [] : [$owned_sel];

require_once __DIR__ . '/craft/actions.php';   // POST 처리(Task5에서 생성) — 없으면 이 줄은 Task5까지 보류
$ctx = craft_load_context($pdo, $acc);
$routes = craft_enumerate_routes($ctx, $target, $owned);

require __DIR__ . '/craft/view.php';
```

주의: `craft/actions.php`는 Task 5에서 생성하므로, 이 태스크에서는 해당 require 줄을 잠시 주석 처리한다.

- [ ] **Step 2: view.php 작성**

`craft/view.php` — 기존 다크+골드 스타일 인라인. 상단 선택 폼, 루트 비교 카드(최저가 강조), breakdown 표.

```php
<?php
$fmt = fn($n) => number_format((int)round($n));
$owned_options = ['없음'];
foreach (['진룡왕','백룡왕','명룡왕','천룡왕','현룡왕'] as $t) $owned_options[] = "{$t}의 {$acc}";
?><!DOCTYPE html><html lang="ko"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>응룡왕 제작효율 계산</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;700;900&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Noto Sans KR',sans-serif;background:#0a0c14;color:#e8eaf0;padding:24px}
.wrap{max-width:1000px;margin:0 auto}
h1{font-size:22px;color:#f0c96a;margin-bottom:16px}
.controls{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
select{padding:9px 12px;background:#141828;border:1px solid #1e2840;border-radius:6px;color:#e8eaf0;font-family:inherit}
.route-card{background:#141828;border:1px solid #1e2840;border-radius:10px;padding:16px;margin-bottom:12px}
.route-card.best{border-color:#c9a84c;box-shadow:0 0 0 1px rgba(201,168,76,.3)}
.route-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px}
.route-label{font-size:15px;font-weight:700}
.route-cost{font-size:20px;font-weight:900;color:#f0c96a}
.route-ev{font-size:12px;color:#8a9ab8}
.bd{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px}
.bd th,.bd td{padding:5px 8px;border-bottom:1px solid rgba(30,40,64,.5);text-align:left}
.bd td.num{text-align:right}
.badge{display:inline-block;font-size:10px;padding:1px 6px;border-radius:3px;background:rgba(201,168,76,.15);color:#c9a84c;margin-left:6px}
.link{color:#5a9bf5;text-decoration:none;font-size:13px}
</style></head><body><div class="wrap">
<h1>⚒️ 응룡왕 제작효율 계산기</h1>
<form method="get" class="controls">
  <select name="acc" onchange="this.form.submit()">
    <?php foreach (['목걸이','귀걸이','반지'] as $a): ?>
      <option value="<?= $a ?>" <?= $a===$acc?'selected':'' ?>><?= $a ?></option>
    <?php endforeach ?>
  </select>
  <select name="owned" onchange="this.form.submit()">
    <?php foreach ($owned_options as $o): ?>
      <option value="<?= htmlspecialchars($o) ?>" <?= $o===$owned_sel?'selected':'' ?>>
        <?= $o==='없음'?'보유 아이템 없음':'보유: '.htmlspecialchars($o) ?></option>
    <?php endforeach ?>
  </select>
  <a class="link" href="craft.php?acc=<?= $acc ?>&owned=<?= urlencode($owned_sel) ?>#prices">↓ 재료 시세 편집</a>
</form>

<?php foreach ($routes as $i => $r): ?>
<div class="route-card <?= $i===0?'best':'' ?>">
  <div class="route-head">
    <div class="route-label"><?= $i===0?'⭐ ':'' ?><?= htmlspecialchars($r['label']) ?></div>
    <div style="text-align:right">
      <div class="route-cost"><?= $fmt($r['cost_fixed']) ?></div>
      <div class="route-ev">COMBO 기대값 <?= $fmt($r['cost_ev']) ?></div>
    </div>
  </div>
  <table class="bd"><thead><tr><th>재료</th><th class="num">수량</th><th class="num">단가</th><th class="num">소계</th></tr></thead><tbody>
  <?php foreach ($r['breakdown'] as $name => $b): $qty=$b['qty']; $unit=$b['unit']; ?>
    <tr>
      <td><?= htmlspecialchars($name) ?><?= !empty($b['core'])?'<span class="badge">코어·무료</span>':'' ?></td>
      <td class="num"><?= $fmt($qty) ?></td>
      <td class="num"><?= !empty($b['core'])?'0':$fmt($unit) ?></td>
      <td class="num"><?= $fmt($qty*$unit) ?></td>
    </tr>
  <?php endforeach ?>
  </tbody></table>
</div>
<?php endforeach ?>

<!-- 시세 편집표는 Task 6에서 추가(anchor id=prices) -->
</div></body></html>
```

- [ ] **Step 3: 커밋 & 배포 & 브라우저 확인**

```bash
git add craft.php craft/view.php && git commit -m "제작계산기: 공개 뷰 + 루트 비교 표시" && git push origin main
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull origin main && php -l craft/view.php && php -l craft.php'
```
```bash
B="$HOME/.claude/skills/gstack/browse/dist/browse"
$B goto "http://14.63.164.109/sanctuary/craft.php"
$B screenshot /tmp/craft1.png
```
Read `/tmp/craft1.png` — 루트 카드 3~4개, 최저가 강조, breakdown 표 렌더 확인. 악세/보유 셀렉트 변경 시 재계산 확인.

---

### Task 5: 공개 재료 시세 편집 (누구나) + 최종 갱신 시각

**Files:**
- Create: `craft/actions.php`
- Modify: `craft.php` (actions require 주석 해제)
- Modify: `craft/view.php` (시세 편집표 섹션 추가)

**Interfaces:**
- Consumes: `$pdo`, `$_POST`
- Produces: POST `update_price` 핸들러(공개) — `material`(name), `price`(int) → craft_materials.unit_price/updated_at/updated_ip 갱신 후 redirect
- Produces: view의 `#prices` 편집표(재료명·카테고리·현재가·최종갱신·저장버튼)

- [ ] **Step 1: actions.php 작성**

`craft/actions.php`:

```php
<?php
// 공개 시세 갱신(비밀번호 불요) + 관리자 레시피 편집(Task 후속)
if (isset($_POST['update_price'])) {
    $name = trim($_POST['material'] ?? '');
    $price = max(0, (int)($_POST['price'] ?? 0));
    if ($name !== '') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $pdo->prepare("UPDATE craft_materials SET unit_price=?, updated_at=NOW(), updated_ip=? WHERE name=? AND is_core=0")
            ->execute([$price, $ip, $name]);
    }
    $q = http_build_query(['acc'=>$_POST['acc']??'목걸이','owned'=>$_POST['owned']??'없음']);
    header("Location: craft.php?$q#prices"); exit;
}
```

- [ ] **Step 2: craft.php의 actions require 주석 해제**

Task 4 Step 1에서 보류했던 줄을 활성화(위치: calc.php require 다음, 렌더 전):
```php
require_once __DIR__ . '/craft/actions.php';
```

- [ ] **Step 3: view.php에 시세 편집표 추가**

`craft/view.php`의 `<!-- 시세 편집표는 Task 6 -->` 자리를 교체:

```php
<h2 id="prices" style="font-size:18px;color:#f0c96a;margin:28px 0 12px">💰 재료 시세 (공개 편집)</h2>
<p style="font-size:12px;color:#8a9ab8;margin-bottom:12px">누구나 현재 시세로 갱신할 수 있습니다. 코어는 시즌 무료라 항상 0입니다.</p>
<table class="bd"><thead><tr><th>재료</th><th>분류</th><th class="num">단가</th><th>최종 갱신</th><th></th></tr></thead><tbody>
<?php
$mrows = $pdo->query("SELECT name,unit_price,is_core,category,updated_at,updated_ip FROM craft_materials WHERE is_core=0 AND category<>'산출물' AND category<>'키나' ORDER BY category,name")->fetchAll();
foreach ($mrows as $m): ?>
  <tr><form method="post">
    <input type="hidden" name="update_price" value="1">
    <input type="hidden" name="acc" value="<?= htmlspecialchars($acc) ?>">
    <input type="hidden" name="owned" value="<?= htmlspecialchars($owned_sel) ?>">
    <input type="hidden" name="material" value="<?= htmlspecialchars($m['name']) ?>">
    <td><?= htmlspecialchars($m['name']) ?></td>
    <td style="color:#8a9ab8"><?= htmlspecialchars($m['category']) ?></td>
    <td class="num"><input name="price" type="number" min="0" value="<?= (int)$m['unit_price'] ?>" style="width:120px;text-align:right;padding:5px;background:#0a0c14;border:1px solid #1e2840;border-radius:5px;color:#e8eaf0"></td>
    <td style="color:#8a9ab8;font-size:12px"><?= $m['updated_at'] ? htmlspecialchars($m['updated_at']) : '—' ?></td>
    <td><button style="padding:5px 12px;background:linear-gradient(135deg,#8a6830,#c9a84c);border:none;border-radius:5px;color:#0a0c14;font-weight:700;cursor:pointer">저장</button></td>
  </form></tr>
<?php endforeach ?>
</tbody></table>
```

- [ ] **Step 4: 커밋 & 배포 & 브라우저 확인**

```bash
git add craft/actions.php craft.php craft/view.php && git commit -m "제작계산기: 공개 재료 시세 편집 + 갱신시각" && git push origin main
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull origin main && php -l craft/actions.php'
```
```bash
B="$HOME/.claude/skills/gstack/browse/dist/browse"
$B goto "http://14.63.164.109/sanctuary/craft.php#prices"
$B snapshot -i
# 달인의 빛나는 루비 목걸이 price 입력칸에 예: 500000 입력 후 저장
$B fill @eN "500000"; $B click @eM
$B goto "http://14.63.164.109/sanctuary/craft.php"
$B text | grep -i "달인의 빛나는"
```
Expected: 가격 반영, 최종 갱신 시각 표시, 루트 비용이 재료값 반영해 바뀜.

---

### Task 6: 귀걸이(304)·반지(305) 스크레이핑 후 seed 확장

**Files:**
- Modify: `craft/seed_data.php` (귀걸이·반지 materials/recipes 추가)

**Interfaces:**
- Consumes: inven 페이지(304/305). Produces: seed_data에 두 악세서리 레시피.

- [ ] **Step 1: inven에서 귀걸이·반지 스크레이핑**

```bash
B="$HOME/.claude/skills/gstack/browse/dist/browse"
$B goto "https://aion2.inven.co.kr/db/craft/?race=1,0,3&class2=304"; sleep 1; $B text > /tmp/ear.txt
$B goto "https://aion2.inven.co.kr/db/craft/?race=1,0,3&class2=305"; sleep 1; $B text > /tmp/ring.txt
```
목걸이와 동일 구조. 재료명 차이(보석 계열: 귀걸이/반지의 원석·중간아이템명)만 반영. 목걸이 seed의 `$A='목걸이'` 블록을 복제해 `$A='귀걸이'`/`'반지'`로 만들고, `루비 목걸이`→해당 완제품명, `찬란한 루비 원석`→해당 원석명 등으로 치환.

- [ ] **Step 2: seed_data.php에 두 블록 추가**

스크레이핑 결과대로 `$recipes`에 귀걸이·반지 레시피 배열을 append하고, 새 재료(중간아이템·원석명)를 `$materials`에 추가. 응룡왕 계승은 두 악세도 `is_estimated=1` 추정치.

- [ ] **Step 3: 재seed & 검증**

기존 seed가 있으면 `craft_init_schema`가 재seed하지 않으므로, 서버에서 craft 테이블을 비우고 재생성:
```bash
ssh aion-sanctuary "cd /var/www/html/sanctuary && php -r \"\\\$p=new PDO('mysql:host=localhost;dbname=budget_manager;charset=utf8mb4','budget_user','budget2026!'); foreach(['craft_recipe_inputs','craft_recipes','craft_materials'] as \\\$t) \\\$p->exec(\\\"DROP TABLE IF EXISTS \\\$t\\\");\""
```
```bash
git add craft/seed_data.php && git commit -m "제작계산기: 귀걸이·반지 seed 추가" && git push origin main
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull origin main'
B="$HOME/.claude/skills/gstack/browse/dist/browse"
$B goto "http://14.63.164.109/sanctuary/craft.php?acc=귀걸이"; $B screenshot /tmp/ear.png
$B goto "http://14.63.164.109/sanctuary/craft.php?acc=반지"; $B screenshot /tmp/ring.png
```
Read 두 스크린샷 — 귀걸이/반지 루트 카드 정상 렌더 확인.

---

### Task 7: 관리자 레시피 편집 (응룡왕 계승 확정값 입력)

**Files:**
- Modify: `craft/actions.php` (edit_recipe 핸들러, 관리자 전용)
- Modify: `craft/view.php` (관리자에게 레시피 편집 링크/폼 노출)

**Interfaces:**
- Consumes: `$is_admin`, `$_POST`
- Produces: POST `edit_recipe`(관리자) — recipe_id, kina_cost, is_estimated, inputs(JSON: [[material,qty],...]) → 해당 레시피 inputs 교체 + 필드 갱신

- [ ] **Step 1: 관리자 레시피 편집 핸들러**

`craft/actions.php`에 추가:

```php
if (isset($_POST['edit_recipe']) && !empty($_SESSION['sanctuary_admin'])) {
    $rid = (int)($_POST['recipe_id'] ?? 0);
    $kina = max(0, (int)($_POST['kina_cost'] ?? 0));
    $est = isset($_POST['is_estimated']) ? 1 : 0;
    $inputs = json_decode($_POST['inputs'] ?? '[]', true);
    if ($rid && is_array($inputs)) {
        $pdo->prepare("UPDATE craft_recipes SET kina_cost=?, is_estimated=? WHERE id=?")->execute([$kina,$est,$rid]);
        $pdo->prepare("DELETE FROM craft_recipe_inputs WHERE recipe_id=?")->execute([$rid]);
        $ins = $pdo->prepare("INSERT INTO craft_recipe_inputs (recipe_id,material_name,qty) VALUES (?,?,?)");
        foreach ($inputs as $it) {
            $nm = trim($it[0] ?? ''); $q = (int)($it[1] ?? 0);
            if ($nm !== '' && $q > 0) $ins->execute([$rid, $nm, $q]);
        }
    }
    $q = http_build_query(['acc'=>$_POST['acc']??'목걸이','owned'=>'없음']);
    header("Location: craft.php?$q#recipes"); exit;
}
```

- [ ] **Step 2: 관리자 레시피 편집 UI**

`craft/view.php` 하단에 관리자에게만 노출. 각 레시피의 inputs를 `name×qty` 텍스트로 편집(줄바꿈 구분) → JS로 JSON 직렬화 후 제출. `is_estimated` 레시피는 눈에 띄게 표시(응룡왕 계승 대상).

```php
<?php if ($is_admin): ?>
<h2 id="recipes" style="font-size:18px;color:#f0c96a;margin:28px 0 12px">🛠 레시피 편집 (관리자)</h2>
<?php
$recs = $pdo->prepare("SELECT * FROM craft_recipes WHERE accessory=? ORDER BY tier,recipe_type");
$recs->execute([$acc]);
foreach ($recs->fetchAll() as $rec):
  $inp = $pdo->prepare("SELECT material_name,qty FROM craft_recipe_inputs WHERE recipe_id=?");
  $inp->execute([$rec['id']]);
  $lines = array_map(fn($x)=>$x['material_name'].' x'.$x['qty'], $inp->fetchAll());
?>
<div class="route-card" style="<?= $rec['is_estimated']?'border-color:#e74c3c':'' ?>">
  <div style="font-weight:700;margin-bottom:8px"><?= htmlspecialchars($rec['output_name']) ?>
    <span class="badge"><?= htmlspecialchars($rec['recipe_type']) ?></span>
    <?= $rec['is_estimated']?'<span class="badge" style="background:rgba(231,76,60,.2);color:#e74c3c">추정치</span>':'' ?></div>
  <form method="post" onsubmit="this.inputs.value=JSON.stringify(this.raw.value.split('\n').map(l=>l.trim()).filter(Boolean).map(l=>{const m=l.match(/^(.*)\sx(\d+)$/);return m?[m[1].trim(),parseInt(m[2])]:null}).filter(Boolean))">
    <input type="hidden" name="edit_recipe" value="1">
    <input type="hidden" name="recipe_id" value="<?= (int)$rec['id'] ?>">
    <input type="hidden" name="acc" value="<?= htmlspecialchars($acc) ?>">
    <input type="hidden" name="inputs">
    <textarea name="raw" rows="<?= max(2,count($lines)) ?>" style="width:100%;background:#0a0c14;border:1px solid #1e2840;border-radius:6px;color:#e8eaf0;padding:8px;font-family:inherit"><?= htmlspecialchars(implode("\n",$lines)) ?></textarea>
    <div style="display:flex;gap:10px;align-items:center;margin-top:8px">
      <label style="font-size:13px">키나 <input name="kina_cost" type="number" min="0" value="<?= (int)$rec['kina_cost'] ?>" style="width:130px;padding:5px;background:#0a0c14;border:1px solid #1e2840;border-radius:5px;color:#e8eaf0"></label>
      <label style="font-size:13px"><input type="checkbox" name="is_estimated" <?= $rec['is_estimated']?'checked':'' ?>> 추정치</label>
      <button style="margin-left:auto;padding:6px 16px;background:linear-gradient(135deg,#8a6830,#c9a84c);border:none;border-radius:5px;color:#0a0c14;font-weight:700;cursor:pointer">저장</button>
    </div>
  </form>
</div>
<?php endforeach ?>
<?php endif ?>
```

- [ ] **Step 3: 커밋 & 배포 & 관리자 확인**

```bash
git add craft/actions.php craft/view.php && git commit -m "제작계산기: 관리자 레시피 편집(응룡왕 계승 확정값 입력)" && git push origin main
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull origin main && php -l craft/actions.php && php -l craft/view.php'
```
브라우저에서 관리자 로그인 세션으로 `craft.php` 접속 → 응룡왕 계승(추정치) 레시피 수정 → 저장 → 루트 비용 반영 확인. (23:00 실제 값 입력에 사용)

---

## Self-Review 결과

- **스펙 커버리지**: 비용모델(Task2), COMBO 확정/기대값(Task2 calc + view), 루트 비교(Task3), DB 3테이블(Task1), 공개 시세편집+갱신시각(Task5), 관리자 레시피편집/응룡왕 계승 추정치(Task1 seed + Task7), 3종 악세(Task6), 비번없는 접근(Task1 craft.php). 모두 태스크 존재.
- **Placeholder 스캔**: 응룡왕 계승 "추정치"는 의도된 값(스펙 명시). 그 외 TODO/TBD 없음. Task6 스크레이핑 재료명은 실행 시 실제 데이터로 채움(구조는 목걸이와 동일).
- **타입 일관성**: `craft_load_context` 반환 구조(price/core/recipes)를 calc/breakdown/enumerate가 동일하게 사용. recipe 배열 키(type/kina/combo/estimated/inputs) 일관. view는 route의 label/cost_fixed/cost_ev/breakdown, breakdown 항목의 qty/unit/core 사용 — calc 산출과 일치.

## 알려진 한계 / 후속

- COMBO 기대값은 빛나는승급 키나를 combo율만큼 절감하는 근사. 실제 확률적 재제작 비용은 근사치.
- 가격 미입력 leaf는 0으로 계산 → 루트 비용이 과소평가될 수 있음(UI에서 미입력 표시는 후속 개선).
- 응룡왕 계승 레시피는 23:00 확정값으로 관리자 수정 필요(Task7 UI로 처리).
