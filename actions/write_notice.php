<?php
$title   = trim($_POST['notice_title']   ?? '');
$content = trim($_POST['notice_content'] ?? '');

if (!$title || !$content) {
    $message = '제목과 내용을 모두 입력하세요.';
    $message_type = 'error';
    return;
}

$pdo->prepare("INSERT INTO sanctuary_notices (title, content, created_at) VALUES (?, ?, NOW())")
    ->execute([$title, $content]);

header('Location: index.php?tab=notices&nav=list&msg=written');
exit;
