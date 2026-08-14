# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

A PHP web application for managing Aion 2 (아이온2) MMORPG legion sanctuary force (포스) registration and party composition. Korean-language UI. No build tools — plain PHP + MySQL.

## Running the App

Requires PHP with PDO/MySQL and a MySQL server. The app connects to a `budget_manager` database on `localhost`. To serve locally:

```bash
php -S localhost:8000
```

There are no automated tests or linters.

## Deployment (ALWAYS deploy after any change)

**Every code change MUST be deployed to the production server immediately, then tested server-side.** Do not stop at local edits — the local machine has no PHP, so verification happens on the server.

Workflow for every change:

1. Edit files under `/Users/eztake/japanMES/sanctuary/` (local clone).
2. `git add` → `git commit` (Korean commit message OK) → `git push origin main`.
3. On the production server, pull and syntax-check:
   ```bash
   ssh aion-sanctuary 'cd /var/www/html/sanctuary && git pull origin main && php -l <changed_file.php>'
   ```
   (Remote `main` has no upstream tracking — always specify `origin main`.)
4. Give the user the server URL to test (docroot is `/var/www/html`, so the app lives under `http://<server>/sanctuary/...`).

**NEVER destroy user data.** `craft_materials.unit_price` (and updated_at) are community-entered market prices — treat them as production user data. Do NOT `DROP`/`TRUNCATE`/`DELETE` `craft_materials`, and never blanket-`UPDATE` its prices on the live server. Recipe changes re-apply automatically: bump `CRAFT_SEED_VERSION` in `craft/seed_data.php` and the additive `craft_init_schema` rebuilds only `craft_recipes`/`craft_recipe_inputs`, leaving prices untouched. `craft/test_calc.php` snapshots+restores prices; still, prefer not to run destructive SQL against the live DB.

**Constraints:** Only ever touch paths inside `/var/www/html/sanctuary/`. Never modify other hosted projects, system dirs, or global config (php.ini, nginx, apache2, systemd, MySQL/PHP-FPM). Keep local and remote pointing at the same git commit. Secrets (`sanctuary_config.json`, `.claude/`) are gitignored on the server — do not overwrite them.

Server: `14.63.164.109:54122` (root), SSH alias `aion-sanctuary`, repo `https://github.com/Komo1284/AION2-sanctuary.git`.

## Architecture

**단일 페이지 편성 도구**. `index.php`가 비밀번호 게이트와 셸 HTML을 내려주고, 초기 state
스냅샷을 `window.FC_STATE`로 심는다. 이후 모든 조작은 `force/api.php` JSON API로 오간다.
프론트엔드에는 빌드 단계가 없다 — 브라우저가 `assets/app.js`를 그대로 읽는다. **PC 전용**이며
모바일 편성은 지원하지 않는다.

- `force/db.php` — PDO 커넥션
- `force/schema.php` — `fc_*` 테이블 멱등 생성
- `force/store.php` — DB 조작 전담 (HTTP/HTML 모름)
- `force/atul.php` — aion2.plaync.com 조회 (DB 모름)
- `force/api.php` — JSON 경계. `fc_api_dispatch()`가 순수 디스패처. 액션: `state`,
  `player.create/delete`, `character.add/update/promote/delete`, `raid.create/update/delete`,
  `force.create/update/delete`, `slot.assign/swap`, `atul.refresh`
- `force/test_api.php` — 서버에서 `php force/test_api.php`로 실행하는 스모크 테스트 (110개+)
- `assets/app.js` — 렌더 · 팝오버 · 드래그앤드롭 · 10초 폴링
- `assets/app.css` — 디자인 토큰과 스타일

구 `sections/`, `actions/`, `cron/`, `migrate_add_19.php`는 삭제됐다.

### 서버 PHP 7.4.3 제약

`match`, `str_contains`, `str_starts_with`, nullsafe(`?->`), 생성자 프로퍼티 승격을 쓸 수 없다.
이 문법을 쓰면 로컬에서는 안 걸리고(로컬에 PHP 자체가 없다) 서버 `php -l`에서만 걸린다.

### 함정: `hidden` 속성과 CSS 특정성

`.fc-modal{display:flex}` 같은 규칙은 브라우저 기본 `[hidden]{display:none}`을 특정성에서
이겨버린다. 그러면 닫힌 모달이 `hidden` 속성은 붙어 있는데도 화면에 남아 전체 화면 클릭
차단 레이어가 되는 버그가 실제로 발생했다. `index.php`에 `hidden`으로 여닫는 요소를 추가할
때는 반드시 `.클래스[hidden]{display:none}`을 CSS에 함께 넣는다.

### 함정: 전체 테이블을 도는 함수는 `zzTest_`로 격리되지 않는다

`force/test_api.php`의 대부분은 `zzTest_` 접두사 데이터만 만들고 지우므로 안전하다. 그러나
`fc_refresh_all_atul()`처럼 **`fc_characters` 전체를 대상으로 도는 함수**는 접두사와 무관하게
운영 명단까지 덮어쓴다. 실제로 이 함수를 테스트에서 offset/limit 없이 호출해 49명 전원의
직업·전투력을 가짜 값으로 날린 적이 있다(외부 API 재조회로 복구).

이런 함수를 테스트할 때는 `craft/test_calc.php`와 같은 방식으로 **실행 전 스냅샷을 뜨고 끝나면
되돌린다.** 복구가 실제로 됐는지 단언까지 넣어야 한다.

### `FC.busy` 규약

드래그 중이거나 팝오버/모달이 열려 있으면 10초 폴링이 화면을 다시 그리지 않는다. 이 판단은
`FC.recomputeBusy()` **한 곳**에서만 한다 — 조건을 여러 곳에 흩어놓으면 폴링이 화면을
갈아엎어 드래그 중 카드가 사라지는 버그가 재발한다(실제로 두 번 재발했다).

## Key Domain Concepts

- **레이드**: 탭으로 구분되는 편성 단위 (루드라, 침식 등)
- **포스**: 고정 2파티 × 5슬롯 = 10명
- **본캐 / 부캐**: 한 사람(`fc_players`)이 여러 캐릭터(`fc_characters`)를 가진다.
  대기창에는 `is_main = 1`만 노출되고, 클릭하면 팝오버에 전부 나온다
- **임시 캐릭터 (`is_placeholder`)**: 어떤 부캐를 보낼지 아직 안 정했을 때 쓰는 자리표시 카드
  (예: "코모부캐1"). 아툴 조회를 건너뛰고 직업·점수가 빈 채로 저장되며, 화면에서 회색 점선
  슬롯으로 그려진다. 명단 관리의 「확정」 버튼(`character.promote`)으로 실제 캐릭명을 넣으면
  이름 변경 + 아툴 조회가 한 번에 일어나고, 슬롯은 `character_id`를 그대로 가리키므로
  **배치된 자리가 유지된다**.
- **캐릭명 유일성**: 실제 캐릭명은 전역에서 유일해야 하지만, 임시명은 같은 플레이어 안에서만
  유일하면 된다 — 조건부 규칙이라 MySQL 부분 유니크 인덱스로 표현할 수 없어 `char_name`에는
  UNIQUE 인덱스가 없다. 검사는 `fc_insert_character()`/`fc_name_exists()` 한 곳에서만 한다.
- **중복**: 레이드가 다르면 같은 캐릭터를 여러 번 넣는 것이 정상이다.
  같은 레이드 안 중복만 경고한다 (차단하지 않음)
- **아툴 점수**: `https://aion2.plaync.com` 2단계 조회로 직업·전투력·아이템레벨을 자동 수집

## Database Schema

- `fc_players` — id, sort_order, created_at
- `fc_characters` — id, player_id, char_name (UNIQUE 인덱스 없음 — 위 참고), char_class,
  atul_score, item_level, is_main, is_placeholder, sort_order, atul_updated_at
- `fc_raids` — id, name, memo, sort_order, created_at
- `fc_forces` — id, raid_id, force_no, day_of_week, start_time, memo, sort_order
- `fc_slots` — id, force_id, party_no(1|2), slot_no(1~5), character_id(NULL 가능),
  UNIQUE(force_id, party_no, slot_no)
- `fc_meta` — k, v (revision 카운터)

구버전 `sanctuary_*` 테이블은 더 이상 쓰지 않는다. DROP하지 않고 그대로 방치한다.

## Admin Access

관리자 구분이 없다. 사이트 비밀번호(`sanctuary_config.json`의 `site_password`)를 아는 사람은
누구나 편집할 수 있다.
