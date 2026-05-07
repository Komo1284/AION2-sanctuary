<?php
// 포스 신청 처리

$season_id = (int)($_POST['season_id'] ?? 0);
$main_name = trim($_POST['main_name'] ?? '');
$main_score = (int)($_POST['main_score'] ?? 0);
$main_class = trim($_POST['main_class'] ?? '');
$main_item_level = ($_POST['main_item_level'] ?? '') !== '' ? (int)$_POST['main_item_level'] : null;

$sub_names        = $_POST['sub_name']       ?? [];
$sub_scores       = $_POST['sub_score']      ?? [];
$sub_classes      = $_POST['sub_class']      ?? [];
$sub_item_levels  = $_POST['sub_item_level'] ?? [];

// 유효성 검사
if (!$season_id || !$main_name || !$main_score || !$main_class) {
    $message = '본캐 정보를 올바르게 입력하세요.';
    $message_type = 'error';
    return;
}

// 시즌 존재 여부만 확인 (신청은 상시 받음)
$chk = $pdo->prepare("SELECT status FROM sanctuary_seasons WHERE id = ?");
$chk->execute([$season_id]);
$s = $chk->fetch();
if (!$s) {
    $message = '존재하지 않는 시즌입니다.';
    $message_type = 'error';
    return;
}

// ── 본캐 레기온명 검증 (부캐는 타 레기온/무소속 허용) ─────────────────────────
$allowed_legion = $_config['allowed_legion_name'] ?? '레전드';
$main_legion = trim($_POST['main_legion'] ?? '');

if (mb_strtolower($main_legion) !== mb_strtolower($allowed_legion)) {
    $disp = $main_legion !== '' ? $main_legion : '미확인';
    $message = "본캐 [{$main_name}]의 레기온이 '{$allowed_legion}'이 아닙니다 (현재: {$disp}). "
             . '캐릭터명 옆의 🔍 조회 버튼을 눌러 레기온 정보를 받아온 뒤 다시 신청하세요.';
    $message_type = 'error';
    return;
}

// 중복 신청 확인 (같은 시즌, 같은 본캐명)
$dup = $pdo->prepare("
    SELECT sa.id FROM sanctuary_applications sa
    JOIN sanctuary_characters sc ON sc.application_id = sa.id
    WHERE sa.season_id = ? AND sc.char_name = ? AND sc.is_main = 1
");
$dup->execute([$season_id, $main_name]);
if ($dup->fetch()) {
    $message = "이미 신청된 본캐입니다: {$main_name}";
    $message_type = 'error';
    return;
}

// 허용 직업 확인
$allowed_classes = ['수호성','검성','살성','궁성','호법성','정령성','마도성','치유성'];
if (!in_array($main_class, $allowed_classes)) {
    $message = '올바른 직업을 선택하세요.';
    $message_type = 'error';
    return;
}

try {
    $pdo->beginTransaction();

    // 신청 레코드 생성
    $ins_app = $pdo->prepare("
        INSERT INTO sanctuary_applications (season_id, applicant_ip, status, applied_at, play_times)
        VALUES (?, ?, '대기', NOW(), NULL)
    ");
    $ins_app->execute([$season_id, $_SERVER['REMOTE_ADDR']]);
    $app_id = $pdo->lastInsertId();

    // 본캐 저장
    $ins_main = $pdo->prepare("
        INSERT INTO sanctuary_characters (application_id, char_name, char_class, atul_score, item_level, is_main)
        VALUES (?, ?, ?, ?, ?, 1)
    ");
    $ins_main->execute([$app_id, $main_name, $main_class, $main_score, $main_item_level]);

    // 부캐 저장
    $ins_sub = $pdo->prepare("
        INSERT INTO sanctuary_characters (application_id, char_name, char_class, atul_score, item_level, is_main)
        VALUES (?, ?, ?, ?, ?, 0)
    ");
    for ($i = 0; $i < count($sub_names); $i++) {
        $sname  = trim($sub_names[$i] ?? '');
        $sscore = (int)($sub_scores[$i] ?? 0);
        $scls   = trim($sub_classes[$i] ?? '');
        $sil    = ($sub_item_levels[$i] ?? '') !== '' ? (int)$sub_item_levels[$i] : null;

        if (!$sname || !$sscore || !in_array($scls, $allowed_classes)) continue;

        $ins_sub->execute([$app_id, $sname, $scls, $sscore, $sil]);
    }

    $pdo->commit();
    $message = "포스 신청이 완료되었습니다! (본캐: {$main_name})";
    $message_type = 'success';

} catch (Exception $e) {
    $pdo->rollBack();
    $message = '신청 중 오류가 발생했습니다: ' . $e->getMessage();
    $message_type = 'error';
}
