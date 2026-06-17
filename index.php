<?php
session_start();
date_default_timezone_set('Asia/Seoul');

// ── 설정 파일 로드 (비밀번호 등 변경 가능한 값) ──────────────────────
$_config_file = __DIR__ . '/sanctuary_config.json';
$_config      = file_exists($_config_file) ? (json_decode(file_get_contents($_config_file), true) ?? []) : [];
$site_pw      = $_config['site_password'] ?? 'forest0305';

// ── 사이트 접근 비밀번호 ─────────────────────────────────────────────
if (!isset($_SESSION['sanctuary_site_auth'])) {
    if (isset($_POST['site_password'])) {
        if (strtolower($_POST['site_password']) === strtolower($site_pw)) {
            $_SESSION['sanctuary_site_auth'] = true;
            header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . (isset($_GET) && $_GET ? '?' . http_build_query($_GET) : ''));
            exit;
        } else {
            $site_auth_error = true;
        }
    }
    ?><!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>숲 — 포스 관리</title>
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
.gate-btn{width:100%;margin-top:16px;padding:11px;background:linear-gradient(135deg,#8a6830,#c9a84c);border:none;border-radius:8px;color:#0a0c14;font-size:14px;font-weight:700;font-family:inherit;cursor:pointer;transition:filter .2s;}
.gate-btn:hover{filter:brightness(1.1);}
.gate-error{margin-top:12px;padding:10px 14px;background:rgba(192,57,43,0.15);border:1px solid rgba(192,57,43,0.3);border-radius:6px;font-size:12px;color:#e74c3c;text-align:center;}
</style>
</head>
<body>
<div class="gate">
  <div class="gate-legion">숲</div>
  <div class="gate-title">AION 2 LEGION · 포스 관리</div>
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

$db_host = 'localhost';
$db_name = 'budget_manager';
$db_user = 'budget_user';
$db_pass = 'budget2026!';

try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die('<div style="color:red;padding:20px;">DB 연결 실패: ' . htmlspecialchars($e->getMessage()) . '</div>');
}

// 레기온 멤버 테이블 (없으면 자동 생성)
$pdo->exec("CREATE TABLE IF NOT EXISTS sanctuary_legion_members (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    main_name  VARCHAR(100) NOT NULL,
    main_class VARCHAR(50)  DEFAULT '',
    main_atul  INT          DEFAULT 0,
    created_at DATETIME     DEFAULT CURRENT_TIMESTAMP
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// 부캐 전용 테이블 (없으면 자동 생성)
$pdo->exec("CREATE TABLE IF NOT EXISTS sanctuary_legion_member_subs (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT          NOT NULL,
    sub_name  VARCHAR(100) NOT NULL DEFAULT '',
    sub_class VARCHAR(50)  DEFAULT '',
    sub_atul  INT          DEFAULT 0
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

// 구버전 단일 부캐 컬럼 → 새 테이블로 마이그레이션 (컬럼 존재 시 1회 실행)
if ($pdo->query("SHOW COLUMNS FROM sanctuary_legion_members LIKE 'sub_name'")->fetch()) {
    $pdo->exec("INSERT INTO sanctuary_legion_member_subs (member_id, sub_name, sub_class, sub_atul)
        SELECT id, sub_name, sub_class, sub_atul FROM sanctuary_legion_members WHERE sub_name != ''");
    $pdo->exec("ALTER TABLE sanctuary_legion_members DROP COLUMN sub_name, DROP COLUMN sub_class, DROP COLUMN sub_atul");
}
// atul_updated_at 컬럼 추가 (없는 경우 1회 실행)
if (!$pdo->query("SHOW COLUMNS FROM sanctuary_legion_members LIKE 'atul_updated_at'")->fetch()) {
    $pdo->exec("ALTER TABLE sanctuary_legion_members ADD COLUMN atul_updated_at DATETIME NULL DEFAULT NULL");
}
if (!$pdo->query("SHOW COLUMNS FROM sanctuary_legion_member_subs LIKE 'atul_updated_at'")->fetch()) {
    $pdo->exec("ALTER TABLE sanctuary_legion_member_subs ADD COLUMN atul_updated_at DATETIME NULL DEFAULT NULL");
}
// item_level 컬럼 추가 (없는 경우 1회 실행)
if (!$pdo->query("SHOW COLUMNS FROM sanctuary_characters LIKE 'item_level'")->fetch()) {
    $pdo->exec("ALTER TABLE sanctuary_characters ADD COLUMN item_level INT DEFAULT NULL");
}
if (!$pdo->query("SHOW COLUMNS FROM sanctuary_buddies LIKE 'buddy_item_level'")->fetch()) {
    $pdo->exec("ALTER TABLE sanctuary_buddies ADD COLUMN buddy_item_level INT DEFAULT NULL");
}
if (!$pdo->query("SHOW COLUMNS FROM sanctuary_forces LIKE 'raid_day'")->fetch()) {
    $pdo->exec("ALTER TABLE sanctuary_forces ADD COLUMN raid_day VARCHAR(10) DEFAULT NULL");
}
if (!$pdo->query("SHOW COLUMNS FROM sanctuary_forces LIKE 'raid_time'")->fetch()) {
    $pdo->exec("ALTER TABLE sanctuary_forces ADD COLUMN raid_time VARCHAR(10) DEFAULT NULL");
}
if (!$pdo->query("SHOW COLUMNS FROM sanctuary_legion_members LIKE 'main_item_level'")->fetch()) {
    $pdo->exec("ALTER TABLE sanctuary_legion_members ADD COLUMN main_item_level INT DEFAULT NULL");
}
if (!$pdo->query("SHOW COLUMNS FROM sanctuary_legion_member_subs LIKE 'sub_item_level'")->fetch()) {
    $pdo->exec("ALTER TABLE sanctuary_legion_member_subs ADD COLUMN sub_item_level INT DEFAULT NULL");
}
if (!$pdo->query("SHOW COLUMNS FROM sanctuary_applications LIKE 'play_times'")->fetch()) {
    $pdo->exec("ALTER TABLE sanctuary_applications ADD COLUMN play_times VARCHAR(50) DEFAULT '20,21,22,23'");
}

$is_admin = isset($_SESSION['sanctuary_admin']) && $_SESSION['sanctuary_admin'] === true;
$seasons = $pdo->query("SELECT * FROM sanctuary_seasons ORDER BY id ASC")->fetchAll();
// 레거시 '모집중' 상태는 '구성중'으로 일괄 정규화 (모집중 상태는 더 이상 사용하지 않음)
$pdo->exec("UPDATE sanctuary_seasons SET status = '구성중' WHERE status = '모집중'");
foreach ($seasons as &$s) { if (($s['status'] ?? '') === '모집중') $s['status'] = '구성중'; }
unset($s);

$current_season_id = isset($_GET['season']) ? (int)$_GET['season'] : ($seasons[0]['id'] ?? 1);
$current_season = null;
foreach ($seasons as $s) {
    if ((int)$s['id'] === $current_season_id) { $current_season = $s; break; }
}
if (!$current_season && !empty($seasons)) $current_season = $seasons[0];

$tab = isset($_GET['tab']) ? $_GET['tab'] : 'home';

// 기본 nav: 모집종료(공개)면 포스리스트, 구성중이면 신청 화면
if (isset($_GET['nav'])) {
    $nav = $_GET['nav'];
} elseif ($tab === 'season' && $current_season) {
    $nav = 'forces';
} else {
    $nav = 'forces';
}

$message = '';
$message_type = '';

if (isset($_POST['admin_login'])) {
    if (strtolower($_POST['admin_password']) === 'naniamori0304') {
        $_SESSION['sanctuary_admin'] = true;
        $is_admin = true;
        $message = '관리자로 로그인했습니다.';
        $message_type = 'success';
    } else {
        $message = '비밀번호가 틀렸습니다.';
        $message_type = 'error';
    }
}

if (isset($_GET['admin_logout'])) {
    unset($_SESSION['sanctuary_admin']);
    header('Location: index.php');
    exit;
}

if (isset($_POST['write_notice']) && $is_admin) {
    require_once 'actions/write_notice.php';
}

if (isset($_POST['delete_notice']) && $is_admin) {
    $notice_id = (int)$_POST['notice_id'];
    $pdo->prepare("DELETE FROM sanctuary_notices WHERE id = ?")->execute([$notice_id]);
    $message = '공지사항이 삭제되었습니다.';
    $message_type = 'success';
}

// 포스 구성 시작 → 구성중으로 전환
if (isset($_POST['start_party_composition']) && $is_admin) {
    $sid = (int)($_POST['season_id'] ?? $current_season_id);
    // 이전 buddy_synthesized 잔여 데이터 정리
    $synth = $pdo->prepare("SELECT id FROM sanctuary_applications WHERE season_id = ? AND applicant_ip = 'buddy_synthesized'");
    $synth->execute([$sid]);
    foreach ($synth->fetchAll(PDO::FETCH_COLUMN) as $aid) {
        $pdo->prepare("DELETE FROM sanctuary_characters WHERE application_id = ?")->execute([$aid]);
    }
    $pdo->prepare("DELETE FROM sanctuary_applications WHERE season_id = ? AND applicant_ip = 'buddy_synthesized'")->execute([$sid]);
    // 상태를 구성중으로 전환
    $pdo->prepare("UPDATE sanctuary_seasons SET status = '구성중' WHERE id = ?")->execute([$sid]);
    header("Location: index.php?tab=season&season={$sid}&nav=admin");
    exit;
}

// 포스 구성 임시 저장 (구성중 상태 유지, 공개 안 함)
if (isset($_POST['save_draft_forces']) && $is_admin) {
    $sid = (int)($_POST['season_id'] ?? $current_season_id);
    $forces_arr = json_decode($_POST['forces_data'] ?? '[]', true);
    if (is_array($forces_arr)) {
        try {
            $pdo->beginTransaction();
            // 기존 포스 삭제
            $ef = $pdo->prepare("SELECT id FROM sanctuary_forces WHERE season_id = ?");
            $ef->execute([$sid]);
            foreach ($ef->fetchAll(PDO::FETCH_COLUMN) as $fid) {
                $ep = $pdo->prepare("SELECT id FROM sanctuary_parties WHERE force_id = ?");
                $ep->execute([$fid]);
                foreach ($ep->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                    $pdo->prepare("DELETE FROM sanctuary_party_members WHERE party_id = ?")->execute([$pid]);
                }
                $pdo->prepare("DELETE FROM sanctuary_parties WHERE force_id = ?")->execute([$fid]);
            }
            $pdo->prepare("DELETE FROM sanctuary_forces WHERE season_id = ?")->execute([$sid]);
            // 캐릭터 점수 조회
            $sq = $pdo->prepare("SELECT sc.id, sc.atul_score FROM sanctuary_characters sc JOIN sanctuary_applications sa ON sa.id = sc.application_id WHERE sa.season_id = ?");
            $sq->execute([$sid]);
            $char_scores = [];
            foreach ($sq->fetchAll() as $r) $char_scores[$r['id']] = (int)$r['atul_score'];
            // 포스 저장
            $force_number = 1;
            foreach ($forces_arr as $force) {
                $parties = $force['parties'] ?? [[], []];
                $all_ids = array_filter(array_merge(...array_map(fn($p) => array_filter($p ?? []), $parties)));
                $all_scores = array_map(fn($cid) => $char_scores[$cid] ?? 0, $all_ids);
                $force_avg = !empty($all_scores) ? array_sum($all_scores) / count($all_scores) : 0;
                $raid_day  = trim($force['raid_day']  ?? '');
                $raid_time = trim($force['raid_time'] ?? '');
                $pdo->prepare("INSERT INTO sanctuary_forces (season_id, force_number, avg_atul, raid_day, raid_time) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$sid, $force_number, round($force_avg), $raid_day ?: null, $raid_time ?: null]);
                $force_id = (int)$pdo->lastInsertId();
                foreach ($parties as $pi => $char_ids) {
                    $pscores = array_map(fn($cid) => $char_scores[$cid] ?? 0, array_filter($char_ids ?? []));
                    $party_avg = !empty($pscores) ? array_sum($pscores) / count($pscores) : 0;
                    $pdo->prepare("INSERT INTO sanctuary_parties (force_id, party_number, avg_atul) VALUES (?, ?, ?)")
                        ->execute([$force_id, $pi + 1, round($party_avg)]);
                    $party_id = (int)$pdo->lastInsertId();
                    foreach (($char_ids ?? []) as $char_id) {
                        if (!$char_id) continue;
                        $pdo->prepare("INSERT INTO sanctuary_party_members (party_id, character_id) VALUES (?, ?)")
                            ->execute([$party_id, (int)$char_id]);
                    }
                }
                $force_number++;
            }
            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
        }
    }
    header("Location: index.php?tab=season&season={$sid}&nav=admin&msg=draft_saved");
    exit;
}

// 포스 구성 완료 확정 → 모집종료로 전환하여 공개
if (isset($_POST['finalize_forces']) && $is_admin) {
    $sid = (int)($_POST['season_id'] ?? $current_season_id);
    $forces_arr = json_decode($_POST['forces_data'] ?? '[]', true);
    if (!is_array($forces_arr)) {
        $message = '포스 데이터가 올바르지 않습니다.'; $message_type = 'error';
    } else {
        try {
            $pdo->beginTransaction();
            // 기존 포스 삭제
            $ef = $pdo->prepare("SELECT id FROM sanctuary_forces WHERE season_id = ?");
            $ef->execute([$sid]);
            foreach ($ef->fetchAll(PDO::FETCH_COLUMN) as $fid) {
                $ep = $pdo->prepare("SELECT id FROM sanctuary_parties WHERE force_id = ?");
                $ep->execute([$fid]);
                foreach ($ep->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                    $pdo->prepare("DELETE FROM sanctuary_party_members WHERE party_id = ?")->execute([$pid]);
                }
                $pdo->prepare("DELETE FROM sanctuary_parties WHERE force_id = ?")->execute([$fid]);
            }
            $pdo->prepare("DELETE FROM sanctuary_forces WHERE season_id = ?")->execute([$sid]);
            // 캐릭터 점수 조회
            $sq = $pdo->prepare("SELECT sc.id, sc.atul_score FROM sanctuary_characters sc JOIN sanctuary_applications sa ON sa.id = sc.application_id WHERE sa.season_id = ?");
            $sq->execute([$sid]);
            $char_scores = [];
            foreach ($sq->fetchAll() as $r) $char_scores[$r['id']] = (int)$r['atul_score'];
            // 포스 저장
            $force_number = 1;
            foreach ($forces_arr as $force) {
                $parties = $force['parties'] ?? [[], []];
                $all_ids = array_filter(array_merge(...array_map(fn($p) => array_filter($p ?? []), $parties)));
                $all_scores = array_map(fn($cid) => $char_scores[$cid] ?? 0, $all_ids);
                $force_avg = !empty($all_scores) ? array_sum($all_scores) / count($all_scores) : 0;
                $raid_day  = trim($force['raid_day']  ?? '');
                $raid_time = trim($force['raid_time'] ?? '');
                $pdo->prepare("INSERT INTO sanctuary_forces (season_id, force_number, avg_atul, raid_day, raid_time) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$sid, $force_number, round($force_avg), $raid_day ?: null, $raid_time ?: null]);
                $force_id = (int)$pdo->lastInsertId();
                foreach ($parties as $pi => $char_ids) {
                    $pscores = array_map(fn($cid) => $char_scores[$cid] ?? 0, array_filter($char_ids ?? []));
                    $party_avg = !empty($pscores) ? array_sum($pscores) / count($pscores) : 0;
                    $pdo->prepare("INSERT INTO sanctuary_parties (force_id, party_number, avg_atul) VALUES (?, ?, ?)")
                        ->execute([$force_id, $pi + 1, round($party_avg)]);
                    $party_id = (int)$pdo->lastInsertId();
                    foreach (($char_ids ?? []) as $char_id) {
                        if (!$char_id) continue;
                        $pdo->prepare("INSERT INTO sanctuary_party_members (party_id, character_id) VALUES (?, ?)")
                            ->execute([$party_id, (int)$char_id]);
                    }
                }
                $force_number++;
            }
            // 보정값 초기화 (치유성 70% 보정 제거됨)
            $pdo->exec("UPDATE sanctuary_characters SET atul_adjusted = NULL");
            // 상태를 모집종료로 전환 → 공개
            $pdo->prepare("UPDATE sanctuary_seasons SET status = '모집종료' WHERE id = ?")->execute([$sid]);
            $pdo->commit();
            header("Location: index.php?tab=season&season={$sid}&nav=admin&msg=finalized");
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $message = '포스 확정 중 오류: ' . $e->getMessage();
            $message_type = 'error';
        }
    }
}

// 포스 배치는 유지한 채 구성중으로 되돌리기 (공개 취소 → 편집 재개)
if (isset($_POST['revert_to_composing']) && $is_admin) {
    $sid = (int)($_POST['season_id'] ?? $current_season_id);
    $pdo->prepare("UPDATE sanctuary_seasons SET status = '구성중' WHERE id = ?")->execute([$sid]);
    header("Location: index.php?tab=season&season={$sid}&nav=admin");
    exit;
}

// 전체 초기화 후 구성중으로
if (isset($_POST['full_reset_and_recompose']) && $is_admin) {
    if (strtolower($_POST['confirm_password'] ?? '') === 'naniamori0304') {
        $sid = (int)($_POST['season_id'] ?? $current_season_id);
        $ef = $pdo->prepare("SELECT id FROM sanctuary_forces WHERE season_id = ?");
        $ef->execute([$sid]);
        foreach ($ef->fetchAll(PDO::FETCH_COLUMN) as $fid) {
            $ep = $pdo->prepare("SELECT id FROM sanctuary_parties WHERE force_id = ?");
            $ep->execute([$fid]);
            foreach ($ep->fetchAll(PDO::FETCH_COLUMN) as $pid) {
                $pdo->prepare("DELETE FROM sanctuary_party_members WHERE party_id = ?")->execute([$pid]);
            }
            $pdo->prepare("DELETE FROM sanctuary_parties WHERE force_id = ?")->execute([$fid]);
        }
        $pdo->prepare("DELETE FROM sanctuary_forces WHERE season_id = ?")->execute([$sid]);
        $ea = $pdo->prepare("SELECT id FROM sanctuary_applications WHERE season_id = ?");
        $ea->execute([$sid]);
        foreach ($ea->fetchAll(PDO::FETCH_COLUMN) as $aid) {
            $pdo->prepare("DELETE FROM sanctuary_characters WHERE application_id = ?")->execute([$aid]);
        }
        $pdo->prepare("DELETE FROM sanctuary_applications WHERE season_id = ?")->execute([$sid]);
        $pdo->exec("UPDATE sanctuary_characters SET atul_adjusted = NULL");
        $pdo->prepare("UPDATE sanctuary_seasons SET status = '구성중' WHERE id = ?")->execute([$sid]);
        $cs = $pdo->prepare("SELECT * FROM sanctuary_seasons WHERE id = ?");
        $cs->execute([$sid]);
        $current_season = $cs->fetch();
        header("Location: index.php?tab=season&season=" . $sid . "&nav=admin&msg=reset");
        exit;
    } else {
        $message = '비밀번호가 틀렸습니다.';
        $message_type = 'error';
    }
}

// ── 레기온 멤버 관리 ─────────────────────────────────────────────────
if (isset($_POST['add_member']) && $is_admin) {
    $mn  = trim($_POST['main_name'] ?? '');
    $mc  = trim($_POST['main_class'] ?? '');
    $ma  = (int)($_POST['main_atul'] ?? 0);
    $mil = ($_POST['main_item_level'] ?? '') !== '' ? (int)$_POST['main_item_level'] : null;
    if ($mn) {
        $pdo->prepare("INSERT INTO sanctuary_legion_members (main_name,main_class,main_atul,main_item_level) VALUES (?,?,?,?)")
            ->execute([$mn, $mc, $ma, $mil]);
        $mid = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO sanctuary_legion_member_subs (member_id,sub_name,sub_class,sub_atul,sub_item_level) VALUES (?,?,?,?,?)");
        foreach ($_POST['sub_name'] ?? [] as $i => $sn) {
            $sn  = trim($sn);
            $sil = ($_POST['sub_item_level'][$i] ?? '') !== '' ? (int)$_POST['sub_item_level'][$i] : null;
            if ($sn) $ins->execute([$mid, $sn, $_POST['sub_class'][$i] ?? '', (int)($_POST['sub_atul'][$i] ?? 0), $sil]);
        }
    }
    header('Location: index.php'); exit;
}
if (isset($_POST['edit_member']) && $is_admin) {
    $mid = (int)($_POST['member_id'] ?? 0);
    $mn  = trim($_POST['main_name'] ?? '');
    $mc  = trim($_POST['main_class'] ?? '');
    $ma  = (int)($_POST['main_atul'] ?? 0);
    $mil = ($_POST['main_item_level'] ?? '') !== '' ? (int)$_POST['main_item_level'] : null;
    if ($mid && $mn) {
        $pdo->prepare("UPDATE sanctuary_legion_members SET main_name=?,main_class=?,main_atul=?,main_item_level=? WHERE id=?")
            ->execute([$mn, $mc, $ma, $mil, $mid]);
        $pdo->prepare("DELETE FROM sanctuary_legion_member_subs WHERE member_id=?")->execute([$mid]);
        $ins = $pdo->prepare("INSERT INTO sanctuary_legion_member_subs (member_id,sub_name,sub_class,sub_atul,sub_item_level) VALUES (?,?,?,?,?)");
        foreach ($_POST['sub_name'] ?? [] as $i => $sn) {
            $sn  = trim($sn);
            $sil = ($_POST['sub_item_level'][$i] ?? '') !== '' ? (int)$_POST['sub_item_level'][$i] : null;
            if ($sn) $ins->execute([$mid, $sn, $_POST['sub_class'][$i] ?? '', (int)($_POST['sub_atul'][$i] ?? 0), $sil]);
        }
    }
    header('Location: index.php'); exit;
}
if (isset($_POST['delete_member']) && $is_admin) {
    $mid = (int)($_POST['member_id'] ?? 0);
    if ($mid) {
        $pdo->prepare("DELETE FROM sanctuary_legion_member_subs WHERE member_id=?")->execute([$mid]);
        $pdo->prepare("DELETE FROM sanctuary_legion_members WHERE id=?")->execute([$mid]);
    }
    header('Location: index.php'); exit;
}

if (isset($_POST['cancel_application']) && $is_admin) {
    $app_id = (int)$_POST['app_id'];
    // 이 신청에 속한 캐릭터들이 포스에 배치돼 있다면 먼저 정리
    $cids = $pdo->prepare("SELECT id FROM sanctuary_characters WHERE application_id = ?");
    $cids->execute([$app_id]);
    $char_ids = $cids->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($char_ids)) {
        $in = implode(',', array_fill(0, count($char_ids), '?'));
        $pdo->prepare("DELETE FROM sanctuary_party_members WHERE character_id IN ($in)")->execute($char_ids);
    }
    $pdo->prepare("DELETE FROM sanctuary_applications WHERE id = ?")->execute([$app_id]);
    $pdo->prepare("DELETE FROM sanctuary_characters WHERE application_id = ?")->execute([$app_id]);
    $message = '신청이 취소되었습니다.';
    $message_type = 'success';
}
// ── 입장 비밀번호 변경 ───────────────────────────────────────────────
if (isset($_POST['change_site_password']) && $is_admin) {
    $cur  = $_POST['current_pw']  ?? '';
    $new1 = $_POST['new_pw']      ?? '';
    $new2 = $_POST['confirm_pw']  ?? '';
    if (strtolower($cur) !== strtolower($site_pw)) {
        $message = '현재 비밀번호가 올바르지 않습니다.'; $message_type = 'error';
    } elseif (strlen($new1) < 4) {
        $message = '새 비밀번호는 4자 이상이어야 합니다.'; $message_type = 'error';
    } elseif ($new1 !== $new2) {
        $message = '새 비밀번호와 비밀번호 확인이 일치하지 않습니다.'; $message_type = 'error';
    } else {
        $_config['site_password'] = $new1;
        $written = file_put_contents($_config_file, json_encode($_config, JSON_UNESCAPED_UNICODE));
        if ($written === false) {
            $message = '파일 저장 실패: 서버 권한 문제입니다. 관리자에게 문의하세요. (' . $_config_file . ')';
            $message_type = 'error';
        } else {
            $site_pw = $new1;
            $message = '입장 비밀번호가 변경되었습니다.'; $message_type = 'success';
        }
    }
}

// ── 홈 대시보드 ──────────────────────────────────────────────────────
if ($tab === 'home') { include 'sections/home.php'; exit; }
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>아이온2 레기온 성역 포스 관리</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@300;400;500;700;900&display=swap" rel="stylesheet">
<style>
:root {
    --bg-dark: #0a0c14;
    --bg-panel: #0f1220;
    --bg-card: #141828;
    --bg-hover: #1a2035;
    --border: #1e2840;
    --border-bright: #2a3a5c;
    --gold: #c9a84c;
    --gold-light: #f0c96a;
    --gold-dark: #8a6830;
    --blue: #3a7bd5;
    --blue-light: #5a9bf5;
    --red: #c0392b;
    --red-light: #e74c3c;
    --green: #1a8a4a;
    --green-light: #2ecc71;
    --purple: #6c3dc9;
    --purple-light: #9b6ef5;
    --text-primary: #e8eaf0;
    --text-secondary: #8a9ab8;
    --text-muted: #4a5a78;
    --class-guardian: #5774B6;
    --class-sword: #7DD3E2;
    --class-kill: #91D191;
    --class-bow: #6BB693;
    --class-hobeop: #B0824E;
    --class-spirit: #8F5192;
    --class-mage: #A07FDA;
    --class-heal: #DECA74;
}
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: 'Noto Sans KR', sans-serif;
    background: var(--bg-dark);
    color: var(--text-primary);
    min-height: 100vh;
    background-image:
        radial-gradient(ellipse at 20% 50%, rgba(58,123,213,0.05) 0%, transparent 60%),
        radial-gradient(ellipse at 80% 20%, rgba(108,61,201,0.05) 0%, transparent 60%);
}
.site-header {
    background: linear-gradient(180deg, #0d1525 0%, #080c18 100%);
    border-bottom: 1px solid var(--border);
    padding: 0 24px;
    position: sticky; top: 0; z-index: 100;
    box-shadow: 0 2px 20px rgba(0,0,0,0.5);
}
.header-inner {
    max-width: 1760px; margin: 0 auto;
    display: flex; align-items: center; height: 60px; gap: 32px;
}
.logo { display: flex; align-items: center; gap: 10px; text-decoration: none; flex-shrink: 0; }
.logo-icon {
    width: 36px; height: 36px;
    background: linear-gradient(135deg, var(--gold), var(--gold-dark));
    border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 18px;
}
.logo-legion { font-size: 26px; font-weight: 900; color: var(--gold-light); letter-spacing: -1px; line-height: 1; margin-right: 2px; }
.logo-text { font-size: 15px; font-weight: 700; color: var(--gold-light); letter-spacing: 0.5px; }
.logo-text span { display: block; font-size: 10px; font-weight: 400; color: var(--text-secondary); letter-spacing: 1px; }
.season-tabs { display: flex; gap: 4px; flex: 1; }
.season-tab {
    padding: 8px 18px; border-radius: 6px; font-size: 13px; font-weight: 500;
    color: var(--text-secondary); text-decoration: none;
    border: 1px solid transparent; transition: all 0.2s; white-space: nowrap;
}
.season-tab:hover { color: var(--text-primary); background: var(--bg-hover); border-color: var(--border); }
.season-tab.active { color: var(--gold-light); background: rgba(201,168,76,0.1); border-color: rgba(201,168,76,0.3); }
.season-tab.notice-tab { color: var(--blue-light); }
.season-tab.notice-tab.active { color: var(--blue-light); background: rgba(58,123,213,0.1); border-color: rgba(58,123,213,0.3); }
.header-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
.btn-sm {
    padding: 6px 14px; border-radius: 5px; font-size: 12px; font-weight: 500;
    cursor: pointer; border: 1px solid; text-decoration: none;
    transition: all 0.2s; display: inline-flex; align-items: center; gap: 4px;
}
.btn-admin { background: rgba(201,168,76,0.1); border-color: var(--gold-dark); color: var(--gold-light); }
.btn-admin:hover { background: rgba(201,168,76,0.2); }
.btn-logout { background: rgba(192,57,43,0.1); border-color: var(--red); color: var(--red-light); }
.btn-logout:hover { background: rgba(192,57,43,0.2); }
.admin-badge {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 4px 10px; background: rgba(201,168,76,0.15);
    border: 1px solid var(--gold-dark); border-radius: 4px; font-size: 11px; color: var(--gold);
}
.main-layout {
    max-width: 1760px; margin: 0 auto; padding: 24px;
    display: flex; gap: 20px; min-height: calc(100vh - 60px);
}
.side-nav { width: 180px; flex-shrink: 0; }
.nav-group { background: var(--bg-panel); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; margin-bottom: 16px; }
.nav-group-title {
    padding: 10px 14px; font-size: 10px; font-weight: 700;
    letter-spacing: 1.5px; color: var(--text-muted); text-transform: uppercase;
    border-bottom: 1px solid var(--border); background: rgba(0,0,0,0.2);
}
.nav-item {
    display: flex; align-items: center; gap: 8px; padding: 11px 14px;
    font-size: 13px; color: var(--text-secondary); text-decoration: none;
    border-bottom: 1px solid rgba(30,40,64,0.5); transition: all 0.15s;
    cursor: pointer; border: none; background: none; width: 100%; text-align: left;
}
.nav-item:last-child { border-bottom: none; }
.nav-item:hover { color: var(--text-primary); background: var(--bg-hover); }
.nav-item.active { color: var(--gold-light); background: rgba(201,168,76,0.08); border-left: 2px solid var(--gold); padding-left: 12px; }
.nav-item .nav-icon { font-size: 15px; }
.status-badge { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 10px; font-weight: 700; letter-spacing: 0.5px; }
.status-badge.모집중 { background: rgba(26,138,74,0.2); color: var(--green-light); border: 1px solid rgba(26,138,74,0.4); }
.status-badge.모집종료 { background: rgba(192,57,43,0.2); color: var(--red-light); border: 1px solid rgba(192,57,43,0.4); }
.status-badge.구성중 { background: rgba(108,61,201,0.2); color: var(--purple-light); border: 1px solid rgba(108,61,201,0.4); }
.content-area { flex: 1; min-width: 0; }
.content-panel { background: var(--bg-panel); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; }
.panel-header {
    padding: 16px 20px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between; background: rgba(0,0,0,0.2);
}
.panel-title { font-size: 15px; font-weight: 700; color: var(--text-primary); display: flex; align-items: center; gap: 8px; }
.panel-body { padding: 20px; }
.alert { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; font-size: 13px; display: flex; align-items: center; gap: 8px; }
.alert-success { background: rgba(26,138,74,0.15); border: 1px solid rgba(26,138,74,0.3); color: var(--green-light); }
.alert-error { background: rgba(192,57,43,0.15); border: 1px solid rgba(192,57,43,0.3); color: var(--red-light); }
.alert-info { background: rgba(58,123,213,0.15); border: 1px solid rgba(58,123,213,0.3); color: var(--blue-light); }
.alert-warning { background: rgba(224,160,48,0.15); border: 1px solid rgba(224,160,48,0.3); color: #f0c060; }
.btn {
    display: inline-flex; align-items: center; gap: 6px; padding: 9px 18px;
    border-radius: 6px; font-size: 13px; font-weight: 600; cursor: pointer;
    border: 1px solid; transition: all 0.2s; text-decoration: none; font-family: inherit;
}
.btn-primary { background: linear-gradient(135deg, #1a4a8a, #2a5aaa); border-color: var(--blue); color: #fff; }
.btn-primary:hover { background: linear-gradient(135deg, #2a5aaa, #3a6aba); transform: translateY(-1px); }
.btn-gold { background: linear-gradient(135deg, var(--gold-dark), var(--gold)); border-color: var(--gold); color: #0a0c14; font-weight: 700; }
.btn-gold:hover { filter: brightness(1.1); transform: translateY(-1px); }
.btn-danger { background: rgba(192,57,43,0.2); border-color: var(--red); color: var(--red-light); }
.btn-danger:hover { background: rgba(192,57,43,0.35); }
.btn-secondary { background: rgba(30,40,64,0.5); border-color: var(--border); color: var(--text-secondary); }
.btn-secondary:hover { background: var(--bg-hover); color: var(--text-primary); }
.form-group { margin-bottom: 16px; }
.form-label { display: block; margin-bottom: 6px; font-size: 12px; font-weight: 600; color: var(--text-secondary); letter-spacing: 0.5px; text-transform: uppercase; }
.form-input, .form-select, .form-textarea {
    width: 100%; padding: 9px 12px; background: var(--bg-dark);
    border: 1px solid var(--border); border-radius: 6px; color: var(--text-primary);
    font-size: 13px; font-family: inherit; transition: border-color 0.2s; outline: none;
}
.form-input:focus, .form-select:focus, .form-textarea:focus { border-color: var(--blue); box-shadow: 0 0 0 2px rgba(58,123,213,0.15); }
.form-select { cursor: pointer; }
.form-textarea { resize: vertical; min-height: 100px; }
.form-input::placeholder { color: var(--text-muted); }
.cls { display: inline-flex; align-items: center; gap: 4px; font-weight: 600; font-size: 12px; }
.cls-수호성 { color: var(--class-guardian); }
.cls-검성 { color: var(--class-sword); }
.cls-살성 { color: var(--class-kill); }
.cls-궁성 { color: var(--class-bow); }
.cls-호법성 { color: var(--class-hobeop); }
.cls-정령성 { color: var(--class-spirit); }
.cls-마도성 { color: var(--class-mage); }
.cls-치유성 { color: var(--class-heal); }
.apply-section { max-width: 640px; }
.char-row {
    display: flex; gap: 8px; align-items: center; padding: 12px;
    background: var(--bg-card); border: 1px solid var(--border);
    border-radius: 8px; margin-bottom: 8px; flex-wrap: wrap;
}
.char-row .char-type { font-size: 10px; font-weight: 700; color: var(--gold); letter-spacing: 1px; text-transform: uppercase; min-width: 30px; }
.char-row .char-type.sub { color: var(--text-muted); }
.char-name-input { flex: 1; min-width: 120px; padding: 7px 10px; background: var(--bg-dark); border: 1px solid var(--border); border-radius: 5px; color: var(--text-primary); font-size: 13px; font-family: inherit; outline: none; }
.char-name-input:focus { border-color: var(--blue); }
.char-result { display: flex; align-items: center; gap: 8px; font-size: 12px; min-width: 200px; flex: 2; }
.atul-score { background: rgba(201,168,76,0.12); border: 1px solid rgba(201,168,76,0.45); border-radius: 4px; padding: 3px 10px; color: var(--gold-light); font-weight: 700; font-size: 13px; letter-spacing: 0.3px; }
.atul-error { color: var(--red-light); font-size: 11px; }
.force-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 16px; }
.force-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; transition: border-color 0.2s; }
.force-card:hover { border-color: var(--border-bright); }
.force-card-header { padding: 12px 16px; background: linear-gradient(135deg, rgba(201,168,76,0.08), rgba(201,168,76,0.03)); border-bottom: 1px solid var(--border); display: flex; align-items: center; justify-content: space-between; }
.force-number { font-size: 18px; font-weight: 900; color: var(--gold-light); }
.force-avg { font-size: 12px; color: var(--text-secondary); }
.force-avg strong { color: var(--gold); font-size: 14px; }
.party-section { padding: 12px 16px; border-bottom: 1px solid rgba(30,40,64,0.5); }
.party-label { font-size: 10px; font-weight: 700; letter-spacing: 1px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 8px; }
.member-row { display: flex; align-items: center; justify-content: space-between; padding: 5px 0; border-bottom: 1px solid rgba(30,40,64,0.3); }
.member-row:last-child { border-bottom: none; }
.member-name { font-size: 13px; font-weight: 500; color: var(--text-primary); }
.member-info { display: flex; align-items: center; gap: 8px; }
.member-score { font-size: 11px; color: var(--gold-light); font-weight: 700; background: rgba(201,168,76,0.1); border: 1px solid rgba(201,168,76,0.35); border-radius: 4px; padding: 2px 7px; letter-spacing: 0.2px; }
.score-badge { display: inline-block; font-size: 12px; color: var(--gold-light); font-weight: 700; background: rgba(201,168,76,0.1); border: 1px solid rgba(201,168,76,0.35); border-radius: 4px; padding: 2px 8px; letter-spacing: 0.2px; }
.score-badge-sm { display: inline-block; font-size: 10px; color: var(--gold-light); font-weight: 700; background: rgba(201,168,76,0.08); border: 1px solid rgba(201,168,76,0.28); border-radius: 3px; padding: 1px 5px; }
.score-adjusted { font-size: 10px; color: var(--text-muted); text-decoration: line-through; }
.main-char-badge { width: 4px; height: 14px; background: var(--gold); border-radius: 2px; flex-shrink: 0; }
.sub-char-badge { width: 4px; height: 14px; background: var(--border-bright); border-radius: 2px; flex-shrink: 0; }
.notice-list-table { width: 100%; border-collapse: collapse; }
.notice-list-table th { padding: 10px 14px; font-size: 11px; font-weight: 700; letter-spacing: 1px; color: var(--text-muted); text-transform: uppercase; border-bottom: 1px solid var(--border); text-align: left; }
.notice-list-table td { padding: 12px 14px; font-size: 13px; border-bottom: 1px solid rgba(30,40,64,0.4); vertical-align: middle; }
.notice-list-table tr:hover td { background: var(--bg-hover); }
.notice-title-link { color: var(--text-primary); text-decoration: none; font-weight: 500; cursor: pointer; }
.notice-title-link:hover { color: var(--gold-light); }
.notice-date { color: var(--text-muted); font-size: 12px; }
.stat-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 8px; padding: 16px; text-align: center; }
.stat-value { font-size: 28px; font-weight: 900; color: var(--gold-light); }
.stat-label { font-size: 11px; color: var(--text-muted); margin-top: 4px; letter-spacing: 0.5px; }
.applicant-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.applicant-table th { padding: 9px 12px; font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; border-bottom: 1px solid var(--border); text-align: left; white-space: nowrap; }
.applicant-table td { padding: 10px 12px; border-bottom: 1px solid rgba(30,40,64,0.4); vertical-align: middle; }
.applicant-table tr:hover td { background: var(--bg-hover); }
.modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.7); backdrop-filter: blur(4px); z-index: 1000; align-items: center; justify-content: center; }
.modal-overlay.active { display: flex; }
.modal { background: var(--bg-panel); border: 1px solid var(--border-bright); border-radius: 12px; padding: 24px; width: 380px; max-width: 90vw; box-shadow: 0 20px 60px rgba(0,0,0,0.5); }
.modal-title { font-size: 16px; font-weight: 700; color: var(--gold-light); margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }
.modal-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 16px; }
.notice-detail { background: var(--bg-card); border: 1px solid var(--border); border-radius: 8px; padding: 20px; }
.notice-detail-title { font-size: 18px; font-weight: 700; color: var(--text-primary); margin-bottom: 8px; }
.notice-detail-meta { font-size: 12px; color: var(--text-muted); margin-bottom: 16px; padding-bottom: 16px; border-bottom: 1px solid var(--border); }
.notice-detail-body { font-size: 14px; line-height: 1.8; color: var(--text-secondary); white-space: pre-wrap; }
.loading-spinner { display: inline-block; width: 14px; height: 14px; border: 2px solid rgba(255,255,255,0.2); border-top-color: var(--gold); border-radius: 50%; animation: spin 0.6s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@media (max-width: 768px) {
    .main-layout { flex-direction: column; padding: 12px; }
    .side-nav { width: 100%; }
    .nav-group { display: flex; overflow-x: auto; }
    .nav-group-title { display: none; }
    .force-grid { grid-template-columns: 1fr; }
    .header-inner { gap: 12px; }
    .season-tab { padding: 6px 10px; font-size: 12px; }
}
.divider { height: 1px; background: var(--border); margin: 16px 0; }
.empty-state { text-align: center; padding: 48px 20px; color: var(--text-muted); }
.empty-state .empty-icon { font-size: 40px; margin-bottom: 12px; }
.empty-state p { font-size: 14px; }
.tag { display: inline-block; padding: 2px 7px; border-radius: 3px; font-size: 10px; font-weight: 700; }
.tag-main { background: rgba(201,168,76,0.15); color: var(--gold); border: 1px solid rgba(201,168,76,0.3); }
.tag-sub { background: rgba(74,90,120,0.15); color: var(--text-muted); border: 1px solid rgba(74,90,120,0.3); }
.flex-gap { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.ml-auto { margin-left: auto; }
</style>
</head>
<body>

<header class="site-header">
  <div class="header-inner">
    <a href="index.php" class="logo">
      <div class="logo-legion">숲</div>
      <div class="logo-icon">⚔️</div>
      <div class="logo-text">성역 포스 관리<span>AION 2 LEGION</span></div>
    </a>
    <nav class="season-tabs">
      <?php foreach ($seasons as $s): ?>
      <a href="index.php?tab=season&season=<?= $s['id'] ?>"
         class="season-tab <?= ($tab === 'season' && (int)$current_season_id === (int)$s['id']) ? 'active' : '' ?>">
        <?= htmlspecialchars($s['name']) ?>
        <span class="status-badge <?= $s['status'] ?>" style="margin-left:4px;"><?= $s['status'] ?></span>
      </a>
      <?php endforeach; ?>
      <a href="index.php?tab=notices&nav=list"
         class="season-tab notice-tab <?= $tab === 'notices' ? 'active' : '' ?>">
        📋 공지사항
      </a>
    </nav>
    <div class="header-actions">
      <?php if ($is_admin): ?>
        <span class="admin-badge">👑 관리자</span>
        <button onclick="document.getElementById('changePwModal').classList.add('active')" class="btn-sm btn-admin">🔑 입장비밀번호 변경</button>
        <a href="index.php?admin_logout=1" class="btn-sm btn-logout">로그아웃</a>
      <?php else: ?>
        <button onclick="document.getElementById('adminModal').classList.add('active')" class="btn-sm btn-admin">🔐 관리자</button>
      <?php endif; ?>
    </div>
  </div>
</header>

<div class="main-layout">
  <aside class="side-nav">
    <?php if ($tab === 'season' && $current_season): ?>
    <div class="nav-group">
      <div class="nav-group-title">메뉴</div>
      <a href="?tab=season&season=<?= $current_season_id ?>&nav=forces" class="nav-item <?= $nav === 'forces' ? 'active' : '' ?>"><span class="nav-icon">⚔️</span> 포스 리스트</a>
      <?php if ($is_admin): ?>
      <a href="?tab=season&season=<?= $current_season_id ?>&nav=admin" class="nav-item <?= $nav === 'admin' ? 'active' : '' ?>"><span class="nav-icon">⚙️</span> 관리</a>
      <?php else: ?>
      <button onclick="document.getElementById('adminModal').classList.add('active')" class="nav-item"><span class="nav-icon">🔐</span> 관리</button>
      <?php endif; ?>
    </div>
    <?php elseif ($tab === 'notices'): ?>
    <div class="nav-group">
      <div class="nav-group-title">공지사항</div>
      <a href="?tab=notices&nav=list" class="nav-item <?= $nav === 'list' ? 'active' : '' ?>"><span class="nav-icon">📋</span> 공지 목록</a>
      <?php if ($is_admin): ?>
      <a href="?tab=notices&nav=write" class="nav-item <?= $nav === 'write' ? 'active' : '' ?>"><span class="nav-icon">✏️</span> 글쓰기</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </aside>

  <main class="content-area">
    <?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?>">
      <?= $message_type === 'success' ? '✅' : ($message_type === 'error' ? '❌' : 'ℹ️') ?>
      <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>
    <?php if ($tab === 'season' && $current_season): ?>
      <?php
        if ($nav === 'admin' && $is_admin) include 'sections/admin.php';
        elseif ($nav === 'admin' && !$is_admin) {
            echo '<div class="content-panel"><div class="panel-body"><div class="empty-state"><div class="empty-icon">🔐</div><p>관리자 로그인이 필요합니다.</p></div></div></div>';
        }
        else include 'sections/forces.php';
      ?>
    <?php elseif ($tab === 'notices'): ?>
      <?php
        if ($nav === 'list') include 'sections/notices_list.php';
        elseif ($nav === 'detail') include 'sections/notices_detail.php';
        elseif ($nav === 'write' && $is_admin) include 'sections/notices_write.php';
      ?>
    <?php endif; ?>
  </main>
</div>

<!-- 입장 비밀번호 변경 모달 -->
<div id="changePwModal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('active')">
  <div class="modal">
    <div class="modal-title">🔑 입장 비밀번호 변경</div>
    <form method="POST">
      <input type="hidden" name="change_site_password" value="1">
      <div class="form-group">
        <label class="form-label">현재 비밀번호</label>
        <input type="password" name="current_pw" class="form-input" placeholder="현재 비밀번호 입력" required>
      </div>
      <div class="form-group">
        <label class="form-label">새 비밀번호</label>
        <input type="password" name="new_pw" class="form-input" placeholder="새 비밀번호 입력 (4자 이상)" required>
      </div>
      <div class="form-group">
        <label class="form-label">새 비밀번호 확인</label>
        <input type="password" name="confirm_pw" class="form-input" placeholder="새 비밀번호 재입력" required>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary"
                onclick="document.getElementById('changePwModal').classList.remove('active')">취소</button>
        <button type="submit" class="btn btn-gold">🔑 변경하기</button>
      </div>
    </form>
  </div>
</div>

<!-- 관리자 로그인 모달 -->
<div id="adminModal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('active')">
  <div class="modal">
    <div class="modal-title">🔐 관리자 인증</div>
    <form method="POST">
      <div class="form-group">
        <label class="form-label">관리자 비밀번호</label>
        <input type="password" name="admin_password" class="form-input" placeholder="비밀번호 입력" autofocus>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="document.getElementById('adminModal').classList.remove('active')">취소</button>
        <button type="submit" name="admin_login" class="btn btn-gold">로그인</button>
      </div>
    </form>
  </div>
</div>

</body>
</html>
