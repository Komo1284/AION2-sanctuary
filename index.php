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
