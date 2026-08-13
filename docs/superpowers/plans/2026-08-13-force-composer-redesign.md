# 포스 편성 사이트 전면 개편 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 엑셀로 하던 레이드 포스 편성을, 캐릭터를 드래그해서 배치하는 단일 페이지 웹 도구로 대체한다.

**Architecture:** 빌드 도구 없는 PHP + MySQL. `force/store.php`가 DB 조작을 전담하고
`force/api.php`가 그 위에 JSON HTTP 경계를 씌운다. 브라우저는 `assets/app.js`가 서버 state
스냅샷 하나를 받아 화면 전체를 그리고, 조작은 슬롯 단위 API 호출로 되돌려보낸다.
기존 신청/자동편성 코드(`sections/`, `actions/`, `cron/`)는 전부 삭제한다.

**Tech Stack:** PHP 7.4.3 (CLI + Apache), MySQL (`budget_manager` DB), 바닐라 JS(ES2019),
HTML5 Drag and Drop API, 검증은 gstack browse 헤드리스 브라우저.

## Global Constraints

- **서버 PHP는 7.4.3.** `match`, `str_contains`, `str_starts_with`, nullsafe(`?->`),
  생성자 프로퍼티 승격 사용 금지. `switch`, `strpos`, `substr` 등으로 대체한다.
- **프론트엔드에 빌드 단계가 없다.** npm, 번들러, 트랜스파일러, CDN 프레임워크 모두 쓰지 않는다.
  브라우저가 `assets/app.js`를 그대로 읽는다. 폰트만 기존과 동일하게 Google Fonts Noto Sans KR.
- **로컬에 PHP가 없다.** 모든 검증은 서버에서 한다. 사이클은
  `git commit` → `git push origin main` → `ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main && ...'`.
  원격 `main`은 upstream 추적이 없으므로 항상 `origin main`을 명시한다.
- **`/var/www/html/sanctuary/` 밖은 절대 건드리지 않는다.** php.ini, apache2, systemd, MySQL 설정,
  다른 호스팅 프로젝트 모두 금지.
- **기존 `sanctuary_*` 및 `craft_*` 테이블에 DROP/TRUNCATE/DELETE를 실행하지 않는다.**
  새 앱은 `fc_*` 테이블만 만들고 쓴다. 구 테이블은 참조만 끊고 그대로 둔다.
- **`sanctuary_config.json`은 서버에서 gitignore 대상이다. 덮어쓰지 않는다.**
  현재 사이트 비밀번호는 `sksldktnv0318`.
- **`craft.php`와 `craft/` 디렉터리는 변경하지 않는다.** 별개 기능으로 계속 살아 있다.
- DB 접속 정보: host `localhost`, dbname `budget_manager`, user `budget_user`, pass `budget2026!`.
- 직업은 8종 고정: `수호성, 검성, 살성, 궁성, 호법성, 정령성, 마도성, 치유성`.
- 포스는 고정 2파티 × 5슬롯 = 10명.
- 커밋 메시지는 한국어. 매 커밋 끝에
  `Co-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>` 를 붙인다.
- 테스트 데이터는 전부 `zzTest_` 접두사를 쓰고 테스트가 스스로 지운다. 실제 데이터를 건드리지 않는다.

## File Structure

| 파일 | 책임 |
|---|---|
| `force/db.php` | PDO 커넥션 하나를 만들어 돌려준다. 그 외 아무것도 모른다. |
| `force/schema.php` | `fc_*` 테이블 DDL. 멱등 생성만 담당. |
| `force/store.php` | DB 조작 함수 전부. HTTP도 HTML도 모른다. |
| `force/atul.php` | aion2.plaync.com 조회 하나. DB를 모른다. |
| `force/api.php` | HTTP/JSON 경계. 요청 검증 → store 호출 → `{ok, data}`. |
| `force/test_api.php` | 서버에서 돌리는 스모크 테스트. |
| `index.php` | 비밀번호 게이트 + 셸 HTML + 초기 state 임베드. |
| `assets/app.css` | 디자인 토큰과 전체 스타일. |
| `assets/app.js` | state 렌더 · 팝오버 · 드래그앤드롭 · 폴링. |

삭제: `sections/`, `actions/`, `cron/`, `migrate_add_19.php`

---

### Task 1: 기반 — DB 커넥션 · 스키마 · 테스트 하네스

**Files:**
- Create: `force/db.php`
- Create: `force/schema.php`
- Create: `force/test_api.php`

**Interfaces:**
- Consumes: 없음
- Produces:
  - `fc_pdo(): PDO`
  - `fc_init_schema(PDO $pdo): void`
  - 테스트 하네스: `t_section(string $name): void`, `t_ok(bool $cond, string $label): void`,
    `t_eq($actual, $expected, string $label): void`, `t_summary(): int`

- [ ] **Step 1: 실패하는 테스트 작성**

`force/test_api.php`:

```php
<?php
// 서버에서 `php force/test_api.php` 로 실행하는 스모크 테스트.
// 만드는 데이터는 전부 zzTest_ 접두사를 쓰고 스스로 지운다.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';

$T_pass = 0;
$T_fail = 0;

function t_section($name) {
    echo "\n\033[1m== $name ==\033[0m\n";
}

function t_ok($cond, $label) {
    global $T_pass, $T_fail;
    if ($cond) { $T_pass++; echo "  \033[32m✓\033[0m $label\n"; }
    else       { $T_fail++; echo "  \033[31m✗\033[0m $label\n"; }
}

function t_eq($actual, $expected, $label) {
    $same = ($actual === $expected);
    t_ok($same, $same ? $label
        : $label . ' — 기대 ' . var_export($expected, true) . ', 실제 ' . var_export($actual, true));
}

function t_summary() {
    global $T_pass, $T_fail;
    echo "\n" . ($T_fail === 0 ? "\033[32m전체 통과" : "\033[31m실패 있음")
       . "\033[0m — 통과 $T_pass / 실패 $T_fail\n";
    return $T_fail === 0 ? 0 : 1;
}

$pdo = fc_pdo();

t_section('스키마');
fc_init_schema($pdo);
fc_init_schema($pdo); // 멱등성: 두 번 돌려도 예외가 없어야 한다
t_ok(true, 'fc_init_schema를 두 번 실행해도 예외가 없다');

$tables = ['fc_players', 'fc_characters', 'fc_raids', 'fc_forces', 'fc_slots', 'fc_meta'];
foreach ($tables as $tbl) {
    $found = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($tbl))->fetch();
    t_ok($found !== false, "$tbl 테이블이 존재한다");
}

$rev = $pdo->query("SELECT v FROM fc_meta WHERE k = 'revision'")->fetchColumn();
t_ok($rev !== false, 'fc_meta에 revision 행이 초기화되어 있다');

exit(t_summary());
```

- [ ] **Step 2: 테스트가 실패하는지 서버에서 확인**

```bash
git add force/test_api.php && \
git commit -m "$(printf '테스트: 포스 편성 스모크 테스트 하네스 추가\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main && php force/test_api.php'
```

Expected: FAIL — `Failed opening required '.../force/db.php'`

- [ ] **Step 3: db.php 구현**

`force/db.php`:

```php
<?php
// PDO 커넥션 하나만 담당한다. 스키마도 쿼리도 모른다.

function fc_pdo() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=localhost;dbname=budget_manager;charset=utf8mb4',
            'budget_user',
            'budget2026!'
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }
    return $pdo;
}
```

- [ ] **Step 4: schema.php 구현**

`force/schema.php`:

```php
<?php
// fc_* 테이블 DDL. 멱등 생성만 담당한다.
// 기존 sanctuary_* / craft_* 테이블은 절대 건드리지 않는다.

function fc_init_schema(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fc_players (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        sort_order INT      NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fc_characters (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        player_id       INT          NOT NULL,
        char_name       VARCHAR(100) NOT NULL,
        char_class      VARCHAR(20)  NOT NULL DEFAULT '',
        atul_score      INT          NULL,
        item_level      INT          NULL,
        is_main         TINYINT(1)   NOT NULL DEFAULT 0,
        sort_order      INT          NOT NULL DEFAULT 0,
        atul_updated_at DATETIME     NULL,
        UNIQUE KEY uq_char_name (char_name),
        KEY idx_player (player_id)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fc_raids (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(50)  NOT NULL,
        memo       VARCHAR(200) NOT NULL DEFAULT '',
        sort_order INT          NOT NULL DEFAULT 0,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fc_forces (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        raid_id     INT          NOT NULL,
        force_no    INT          NOT NULL,
        day_of_week VARCHAR(3)   NULL,
        start_time  VARCHAR(5)   NULL,
        memo        VARCHAR(200) NOT NULL DEFAULT '',
        sort_order  INT          NOT NULL DEFAULT 0,
        KEY idx_raid (raid_id)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fc_slots (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        force_id     INT     NOT NULL,
        party_no     TINYINT NOT NULL,
        slot_no      TINYINT NOT NULL,
        character_id INT     NULL,
        UNIQUE KEY uq_slot (force_id, party_no, slot_no),
        KEY idx_character (character_id)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fc_meta (
        k VARCHAR(40) PRIMARY KEY,
        v BIGINT NOT NULL DEFAULT 0
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("INSERT IGNORE INTO fc_meta (k, v) VALUES ('revision', 0)");
}
```

- [ ] **Step 5: 테스트가 통과하는지 서버에서 확인**

```bash
git add force/db.php force/schema.php && \
git commit -m "$(printf '포스 편성: DB 커넥션과 fc_* 스키마 추가\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main && php -l force/db.php && php -l force/schema.php && php force/test_api.php'
```

Expected: PASS — 8개 항목 전부 ✓, `전체 통과`

---

### Task 2: store — 인원 등록과 삭제

**Files:**
- Create: `force/store.php`
- Modify: `force/test_api.php` (Task 1에서 만든 파일 끝의 `exit(t_summary());` 앞에 추가)

**Interfaces:**
- Consumes: `fc_pdo()`, `fc_init_schema()`, 테스트 하네스 (Task 1)
- Produces:
  - `fc_bump_revision(PDO $pdo): int`
  - `fc_revision(PDO $pdo): int`
  - `fc_create_player(PDO $pdo, string $mainName, array $subNames = [], $lookup = null): int` — player_id 반환. 이름 중복 시 `RuntimeException`
  - `fc_add_character(PDO $pdo, int $playerId, string $name, $lookup = null): int` — character_id 반환
  - `fc_update_character(PDO $pdo, int $id, array $fields): void` — `name`, `class`, `atul`, `item_level` 키만 허용
  - `fc_delete_character(PDO $pdo, int $id): void`
  - `fc_delete_player(PDO $pdo, int $id): void`
  - `fc_cleanup_test_data(PDO $pdo): void`
  - `$lookup` 규약: `function (string $name): ?array` — `['class'=>string,'atul'=>?int,'item_level'=>?int]` 또는 `null`

- [ ] **Step 1: 실패하는 테스트 작성**

`force/test_api.php`의 `exit(t_summary());` 바로 앞에 삽입하고, 파일 상단 `require_once` 목록에
`require_once __DIR__ . '/store.php';` 를 추가:

```php
fc_cleanup_test_data($pdo);

t_section('인원 등록');

$rev0 = fc_revision($pdo);
$pid  = fc_create_player($pdo, 'zzTest_본캐', ['zzTest_부캐A', 'zzTest_부캐B']);
t_ok($pid > 0, '플레이어가 생성되고 id를 돌려준다');
t_ok(fc_revision($pdo) > $rev0, '쓰기 후 revision이 증가한다');

$chars = $pdo->query("SELECT char_name, is_main, sort_order FROM fc_characters
                      WHERE player_id = $pid ORDER BY sort_order")->fetchAll();
t_eq(count($chars), 3, '본캐 1 + 부캐 2 = 캐릭터 3개가 등록된다');
t_eq($chars[0]['char_name'], 'zzTest_본캐', '첫 행이 본캐다');
t_eq((int)$chars[0]['is_main'], 1, '본캐의 is_main이 1이다');
t_eq((int)$chars[1]['is_main'], 0, '부캐의 is_main이 0이다');
t_eq((int)$chars[2]['is_main'], 0, '두 번째 부캐의 is_main도 0이다');

t_section('캐릭명 중복 거부');

$dup_rejected = false;
try {
    fc_create_player($pdo, 'zzTest_부캐A');
} catch (RuntimeException $e) {
    $dup_rejected = (strpos($e->getMessage(), 'duplicate_name') !== false);
}
t_ok($dup_rejected, '이미 있는 캐릭명으로 등록하면 duplicate_name 예외가 난다');

$leftover = (int)$pdo->query("SELECT COUNT(*) FROM fc_players")->fetchColumn();
$pdo->query("SELECT 1"); // no-op
t_ok($leftover >= 1, '중복 실패가 다른 플레이어를 지우지 않는다');

t_section('아툴 조회 주입');

$fake_lookup = function ($name) {
    return ['class' => '검성', 'atul' => 41200, 'item_level' => 3520];
};
$cid = fc_add_character($pdo, $pid, 'zzTest_부캐C', $fake_lookup);
$row = $pdo->query("SELECT char_class, atul_score, item_level FROM fc_characters WHERE id = $cid")->fetch();
t_eq($row['char_class'], '검성', 'lookup 결과로 직업이 채워진다');
t_eq((int)$row['atul_score'], 41200, 'lookup 결과로 아툴점수가 채워진다');
t_eq((int)$row['item_level'], 3520, 'lookup 결과로 아이템레벨이 채워진다');

$null_lookup = function ($name) { return null; };
$cid2 = fc_add_character($pdo, $pid, 'zzTest_부캐D', $null_lookup);
$row2 = $pdo->query("SELECT char_class, atul_score FROM fc_characters WHERE id = $cid2")->fetch();
t_eq($row2['char_class'], '', 'lookup 실패해도 등록은 되고 직업은 빈 값이다');
t_ok($row2['atul_score'] === null, 'lookup 실패 시 아툴점수는 NULL이다');

t_section('캐릭터 수정과 삭제');

fc_update_character($pdo, $cid2, ['class' => '치유성', 'atul' => 38000]);
$row3 = $pdo->query("SELECT char_class, atul_score FROM fc_characters WHERE id = $cid2")->fetch();
t_eq($row3['char_class'], '치유성', '직업을 손으로 고칠 수 있다');
t_eq((int)$row3['atul_score'], 38000, '아툴점수를 손으로 고칠 수 있다');

fc_delete_character($pdo, $cid2);
$gone = $pdo->query("SELECT COUNT(*) FROM fc_characters WHERE id = $cid2")->fetchColumn();
t_eq((int)$gone, 0, '캐릭터가 삭제된다');

fc_delete_player($pdo, $pid);
$left = $pdo->query("SELECT COUNT(*) FROM fc_characters WHERE player_id = $pid")->fetchColumn();
t_eq((int)$left, 0, '플레이어를 지우면 소속 캐릭터가 전부 사라진다');

fc_cleanup_test_data($pdo);
```

- [ ] **Step 2: 테스트가 실패하는지 서버에서 확인**

```bash
git add force/test_api.php && \
git commit -m "$(printf '테스트: 인원 등록/삭제 케이스 추가\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main && php force/test_api.php'
```

Expected: FAIL — `Failed opening required '.../force/store.php'`

- [ ] **Step 3: store.php 구현**

`force/store.php`:

```php
<?php
// fc_* 테이블 조작 전담. HTTP도 HTML도 모른다.

function fc_revision(PDO $pdo) {
    return (int)$pdo->query("SELECT v FROM fc_meta WHERE k = 'revision'")->fetchColumn();
}

function fc_bump_revision(PDO $pdo) {
    $pdo->exec("UPDATE fc_meta SET v = v + 1 WHERE k = 'revision'");
    return fc_revision($pdo);
}

// $lookup: function(string $name): ?array{class:string, atul:?int, item_level:?int}
function fc_apply_lookup($lookup, $name) {
    $blank = ['class' => '', 'atul' => null, 'item_level' => null];
    if (!is_callable($lookup)) return $blank;
    $got = call_user_func($lookup, $name);
    if (!is_array($got)) return $blank;
    return [
        'class'      => isset($got['class']) ? (string)$got['class'] : '',
        'atul'       => isset($got['atul']) && $got['atul'] !== null ? (int)$got['atul'] : null,
        'item_level' => isset($got['item_level']) && $got['item_level'] !== null ? (int)$got['item_level'] : null,
    ];
}

function fc_name_exists(PDO $pdo, $name) {
    $st = $pdo->prepare("SELECT 1 FROM fc_characters WHERE char_name = ?");
    $st->execute([$name]);
    return (bool)$st->fetch();
}

function fc_insert_character(PDO $pdo, $playerId, $name, $isMain, $sortOrder, $lookup) {
    $name = trim($name);
    if ($name === '') throw new RuntimeException('empty_name');
    if (fc_name_exists($pdo, $name)) throw new RuntimeException('duplicate_name:' . $name);

    $info = fc_apply_lookup($lookup, $name);
    $st = $pdo->prepare("INSERT INTO fc_characters
        (player_id, char_name, char_class, atul_score, item_level, is_main, sort_order, atul_updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $st->execute([
        $playerId, $name, $info['class'], $info['atul'], $info['item_level'],
        $isMain ? 1 : 0, $sortOrder,
        $info['atul'] === null ? null : date('Y-m-d H:i:s'),
    ]);
    return (int)$pdo->lastInsertId();
}

function fc_create_player(PDO $pdo, $mainName, array $subNames = [], $lookup = null) {
    $mainName = trim($mainName);
    if ($mainName === '') throw new RuntimeException('empty_name');

    // 중복은 어떤 캐릭터도 만들기 전에 전부 확인한다 — 절반만 등록되는 상태를 만들지 않는다
    $all = array_merge([$mainName], array_map('trim', $subNames));
    $all = array_values(array_filter($all, function ($n) { return $n !== ''; }));
    if (count($all) !== count(array_unique($all))) throw new RuntimeException('duplicate_name:입력 안에 같은 이름');
    foreach ($all as $n) {
        if (fc_name_exists($pdo, $n)) throw new RuntimeException('duplicate_name:' . $n);
    }

    $nextOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM fc_players")->fetchColumn();
    $pdo->prepare("INSERT INTO fc_players (sort_order) VALUES (?)")->execute([$nextOrder]);
    $playerId = (int)$pdo->lastInsertId();

    foreach ($all as $i => $n) {
        fc_insert_character($pdo, $playerId, $n, $i === 0, $i, $lookup);
    }
    fc_bump_revision($pdo);
    return $playerId;
}

function fc_add_character(PDO $pdo, $playerId, $name, $lookup = null) {
    $st = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM fc_characters WHERE player_id = ?");
    $st->execute([$playerId]);
    $order = (int)$st->fetchColumn();

    $id = fc_insert_character($pdo, $playerId, $name, false, $order, $lookup);
    fc_bump_revision($pdo);
    return $id;
}

function fc_update_character(PDO $pdo, $id, array $fields) {
    $map = ['name' => 'char_name', 'class' => 'char_class', 'atul' => 'atul_score', 'item_level' => 'item_level'];
    $sets = [];
    $args = [];
    foreach ($map as $key => $col) {
        if (!array_key_exists($key, $fields)) continue;
        if ($key === 'name') {
            $newName = trim($fields['name']);
            if ($newName === '') throw new RuntimeException('empty_name');
            $st = $pdo->prepare("SELECT 1 FROM fc_characters WHERE char_name = ? AND id <> ?");
            $st->execute([$newName, $id]);
            if ($st->fetch()) throw new RuntimeException('duplicate_name:' . $newName);
            $sets[] = "$col = ?"; $args[] = $newName;
        } else {
            $sets[] = "$col = ?";
            $args[] = $fields[$key] === null || $fields[$key] === '' ? null : $fields[$key];
        }
    }
    if (!$sets) return;
    $args[] = $id;
    $pdo->prepare("UPDATE fc_characters SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
    fc_bump_revision($pdo);
}

function fc_delete_character(PDO $pdo, $id) {
    // 배치된 슬롯은 비우되 슬롯 행 자체는 남긴다 (포스의 칸 구조는 유지)
    $pdo->prepare("UPDATE fc_slots SET character_id = NULL WHERE character_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM fc_characters WHERE id = ?")->execute([$id]);
    fc_bump_revision($pdo);
}

function fc_delete_player(PDO $pdo, $id) {
    $st = $pdo->prepare("SELECT id FROM fc_characters WHERE player_id = ?");
    $st->execute([$id]);
    foreach ($st->fetchAll() as $row) {
        $pdo->prepare("UPDATE fc_slots SET character_id = NULL WHERE character_id = ?")->execute([$row['id']]);
    }
    $pdo->prepare("DELETE FROM fc_characters WHERE player_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM fc_players WHERE id = ?")->execute([$id]);
    fc_bump_revision($pdo);
}

// 테스트 전용: zzTest_ 접두사가 붙은 것만 지운다. 실제 데이터는 건드리지 않는다.
function fc_cleanup_test_data(PDO $pdo) {
    $pids = $pdo->query("SELECT DISTINCT player_id FROM fc_characters WHERE char_name LIKE 'zzTest\\_%'")
                ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($pids as $pid) {
        fc_delete_player($pdo, (int)$pid);
    }
    $rids = $pdo->query("SELECT id FROM fc_raids WHERE name LIKE 'zzTest\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rids as $rid) {
        $fids = $pdo->query("SELECT id FROM fc_forces WHERE raid_id = " . (int)$rid)->fetchAll(PDO::FETCH_COLUMN);
        foreach ($fids as $fid) {
            $pdo->exec("DELETE FROM fc_slots WHERE force_id = " . (int)$fid);
        }
        $pdo->exec("DELETE FROM fc_forces WHERE raid_id = " . (int)$rid);
        $pdo->exec("DELETE FROM fc_raids WHERE id = " . (int)$rid);
    }
}
```

- [ ] **Step 4: 테스트가 통과하는지 서버에서 확인**

```bash
git add force/store.php && \
git commit -m "$(printf '포스 편성: 인원 등록/수정/삭제 store 함수 추가\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main && php -l force/store.php && php force/test_api.php'
```

Expected: PASS — 인원 등록 / 캐릭명 중복 거부 / 아툴 조회 주입 / 캐릭터 수정과 삭제 섹션 전부 ✓

---

### Task 3: store — 레이드와 포스 (빈 슬롯 10행 자동 생성)

**Files:**
- Modify: `force/store.php` (파일 끝의 `fc_cleanup_test_data` 함수 앞에 추가)
- Modify: `force/test_api.php` (`fc_cleanup_test_data($pdo);` 로 끝나는 줄 앞에 추가)

**Interfaces:**
- Consumes: `fc_bump_revision()`, `fc_cleanup_test_data()` (Task 2)
- Produces:
  - `fc_create_raid(PDO $pdo, string $name): int`
  - `fc_update_raid(PDO $pdo, int $id, array $fields): void` — `name`, `memo` 키만 허용
  - `fc_delete_raid(PDO $pdo, int $id): void`
  - `fc_create_force(PDO $pdo, int $raidId, $day, $time, string $memo = ''): int`
  - `fc_update_force(PDO $pdo, int $id, array $fields): void` — `day_of_week`, `start_time`, `memo` 키만 허용
  - `fc_delete_force(PDO $pdo, int $id): void`

- [ ] **Step 1: 실패하는 테스트 작성**

`force/test_api.php`의 마지막 `fc_cleanup_test_data($pdo);` 앞에 삽입:

```php
t_section('레이드와 포스');

$rid = fc_create_raid($pdo, 'zzTest_루드라');
t_ok($rid > 0, '레이드가 생성된다');

$fid1 = fc_create_force($pdo, $rid, '토', '19:30', '남는자리 새싹');
$fid2 = fc_create_force($pdo, $rid, '토', '19:40', '');
t_ok($fid1 > 0 && $fid2 > 0, '포스가 두 개 생성된다');

$nos = $pdo->query("SELECT force_no FROM fc_forces WHERE raid_id = $rid ORDER BY force_no")
           ->fetchAll(PDO::FETCH_COLUMN);
t_eq(array_map('intval', $nos), [1, 2], '포스 번호가 1, 2로 자동 부여된다');

$slotCount = (int)$pdo->query("SELECT COUNT(*) FROM fc_slots WHERE force_id = $fid1")->fetchColumn();
t_eq($slotCount, 10, '포스 생성 시 빈 슬롯이 정확히 10행 생긴다');

$shape = $pdo->query("SELECT party_no, COUNT(*) c FROM fc_slots WHERE force_id = $fid1
                      GROUP BY party_no ORDER BY party_no")->fetchAll();
t_eq(count($shape), 2, '슬롯이 두 파티로 나뉜다');
t_eq((int)$shape[0]['c'], 5, '1파티가 5슬롯이다');
t_eq((int)$shape[1]['c'], 5, '2파티가 5슬롯이다');

$empty = (int)$pdo->query("SELECT COUNT(*) FROM fc_slots WHERE force_id = $fid1 AND character_id IS NULL")
                  ->fetchColumn();
t_eq($empty, 10, '새 포스의 슬롯은 전부 비어 있다');

$f1 = $pdo->query("SELECT day_of_week, start_time, memo FROM fc_forces WHERE id = $fid1")->fetch();
t_eq($f1['day_of_week'], '토', '요일이 저장된다');
t_eq($f1['start_time'], '19:30', '시각이 저장된다');
t_eq($f1['memo'], '남는자리 새싹', '메모가 저장된다');

fc_update_force($pdo, $fid1, ['day_of_week' => '일', 'start_time' => '20:00']);
$f1b = $pdo->query("SELECT day_of_week, start_time FROM fc_forces WHERE id = $fid1")->fetch();
t_eq($f1b['day_of_week'], '일', '요일을 수정할 수 있다');
t_eq($f1b['start_time'], '20:00', '시각을 수정할 수 있다');

t_section('포스 삭제 시 번호를 다시 매기지 않는다');

fc_delete_force($pdo, $fid1);
$slotsGone = (int)$pdo->query("SELECT COUNT(*) FROM fc_slots WHERE force_id = $fid1")->fetchColumn();
t_eq($slotsGone, 0, '포스를 지우면 슬롯 10행도 함께 사라진다');

$remain = $pdo->query("SELECT force_no FROM fc_forces WHERE raid_id = $rid")->fetchAll(PDO::FETCH_COLUMN);
t_eq(array_map('intval', $remain), [2], '남은 포스의 번호는 2 그대로다 (재부여 없음)');

$fid3 = fc_create_force($pdo, $rid, '월', '21:00', '');
$no3 = (int)$pdo->query("SELECT force_no FROM fc_forces WHERE id = $fid3")->fetchColumn();
t_eq($no3, 3, '새 포스는 MAX+1인 3번을 받는다');

t_section('레이드 삭제');

fc_update_raid($pdo, $rid, ['memo' => '토요일 고정']);
$memo = $pdo->query("SELECT memo FROM fc_raids WHERE id = $rid")->fetchColumn();
t_eq($memo, '토요일 고정', '레이드 메모를 수정할 수 있다');

fc_delete_raid($pdo, $rid);
t_eq((int)$pdo->query("SELECT COUNT(*) FROM fc_raids WHERE id = $rid")->fetchColumn(), 0, '레이드가 삭제된다');
t_eq((int)$pdo->query("SELECT COUNT(*) FROM fc_forces WHERE raid_id = $rid")->fetchColumn(), 0, '소속 포스가 함께 삭제된다');
t_eq((int)$pdo->query("SELECT COUNT(*) FROM fc_slots WHERE force_id IN ($fid2, $fid3)")->fetchColumn(), 0, '소속 포스의 슬롯도 함께 삭제된다');
```

- [ ] **Step 2: 테스트가 실패하는지 서버에서 확인**

```bash
git add force/test_api.php && \
git commit -m "$(printf '테스트: 레이드/포스 생성·삭제 케이스 추가\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main && php force/test_api.php'
```

Expected: FAIL — `Call to undefined function fc_create_raid()`

- [ ] **Step 3: 레이드/포스 함수 구현**

`force/store.php`의 `fc_cleanup_test_data` 함수 정의 바로 앞에 삽입:

```php
function fc_create_raid(PDO $pdo, $name) {
    $name = trim($name);
    if ($name === '') throw new RuntimeException('empty_name');
    $order = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM fc_raids")->fetchColumn();
    $pdo->prepare("INSERT INTO fc_raids (name, sort_order) VALUES (?, ?)")->execute([$name, $order]);
    $id = (int)$pdo->lastInsertId();
    fc_bump_revision($pdo);
    return $id;
}

function fc_update_raid(PDO $pdo, $id, array $fields) {
    $sets = [];
    $args = [];
    if (array_key_exists('name', $fields)) {
        $n = trim($fields['name']);
        if ($n === '') throw new RuntimeException('empty_name');
        $sets[] = 'name = ?'; $args[] = $n;
    }
    if (array_key_exists('memo', $fields)) {
        $sets[] = 'memo = ?'; $args[] = (string)$fields['memo'];
    }
    if (!$sets) return;
    $args[] = $id;
    $pdo->prepare("UPDATE fc_raids SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
    fc_bump_revision($pdo);
}

function fc_delete_raid(PDO $pdo, $id) {
    $st = $pdo->prepare("SELECT id FROM fc_forces WHERE raid_id = ?");
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $fid) {
        $pdo->prepare("DELETE FROM fc_slots WHERE force_id = ?")->execute([(int)$fid]);
    }
    $pdo->prepare("DELETE FROM fc_forces WHERE raid_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM fc_raids WHERE id = ?")->execute([$id]);
    fc_bump_revision($pdo);
}

// 포스 번호는 레이드별 MAX+1. 삭제해도 남은 포스의 번호는 다시 매기지 않는다 —
// "3포스 토 7시"라고 공지해둔 약속이 어긋나면 안 된다.
function fc_create_force(PDO $pdo, $raidId, $day, $time, $memo = '') {
    $st = $pdo->prepare("SELECT COALESCE(MAX(force_no), 0) + 1 FROM fc_forces WHERE raid_id = ?");
    $st->execute([$raidId]);
    $no = (int)$st->fetchColumn();

    $st2 = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM fc_forces WHERE raid_id = ?");
    $st2->execute([$raidId]);
    $order = (int)$st2->fetchColumn();

    $pdo->prepare("INSERT INTO fc_forces (raid_id, force_no, day_of_week, start_time, memo, sort_order)
                   VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$raidId, $no, $day !== '' ? $day : null, $time !== '' ? $time : null, (string)$memo, $order]);
    $forceId = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO fc_slots (force_id, party_no, slot_no, character_id) VALUES (?, ?, ?, NULL)");
    for ($party = 1; $party <= 2; $party++) {
        for ($slot = 1; $slot <= 5; $slot++) {
            $ins->execute([$forceId, $party, $slot]);
        }
    }
    fc_bump_revision($pdo);
    return $forceId;
}

function fc_update_force(PDO $pdo, $id, array $fields) {
    $allowed = ['day_of_week', 'start_time', 'memo'];
    $sets = [];
    $args = [];
    foreach ($allowed as $col) {
        if (!array_key_exists($col, $fields)) continue;
        $val = $fields[$col];
        if ($col === 'memo') {
            $sets[] = "$col = ?"; $args[] = (string)$val;
        } else {
            $sets[] = "$col = ?"; $args[] = ($val === '' || $val === null) ? null : $val;
        }
    }
    if (!$sets) return;
    $args[] = $id;
    $pdo->prepare("UPDATE fc_forces SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
    fc_bump_revision($pdo);
}

function fc_delete_force(PDO $pdo, $id) {
    $pdo->prepare("DELETE FROM fc_slots WHERE force_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM fc_forces WHERE id = ?")->execute([$id]);
    fc_bump_revision($pdo);
}
```

- [ ] **Step 4: 테스트가 통과하는지 서버에서 확인**

```bash
git add force/store.php && \
git commit -m "$(printf '포스 편성: 레이드/포스 store 함수 + 슬롯 10행 자동 생성\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main && php -l force/store.php && php force/test_api.php'
```

Expected: PASS — 레이드와 포스 / 포스 삭제 / 레이드 삭제 섹션 전부 ✓

---

### Task 4: store — 배치, 자리 교체, 삭제 시 참조 정리

**Files:**
- Modify: `force/store.php` (`fc_cleanup_test_data` 함수 앞에 추가)
- Modify: `force/test_api.php` (마지막 `fc_cleanup_test_data($pdo);` 앞에 추가)

**Interfaces:**
- Consumes: `fc_create_player()`, `fc_create_raid()`, `fc_create_force()`, `fc_delete_character()` (Task 2·3)
- Produces:
  - `fc_assign_slot(PDO $pdo, int $slotId, $characterId): void` — `$characterId`가 `null`이면 비우기
  - `fc_swap_slots(PDO $pdo, int $slotIdA, int $slotIdB): void`
  - `fc_slot_ids(PDO $pdo, int $forceId): array` — `[['id'=>int,'party_no'=>int,'slot_no'=>int], ...]` 파티·슬롯 순 정렬

- [ ] **Step 1: 실패하는 테스트 작성**

`force/test_api.php`의 마지막 `fc_cleanup_test_data($pdo);` 앞에 삽입:

```php
t_section('배치와 자리 교체');

$pid2 = fc_create_player($pdo, 'zzTest_대섬', ['zzTest_대섬부캐']);
$cids = $pdo->query("SELECT id FROM fc_characters WHERE player_id = $pid2 ORDER BY sort_order")
            ->fetchAll(PDO::FETCH_COLUMN);
$cMain = (int)$cids[0];
$cSub  = (int)$cids[1];

$rid2  = fc_create_raid($pdo, 'zzTest_침식');
$fidA  = fc_create_force($pdo, $rid2, '토', '19:30', '');
$slots = fc_slot_ids($pdo, $fidA);
t_eq(count($slots), 10, 'fc_slot_ids가 슬롯 10개를 돌려준다');
t_eq((int)$slots[0]['party_no'], 1, '첫 슬롯은 1파티다');
t_eq((int)$slots[0]['slot_no'], 1, '첫 슬롯은 1번 칸이다');
t_eq((int)$slots[5]['party_no'], 2, '여섯 번째 슬롯부터 2파티다');

fc_assign_slot($pdo, (int)$slots[0]['id'], $cMain);
$got = $pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[0]['id'])->fetchColumn();
t_eq((int)$got, $cMain, '배치 후 조회하면 캐릭터가 남아 있다');

fc_assign_slot($pdo, (int)$slots[5]['id'], $cSub);
fc_swap_slots($pdo, (int)$slots[0]['id'], (int)$slots[5]['id']);
$a = (int)$pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[0]['id'])->fetchColumn();
$b = (int)$pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[5]['id'])->fetchColumn();
t_eq($a, $cSub, 'swap 후 A슬롯에 B의 캐릭터가 있다');
t_eq($b, $cMain, 'swap 후 B슬롯에 A의 캐릭터가 있다');

fc_swap_slots($pdo, (int)$slots[1]['id'], (int)$slots[0]['id']);
$emptyNow = $pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[0]['id'])->fetchColumn();
$movedTo  = (int)$pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[1]['id'])->fetchColumn();
t_ok($emptyNow === null, '빈 슬롯과 swap하면 원래 자리가 빈다');
t_eq($movedTo, $cSub, '빈 슬롯과 swap하면 캐릭터가 그 자리로 옮겨간다');

fc_assign_slot($pdo, (int)$slots[1]['id'], null);
$cleared = $pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[1]['id'])->fetchColumn();
t_ok($cleared === null, 'character_id에 null을 넣으면 슬롯이 비워진다');

t_section('캐릭터 삭제 시 참조 정리');

fc_assign_slot($pdo, (int)$slots[2]['id'], $cMain);
fc_delete_character($pdo, $cMain);
$after = $pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$slots[2]['id'])->fetchColumn();
t_ok($after === null, '캐릭터를 지우면 배치된 슬롯이 비워진다');
t_eq((int)$pdo->query("SELECT COUNT(*) FROM fc_slots WHERE force_id = $fidA")->fetchColumn(), 10,
     '캐릭터를 지워도 슬롯 행 10개는 그대로 남는다');
```

- [ ] **Step 2: 테스트가 실패하는지 서버에서 확인**

```bash
git add force/test_api.php && \
git commit -m "$(printf '테스트: 슬롯 배치/교체/참조정리 케이스 추가\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main && php force/test_api.php'
```

Expected: FAIL — `Call to undefined function fc_slot_ids()`

- [ ] **Step 3: 배치 함수 구현**

`force/store.php`의 `fc_cleanup_test_data` 함수 앞에 삽입:

```php
function fc_slot_ids(PDO $pdo, $forceId) {
    $st = $pdo->prepare("SELECT id, party_no, slot_no FROM fc_slots
                         WHERE force_id = ? ORDER BY party_no, slot_no");
    $st->execute([$forceId]);
    return $st->fetchAll();
}

function fc_assign_slot(PDO $pdo, $slotId, $characterId) {
    $cid = ($characterId === null || $characterId === '' || (int)$characterId === 0) ? null : (int)$characterId;
    $pdo->prepare("UPDATE fc_slots SET character_id = ? WHERE id = ?")->execute([$cid, $slotId]);
    fc_bump_revision($pdo);
}

// 빈 슬롯과의 교체도 지원한다 — 그 경우 사실상 이동이 된다.
function fc_swap_slots(PDO $pdo, $slotIdA, $slotIdB) {
    if ((int)$slotIdA === (int)$slotIdB) return;
    $st = $pdo->prepare("SELECT id, character_id FROM fc_slots WHERE id IN (?, ?)");
    $st->execute([$slotIdA, $slotIdB]);
    $rows = $st->fetchAll();
    if (count($rows) !== 2) throw new RuntimeException('slot_not_found');

    $byId = [];
    foreach ($rows as $r) { $byId[(int)$r['id']] = $r['character_id']; }

    $upd = $pdo->prepare("UPDATE fc_slots SET character_id = ? WHERE id = ?");
    $upd->execute([$byId[(int)$slotIdB], $slotIdA]);
    $upd->execute([$byId[(int)$slotIdA], $slotIdB]);
    fc_bump_revision($pdo);
}
```

- [ ] **Step 4: 테스트가 통과하는지 서버에서 확인**

```bash
git add force/store.php && \
git commit -m "$(printf '포스 편성: 슬롯 배치/자리교체 함수 추가\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main && php -l force/store.php && php force/test_api.php'
```

Expected: PASS — 배치와 자리 교체 / 캐릭터 삭제 시 참조 정리 섹션 전부 ✓

---

### Task 5: store — state 스냅샷과 중복 감지

**Files:**
- Modify: `force/store.php` (`fc_cleanup_test_data` 함수 앞에 추가)
- Modify: `force/test_api.php` (마지막 `fc_cleanup_test_data($pdo);` 앞에 추가)

**Interfaces:**
- Consumes: Task 2~4의 store 함수 전부
- Produces:
  - `fc_state(PDO $pdo): array` — 아래 형태의 배열
    ```
    [
      'revision'   => int,
      'players'    => [['id'=>int, 'sort_order'=>int], ...],
      'characters' => [['id'=>int,'player_id'=>int,'name'=>string,'class'=>string,
                        'atul'=>?int,'item_level'=>?int,'is_main'=>int,'sort_order'=>int], ...],
      'raids'      => [['id'=>int,'name'=>string,'memo'=>string,'sort_order'=>int], ...],
      'forces'     => [['id'=>int,'raid_id'=>int,'force_no'=>int,'day_of_week'=>?string,
                        'start_time'=>?string,'memo'=>string,'sort_order'=>int], ...],
      'slots'      => [['id'=>int,'force_id'=>int,'party_no'=>int,'slot_no'=>int,
                        'character_id'=>?int], ...],
      'duplicates' => [ raid_id(string) => [['character_id'=>int,'force_ids'=>[int,...]], ...] ],
    ]
    ```
  - `fc_duplicates(array $forces, array $slots): array` — 순수 함수. DB를 건드리지 않는다.

- [ ] **Step 1: 실패하는 테스트 작성**

`force/test_api.php`의 마지막 `fc_cleanup_test_data($pdo);` 앞에 삽입:

```php
t_section('중복 감지 (순수 함수)');

$fForces = [
    ['id' => 100, 'raid_id' => 1], ['id' => 101, 'raid_id' => 1], ['id' => 102, 'raid_id' => 2],
];
$fSlots = [
    ['id' => 1, 'force_id' => 100, 'character_id' => 7],
    ['id' => 2, 'force_id' => 101, 'character_id' => 7],   // 같은 레이드 1 안에서 중복
    ['id' => 3, 'force_id' => 100, 'character_id' => 8],
    ['id' => 4, 'force_id' => 102, 'character_id' => 7],   // 레이드 2 — 중복 아님
    ['id' => 5, 'force_id' => 100, 'character_id' => null],
];
$dups = fc_duplicates($fForces, $fSlots);
t_ok(isset($dups['1']), '중복이 있는 레이드 1의 키가 존재한다');
t_eq(count($dups['1']), 1, '레이드 1의 중복 캐릭터는 1명이다');
t_eq((int)$dups['1'][0]['character_id'], 7, '중복된 캐릭터는 7번이다');
t_eq(array_map('intval', $dups['1'][0]['force_ids']), [100, 101], '중복된 포스 id 두 개가 잡힌다');
t_ok(!isset($dups['2']), '레이드가 다르면 중복으로 잡지 않는다');

t_section('state 스냅샷');

$pid3 = fc_create_player($pdo, 'zzTest_상태본캐', ['zzTest_상태부캐']);
$rid3 = fc_create_raid($pdo, 'zzTest_상태레이드');
$fid4 = fc_create_force($pdo, $rid3, '수', '22:00', '메모테스트');
$sl   = fc_slot_ids($pdo, $fid4);
$cid3 = (int)$pdo->query("SELECT id FROM fc_characters WHERE char_name = 'zzTest_상태본캐'")->fetchColumn();
fc_assign_slot($pdo, (int)$sl[0]['id'], $cid3);
fc_assign_slot($pdo, (int)$sl[6]['id'], $cid3);   // 같은 레이드 같은 포스 안 중복

$state = fc_state($pdo);
t_ok(is_int($state['revision']), 'state에 revision이 정수로 들어 있다');

$names = array_column($state['characters'], 'name');
t_ok(in_array('zzTest_상태본캐', $names, true), 'state에 캐릭터가 들어 있다');

$myChar = null;
foreach ($state['characters'] as $c) { if ($c['name'] === 'zzTest_상태본캐') { $myChar = $c; break; } }
t_eq((int)$myChar['is_main'], 1, 'state의 캐릭터에 is_main이 담긴다');
t_ok(array_key_exists('atul', $myChar), 'state의 캐릭터 키는 atul이다 (atul_score가 아님)');

$myForce = null;
foreach ($state['forces'] as $f) { if ((int)$f['id'] === $fid4) { $myForce = $f; break; } }
t_eq($myForce['day_of_week'], '수', 'state에 포스 요일이 담긴다');
t_eq((int)$myForce['force_no'], 1, 'state에 포스 번호가 담긴다');

$mySlots = array_values(array_filter($state['slots'], function ($s) use ($fid4) {
    return (int)$s['force_id'] === $fid4;
}));
t_eq(count($mySlots), 10, 'state에 그 포스의 슬롯 10개가 담긴다');

t_ok(isset($state['duplicates'][(string)$rid3]), '같은 포스 안 중복도 duplicates에 잡힌다');
t_eq((int)$state['duplicates'][(string)$rid3][0]['character_id'], $cid3, '중복된 캐릭터 id가 맞다');
```

- [ ] **Step 2: 테스트가 실패하는지 서버에서 확인**

```bash
git add force/test_api.php && \
git commit -m "$(printf '테스트: state 스냅샷·중복감지 케이스 추가\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main && php force/test_api.php'
```

Expected: FAIL — `Call to undefined function fc_duplicates()`

- [ ] **Step 3: state와 중복 감지 구현**

`force/store.php`의 `fc_cleanup_test_data` 함수 앞에 삽입:

```php
// 순수 함수 — DB를 모른다. 같은 레이드 안에서 같은 캐릭터가 두 번 이상 배치된 경우를 찾는다.
// 같은 포스 안의 중복도 잡는다.
function fc_duplicates(array $forces, array $slots) {
    $raidOfForce = [];
    foreach ($forces as $f) { $raidOfForce[(int)$f['id']] = (int)$f['raid_id']; }

    $seen = [];   // raid_id => character_id => [force_id, ...]
    foreach ($slots as $s) {
        if ($s['character_id'] === null) continue;
        $fid = (int)$s['force_id'];
        if (!isset($raidOfForce[$fid])) continue;
        $rid = $raidOfForce[$fid];
        $cid = (int)$s['character_id'];
        if (!isset($seen[$rid])) $seen[$rid] = [];
        if (!isset($seen[$rid][$cid])) $seen[$rid][$cid] = [];
        $seen[$rid][$cid][] = $fid;
    }

    $out = [];
    foreach ($seen as $rid => $byChar) {
        foreach ($byChar as $cid => $forceIds) {
            if (count($forceIds) < 2) continue;
            if (!isset($out[(string)$rid])) $out[(string)$rid] = [];
            $out[(string)$rid][] = [
                'character_id' => (int)$cid,
                'force_ids'    => array_values(array_unique(array_map('intval', $forceIds))),
            ];
        }
    }
    return $out;
}

function fc_state(PDO $pdo) {
    $players = $pdo->query("SELECT id, sort_order FROM fc_players ORDER BY sort_order, id")->fetchAll();

    $characters = $pdo->query(
        "SELECT id, player_id, char_name AS name, char_class AS class,
                atul_score AS atul, item_level, is_main, sort_order
         FROM fc_characters ORDER BY player_id, sort_order, id")->fetchAll();

    $raids  = $pdo->query("SELECT id, name, memo, sort_order FROM fc_raids ORDER BY sort_order, id")->fetchAll();
    $forces = $pdo->query("SELECT id, raid_id, force_no, day_of_week, start_time, memo, sort_order
                           FROM fc_forces ORDER BY raid_id, force_no, id")->fetchAll();
    $slots  = $pdo->query("SELECT id, force_id, party_no, slot_no, character_id
                           FROM fc_slots ORDER BY force_id, party_no, slot_no")->fetchAll();

    // 숫자 컬럼을 JSON에서 정수로 나가게 정리한다 (PDO는 문자열로 준다)
    $toInt = function (&$rows, array $intCols, array $nullableIntCols = []) {
        foreach ($rows as &$r) {
            foreach ($intCols as $c) { $r[$c] = (int)$r[$c]; }
            foreach ($nullableIntCols as $c) { $r[$c] = $r[$c] === null ? null : (int)$r[$c]; }
        }
        unset($r);
    };
    $toInt($players, ['id', 'sort_order']);
    $toInt($characters, ['id', 'player_id', 'is_main', 'sort_order'], ['atul', 'item_level']);
    $toInt($raids, ['id', 'sort_order']);
    $toInt($forces, ['id', 'raid_id', 'force_no', 'sort_order']);
    $toInt($slots, ['id', 'force_id', 'party_no', 'slot_no'], ['character_id']);

    return [
        'revision'   => fc_revision($pdo),
        'players'    => $players,
        'characters' => $characters,
        'raids'      => $raids,
        'forces'     => $forces,
        'slots'      => $slots,
        'duplicates' => fc_duplicates($forces, $slots),
    ];
}
```

- [ ] **Step 4: 테스트가 통과하는지 서버에서 확인**

```bash
git add force/store.php && \
git commit -m "$(printf '포스 편성: state 스냅샷과 중복 감지 추가\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main && php -l force/store.php && php force/test_api.php'
```

Expected: PASS — 중복 감지 / state 스냅샷 섹션 전부 ✓, `전체 통과`

---

### Task 6: 아툴 조회 이식

**Files:**
- Create: `force/atul.php`
- Read (참고용, 수정 금지): `actions/fetch_atul.php`

**Interfaces:**
- Consumes: 없음 (외부 HTTP만)
- Produces:
  - `fc_atul_lookup(string $name): ?array` — 성공 시
    `['class'=>string, 'atul'=>int, 'item_level'=>?int, 'legion'=>string]`, 실패 시 `null`

- [ ] **Step 1: 구현**

기존 `actions/fetch_atul.php`의 2단계 조회 로직을 함수로 감싼다. HTTP 응답을 직접 출력하던
부분을 없애고 배열을 반환한다.

`force/atul.php`:

```php
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
```

- [ ] **Step 2: 실제 캐릭명으로 서버에서 확인**

`force/test_api.php`는 외부 API에 의존하지 않으므로 건드리지 않는다. 대신 일회성으로 확인한다.

```bash
git add force/atul.php && \
git commit -m "$(printf '포스 편성: 아툴 조회를 함수로 이식\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main && php -l force/atul.php && php -r "require \"force/atul.php\"; var_dump(fc_atul_lookup(\"KOMOREBI\"));"'
```

Expected: `class`, `atul`, `item_level`이 채워진 배열. 캐릭명을 못 찾으면 `NULL` — 그 경우
존재하는 다른 캐릭명으로 한 번 더 확인한다. 두 이름 모두 NULL이면 API 스펙이 바뀐 것이므로
`?debug=1` 없이 `fc_atul_http_get`이 돌려주는 원본을 출력해 필드명을 확인하고 매핑을 고친다.

- [ ] **Step 3: 기존 테스트가 그대로 통과하는지 확인**

```bash
ssh aion-sanctuary 'cd /var/www/html/sanctuary && php force/test_api.php'
```

Expected: PASS — `전체 통과`

---

### Task 7: HTTP 경계 — api.php

**Files:**
- Create: `force/api.php`
- Modify: `force/test_api.php` (마지막 `fc_cleanup_test_data($pdo);` 앞에 추가)

**Interfaces:**
- Consumes: `fc_pdo()`, `fc_init_schema()`, store 함수 전부, `fc_atul_lookup()`
- Produces:
  - HTTP: `POST force/api.php`, body = JSON, `{"action": "...", ...}`
  - 응답: 성공 `{"ok":true,"data":...,"revision":N}` / 실패 `{"ok":false,"error":"..."}`
  - `fc_api_dispatch(PDO $pdo, array $req, $lookup): array` — HTTP를 모르는 순수 디스패처.
    반환값은 `data`로 나갈 값. 실패는 `RuntimeException`.

- [ ] **Step 1: 실패하는 테스트 작성**

`force/test_api.php` 상단 `require_once` 목록에 `require_once __DIR__ . '/api.php';` 를 추가하고,
마지막 `fc_cleanup_test_data($pdo);` 앞에 삽입:

```php
t_section('API 디스패처');

$noLookup = function ($name) { return null; };
$call = function ($req) use ($pdo, $noLookup) {
    return fc_api_dispatch($pdo, $req, $noLookup);
};

$res = $call(['action' => 'player.create', 'main_name' => 'zzTest_API본캐', 'subs' => ['zzTest_API부캐']]);
t_ok(isset($res['player_id']) && $res['player_id'] > 0, 'player.create가 player_id를 돌려준다');
$apiPid = (int)$res['player_id'];

$res2 = $call(['action' => 'raid.create', 'name' => 'zzTest_API레이드']);
$apiRid = (int)$res2['raid_id'];
t_ok($apiRid > 0, 'raid.create가 raid_id를 돌려준다');

$res3 = $call(['action' => 'force.create', 'raid_id' => $apiRid,
               'day_of_week' => '금', 'start_time' => '21:30', 'memo' => '']);
$apiFid = (int)$res3['force_id'];
t_ok($apiFid > 0, 'force.create가 force_id를 돌려준다');

$st8 = $call(['action' => 'state']);
t_ok(isset($st8['revision']) && isset($st8['slots']), 'state가 스냅샷을 돌려준다');

$apiSlots = array_values(array_filter($st8['slots'], function ($s) use ($apiFid) {
    return (int)$s['force_id'] === $apiFid;
}));
t_eq(count($apiSlots), 10, 'state의 슬롯에 새 포스 10칸이 보인다');

$apiCid = 0;
foreach ($st8['characters'] as $c) { if ($c['name'] === 'zzTest_API본캐') { $apiCid = (int)$c['id']; } }
$call(['action' => 'slot.assign', 'slot_id' => (int)$apiSlots[0]['id'], 'character_id' => $apiCid]);
$after = $pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$apiSlots[0]['id'])->fetchColumn();
t_eq((int)$after, $apiCid, 'slot.assign이 배치를 저장한다');

$call(['action' => 'slot.swap', 'slot_id_a' => (int)$apiSlots[0]['id'], 'slot_id_b' => (int)$apiSlots[9]['id']]);
$moved = $pdo->query("SELECT character_id FROM fc_slots WHERE id = " . (int)$apiSlots[9]['id'])->fetchColumn();
t_eq((int)$moved, $apiCid, 'slot.swap이 자리를 바꾼다');

t_section('API 오류 처리');

$err = '';
try { $call(['action' => 'nope.nope']); }
catch (RuntimeException $e) { $err = $e->getMessage(); }
t_eq($err, 'unknown_action', '모르는 action은 unknown_action 예외를 던진다');

$err2 = '';
try { $call(['action' => 'player.create', 'main_name' => 'zzTest_API본캐']); }
catch (RuntimeException $e) { $err2 = $e->getMessage(); }
t_ok(strpos($err2, 'duplicate_name') === 0, '중복 캐릭명은 duplicate_name 예외로 나온다');

$err3 = '';
try { $call(['action' => 'force.create', 'raid_id' => 0, 'day_of_week' => '', 'start_time' => '']); }
catch (RuntimeException $e) { $err3 = $e->getMessage(); }
t_eq($err3, 'bad_request', 'raid_id가 없으면 bad_request다');

$call(['action' => 'raid.delete', 'raid_id' => $apiRid]);
$call(['action' => 'player.delete', 'player_id' => $apiPid]);
t_eq((int)$pdo->query("SELECT COUNT(*) FROM fc_raids WHERE id = $apiRid")->fetchColumn(), 0,
     'raid.delete가 레이드를 지운다');
```

- [ ] **Step 2: 테스트가 실패하는지 서버에서 확인**

```bash
git add force/test_api.php && \
git commit -m "$(printf '테스트: API 디스패처 케이스 추가\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main && php force/test_api.php'
```

Expected: FAIL — `Failed opening required '.../force/api.php'`

- [ ] **Step 3: api.php 구현**

`force/api.php`:

```php
<?php
// HTTP/JSON 경계. 요청을 검증하고 store를 호출한다. SQL을 직접 쓰지 않는다.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/atul.php';

function fc_req_int(array $req, $key) {
    if (!isset($req[$key])) return 0;
    return (int)$req[$key];
}

function fc_req_str(array $req, $key, $default = '') {
    if (!isset($req[$key]) || $req[$key] === null) return $default;
    return trim((string)$req[$key]);
}

// HTTP를 모르는 순수 디스패처. 실패는 RuntimeException으로 던진다.
function fc_api_dispatch(PDO $pdo, array $req, $lookup) {
    $action = fc_req_str($req, 'action');

    switch ($action) {
        case 'state':
            return fc_state($pdo);

        case 'player.create':
            $main = fc_req_str($req, 'main_name');
            if ($main === '') throw new RuntimeException('bad_request');
            $subs = isset($req['subs']) && is_array($req['subs']) ? $req['subs'] : [];
            return ['player_id' => fc_create_player($pdo, $main, $subs, $lookup)];

        case 'player.delete':
            $pid = fc_req_int($req, 'player_id');
            if ($pid <= 0) throw new RuntimeException('bad_request');
            fc_delete_player($pdo, $pid);
            return ['deleted' => $pid];

        case 'character.add':
            $pid  = fc_req_int($req, 'player_id');
            $name = fc_req_str($req, 'name');
            if ($pid <= 0 || $name === '') throw new RuntimeException('bad_request');
            return ['character_id' => fc_add_character($pdo, $pid, $name, $lookup)];

        case 'character.update':
            $cid = fc_req_int($req, 'character_id');
            if ($cid <= 0) throw new RuntimeException('bad_request');
            $fields = [];
            foreach (['name', 'class', 'atul', 'item_level'] as $k) {
                if (array_key_exists($k, $req)) $fields[$k] = $req[$k];
            }
            fc_update_character($pdo, $cid, $fields);
            return ['updated' => $cid];

        case 'character.delete':
            $cid = fc_req_int($req, 'character_id');
            if ($cid <= 0) throw new RuntimeException('bad_request');
            fc_delete_character($pdo, $cid);
            return ['deleted' => $cid];

        case 'raid.create':
            $name = fc_req_str($req, 'name');
            if ($name === '') throw new RuntimeException('bad_request');
            return ['raid_id' => fc_create_raid($pdo, $name)];

        case 'raid.update':
            $rid = fc_req_int($req, 'raid_id');
            if ($rid <= 0) throw new RuntimeException('bad_request');
            $fields = [];
            foreach (['name', 'memo'] as $k) {
                if (array_key_exists($k, $req)) $fields[$k] = $req[$k];
            }
            fc_update_raid($pdo, $rid, $fields);
            return ['updated' => $rid];

        case 'raid.delete':
            $rid = fc_req_int($req, 'raid_id');
            if ($rid <= 0) throw new RuntimeException('bad_request');
            fc_delete_raid($pdo, $rid);
            return ['deleted' => $rid];

        case 'force.create':
            $rid = fc_req_int($req, 'raid_id');
            if ($rid <= 0) throw new RuntimeException('bad_request');
            return ['force_id' => fc_create_force(
                $pdo, $rid, fc_req_str($req, 'day_of_week'),
                fc_req_str($req, 'start_time'), fc_req_str($req, 'memo')
            )];

        case 'force.update':
            $fid = fc_req_int($req, 'force_id');
            if ($fid <= 0) throw new RuntimeException('bad_request');
            $fields = [];
            foreach (['day_of_week', 'start_time', 'memo'] as $k) {
                if (array_key_exists($k, $req)) $fields[$k] = $req[$k];
            }
            fc_update_force($pdo, $fid, $fields);
            return ['updated' => $fid];

        case 'force.delete':
            $fid = fc_req_int($req, 'force_id');
            if ($fid <= 0) throw new RuntimeException('bad_request');
            fc_delete_force($pdo, $fid);
            return ['deleted' => $fid];

        case 'slot.assign':
            $sid = fc_req_int($req, 'slot_id');
            if ($sid <= 0) throw new RuntimeException('bad_request');
            $cid = array_key_exists('character_id', $req) && $req['character_id'] !== null
                 ? (int)$req['character_id'] : null;
            fc_assign_slot($pdo, $sid, $cid);
            return ['slot_id' => $sid, 'character_id' => $cid];

        case 'slot.swap':
            $a = fc_req_int($req, 'slot_id_a');
            $b = fc_req_int($req, 'slot_id_b');
            if ($a <= 0 || $b <= 0) throw new RuntimeException('bad_request');
            fc_swap_slots($pdo, $a, $b);
            return ['slot_id_a' => $a, 'slot_id_b' => $b];

        case 'atul.refresh':
            $cid = fc_req_int($req, 'character_id');
            if ($cid <= 0) throw new RuntimeException('bad_request');
            $st = $pdo->prepare("SELECT char_name FROM fc_characters WHERE id = ?");
            $st->execute([$cid]);
            $name = $st->fetchColumn();
            if ($name === false) throw new RuntimeException('not_found');
            $info = is_callable($lookup) ? call_user_func($lookup, $name) : null;
            if (!is_array($info)) throw new RuntimeException('lookup_failed');
            fc_update_character($pdo, $cid, [
                'class'      => $info['class'],
                'atul'       => $info['atul'],
                'item_level' => $info['item_level'],
            ]);
            return ['character_id' => $cid, 'atul' => $info['atul'], 'class' => $info['class']];
    }

    throw new RuntimeException('unknown_action');
}

// 이 파일이 직접 호출되었을 때만 HTTP 응답을 낸다 (test_api.php에서 require해도 안전하게)
if (isset($_SERVER['REQUEST_METHOD']) && basename($_SERVER['SCRIPT_FILENAME']) === 'api.php') {
    session_start();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if (empty($_SESSION['sanctuary_site_auth'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'unauthorized']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $req = json_decode($raw, true);
    if (!is_array($req)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_json']);
        exit;
    }

    try {
        $pdo = fc_pdo();
        fc_init_schema($pdo);
        $data = fc_api_dispatch($pdo, $req, 'fc_atul_lookup');
        echo json_encode(['ok' => true, 'data' => $data, 'revision' => fc_revision($pdo)],
                         JSON_UNESCAPED_UNICODE);
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'server_error'], JSON_UNESCAPED_UNICODE);
    }
}
```

- [ ] **Step 4: 테스트가 통과하는지 서버에서 확인**

```bash
git add force/api.php && \
git commit -m "$(printf '포스 편성: JSON API 엔드포인트 추가\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main && php -l force/api.php && php force/test_api.php'
```

Expected: PASS — API 디스패처 / API 오류 처리 섹션 전부 ✓, `전체 통과`

---

### Task 8: index.php 셸과 구 코드 삭제

**Files:**
- Modify: `index.php` (전체 교체)
- Delete: `sections/` 전체, `actions/` 전체, `cron/` 전체, `migrate_add_19.php`
- Create: `assets/app.css` (이 태스크에서는 최소 뼈대만)
- Create: `assets/app.js` (이 태스크에서는 state 수신 확인만)

**Interfaces:**
- Consumes: `fc_pdo()`, `fc_init_schema()`, `fc_state()`
- Produces:
  - 전역 `window.FC_STATE` — 초기 state 스냅샷 (Task 9~13이 여기서 출발한다)
  - DOM 골격: `#fc-app`, `#fc-sidebar`, `#fc-board`, `#fc-tabs`, `#fc-toast`, `#fc-conn`

- [ ] **Step 1: index.php 교체**

기존 게이트 마크업과 스타일은 그대로 살리고(비밀번호 UX가 이미 익숙하다), 그 아래 앱 셸만 새로 만든다.

`index.php`:

```php
<?php
session_start();
date_default_timezone_set('Asia/Seoul');

$_config_file = __DIR__ . '/sanctuary_config.json';
$_config      = file_exists($_config_file) ? (json_decode(file_get_contents($_config_file), true) ?? []) : [];
$site_pw      = $_config['site_password'] ?? 'forest0305';

if (!isset($_SESSION['sanctuary_site_auth'])) {
    if (isset($_POST['site_password'])) {
        if (strtolower($_POST['site_password']) === strtolower($site_pw)) {
            $_SESSION['sanctuary_site_auth'] = true;
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
            exit;
        }
        $site_auth_error = true;
    }
    ?><!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>숲 — 포스 편성</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;700;900&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Noto Sans KR',sans-serif;background:#0a0c14;color:#e8eaf0;min-height:100vh;display:flex;align-items:center;justify-content:center;
background-image:radial-gradient(ellipse at 30% 50%,rgba(58,123,213,0.06) 0%,transparent 60%),radial-gradient(ellipse at 70% 30%,rgba(108,61,201,0.06) 0%,transparent 60%);}
.gate{background:#0f1220;border:1px solid #1e2840;border-radius:16px;padding:40px 36px;width:360px;max-width:90vw;box-shadow:0 20px 60px rgba(0,0,0,0.5);}
.gate-legion{font-size:48px;font-weight:900;color:#f0c96a;text-align:center;margin-bottom:4px;letter-spacing:-2px;}
.gate-title{font-size:14px;color:#8a9ab8;text-align:center;margin-bottom:28px;letter-spacing:1px;}
.gate-label{display:block;font-size:11px;font-weight:700;color:#4a5a78;letter-spacing:1px;text-transform:uppercase;margin-bottom:6px;}
.gate-input{width:100%;padding:10px 14px;background:#0a0c14;border:1px solid #1e2840;border-radius:8px;color:#e8eaf0;font-size:14px;font-family:inherit;outline:none;transition:border-color .2s;}
.gate-input:focus{border-color:#3a7bd5;box-shadow:0 0 0 2px rgba(58,123,213,0.15);}
.gate-btn{width:100%;margin-top:16px;padding:11px;background:linear-gradient(135deg,#8a6830,#c9a84c);border:none;border-radius:8px;color:#0a0c14;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;}
.gate-btn:hover{filter:brightness(1.1);}
.gate-error{margin-top:12px;padding:10px 14px;background:rgba(192,57,43,0.15);border:1px solid rgba(192,57,43,0.3);border-radius:6px;font-size:12px;color:#e74c3c;text-align:center;}
</style>
</head>
<body>
<div class="gate">
  <div class="gate-legion">숲</div>
  <div class="gate-title">AION 2 LEGION · 포스 편성</div>
  <form method="POST">
    <label class="gate-label">접속 비밀번호</label>
    <input type="password" name="site_password" class="gate-input" placeholder="비밀번호를 입력하세요" autofocus>
    <button type="submit" class="gate-btn">입장</button>
    <?php if (!empty($site_auth_error)): ?>
    <div class="gate-error">❌ 비밀번호가 올바르지 않습니다.</div>
    <?php endif; ?>
  </form>
</div>
</body>
</html><?php
    exit;
}

require_once __DIR__ . '/force/db.php';
require_once __DIR__ . '/force/schema.php';
require_once __DIR__ . '/force/store.php';

$pdo = fc_pdo();
fc_init_schema($pdo);
$state = fc_state($pdo);
?><!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>숲 — 포스 편성</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__ . '/assets/app.css') ?>">
</head>
<body>
<div id="fc-conn" class="fc-conn" hidden>⚠ 서버와 연결 끊김 — 재시도 중</div>

<header class="fc-header">
  <div class="fc-brand"><span class="fc-legion">숲</span><span class="fc-brand-sub">포스 편성</span></div>
  <nav class="fc-header-actions">
    <a class="fc-link" href="craft.php">제작계산기</a>
    <button type="button" class="fc-btn" id="fc-open-roster">명단 관리</button>
  </nav>
</header>

<main id="fc-app" class="fc-app">
  <aside id="fc-sidebar" class="fc-sidebar"></aside>
  <section class="fc-main">
    <div id="fc-tabs" class="fc-tabs"></div>
    <div id="fc-board" class="fc-board"></div>
  </section>
</main>

<div id="fc-popover" class="fc-popover" hidden></div>
<div id="fc-modal" class="fc-modal" hidden></div>
<div id="fc-toast" class="fc-toast-wrap"></div>

<script>window.FC_STATE = <?= json_encode($state, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="assets/app.js?v=<?= filemtime(__DIR__ . '/assets/app.js') ?>"></script>
</body>
</html>
```

- [ ] **Step 2: 최소 CSS·JS 뼈대 작성**

`assets/app.css`:

```css
/* 디자인 토큰 — 기존 사이트의 다크 네이비 + 골드 톤 계승 */
:root{
  --bg:#0a0c14; --panel:#0f1220; --panel-2:#141830; --line:#1e2840; --line-2:#2b3452;
  --text:#e8eaf0; --muted:#8a9ab8; --dim:#4a5a78;
  --gold:#f0c96a; --gold-2:#c9a84c; --blue:#3a7bd5; --danger:#e74c3c;
  --radius:10px;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Noto Sans KR',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}
.fc-header{display:flex;align-items:center;justify-content:space-between;padding:12px 20px;border-bottom:1px solid var(--line);}
.fc-legion{font-size:24px;font-weight:900;color:var(--gold);}
.fc-brand-sub{margin-left:10px;font-size:13px;color:var(--muted);letter-spacing:1px;}
.fc-app{display:grid;grid-template-columns:270px 1fr;gap:16px;padding:16px 20px;align-items:start;}
.fc-sidebar{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);padding:12px;position:sticky;top:16px;}
.fc-main{min-width:0;}
.fc-btn{background:var(--panel-2);border:1px solid var(--line);border-radius:8px;color:var(--text);
  padding:7px 12px;font-family:inherit;font-size:13px;cursor:pointer;}
.fc-btn:hover{border-color:var(--blue);}
.fc-link{color:var(--muted);font-size:13px;text-decoration:none;margin-right:14px;}
.fc-link:hover{color:var(--gold);}
.fc-conn{position:fixed;top:0;left:0;right:0;z-index:90;background:var(--danger);color:#fff;
  text-align:center;font-size:13px;padding:6px;}
.fc-toast-wrap{position:fixed;right:18px;bottom:18px;display:flex;flex-direction:column;gap:8px;z-index:100;}
```

`assets/app.js`:

```javascript
'use strict';

// 서버가 심어준 초기 스냅샷. 이후 폴링으로 교체된다.
var FC = {
  state: window.FC_STATE,
  activeRaidId: null,
};

(function boot() {
  var raids = FC.state.raids || [];
  FC.activeRaidId = raids.length ? raids[0].id : null;
  console.log('[fc] state loaded', {
    revision: FC.state.revision,
    players: (FC.state.players || []).length,
    characters: (FC.state.characters || []).length,
    raids: raids.length,
    forces: (FC.state.forces || []).length,
    slots: (FC.state.slots || []).length
  });
})();
```

- [ ] **Step 3: 구 코드 삭제**

```bash
git rm -r --quiet sections actions cron migrate_add_19.php
```

- [ ] **Step 4: 배포하고 브라우저에서 확인**

```bash
git add index.php assets/app.css assets/app.js && \
git commit -m "$(printf '포스 편성: 새 index.php 셸 + 구 신청/자동편성 코드 삭제\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main && php -l index.php && ls'
```

Expected: `ls` 결과에 `sections`, `actions`, `cron`, `migrate_add_19.php`가 없고
`assets`, `force`, `craft`가 있다.

```bash
B=~/.claude/skills/gstack/browse/dist/browse
$B goto 'http://14.63.164.109/sanctuary/'
$B fill 'input[name=site_password]' 'sksldktnv0318'
$B click '.gate-btn'
$B js "JSON.stringify({hasState: !!window.FC_STATE, rev: window.FC_STATE && window.FC_STATE.revision, sidebar: !!document.getElementById('fc-sidebar'), board: !!document.getElementById('fc-board')})"
$B console --errors
```

Expected: `{"hasState":true,"rev":<정수>,"sidebar":true,"board":true}`, 콘솔 에러 없음

- [ ] **Step 5: 제작계산기가 살아 있는지 확인**

```bash
B=~/.claude/skills/gstack/browse/dist/browse
$B goto 'http://14.63.164.109/sanctuary/craft.php'
$B js "document.title"
```

Expected: 제작계산기 페이지가 정상 응답 (500이나 빈 화면이 아님)

---

### Task 9: 화면 렌더 — 대기창 · 레이드 탭 · 포스 보드 · 빈 상태

**Files:**
- Modify: `assets/app.js`
- Modify: `assets/app.css`

**Interfaces:**
- Consumes: `window.FC_STATE`, `FC.activeRaidId` (Task 8)
- Produces:
  - `FC.classColor(cls): string` — 직업명 → CSS 색 변수명
  - `FC.byId(list, id): object|null`
  - `FC.charsOfPlayer(playerId): array`
  - `FC.mainOf(playerId): object|null`
  - `FC.placedCount(playerId, raidId): number`
  - `FC.render(): void` — state를 보고 사이드바·탭·보드를 전부 다시 그린다
  - `FC.el(tag, attrs, children): HTMLElement` — 작은 DOM 헬퍼
  - DOM 계약: 슬롯 요소는 `.fc-slot[data-slot-id]`, 대기창 카드는 `.fc-roster-card[data-player-id]`,
    탭은 `.fc-tab[data-raid-id]`

- [ ] **Step 1: 렌더 구현**

`assets/app.js`의 `boot()` 호출 앞에 아래를 추가하고, `boot()` 마지막 줄에 `FC.render();` 를 넣는다.

```javascript
var CLASS_COLORS = {
  '수호성': '#5b8def', '검성': '#e0574a', '살성': '#8e6bd8', '궁성': '#3fa86a',
  '호법성': '#d99a3c', '정령성': '#37a7a0', '마도성': '#c956a5', '치유성': '#e0c04a'
};
var DAYS = ['월', '화', '수', '목', '금', '토', '일'];

FC.classColor = function (cls) {
  return CLASS_COLORS[cls] || '#4a5a78';
};

FC.el = function (tag, attrs, children) {
  var node = document.createElement(tag);
  attrs = attrs || {};
  Object.keys(attrs).forEach(function (k) {
    if (k === 'class') node.className = attrs[k];
    else if (k === 'text') node.textContent = attrs[k];
    else if (k === 'html') node.innerHTML = attrs[k];
    else if (attrs[k] !== null && attrs[k] !== undefined) node.setAttribute(k, attrs[k]);
  });
  (children || []).forEach(function (c) { if (c) node.appendChild(c); });
  return node;
};

FC.byId = function (list, id) {
  var found = null;
  (list || []).forEach(function (x) { if (Number(x.id) === Number(id)) found = x; });
  return found;
};

FC.charsOfPlayer = function (playerId) {
  return (FC.state.characters || []).filter(function (c) {
    return Number(c.player_id) === Number(playerId);
  });
};

FC.mainOf = function (playerId) {
  var mains = FC.charsOfPlayer(playerId).filter(function (c) { return Number(c.is_main) === 1; });
  return mains.length ? mains[0] : null;
};

// 이 플레이어의 캐릭터가 해당 레이드에 몇 칸 배치되어 있는지
FC.placedCount = function (playerId, raidId) {
  if (!raidId) return 0;
  var forceIds = (FC.state.forces || [])
    .filter(function (f) { return Number(f.raid_id) === Number(raidId); })
    .map(function (f) { return Number(f.id); });
  var myCharIds = FC.charsOfPlayer(playerId).map(function (c) { return Number(c.id); });
  return (FC.state.slots || []).filter(function (s) {
    return s.character_id !== null &&
           forceIds.indexOf(Number(s.force_id)) !== -1 &&
           myCharIds.indexOf(Number(s.character_id)) !== -1;
  }).length;
};

FC.dupCharIds = function (raidId) {
  var byRaid = (FC.state.duplicates || {})[String(raidId)] || [];
  return byRaid.map(function (d) { return Number(d.character_id); });
};

// ── 사이드바 ────────────────────────────────────────────────
FC.renderSidebar = function () {
  var host = document.getElementById('fc-sidebar');
  host.innerHTML = '';
  host.appendChild(FC.el('div', { class: 'fc-side-title', text: '캐릭터 대기창' }));

  var search = FC.el('input', {
    class: 'fc-search', id: 'fc-search', type: 'text',
    placeholder: '이름 검색', value: FC.searchTerm || ''
  });
  search.addEventListener('input', function () {
    FC.searchTerm = search.value;
    FC.renderSidebar();
    var again = document.getElementById('fc-search');
    again.focus();
    again.setSelectionRange(again.value.length, again.value.length);
  });
  host.appendChild(search);

  var list = FC.el('div', { class: 'fc-roster' });
  var term = (FC.searchTerm || '').trim().toLowerCase();
  var players = FC.state.players || [];

  if (!players.length) {
    list.appendChild(FC.el('p', {
      class: 'fc-empty', text: '아직 등록된 인원이 없어요. 「명단 관리」에서 추가하세요.'
    }));
  }

  players.forEach(function (p) {
    var main = FC.mainOf(p.id);
    if (!main) return;
    if (term) {
      var hit = FC.charsOfPlayer(p.id).some(function (c) {
        return c.name.toLowerCase().indexOf(term) !== -1;
      });
      if (!hit) return;
    }
    var subCount = FC.charsOfPlayer(p.id).length - 1;
    var placed = FC.placedCount(p.id, FC.activeRaidId);

    var card = FC.el('div', { class: 'fc-roster-card', 'data-player-id': p.id }, [
      FC.el('span', { class: 'fc-dot', style: 'background:' + FC.classColor(main.class) }),
      FC.el('span', { class: 'fc-roster-name', text: main.name }),
      FC.el('span', { class: 'fc-roster-meta',
        text: (main.atul ? main.atul.toLocaleString() : '—') + (subCount > 0 ? ' · 부캐 ' + subCount : '') }),
      FC.el('span', { class: 'fc-badge' + (placed === 0 ? ' is-zero' : ''), text: String(placed) })
    ]);
    list.appendChild(card);
  });

  host.appendChild(list);
  host.appendChild(FC.el('button', { class: 'fc-btn fc-block', id: 'fc-add-player', text: '+ 인원 추가' }));
};

// ── 레이드 탭 ───────────────────────────────────────────────
FC.renderTabs = function () {
  var host = document.getElementById('fc-tabs');
  host.innerHTML = '';
  (FC.state.raids || []).forEach(function (r) {
    var tab = FC.el('button', {
      class: 'fc-tab' + (Number(r.id) === Number(FC.activeRaidId) ? ' is-active' : ''),
      'data-raid-id': r.id, type: 'button', text: r.name
    });
    host.appendChild(tab);
  });
  host.appendChild(FC.el('button', { class: 'fc-tab fc-tab-add', id: 'fc-add-raid', type: 'button', text: '+' }));

  if (FC.activeRaidId) {
    host.appendChild(FC.el('span', { class: 'fc-spacer' }));
    host.appendChild(FC.el('button', { class: 'fc-btn', id: 'fc-add-force', type: 'button', text: '+ 포스 추가' }));
    host.appendChild(FC.el('button', { class: 'fc-btn', id: 'fc-edit-raid', type: 'button', text: '레이드 수정' }));
  }
};

// ── 보드 ────────────────────────────────────────────────────
FC.renderBoard = function () {
  var host = document.getElementById('fc-board');
  host.innerHTML = '';

  if (!(FC.state.raids || []).length) {
    host.appendChild(FC.el('div', { class: 'fc-empty-big' }, [
      FC.el('p', { text: '레이드를 먼저 만들어주세요' }),
      FC.el('button', { class: 'fc-btn fc-btn-primary', id: 'fc-add-raid-big', type: 'button', text: '+ 레이드 추가' })
    ]));
    return;
  }

  var raid = FC.byId(FC.state.raids, FC.activeRaidId);
  if (raid && raid.memo) {
    host.appendChild(FC.el('div', { class: 'fc-raid-memo', text: raid.memo }));
  }

  var dupIds = FC.dupCharIds(FC.activeRaidId);
  if (dupIds.length) {
    var names = dupIds.map(function (cid) {
      var c = FC.byId(FC.state.characters, cid);
      return c ? c.name : '?';
    });
    host.appendChild(FC.el('div', { class: 'fc-warn', text: '⚠ ' + names.join(', ') + ' 이(가) 같은 레이드에 중복 배치되어 있어요' }));
  }

  var forces = (FC.state.forces || []).filter(function (f) {
    return Number(f.raid_id) === Number(FC.activeRaidId);
  });

  if (!forces.length) {
    host.appendChild(FC.el('div', { class: 'fc-empty-big' }, [
      FC.el('p', { text: '아직 포스가 없어요' }),
      FC.el('button', { class: 'fc-btn fc-btn-primary', id: 'fc-add-force-big', type: 'button', text: '+ 포스 추가' })
    ]));
    return;
  }

  forces.forEach(function (f) {
    host.appendChild(FC.renderForce(f, dupIds));
  });
};

FC.renderForce = function (force, dupIds) {
  var slots = (FC.state.slots || []).filter(function (s) {
    return Number(s.force_id) === Number(force.id);
  });
  var filled = slots.filter(function (s) { return s.character_id !== null; }).length;

  var when = (force.day_of_week || '') + (force.start_time ? ' ' + force.start_time : '');
  var head = FC.el('div', { class: 'fc-force-head' }, [
    FC.el('span', { class: 'fc-force-no', text: force.force_no + '포스' }),
    FC.el('span', { class: 'fc-force-when', text: when || '시간 미정' }),
    FC.el('span', { class: 'fc-force-count', text: filled + '/10' }),
    FC.el('span', { class: 'fc-spacer' }),
    FC.el('button', { class: 'fc-icon-btn fc-force-edit', 'data-force-id': force.id, type: 'button', text: '수정' }),
    FC.el('button', { class: 'fc-icon-btn fc-force-del', 'data-force-id': force.id, type: 'button', text: '삭제' })
  ]);

  var body = FC.el('div', { class: 'fc-force-body' });
  [1, 2].forEach(function (party) {
    var row = FC.el('div', { class: 'fc-party' }, [
      FC.el('span', { class: 'fc-party-label', text: party + '파티' })
    ]);
    slots.filter(function (s) { return Number(s.party_no) === party; })
         .sort(function (a, b) { return a.slot_no - b.slot_no; })
         .forEach(function (s) { row.appendChild(FC.renderSlot(s, dupIds)); });
    body.appendChild(row);
  });

  var card = FC.el('div', { class: 'fc-force', 'data-force-id': force.id }, [head, body]);
  if (force.memo) card.appendChild(FC.el('div', { class: 'fc-force-memo', text: force.memo }));
  return card;
};

FC.renderSlot = function (slot, dupIds) {
  if (slot.character_id === null) {
    return FC.el('div', { class: 'fc-slot is-empty', 'data-slot-id': slot.id, text: '＋' });
  }
  var c = FC.byId(FC.state.characters, slot.character_id);
  if (!c) return FC.el('div', { class: 'fc-slot is-empty', 'data-slot-id': slot.id, text: '＋' });

  var main = FC.mainOf(c.player_id);
  var isDup = dupIds.indexOf(Number(c.id)) !== -1;
  var node = FC.el('div', {
    class: 'fc-slot is-filled' + (isDup ? ' is-dup' : ''),
    'data-slot-id': slot.id, 'data-character-id': c.id, draggable: 'true',
    style: '--slot-color:' + FC.classColor(c.class)
  }, [
    FC.el('span', { class: 'fc-slot-name', text: c.name }),
    FC.el('span', { class: 'fc-slot-owner',
      text: (main && main.id !== c.id ? main.name : c.class || '') })
  ]);
  node.appendChild(FC.el('button', { class: 'fc-slot-x', type: 'button', 'data-slot-id': slot.id, text: '×' }));
  return node;
};

FC.render = function () {
  var raids = FC.state.raids || [];
  if (FC.activeRaidId && !FC.byId(raids, FC.activeRaidId)) FC.activeRaidId = null;
  if (!FC.activeRaidId && raids.length) FC.activeRaidId = raids[0].id;
  FC.renderSidebar();
  FC.renderTabs();
  FC.renderBoard();
};
```

- [ ] **Step 2: CSS 작성**

`assets/app.css` 끝에 추가:

```css
.fc-side-title{font-size:11px;font-weight:700;color:var(--dim);letter-spacing:1px;margin-bottom:8px;}
.fc-search{width:100%;padding:7px 10px;background:var(--bg);border:1px solid var(--line);border-radius:8px;
  color:var(--text);font-family:inherit;font-size:13px;outline:none;margin-bottom:10px;}
.fc-search:focus{border-color:var(--blue);}
.fc-roster{display:flex;flex-direction:column;gap:5px;max-height:calc(100vh - 220px);overflow-y:auto;}
.fc-roster-card{display:flex;align-items:center;gap:7px;padding:7px 9px;background:var(--panel-2);
  border:1px solid var(--line);border-radius:8px;cursor:pointer;}
.fc-roster-card:hover{border-color:var(--gold-2);}
.fc-roster-card.is-open{border-color:var(--gold);}
.fc-dot{width:8px;height:8px;border-radius:50%;flex:0 0 auto;}
.fc-roster-name{font-size:13px;font-weight:700;}
.fc-roster-meta{font-size:11px;color:var(--dim);margin-left:auto;}
.fc-badge{min-width:20px;height:20px;line-height:20px;text-align:center;border-radius:10px;
  background:var(--blue);color:#fff;font-size:11px;font-weight:700;flex:0 0 auto;}
.fc-badge.is-zero{background:var(--panel);color:var(--dim);border:1px solid var(--line);}
.fc-block{width:100%;margin-top:10px;}
.fc-empty{font-size:12px;color:var(--dim);line-height:1.6;padding:10px 2px;}
.fc-empty-big{text-align:center;padding:60px 20px;color:var(--muted);border:1px dashed var(--line);
  border-radius:var(--radius);display:flex;flex-direction:column;gap:14px;align-items:center;}
.fc-btn-primary{background:linear-gradient(135deg,var(--gold-2),var(--gold));border:none;color:#0a0c14;font-weight:700;}

.fc-tabs{display:flex;align-items:center;gap:6px;margin-bottom:14px;flex-wrap:wrap;}
.fc-tab{background:transparent;border:1px solid var(--line);border-radius:8px;color:var(--muted);
  padding:7px 14px;font-family:inherit;font-size:13px;font-weight:700;cursor:pointer;}
.fc-tab:hover{color:var(--text);}
.fc-tab.is-active{background:var(--panel-2);border-color:var(--gold-2);color:var(--gold);}
.fc-tab-add{padding:7px 11px;color:var(--dim);}
.fc-spacer{flex:1 1 auto;}

.fc-raid-memo{font-size:12px;color:var(--muted);margin-bottom:10px;padding-left:2px;}
.fc-warn{background:rgba(231,76,60,0.12);border:1px solid rgba(231,76,60,0.35);border-radius:8px;
  color:#ff9c92;font-size:12px;padding:8px 12px;margin-bottom:12px;}

.fc-force{background:var(--panel);border:1px solid var(--line);border-radius:var(--radius);
  padding:12px;margin-bottom:12px;}
.fc-force-head{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.fc-force-no{font-size:13px;font-weight:900;color:var(--gold);}
.fc-force-when{font-size:12px;color:var(--muted);}
.fc-force-count{font-size:11px;color:var(--dim);}
.fc-icon-btn{background:transparent;border:1px solid var(--line);border-radius:6px;color:var(--dim);
  font-family:inherit;font-size:11px;padding:3px 8px;cursor:pointer;}
.fc-icon-btn:hover{color:var(--text);border-color:var(--line-2);}
.fc-party{display:flex;align-items:center;gap:6px;margin-bottom:6px;}
.fc-party-label{width:40px;flex:0 0 40px;font-size:11px;color:var(--dim);}
.fc-force-memo{font-size:11px;color:var(--dim);margin-top:8px;padding-left:46px;}

.fc-slot{flex:1 1 0;min-width:0;height:46px;border-radius:8px;display:flex;flex-direction:column;
  align-items:center;justify-content:center;font-size:13px;}
.fc-slot.is-empty{border:1px dashed var(--line);color:var(--dim);}
.fc-slot.is-filled{position:relative;background:color-mix(in srgb, var(--slot-color) 22%, var(--panel-2));
  border:1px solid var(--slot-color);cursor:grab;}
.fc-slot.is-filled:active{cursor:grabbing;}
.fc-slot.is-dup{outline:2px solid var(--danger);outline-offset:-2px;}
.fc-slot-name{font-weight:700;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.fc-slot-owner{font-size:10px;color:var(--muted);}
.fc-slot-x{display:none;position:absolute;top:2px;right:4px;background:transparent;border:none;
  color:var(--muted);font-size:14px;cursor:pointer;line-height:1;}
.fc-slot.is-filled:hover .fc-slot-x{display:block;}
.fc-slot.is-drop-target{border-color:var(--gold);box-shadow:0 0 0 2px rgba(240,201,106,0.25);}
```

- [ ] **Step 3: 배포하고 렌더를 브라우저에서 확인**

```bash
git add assets/app.js assets/app.css && \
git commit -m "$(printf '포스 편성: 대기창·레이드탭·포스보드 렌더 구현\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main'
```

```bash
B=~/.claude/skills/gstack/browse/dist/browse
$B goto 'http://14.63.164.109/sanctuary/'
$B js "JSON.stringify({tabs: document.querySelectorAll('.fc-tab').length, emptyBoard: !!document.querySelector('.fc-empty-big'), sidebarEmpty: !!document.querySelector('.fc-empty')})"
$B console --errors
$B screenshot /private/tmp/claude-501/-Users-eztake-japanMES-sanctuary/d61ea218-6d2a-4eb5-a1dd-e1b74ecefa74/scratchpad/fc-empty.png
```

Expected: 데이터가 없는 상태이므로 `{"tabs":1,...,"emptyBoard":true,"sidebarEmpty":true}`
(탭 1개는 `+` 버튼). 콘솔 에러 없음. 스크린샷에 "레이드를 먼저 만들어주세요"가 보인다.

- [ ] **Step 4: 시드 데이터로 렌더 확인**

```bash
ssh aion-sanctuary 'cd /var/www/html/sanctuary && php -r "
require \"force/db.php\"; require \"force/schema.php\"; require \"force/store.php\";
\$p = fc_pdo(); fc_init_schema(\$p);
\$pid = fc_create_player(\$p, \"zzSeed_컹용\", [\"zzSeed_소월령\", \"zzSeed_광천대성\"]);
fc_update_character(\$p, (int)\$p->query(\"SELECT id FROM fc_characters WHERE char_name=\\\"zzSeed_컹용\\\"\")->fetchColumn(), [\"class\"=>\"검성\",\"atul\"=>41200]);
\$rid = fc_create_raid(\$p, \"zzSeed_루드라\");
\$fid = fc_create_force(\$p, \$rid, \"토\", \"19:30\", \"남는자리 새싹\");
\$s = fc_slot_ids(\$p, \$fid);
fc_assign_slot(\$p, (int)\$s[0][\"id\"], (int)\$p->query(\"SELECT id FROM fc_characters WHERE char_name=\\\"zzSeed_컹용\\\"\")->fetchColumn());
echo \"seeded\n\";
"'
```

```bash
B=~/.claude/skills/gstack/browse/dist/browse
$B goto 'http://14.63.164.109/sanctuary/'
$B js "JSON.stringify({cards: document.querySelectorAll('.fc-roster-card').length, forces: document.querySelectorAll('.fc-force').length, slots: document.querySelectorAll('.fc-slot').length, filled: document.querySelectorAll('.fc-slot.is-filled').length, badge: document.querySelector('.fc-badge') && document.querySelector('.fc-badge').textContent})"
$B screenshot /private/tmp/claude-501/-Users-eztake-japanMES-sanctuary/d61ea218-6d2a-4eb5-a1dd-e1b74ecefa74/scratchpad/fc-seeded.png
```

Expected: `{"cards":1,"forces":1,"slots":10,"filled":1,"badge":"1"}`.
스크린샷을 Read 툴로 열어 레이아웃이 설계대로인지 눈으로 확인한다.

- [ ] **Step 5: 시드 데이터 정리**

```bash
ssh aion-sanctuary 'cd /var/www/html/sanctuary && php -r "
require \"force/db.php\"; require \"force/store.php\";
\$p = fc_pdo();
foreach (\$p->query(\"SELECT id FROM fc_raids WHERE name LIKE \\\"zzSeed%\\\"\")->fetchAll(PDO::FETCH_COLUMN) as \$r) fc_delete_raid(\$p, (int)\$r);
foreach (\$p->query(\"SELECT DISTINCT player_id FROM fc_characters WHERE char_name LIKE \\\"zzSeed%\\\"\")->fetchAll(PDO::FETCH_COLUMN) as \$x) fc_delete_player(\$p, (int)\$x);
echo \"cleaned\n\";
"'
```

Expected: `cleaned`

---

### Task 10: API 클라이언트 · 토스트 · 연결 배너 · 폴링

**Files:**
- Modify: `assets/app.js`
- Modify: `assets/app.css`

**Interfaces:**
- Consumes: `FC.render()` (Task 9)
- Produces:
  - `FC.api(action, payload): Promise<object>` — 실패 시 reject(Error). `Error.message`는 서버의 `error` 문자열
  - `FC.toast(message, kind): void` — `kind`는 `'ok'` 또는 `'err'`
  - `FC.setConnected(ok): void`
  - `FC.refresh(): Promise<void>` — `state`를 새로 받아 `FC.render()`
  - `FC.busy` — 드래그 중이거나 팝오버/모달이 열려 있으면 `true` (폴링 보류 신호)

- [ ] **Step 1: 구현**

`assets/app.js`의 `FC.render` 정의 뒤에 추가:

```javascript
FC.busy = false;

FC.toast = function (message, kind) {
  var wrap = document.getElementById('fc-toast');
  var node = FC.el('div', { class: 'fc-toast is-' + (kind || 'ok'), text: message });
  wrap.appendChild(node);
  setTimeout(function () {
    node.classList.add('is-out');
    setTimeout(function () { if (node.parentNode) node.parentNode.removeChild(node); }, 250);
  }, 3000);
};

FC.setConnected = function (ok) {
  document.getElementById('fc-conn').hidden = !!ok;
};

FC.api = function (action, payload) {
  var body = Object.assign({ action: action }, payload || {});
  return fetch('force/api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  }).then(function (res) {
    return res.json().catch(function () { throw new Error('bad_response'); });
  }).then(function (json) {
    FC.setConnected(true);
    if (!json.ok) throw new Error(json.error || 'unknown_error');
    if (typeof json.revision === 'number') FC.state.revision = json.revision;
    return json.data;
  }).catch(function (err) {
    if (err.message === 'bad_response' || err instanceof TypeError) FC.setConnected(false);
    throw err;
  });
};

FC.refresh = function () {
  return FC.api('state', {}).then(function (state) {
    FC.state = state;
    FC.render();
  });
};

var ERROR_TEXT = {
  'bad_request': '입력값이 올바르지 않아요',
  'unauthorized': '세션이 만료됐어요. 새로고침 후 다시 로그인하세요',
  'not_found': '대상을 찾을 수 없어요',
  'lookup_failed': '아툴 조회에 실패했어요',
  'slot_not_found': '슬롯을 찾을 수 없어요',
  'empty_name': '이름을 입력하세요',
  'unknown_action': '알 수 없는 요청이에요'
};

FC.errorText = function (err) {
  var msg = err && err.message ? err.message : '';
  if (msg.indexOf('duplicate_name') === 0) {
    var who = msg.split(':')[1] || '';
    return '이미 등록된 캐릭명이에요' + (who ? ' — ' + who : '');
  }
  return ERROR_TEXT[msg] || ('저장 실패: ' + (msg || '알 수 없는 오류'));
};

// 10초마다 revision만 확인한다. 드래그 중이거나 팝오버/모달이 열려 있으면 건너뛴다 —
// 손에 든 카드가 사라지면 안 된다.
FC.startPolling = function () {
  setInterval(function () {
    if (FC.busy) return;
    FC.api('state', {}).then(function (state) {
      if (Number(state.revision) === Number(FC.state.revision) &&
          (FC.state.slots || []).length === (state.slots || []).length) return;
      FC.state = state;
      FC.render();
    }).catch(function () { /* 배너는 FC.api가 이미 띄웠다 */ });
  }, 10000);
};
```

`boot()` 함수 끝(`FC.render();` 다음)에 `FC.startPolling();` 을 추가한다.

- [ ] **Step 2: CSS 추가**

`assets/app.css` 끝에 추가:

```css
.fc-toast{background:var(--panel-2);border:1px solid var(--line);border-left:3px solid var(--blue);
  border-radius:8px;padding:10px 14px;font-size:13px;box-shadow:0 8px 24px rgba(0,0,0,0.4);
  transition:opacity .25s, transform .25s;}
.fc-toast.is-err{border-left-color:var(--danger);}
.fc-toast.is-out{opacity:0;transform:translateY(6px);}
```

- [ ] **Step 3: 배포하고 브라우저에서 확인**

```bash
git add assets/app.js assets/app.css && \
git commit -m "$(printf '포스 편성: API 클라이언트·토스트·연결배너·폴링 추가\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main'
```

```bash
B=~/.claude/skills/gstack/browse/dist/browse
$B goto 'http://14.63.164.109/sanctuary/'
$B js "await FC.api('state',{}).then(s => JSON.stringify({ok:true, rev:s.revision})).catch(e => 'ERR:'+e.message)"
$B js "FC.toast('테스트 토스트','ok'); document.querySelectorAll('.fc-toast').length"
$B js "await FC.api('nope.nope',{}).then(()=> 'no-error').catch(e => FC.errorText(e))"
$B console --errors
```

Expected: 첫 번째 `{"ok":true,"rev":<정수>}`, 두 번째 `1`,
세 번째 `"알 수 없는 요청이에요"`. 콘솔 에러 없음.

---

### Task 11: 명단 관리 — 인원 추가 · 부캐 추가 · 삭제

**Files:**
- Modify: `assets/app.js`
- Modify: `assets/app.css`

**Interfaces:**
- Consumes: `FC.api()`, `FC.toast()`, `FC.refresh()`, `FC.el()` (Task 9·10)
- Produces:
  - `FC.openModal(title, contentEl, onClose): void`
  - `FC.closeModal(): void`
  - `FC.openRoster(): void` — 명단 관리 모달
  - `FC.openAddPlayer(): void` — 본캐 1 + 부캐 N 입력 모달
  - 이벤트 위임 진입점 `FC.bindGlobalEvents(): void` (Task 12·13이 여기에 핸들러를 덧붙인다)

- [ ] **Step 1: 모달 기반과 명단 관리 구현**

`assets/app.js` 끝에 추가:

```javascript
FC.closeModal = function () {
  var host = document.getElementById('fc-modal');
  host.hidden = true;
  host.innerHTML = '';
  FC.busy = false;
};

FC.openModal = function (title, contentEl) {
  var host = document.getElementById('fc-modal');
  host.innerHTML = '';
  var panel = FC.el('div', { class: 'fc-modal-panel' }, [
    FC.el('div', { class: 'fc-modal-head' }, [
      FC.el('span', { class: 'fc-modal-title', text: title }),
      FC.el('button', { class: 'fc-icon-btn', id: 'fc-modal-close', type: 'button', text: '닫기' })
    ]),
    contentEl
  ]);
  host.appendChild(panel);
  host.hidden = false;
  FC.busy = true;
  document.getElementById('fc-modal-close').addEventListener('click', FC.closeModal);
  host.addEventListener('click', function (e) { if (e.target === host) FC.closeModal(); });
};

FC.openAddPlayer = function () {
  var mainInput = FC.el('input', { class: 'fc-input', type: 'text', placeholder: '본캐명 (필수)' });
  var subsWrap = FC.el('div', { class: 'fc-subs' });

  function addSubRow(value) {
    var row = FC.el('input', { class: 'fc-input fc-sub-input', type: 'text', placeholder: '부캐명' });
    if (value) row.value = value;
    subsWrap.appendChild(row);
  }
  addSubRow('');
  addSubRow('');

  var addMore = FC.el('button', { class: 'fc-btn fc-block', type: 'button', text: '+ 부캐 칸 추가' });
  addMore.addEventListener('click', function () { addSubRow(''); });

  var save = FC.el('button', { class: 'fc-btn fc-btn-primary fc-block', type: 'button', text: '등록' });
  var hint = FC.el('p', { class: 'fc-hint',
    text: '캐릭명만 넣으면 직업·아툴점수·아이템레벨을 자동으로 불러옵니다. 조회에 실패해도 등록은 됩니다.' });

  save.addEventListener('click', function () {
    var main = mainInput.value.trim();
    if (!main) { FC.toast('본캐명을 입력하세요', 'err'); return; }
    var subs = Array.prototype.slice.call(subsWrap.querySelectorAll('.fc-sub-input'))
      .map(function (i) { return i.value.trim(); })
      .filter(function (v) { return v !== ''; });

    save.disabled = true;
    save.textContent = '조회 중…';
    FC.api('player.create', { main_name: main, subs: subs }).then(function () {
      FC.toast(main + ' 등록 완료', 'ok');
      FC.closeModal();
      return FC.refresh();
    }).catch(function (err) {
      FC.toast(FC.errorText(err), 'err');
      save.disabled = false;
      save.textContent = '등록';
    });
  });

  FC.openModal('인원 추가', FC.el('div', { class: 'fc-form' }, [mainInput, subsWrap, addMore, hint, save]));
  mainInput.focus();
};

FC.openRoster = function () {
  var list = FC.el('div', { class: 'fc-roster-manage' });

  (FC.state.players || []).forEach(function (p) {
    var main = FC.mainOf(p.id);
    if (!main) return;
    var chars = FC.charsOfPlayer(p.id);

    var rows = FC.el('div', { class: 'fc-manage-chars' });
    chars.forEach(function (c) {
      rows.appendChild(FC.el('div', { class: 'fc-manage-char' }, [
        FC.el('span', { class: 'fc-dot', style: 'background:' + FC.classColor(c.class) }),
        FC.el('span', { class: 'fc-manage-name', text: (Number(c.is_main) === 1 ? '⭐ ' : '') + c.name }),
        FC.el('span', { class: 'fc-manage-meta',
          text: (c.class || '직업?') + ' · ' + (c.atul ? c.atul.toLocaleString() : '점수?') }),
        FC.el('button', { class: 'fc-icon-btn fc-char-refresh', 'data-character-id': c.id, type: 'button', text: '갱신' }),
        FC.el('button', { class: 'fc-icon-btn fc-char-del', 'data-character-id': c.id, type: 'button', text: '삭제' })
      ]));
    });

    var addSub = FC.el('div', { class: 'fc-manage-add' }, [
      FC.el('input', { class: 'fc-input fc-add-sub-name', type: 'text', placeholder: '부캐명 추가' }),
      FC.el('button', { class: 'fc-btn fc-add-sub-go', 'data-player-id': p.id, type: 'button', text: '추가' })
    ]);

    list.appendChild(FC.el('div', { class: 'fc-manage-player' }, [
      FC.el('div', { class: 'fc-manage-head' }, [
        FC.el('strong', { text: main.name }),
        FC.el('span', { class: 'fc-spacer' }),
        FC.el('button', { class: 'fc-icon-btn fc-player-del', 'data-player-id': p.id, type: 'button', text: '이 사람 전체 삭제' })
      ]),
      rows, addSub
    ]));
  });

  if (!(FC.state.players || []).length) {
    list.appendChild(FC.el('p', { class: 'fc-empty', text: '아직 등록된 인원이 없어요.' }));
  }

  var addBtn = FC.el('button', { class: 'fc-btn fc-btn-primary fc-block', type: 'button', text: '+ 인원 추가' });
  addBtn.addEventListener('click', function () { FC.closeModal(); FC.openAddPlayer(); });

  FC.openModal('명단 관리', FC.el('div', {}, [addBtn, list]));
};

FC.bindGlobalEvents = function () {
  document.addEventListener('click', function (e) {
    var t = e.target;

    if (t.id === 'fc-open-roster') { FC.openRoster(); return; }
    if (t.id === 'fc-add-player')  { FC.openAddPlayer(); return; }

    if (t.classList.contains('fc-char-del')) {
      var cid = Number(t.getAttribute('data-character-id'));
      var ch = FC.byId(FC.state.characters, cid);
      if (!confirm((ch ? ch.name : '이 캐릭터') + ' 을(를) 삭제할까요? 배치된 자리도 비워집니다.')) return;
      FC.api('character.delete', { character_id: cid })
        .then(function () { FC.toast('삭제했어요', 'ok'); return FC.refresh(); })
        .then(function () { FC.closeModal(); FC.openRoster(); })
        .catch(function (err) { FC.toast(FC.errorText(err), 'err'); });
      return;
    }

    if (t.classList.contains('fc-player-del')) {
      var pid = Number(t.getAttribute('data-player-id'));
      var m = FC.mainOf(pid);
      var n = FC.charsOfPlayer(pid).length;
      if (!confirm((m ? m.name : '이 사람') + ' 의 캐릭터 ' + n + '개를 전부 삭제할까요?')) return;
      FC.api('player.delete', { player_id: pid })
        .then(function () { FC.toast('삭제했어요', 'ok'); return FC.refresh(); })
        .then(function () { FC.closeModal(); FC.openRoster(); })
        .catch(function (err) { FC.toast(FC.errorText(err), 'err'); });
      return;
    }

    if (t.classList.contains('fc-char-refresh')) {
      var rid = Number(t.getAttribute('data-character-id'));
      t.disabled = true; t.textContent = '조회중';
      FC.api('atul.refresh', { character_id: rid })
        .then(function () { FC.toast('갱신했어요', 'ok'); return FC.refresh(); })
        .then(function () { FC.closeModal(); FC.openRoster(); })
        .catch(function (err) {
          FC.toast(FC.errorText(err), 'err');
          t.disabled = false; t.textContent = '갱신';
        });
      return;
    }

    if (t.classList.contains('fc-add-sub-go')) {
      var ownerId = Number(t.getAttribute('data-player-id'));
      var input = t.parentNode.querySelector('.fc-add-sub-name');
      var name = input.value.trim();
      if (!name) { FC.toast('부캐명을 입력하세요', 'err'); return; }
      t.disabled = true; t.textContent = '조회중';
      FC.api('character.add', { player_id: ownerId, name: name })
        .then(function () { FC.toast(name + ' 추가 완료', 'ok'); return FC.refresh(); })
        .then(function () { FC.closeModal(); FC.openRoster(); })
        .catch(function (err) {
          FC.toast(FC.errorText(err), 'err');
          t.disabled = false; t.textContent = '추가';
        });
      return;
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') FC.closeModal();
  });
};
```

`boot()` 끝에 `FC.bindGlobalEvents();` 를 추가한다.

- [ ] **Step 2: CSS 추가**

`assets/app.css` 끝에 추가:

```css
.fc-modal{position:fixed;inset:0;background:rgba(4,6,12,0.72);display:flex;align-items:center;
  justify-content:center;z-index:80;padding:24px;}
.fc-modal-panel{background:var(--panel);border:1px solid var(--line);border-radius:14px;
  width:520px;max-width:100%;max-height:85vh;overflow-y:auto;padding:18px;
  box-shadow:0 24px 70px rgba(0,0,0,0.6);}
.fc-modal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;}
.fc-modal-title{font-size:15px;font-weight:900;color:var(--gold);}
.fc-form{display:flex;flex-direction:column;gap:8px;}
.fc-subs{display:flex;flex-direction:column;gap:6px;}
.fc-input{width:100%;padding:8px 11px;background:var(--bg);border:1px solid var(--line);border-radius:8px;
  color:var(--text);font-family:inherit;font-size:13px;outline:none;}
.fc-input:focus{border-color:var(--blue);}
.fc-hint{font-size:11px;color:var(--dim);line-height:1.6;}
.fc-manage-player{border:1px solid var(--line);border-radius:10px;padding:10px;margin-top:10px;}
.fc-manage-head{display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:13px;}
.fc-manage-chars{display:flex;flex-direction:column;gap:4px;}
.fc-manage-char{display:flex;align-items:center;gap:7px;font-size:12px;padding:4px 2px;}
.fc-manage-name{font-weight:700;}
.fc-manage-meta{color:var(--dim);font-size:11px;margin-left:auto;}
.fc-manage-add{display:flex;gap:6px;margin-top:8px;}
.fc-manage-add .fc-input{flex:1 1 auto;}
```

- [ ] **Step 3: 배포하고 브라우저에서 실제로 인원을 등록해 확인**

```bash
git add assets/app.js assets/app.css && \
git commit -m "$(printf '포스 편성: 명단 관리 모달(인원/부캐 추가·삭제·갱신) 구현\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main'
```

```bash
B=~/.claude/skills/gstack/browse/dist/browse
$B goto 'http://14.63.164.109/sanctuary/'
$B click '#fc-open-roster'
$B js "!!document.querySelector('.fc-modal-panel')"
$B js "await FC.api('player.create',{main_name:'zzUI_본캐',subs:['zzUI_부캐1']}).then(()=>FC.refresh()).then(()=> JSON.stringify({cards: document.querySelectorAll('.fc-roster-card').length}))"
$B screenshot /private/tmp/claude-501/-Users-eztake-japanMES-sanctuary/d61ea218-6d2a-4eb5-a1dd-e1b74ecefa74/scratchpad/fc-roster.png
$B console --errors
```

Expected: 모달이 열리고 `true`, 등록 후 `{"cards":1}`. 콘솔 에러 없음.
스크린샷을 Read로 열어 명단 모달 디자인을 확인한다. 이 테스트 데이터는 Task 14에서 정리한다.

---

### Task 12: 레이드·포스 추가와 수정

**Files:**
- Modify: `assets/app.js`

**Interfaces:**
- Consumes: `FC.openModal()`, `FC.api()`, `FC.refresh()`, `FC.bindGlobalEvents()` (Task 11)
- Produces:
  - `FC.openRaidForm(raid): void` — `raid`가 `null`이면 새로 만들기
  - `FC.openForceForm(force): void` — `force`가 `null`이면 새로 만들기
  - `DAYS` 상수 사용 (Task 9에서 정의됨)

- [ ] **Step 1: 구현**

`assets/app.js`의 `FC.bindGlobalEvents` 정의 **앞**에 추가:

```javascript
FC.openRaidForm = function (raid) {
  var nameInput = FC.el('input', { class: 'fc-input', type: 'text', placeholder: '레이드 이름 (예: 루드라)' });
  var memoInput = FC.el('input', { class: 'fc-input', type: 'text', placeholder: '메모 (선택)' });
  if (raid) { nameInput.value = raid.name; memoInput.value = raid.memo || ''; }

  var save = FC.el('button', { class: 'fc-btn fc-btn-primary fc-block', type: 'button', text: raid ? '저장' : '추가' });
  save.addEventListener('click', function () {
    var name = nameInput.value.trim();
    if (!name) { FC.toast('레이드 이름을 입력하세요', 'err'); return; }
    save.disabled = true;
    var call = raid
      ? FC.api('raid.update', { raid_id: raid.id, name: name, memo: memoInput.value.trim() })
      : FC.api('raid.create', { name: name });
    call.then(function (data) {
      if (!raid && data && data.raid_id) FC.activeRaidId = Number(data.raid_id);
      FC.closeModal();
      return FC.refresh();
    }).catch(function (err) { FC.toast(FC.errorText(err), 'err'); save.disabled = false; });
  });

  var children = [nameInput];
  if (raid) children.push(memoInput);
  children.push(save);

  if (raid) {
    var del = FC.el('button', { class: 'fc-btn fc-block', type: 'button', text: '이 레이드 삭제' });
    del.addEventListener('click', function () {
      var n = (FC.state.forces || []).filter(function (f) {
        return Number(f.raid_id) === Number(raid.id);
      }).length;
      if (!confirm(raid.name + ' 을(를) 삭제할까요? 소속 포스 ' + n + '개가 함께 사라집니다.')) return;
      FC.api('raid.delete', { raid_id: raid.id }).then(function () {
        FC.activeRaidId = null;
        FC.closeModal();
        FC.toast('삭제했어요', 'ok');
        return FC.refresh();
      }).catch(function (err) { FC.toast(FC.errorText(err), 'err'); });
    });
    children.push(del);
  }

  FC.openModal(raid ? '레이드 수정' : '레이드 추가', FC.el('div', { class: 'fc-form' }, children));
  nameInput.focus();
};

FC.openForceForm = function (force) {
  var daySel = FC.el('select', { class: 'fc-input' });
  daySel.appendChild(FC.el('option', { value: '', text: '요일 미정' }));
  DAYS.forEach(function (d) {
    var opt = FC.el('option', { value: d, text: d + '요일' });
    if (force && force.day_of_week === d) opt.selected = true;
    daySel.appendChild(opt);
  });

  var timeInput = FC.el('input', { class: 'fc-input', type: 'time' });
  if (force && force.start_time) timeInput.value = force.start_time;

  var memoInput = FC.el('input', { class: 'fc-input', type: 'text', placeholder: '메모 (예: 남는자리 새싹)' });
  if (force) memoInput.value = force.memo || '';

  var save = FC.el('button', { class: 'fc-btn fc-btn-primary fc-block', type: 'button', text: force ? '저장' : '추가' });
  save.addEventListener('click', function () {
    save.disabled = true;
    var payload = {
      day_of_week: daySel.value,
      start_time: timeInput.value,
      memo: memoInput.value.trim()
    };
    var call = force
      ? FC.api('force.update', Object.assign({ force_id: force.id }, payload))
      : FC.api('force.create', Object.assign({ raid_id: FC.activeRaidId }, payload));
    call.then(function () {
      FC.closeModal();
      return FC.refresh();
    }).catch(function (err) { FC.toast(FC.errorText(err), 'err'); save.disabled = false; });
  });

  FC.openModal(force ? (force.force_no + '포스 수정') : '포스 추가',
    FC.el('div', { class: 'fc-form' }, [
      FC.el('label', { class: 'fc-hint', text: '무슨 요일 몇 시에 진행하나요?' }),
      daySel, timeInput, memoInput, save
    ]));
};
```

`FC.bindGlobalEvents`의 `document.addEventListener('click', ...)` 핸들러 안, `if (t.id === 'fc-add-player')`
줄 다음에 추가:

```javascript
    if (t.id === 'fc-add-raid' || t.id === 'fc-add-raid-big') { FC.openRaidForm(null); return; }
    if (t.id === 'fc-edit-raid') { FC.openRaidForm(FC.byId(FC.state.raids, FC.activeRaidId)); return; }
    if (t.id === 'fc-add-force' || t.id === 'fc-add-force-big') { FC.openForceForm(null); return; }

    if (t.classList.contains('fc-tab') && t.hasAttribute('data-raid-id')) {
      FC.activeRaidId = Number(t.getAttribute('data-raid-id'));
      FC.render();
      return;
    }

    if (t.classList.contains('fc-force-edit')) {
      FC.openForceForm(FC.byId(FC.state.forces, Number(t.getAttribute('data-force-id'))));
      return;
    }

    if (t.classList.contains('fc-force-del')) {
      var delFid = Number(t.getAttribute('data-force-id'));
      var f = FC.byId(FC.state.forces, delFid);
      if (!confirm((f ? f.force_no + '포스' : '이 포스') + ' 를 삭제할까요?')) return;
      FC.api('force.delete', { force_id: delFid })
        .then(function () { FC.toast('삭제했어요', 'ok'); return FC.refresh(); })
        .catch(function (err) { FC.toast(FC.errorText(err), 'err'); });
      return;
    }
```

- [ ] **Step 2: 배포하고 브라우저에서 확인**

```bash
git add assets/app.js && \
git commit -m "$(printf '포스 편성: 레이드/포스 추가·수정·삭제 UI 구현\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main'
```

```bash
B=~/.claude/skills/gstack/browse/dist/browse
$B goto 'http://14.63.164.109/sanctuary/'
$B click '#fc-add-raid'
$B fill '.fc-modal-panel .fc-input' 'zzUI_루드라'
$B click '.fc-btn-primary'
$B js "JSON.stringify({tabs: document.querySelectorAll('.fc-tab[data-raid-id]').length, active: document.querySelector('.fc-tab.is-active') && document.querySelector('.fc-tab.is-active').textContent})"
$B click '#fc-add-force'
$B js "document.querySelectorAll('.fc-modal-panel select option').length"
$B click '.fc-modal-panel .fc-btn-primary'
$B js "JSON.stringify({forces: document.querySelectorAll('.fc-force').length, slots: document.querySelectorAll('.fc-slot').length})"
$B console --errors
```

Expected: 탭 생성 후 `{"tabs":1,"active":"zzUI_루드라"}`, 요일 옵션 `8`개(미정+7요일),
포스 추가 후 `{"forces":1,"slots":10}`. 콘솔 에러 없음.

---

### Task 13: 팝오버와 드래그앤드롭

**Files:**
- Modify: `assets/app.js`
- Modify: `assets/app.css`

**Interfaces:**
- Consumes: Task 9~12 전부
- Produces:
  - `FC.openPopover(playerId, anchorEl): void`
  - `FC.closePopover(): void`
  - `FC.drag` — `{type: 'character'|'slot', characterId: number, fromSlotId: number|null}` 또는 `null`
  - `FC.dropOnSlot(slotId): void` — 낙관적 UI + 실패 시 롤백

- [ ] **Step 1: 구현**

`assets/app.js` 끝에 추가:

```javascript
FC.drag = null;

FC.closePopover = function () {
  var pop = document.getElementById('fc-popover');
  pop.hidden = true;
  pop.innerHTML = '';
  var opened = document.querySelector('.fc-roster-card.is-open');
  if (opened) opened.classList.remove('is-open');
  if (!FC.drag) FC.busy = false;
};

FC.openPopover = function (playerId, anchorEl) {
  var pop = document.getElementById('fc-popover');
  pop.innerHTML = '';

  var main = FC.mainOf(playerId);
  var chars = FC.charsOfPlayer(playerId);

  // 이 레이드에서 이 캐릭터가 배치된 포스 번호들
  var placedByChar = {};
  var forcesOfRaid = (FC.state.forces || []).filter(function (f) {
    return Number(f.raid_id) === Number(FC.activeRaidId);
  });
  forcesOfRaid.forEach(function (f) {
    (FC.state.slots || []).forEach(function (s) {
      if (Number(s.force_id) !== Number(f.id) || s.character_id === null) return;
      var key = String(s.character_id);
      if (!placedByChar[key]) placedByChar[key] = [];
      if (placedByChar[key].indexOf(f.force_no) === -1) placedByChar[key].push(f.force_no);
    });
  });

  pop.appendChild(FC.el('div', { class: 'fc-pop-title', text: (main ? main.name : '') + ' 의 캐릭터' }));

  chars.forEach(function (c) {
    var placed = placedByChar[String(c.id)] || [];
    var row = FC.el('div', {
      class: 'fc-pop-row' + (placed.length ? ' is-placed' : ''),
      draggable: 'true', 'data-character-id': c.id,
      style: '--slot-color:' + FC.classColor(c.class)
    }, [
      FC.el('span', { class: 'fc-dot', style: 'background:' + FC.classColor(c.class) }),
      FC.el('span', { class: 'fc-pop-name', text: (Number(c.is_main) === 1 ? '⭐ ' : '') + c.name }),
      FC.el('span', { class: 'fc-pop-meta',
        text: (c.class || '직업?') + ' · ' + (c.atul ? c.atul.toLocaleString() : '—') })
    ]);
    if (placed.length) {
      row.appendChild(FC.el('span', { class: 'fc-pop-tag', text: placed.join(',') + '포스' }));
    }
    pop.appendChild(row);
  });

  if (!FC.activeRaidId) {
    pop.appendChild(FC.el('p', { class: 'fc-hint', text: '레이드를 먼저 선택하세요.' }));
  }

  pop.hidden = false;
  var rect = anchorEl.getBoundingClientRect();
  var top = Math.min(rect.top + window.scrollY, window.scrollY + window.innerHeight - pop.offsetHeight - 16);
  pop.style.top = Math.max(window.scrollY + 8, top) + 'px';
  pop.style.left = (rect.right + 10) + 'px';

  anchorEl.classList.add('is-open');
  FC.busy = true;
};

// 낙관적 UI: 화면을 먼저 바꾸고 뒤에서 저장한다. 실패하면 되돌린다.
FC.dropOnSlot = function (slotId) {
  var drag = FC.drag;
  if (!drag) return;

  var slot = null;
  (FC.state.slots || []).forEach(function (s) { if (Number(s.id) === Number(slotId)) slot = s; });
  if (!slot) return;

  var snapshot = (FC.state.slots || []).map(function (s) {
    return { id: s.id, character_id: s.character_id };
  });
  var rollback = function () {
    var byId = {};
    snapshot.forEach(function (s) { byId[String(s.id)] = s.character_id; });
    (FC.state.slots || []).forEach(function (s) { s.character_id = byId[String(s.id)]; });
    FC.render();
  };

  var call;
  if (drag.type === 'slot') {
    if (Number(drag.fromSlotId) === Number(slotId)) return;
    var from = null;
    (FC.state.slots || []).forEach(function (s) { if (Number(s.id) === Number(drag.fromSlotId)) from = s; });
    if (!from) return;
    var tmp = slot.character_id;
    slot.character_id = from.character_id;
    from.character_id = tmp;
    call = FC.api('slot.swap', { slot_id_a: drag.fromSlotId, slot_id_b: slotId });
  } else {
    slot.character_id = drag.characterId;
    call = FC.api('slot.assign', { slot_id: slotId, character_id: drag.characterId });
  }

  FC.render();
  call.then(function () { return FC.refresh(); })
      .catch(function (err) { rollback(); FC.toast(FC.errorText(err), 'err'); });
};

FC.bindDragEvents = function () {
  document.addEventListener('dragstart', function (e) {
    var row = e.target.closest ? e.target.closest('.fc-pop-row') : null;
    var slot = e.target.closest ? e.target.closest('.fc-slot.is-filled') : null;

    if (row) {
      if (!FC.activeRaidId) { e.preventDefault(); FC.toast('레이드를 먼저 선택하세요', 'err'); return; }
      FC.drag = { type: 'character', characterId: Number(row.getAttribute('data-character-id')), fromSlotId: null };
    } else if (slot) {
      FC.drag = {
        type: 'slot',
        characterId: Number(slot.getAttribute('data-character-id')),
        fromSlotId: Number(slot.getAttribute('data-slot-id'))
      };
    } else {
      return;
    }
    FC.busy = true;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', String(FC.drag.characterId));
  });

  document.addEventListener('dragover', function (e) {
    var slot = e.target.closest ? e.target.closest('.fc-slot') : null;
    if (!slot || !FC.drag) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    slot.classList.add('is-drop-target');
  });

  document.addEventListener('dragleave', function (e) {
    var slot = e.target.closest ? e.target.closest('.fc-slot') : null;
    if (slot) slot.classList.remove('is-drop-target');
  });

  document.addEventListener('drop', function (e) {
    var slot = e.target.closest ? e.target.closest('.fc-slot') : null;
    if (!slot || !FC.drag) return;
    e.preventDefault();
    slot.classList.remove('is-drop-target');
    FC.dropOnSlot(Number(slot.getAttribute('data-slot-id')));
  });

  document.addEventListener('dragend', function () {
    FC.drag = null;
    FC.busy = !document.getElementById('fc-popover').hidden || !document.getElementById('fc-modal').hidden;
    Array.prototype.slice.call(document.querySelectorAll('.is-drop-target'))
      .forEach(function (n) { n.classList.remove('is-drop-target'); });
  });
};
```

`FC.bindGlobalEvents`의 click 핸들러 맨 앞(`var t = e.target;` 다음)에 추가:

```javascript
    var card = t.closest ? t.closest('.fc-roster-card') : null;
    if (card) {
      if (card.classList.contains('is-open')) { FC.closePopover(); }
      else { FC.closePopover(); FC.openPopover(Number(card.getAttribute('data-player-id')), card); }
      return;
    }

    if (t.classList.contains('fc-slot-x')) {
      var xSlotId = Number(t.getAttribute('data-slot-id'));
      FC.api('slot.assign', { slot_id: xSlotId, character_id: null })
        .then(function () { return FC.refresh(); })
        .catch(function (err) { FC.toast(FC.errorText(err), 'err'); });
      return;
    }

    if (!t.closest || !t.closest('#fc-popover')) FC.closePopover();
```

`document.addEventListener('keydown', ...)` 핸들러의 `FC.closeModal();` 옆에 `FC.closePopover();` 를 추가하고,
`boot()` 끝에 `FC.bindDragEvents();` 를 추가한다.

- [ ] **Step 2: CSS 추가**

`assets/app.css` 끝에 추가:

```css
.fc-popover{position:absolute;z-index:70;background:var(--panel);border:1px solid var(--gold-2);
  border-radius:12px;padding:10px;width:250px;box-shadow:0 16px 44px rgba(0,0,0,0.55);}
.fc-pop-title{font-size:11px;color:var(--dim);margin-bottom:8px;letter-spacing:.5px;}
.fc-pop-row{display:flex;align-items:center;gap:7px;padding:7px 8px;border-radius:8px;
  border:1px solid var(--line);background:var(--panel-2);margin-bottom:5px;cursor:grab;}
.fc-pop-row:hover{border-color:var(--slot-color);}
.fc-pop-row:active{cursor:grabbing;}
.fc-pop-row.is-placed{opacity:.45;}
.fc-pop-name{font-size:13px;font-weight:700;}
.fc-pop-meta{font-size:10px;color:var(--dim);margin-left:auto;}
.fc-pop-tag{font-size:10px;color:var(--gold);border:1px solid var(--gold-2);border-radius:6px;padding:1px 5px;}
```

- [ ] **Step 3: 배포하고 드래그앤드롭을 브라우저에서 확인**

HTML5 드래그 이벤트는 헤드리스에서 합성해야 하므로, `DataTransfer`를 만들어 직접 디스패치한다.

```bash
git add assets/app.js assets/app.css && \
git commit -m "$(printf '포스 편성: 팝오버와 드래그앤드롭 배치 구현\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main'
```

```bash
B=~/.claude/skills/gstack/browse/dist/browse
$B goto 'http://14.63.164.109/sanctuary/'
$B click '.fc-roster-card'
$B js "JSON.stringify({popOpen: !document.getElementById('fc-popover').hidden, rows: document.querySelectorAll('.fc-pop-row').length})"
```

Expected: `{"popOpen":true,"rows":2}` (Task 11에서 만든 zzUI_본캐 + zzUI_부캐1)

```bash
$B js "
const row = document.querySelector('.fc-pop-row');
const slot = document.querySelector('.fc-slot');
const dt = new DataTransfer();
row.dispatchEvent(new DragEvent('dragstart', {bubbles:true, dataTransfer:dt}));
slot.dispatchEvent(new DragEvent('dragover', {bubbles:true, cancelable:true, dataTransfer:dt}));
slot.dispatchEvent(new DragEvent('drop', {bubbles:true, cancelable:true, dataTransfer:dt}));
row.dispatchEvent(new DragEvent('dragend', {bubbles:true, dataTransfer:dt}));
'dropped';
"
$B js "await new Promise(r => setTimeout(r, 900)); JSON.stringify({filled: document.querySelectorAll('.fc-slot.is-filled').length, badge: document.querySelector('.fc-badge') && document.querySelector('.fc-badge').textContent})"
$B console --errors
```

Expected: `{"filled":1,"badge":"1"}` — 드롭된 캐릭터가 슬롯에 남고 대기창 뱃지가 1로 올라간다.

- [ ] **Step 4: 중복 경고와 슬롯 교체 확인**

```bash
$B js "
const rows = document.querySelectorAll('.fc-pop-row');
const slots = document.querySelectorAll('.fc-slot');
'popover rows=' + rows.length + ' slots=' + slots.length;
"
$B click '.fc-roster-card'
$B js "
const row = document.querySelector('.fc-pop-row');
const target = document.querySelectorAll('.fc-slot')[5];
const dt = new DataTransfer();
row.dispatchEvent(new DragEvent('dragstart', {bubbles:true, dataTransfer:dt}));
target.dispatchEvent(new DragEvent('dragover', {bubbles:true, cancelable:true, dataTransfer:dt}));
target.dispatchEvent(new DragEvent('drop', {bubbles:true, cancelable:true, dataTransfer:dt}));
row.dispatchEvent(new DragEvent('dragend', {bubbles:true, dataTransfer:dt}));
'dropped-dup';
"
$B js "await new Promise(r => setTimeout(r, 900)); JSON.stringify({warn: !!document.querySelector('.fc-warn'), dups: document.querySelectorAll('.fc-slot.is-dup').length})"
$B screenshot /private/tmp/claude-501/-Users-eztake-japanMES-sanctuary/d61ea218-6d2a-4eb5-a1dd-e1b74ecefa74/scratchpad/fc-dup.png
```

Expected: `{"warn":true,"dups":2}` — 같은 캐릭터를 같은 레이드에 두 번 넣으면 경고 배너와
빨간 테두리 두 개가 나타난다.

```bash
$B js "
const a = document.querySelector('.fc-slot.is-filled');
const empty = document.querySelector('.fc-slot.is-empty');
const dt = new DataTransfer();
a.dispatchEvent(new DragEvent('dragstart', {bubbles:true, dataTransfer:dt}));
empty.dispatchEvent(new DragEvent('dragover', {bubbles:true, cancelable:true, dataTransfer:dt}));
empty.dispatchEvent(new DragEvent('drop', {bubbles:true, cancelable:true, dataTransfer:dt}));
a.dispatchEvent(new DragEvent('dragend', {bubbles:true, dataTransfer:dt}));
'swapped';
"
$B js "await new Promise(r => setTimeout(r, 900)); document.querySelectorAll('.fc-slot.is-filled').length"
$B console --errors
```

Expected: `2` — 슬롯 간 이동 후에도 채워진 칸 수는 그대로다. 콘솔 에러 없음.

---

### Task 14: 테스트 데이터 정리 · 전체 시나리오 QA · 문서 갱신

**Files:**
- Modify: `CLAUDE.md`
- Test: 전체 시나리오

**Interfaces:**
- Consumes: Task 1~13 전부
- Produces: 배포 완료된 사이트

- [ ] **Step 1: 자동 테스트 전체 통과 확인**

```bash
ssh aion-sanctuary 'cd /var/www/html/sanctuary && php force/test_api.php'
```

Expected: `전체 통과` — 실패 0

- [ ] **Step 2: UI 테스트 데이터 삭제**

```bash
ssh aion-sanctuary 'cd /var/www/html/sanctuary && php -r "
require \"force/db.php\"; require \"force/store.php\";
\$p = fc_pdo();
foreach (\$p->query(\"SELECT id FROM fc_raids WHERE name LIKE \\\"zzUI%\\\" OR name LIKE \\\"zzSeed%\\\"\")->fetchAll(PDO::FETCH_COLUMN) as \$r) fc_delete_raid(\$p, (int)\$r);
foreach (\$p->query(\"SELECT DISTINCT player_id FROM fc_characters WHERE char_name LIKE \\\"zzUI%\\\" OR char_name LIKE \\\"zzSeed%\\\"\")->fetchAll(PDO::FETCH_COLUMN) as \$x) fc_delete_player(\$p, (int)\$x);
echo \"남은 레이드 \" . \$p->query(\"SELECT COUNT(*) FROM fc_raids\")->fetchColumn() . \", 남은 캐릭터 \" . \$p->query(\"SELECT COUNT(*) FROM fc_characters\")->fetchColumn() . \"\n\";
"'
```

Expected: `남은 레이드 0, 남은 캐릭터 0`

- [ ] **Step 3: 빈 상태에서 전체 시나리오를 브라우저로 통과**

```bash
B=~/.claude/skills/gstack/browse/dist/browse
$B goto 'http://14.63.164.109/sanctuary/'
$B js "JSON.stringify({emptyBoard: !!document.querySelector('.fc-empty-big'), addForceHidden: !document.getElementById('fc-add-force')})"
```

Expected: `{"emptyBoard":true,"addForceHidden":true}` — 레이드가 없으면 `+ 포스 추가`가 숨겨진다

```bash
$B click '#fc-open-roster'
$B click '.fc-modal-panel .fc-btn-primary'
$B fill '.fc-modal-panel .fc-input' '실제캐릭명을여기에'
$B click '.fc-modal-panel .fc-btn-primary'
$B js "await new Promise(r=>setTimeout(r,3000)); JSON.stringify(FC.state.characters.map(c => ({name:c.name, cls:c.class, atul:c.atul})))"
```

Expected: 실제 존재하는 아이온2 캐릭명을 넣었다면 `cls`와 `atul`이 채워진다 —
아툴 자동 조회가 실서비스에서 작동하는지 확인하는 유일한 지점이다.
채워지지 않으면 Task 6의 매핑을 다시 확인한다.

```bash
$B screenshot --viewport /private/tmp/claude-501/-Users-eztake-japanMES-sanctuary/d61ea218-6d2a-4eb5-a1dd-e1b74ecefa74/scratchpad/fc-final.png
$B console --errors
$B network | tail -20
```

Expected: 콘솔 에러 없음, 네트워크에 4xx/5xx 없음. 스크린샷을 Read 툴로 열어 디자인을 눈으로 확인한다.

- [ ] **Step 4: 최종 QA에서 만든 데이터 정리**

Step 3에서 실제 캐릭명으로 등록한 인원과 레이드를 화면의 「명단 관리 → 삭제」로 지우거나,
운영에 그대로 쓸 것이면 남긴다. **남길지 지울지는 사용자에게 물어본다.**

- [ ] **Step 5: CLAUDE.md 갱신**

`## Architecture` 이하와 `## Key Domain Concepts`의 파티 편성 알고리즘 설명, `## Database Schema`를
새 구조로 교체한다. 아래 내용으로 해당 섹션들을 대체한다:

```markdown
## Architecture

**단일 페이지 편성 도구**. `index.php`가 비밀번호 게이트와 셸 HTML을 내려주고, 초기 state
스냅샷을 `window.FC_STATE`로 심는다. 이후 모든 조작은 `force/api.php` JSON API로 오간다.

- `force/db.php` — PDO 커넥션
- `force/schema.php` — `fc_*` 테이블 멱등 생성
- `force/store.php` — DB 조작 전담 (HTTP/HTML 모름)
- `force/atul.php` — aion2.plaync.com 조회 (DB 모름)
- `force/api.php` — JSON 경계. `fc_api_dispatch()`가 순수 디스패처
- `force/test_api.php` — 서버에서 `php force/test_api.php`로 실행하는 스모크 테스트
- `assets/app.js` — 렌더 · 팝오버 · 드래그앤드롭 · 10초 폴링
- `assets/app.css` — 디자인 토큰과 스타일

## Key Domain Concepts

- **레이드**: 탭으로 구분되는 편성 단위 (루드라, 침식 등)
- **포스**: 고정 2파티 × 5슬롯 = 10명
- **본캐 / 부캐**: 한 사람(`fc_players`)이 여러 캐릭터(`fc_characters`)를 가진다.
  대기창에는 `is_main = 1`만 노출되고, 클릭하면 팝오버에 전부 나온다
- **중복**: 레이드가 다르면 같은 캐릭터를 여러 번 넣는 것이 정상이다.
  같은 레이드 안 중복만 경고한다 (차단하지 않음)
- **아툴 점수**: `https://aion2.plaync.com` 2단계 조회로 직업·전투력·아이템레벨을 자동 수집

## Database Schema

- `fc_players` — id, sort_order, created_at
- `fc_characters` — id, player_id, char_name(UNIQUE), char_class, atul_score, item_level,
  is_main, sort_order, atul_updated_at
- `fc_raids` — id, name, memo, sort_order, created_at
- `fc_forces` — id, raid_id, force_no, day_of_week, start_time, memo, sort_order
- `fc_slots` — id, force_id, party_no(1|2), slot_no(1~5), character_id(NULL 가능),
  UNIQUE(force_id, party_no, slot_no)
- `fc_meta` — k, v (revision 카운터)

구버전 `sanctuary_*` 테이블은 더 이상 쓰지 않는다. DROP하지 않고 그대로 방치한다.

## Admin Access

관리자 구분이 없다. 사이트 비밀번호(`sanctuary_config.json`의 `site_password`)를 아는 사람은
누구나 편집할 수 있다.
```

- [ ] **Step 6: 커밋과 배포**

```bash
git add CLAUDE.md && \
git commit -m "$(printf '문서: CLAUDE.md를 새 포스 편성 구조로 갱신\n\nCo-Authored-By: Claude Opus 5 (1M context) <noreply@anthropic.com>')" && \
git push origin main && \
ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull -q origin main && git log --oneline -1'
```

Expected: 서버 HEAD가 로컬과 같은 커밋

- [ ] **Step 7: 사용자에게 안내**

`http://14.63.164.109/sanctuary/` 를 안내하고, 아래 순서로 써보라고 알린다:

1. 「명단 관리」에서 인원을 등록한다 (본캐명 + 부캐명들, 캐릭명만 넣으면 나머지는 자동)
2. `+` 탭으로 레이드를 만든다 (루드라, 침식 …)
3. 「+ 포스 추가」로 요일·시각을 넣어 포스를 만든다
4. 왼쪽 대기창에서 사람을 클릭 → 팝오버에서 캐릭터를 슬롯으로 끌어다 놓는다

---

## Self-Review

**스펙 커버리지**

| 스펙 요구 | 담당 태스크 |
|---|---|
| 기존 포스 기능·명단 폐기, craft 유지 | Task 8 (구 파일 삭제), Global Constraints |
| 본캐 우선 입력 후 부캐 전부 입력 | Task 11 (`FC.openAddPlayer`) |
| 대기창에 본캐만 노출 | Task 9 (`FC.renderSidebar`) |
| 클릭 시 본캐+부캐 팝오버 | Task 13 (`FC.openPopover`) |
| 팝오버에서 드래그앤드롭 배치 | Task 13 (`FC.bindDragEvents`, `FC.dropOnSlot`) |
| 대기창 카드가 사라지지 않음 (레이드 간 중복 허용) | Task 9 — 대기창은 배치와 무관하게 항상 전원 렌더 |
| 레이드 탭 + `+` 탭으로 추가 | Task 12 (`FC.openRaidForm`) |
| 포스 추가 시 요일·시각 입력 | Task 12 (`FC.openForceForm`) |
| 고정 2파티 × 5슬롯 | Task 3 (`fc_create_force` 10행) |
| 같은 레이드 내 중복 경고 | Task 5 (`fc_duplicates`), Task 9 (`.fc-warn`, `.is-dup`) |
| 캐릭명+직업+아툴+아이템레벨 | Task 2·6 |
| 누구나 편집 (비번 게이트 하나) | Task 8 (게이트), Task 7 (`unauthorized` 검사) |
| 낙관적 UI + 실패 롤백 | Task 13 (`FC.dropOnSlot`) |
| 연결 끊김 배너 / 토스트 | Task 10 |
| 10초 폴링, 드래그 중 보류 | Task 10 (`FC.startPolling`, `FC.busy`) |
| 빈 상태 3종 | Task 9 (`renderBoard`, `renderSidebar`) |
| 포스 번호 재부여 안 함 | Task 3 (테스트로 고정) |
| 포스·레이드 메모 | Task 9 렌더, Task 12 입력 |
| 서버 스모크 테스트 11항목 | Task 1~7 |
| PC 전용 | 전체 — 모바일 대응 코드 없음 |

**타입 일관성 확인**

- `fc_state()`가 내보내는 캐릭터 키는 `name`/`class`/`atul` (컬럼명 `char_name`/`char_class`/`atul_score`가
  아님). Task 5 테스트가 이를 못박고, Task 9·11·13의 JS가 같은 키를 쓴다.
- `fc_duplicates()` 반환 키는 **문자열** raid_id. Task 5 테스트(`$dups['1']`)와
  Task 9(`FC.dupCharIds`의 `String(raidId)`)가 일치한다.
- `fc_api_dispatch($pdo, $req, $lookup)` 3인자 시그니처를 Task 7 테스트와 `api.php` HTTP 블록이 동일하게 쓴다.
- 슬롯 DOM 계약 `.fc-slot[data-slot-id]`을 Task 9가 만들고 Task 13이 소비한다.
- `DAYS` 상수는 Task 9에서 정의되어 Task 12에서 쓰인다.
