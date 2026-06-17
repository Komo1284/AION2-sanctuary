# 검색 기반 포스 구성 + 전체 와이드 리디자인

작성일: 2026-06-17

## 배경 / 목표

기존에는 일반 유저가 "포스 신청(apply)"으로 캐릭터를 등록하면, 관리자가 그 신청 데이터로
채워진 "미배정 캐릭터" 풀을 드래그앤드롭으로 포스에 배치했다.

변경 목표:
- 신청 과정을 거치지 않고, **관리자가 캐릭터명을 검색 → 실시간 정보 조회 → 카드 생성 → 드래그앤드롭**으로
  아무 캐릭터나 바로 포스에 넣을 수 있게 한다.
- "미배정 캐릭터(신청자 기반)" 개념을 제거한다.
- 전체 앱 UI를 가로로 넓고 깔끔하게 리디자인한다.

## 결정 사항 (사용자 승인)

1. **신청(apply) 기능 완전 제거** — 유저용 신청 탭/폼 삭제, 관리자 검색-추가만 사용
2. **전체 앱 와이드 리디자인** — 색 토큰은 유지, 컨테이너 폭/여백 위주로 정리
3. **검색 캐릭터 즉시 등록 (Approach A)** — 검색 시 `sanctuary_characters`에 행을 바로 생성하여
   기존 저장/조회 로직을 그대로 재사용

## 핵심 설계

### 1. 신청 기능 제거
- 삭제: `sections/apply.php`, `actions/apply_force.php`
- `index.php`:
  - `apply_force` POST 디스패치 제거
  - 사이드 네비 "포스 신청" 항목 제거
  - 기본 nav 로직을 `apply` → **`forces`** 로 변경
  - 라우팅에서 apply include 제거, forces를 기본 분기로
- `cron/update_atul.php` 는 유지 (추가된 캐릭터의 아툴 점수 자동 갱신 — 이득)

### 2. 관리자 검색-추가 흐름 (admin.php, 구성중 화면)
좌측 "미배정 캐릭터" 풀을 **"캐릭터 검색 / 대기열"** 로 교체:
- 검색 입력 + 🔍 → `actions/fetch_atul.php?name=` 로 점수/직업/아이템레벨 실시간 조회
- 성공 시 `actions/add_character.php`(POST, 관리자 세션 검증)로 전송 → 즉시 DB 등록 →
  반환된 실제 `id` + `app_id` 로 JS 풀에 카드 추가 (페이지 리로드 없음)
- 풀 카드에 ✕(삭제) → `actions/delete_character.php` 로 행 삭제 후 JS 풀에서 제거
- 중복 방지: 같은 시즌에 동일 캐릭명 존재 시 재삽입 없이 안내

### 3. 데이터 모델 (Approach A)
- **추가 캐릭터마다 개별 application 행 생성**: `applicant_ip='admin_added'`, `status='대기'`
  - 이유: `app_id` 가 "같은 플레이어 같은 포스 금지" 규칙에 쓰임. 공유 application을 쓰면
    모든 캐릭터가 한 명으로 묶여 포스당 1명만 허용되는 버그 발생. 캐릭터마다 고유 app_id가
    필요하므로 1캐릭터=1application 으로 둔다. (신청/깐부 그룹 개념은 폐기)
- 캐릭터 행: `char_name, char_class, atul_score, item_level, is_main=1`
  - 본캐/부캐 구분이 사라지므로 `is_main=1` 고정. 카드에서 부캐 태그 사라짐.
- 풀 로딩 쿼리(`!= 'buddy_synthesized'`)는 그대로 — 이제 `admin_added` 캐릭터만 잡힘.
  새로고침해도 미배치 캐릭터 유지.
- 기존 DnD/임시저장/구성완료 로직은 **수정 없이 재사용** (카드가 실제 char id 보유).

### 4. 상단 통계 / 신청자 목록 정리 (admin.php)
- "총 신청자/본캐/부캐" 통계 → **총 캐릭터 / 치유성 / 비치유 / 포스 수** 로 재정의
  (캐릭터 직접 카운트)
- 구식 "신청자 목록 관리" 접이식 테이블 제거 (검색/대기열 + DnD가 대체)
- `cancel_application` 핸들러는 미사용이 되나 무해하므로 유지

### 5. 전체 와이드 UI 리디자인
- 컨테이너 폭 `max-width: 1400px` → **`1760px`** (`.header-inner`, `.main-layout`, home.php 동일)
- `.force-grid` 는 기존 `minmax(340px,1fr)` 로 넓은 화면에서 자동 다열(3~4열)
- 색/라운드/보더 토큰 유지, 폭·여백만 정리하는 보수적 리디자인

## 신규/변경 파일

| 파일 | 변경 |
|---|---|
| `sections/apply.php` | 삭제 |
| `actions/apply_force.php` | 삭제 |
| `actions/_bootstrap.php` | 신규 (세션+PDO 부트스트랩 공용) |
| `actions/add_character.php` | 신규 (캐릭터 즉시 등록, 중복 방지) |
| `actions/delete_character.php` | 신규 (풀 카드 삭제) |
| `sections/admin.php` | 풀 → 검색/대기열 UI + JS, 통계/신청자목록 정리 |
| `index.php` | 라우팅/네비/기본nav/와이드 CSS |
| `sections/home.php` | 와이드 폭 적용 |

## 검증

- PHP가 로컬에 없으므로 구문 검증은 원격 서버에서 `php -l` 로 수행
- 워크플로우: 로컬 수정 → commit → push → 원격 `git pull` → 원격 `php -l`

## 리스크

- 기존 시즌에 남아있는 진짜 신청 데이터는 풀에 함께 표시됨 (필요 시 별도 정리)
- 동명이인 캐릭터는 중복 방지 로직상 한 시즌에 1명만 추가 가능 (현실적으로 충분)
