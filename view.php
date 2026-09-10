<?php
// 공유 보기 — 비밀번호 없이 누구나 현재 편성을 볼 수 있는 읽기 전용 페이지.
// 편집은 index.php(비밀번호 게이트)에서만 한다. 이 페이지는 api.php를 호출하지 않고
// force/view_state.php(공개, GET 전용)만 폴링한다. 모바일 우선 레이아웃.
date_default_timezone_set('Asia/Seoul');
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
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="theme-color" content="#0a0c14">
<meta name="robots" content="noindex">
<title>레전드 — 포스 편성 현황</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;700;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/view.css?v=<?= filemtime(__DIR__ . '/assets/view.css') ?>">
</head>
<body>
<header class="vw-header">
  <div class="vw-brand"><span class="vw-legion">레전드</span><span class="vw-brand-sub">포스 편성 현황</span></div>
  <div class="vw-updated" id="vw-updated" title="30초마다 자동으로 갱신됩니다"></div>
</header>

<main class="vw-app">
  <nav id="vw-tabs" class="vw-tabs" aria-label="레이드"></nav>
  <div id="vw-board" class="vw-board"></div>
</main>

<footer class="vw-footer">읽기 전용 · 편성 변경은 운영진에게 문의하세요</footer>

<script>window.FC_STATE = <?= json_encode($state, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
<script src="assets/view.js?v=<?= filemtime(__DIR__ . '/assets/view.js') ?>"></script>
</body>
</html>
