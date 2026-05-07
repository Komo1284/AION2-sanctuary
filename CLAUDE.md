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

## Architecture

**Single-file routing via `index.php`**: All HTTP requests go through `index.php`, which handles session management, DB connection, POST action dispatch, and view rendering.

**Pattern — POST action handlers** (`actions/`): Each major POST action is dispatched via `require_once` in `index.php`. These files run in the same scope and can read/write `$pdo`, `$message`, `$message_type`, and `$current_season`.

- `actions/apply_force.php` — validates and inserts a new force application (신청)
- `actions/compose_parties.php` — runs the party composition algorithm

**Pattern — view partials** (`sections/`): Included at the bottom of `index.php` based on `$tab` and `$nav` GET params. They share the same scope (access `$pdo`, `$current_season_id`, `$is_admin`, etc.).

- `sections/apply.php` — force registration form
- `sections/admin.php` — admin panel (applicant list + force results)
- Additional sections for notices (referenced but not present in repo): `sections/forces.php`, `sections/notices_list.php`, `sections/notices_detail.php`, `sections/notices_write.php`

## Key Domain Concepts

- **포스 (Force)**: A unit of 8 players split into two parties of 4
- **본캐 / 부캐**: Main character / alt character; only main chars are guaranteed a slot
- **깐부**: Buddy system — players who request to be in the same force
- **아툴 점수**: Combat score fetched from external API `https://aion2tool.com/api/character/search`
- **직업 (Classes)**: 수호성, 검성, 살성, 궁성, 호법성, 정령성, 마도성, 치유성

## Party Composition Algorithm (`actions/compose_parties.php`)

Runs when admin clicks "파티 구성 시작". Logic:

1. Delete existing forces/parties for the season
2. Fetch all characters ordered by atul score DESC
3. If total characters % 8 ≠ 0, exclude alt chars with lowest scores
4. Assign 깐부 groups to forces first (greedy, finds force with enough space)
5. Assign remaining chars via snake draft (always pick force with lowest average atul)
6. Within each force, assign to the party with lower average to balance the two parties
7. Apply 치유성 penalty: if a party has ≥2 healers, the highest-score healer's score × 2/3 for display only (`atul_adjusted`)
8. Sort forces by avg atul DESC for force numbering

## Database Schema (inferred)

- `sanctuary_seasons` — id, name, status (구성중|모집종료), start_date. 신청은 상태와 무관하게 상시 가능. 레거시 '모집중' 값은 앱 시작 시 '구성중'으로 자동 마이그레이션됨 (`index.php`).
- `sanctuary_applications` — id, season_id, applicant_ip, status, applied_at
- `sanctuary_characters` — id, application_id, char_name, char_class, atul_score, is_main, atul_adjusted
- `sanctuary_buddies` — id, application_id, buddy_name, buddy_score, buddy_class, is_main, buddy_group
- `sanctuary_forces` — id, season_id, force_number, avg_atul
- `sanctuary_parties` — id, force_id, party_number, avg_atul
- `sanctuary_party_members` — id, party_id, character_id

## Admin Access

Session-based via `$_SESSION['sanctuary_admin']`. Login via password modal in the header. Logout via `?admin_logout=1` GET param.
