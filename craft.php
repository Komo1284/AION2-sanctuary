<?php
// 제작효율 계산 페이지 — 비밀번호 없이 접근 가능한 독립 페이지
// (index.php의 사이트 접근 게이트를 거치지 않음)
?><!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>제작효율 계산</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;700;900&display=swap" rel="stylesheet">
<style>
* { margin: 0; padding: 0; box-sizing: border-box; }
body {
    font-family: 'Noto Sans KR', sans-serif;
    background: #0a0c14;
    color: #e8eaf0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    background-image:
        radial-gradient(ellipse at 30% 50%, rgba(58,123,213,0.06) 0%, transparent 60%),
        radial-gradient(ellipse at 70% 30%, rgba(108,61,201,0.06) 0%, transparent 60%);
}
.test-text {
    font-size: 64px;
    font-weight: 900;
    color: #f0c96a;
    letter-spacing: 2px;
}
</style>
</head>
<body>
  <div class="test-text">테스트</div>
</body>
</html>
