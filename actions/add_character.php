<?php
// 관리자가 검색한 캐릭터를 시즌 풀에 즉시 등록 (Approach A)
// 캐릭터마다 개별 application(applicant_ip='admin_added') 을 생성하여 app_id 를 고유하게 둔다.
require_once __DIR__ . '/_bootstrap.php';

if (!$is_admin) {
    json_out(['success' => false, 'error' => 'unauthorized'], 403);
}

$season_id  = (int)($_POST['season_id'] ?? 0);
$char_name  = trim($_POST['char_name'] ?? '');
$char_class = trim($_POST['char_class'] ?? '');
$atul_score = (int)($_POST['atul_score'] ?? 0);
$item_level_raw = $_POST['item_level'] ?? '';
$item_level = ($item_level_raw === '' || $item_level_raw === null) ? null : (int)$item_level_raw;

$valid_classes = ['수호성','검성','살성','궁성','호법성','정령성','마도성','치유성'];

if ($season_id <= 0 || $char_name === '') {
    json_out(['success' => false, 'error' => 'invalid_input'], 400);
}
if (!in_array($char_class, $valid_classes, true)) {
    json_out(['success' => false, 'error' => 'invalid_class'], 400);
}

try {
    // 시즌 존재 확인
    $sq = $pdo->prepare("SELECT id FROM sanctuary_seasons WHERE id = ?");
    $sq->execute([$season_id]);
    if (!$sq->fetchColumn()) {
        json_out(['success' => false, 'error' => 'season_not_found'], 404);
    }

    // 중복 방지: 같은 시즌에 동일 캐릭명이 이미 있으면 기존 카드 반환
    $dup = $pdo->prepare("
        SELECT sc.id, sc.application_id, sc.char_name, sc.char_class, sc.atul_score, sc.item_level, sc.is_main
        FROM sanctuary_characters sc
        JOIN sanctuary_applications sa ON sa.id = sc.application_id
        WHERE sa.season_id = ? AND sa.applicant_ip != 'buddy_synthesized' AND sc.char_name = ?
        LIMIT 1
    ");
    $dup->execute([$season_id, $char_name]);
    $existing = $dup->fetch();
    if ($existing) {
        json_out([
            'success'   => true,
            'duplicate' => true,
            'char'      => [
                'id'         => (int)$existing['id'],
                'app_id'     => (int)$existing['application_id'],
                'char_name'  => $existing['char_name'],
                'char_class' => $existing['char_class'],
                'atul_score' => (int)$existing['atul_score'],
                'item_level' => $existing['item_level'] !== null ? (int)$existing['item_level'] : null,
                'is_main'    => (int)$existing['is_main'],
            ],
        ]);
    }

    $pdo->beginTransaction();

    $pdo->prepare("
        INSERT INTO sanctuary_applications (season_id, applicant_ip, status, applied_at, play_times)
        VALUES (?, 'admin_added', '대기', NOW(), NULL)
    ")->execute([$season_id]);
    $app_id = (int)$pdo->lastInsertId();

    $pdo->prepare("
        INSERT INTO sanctuary_characters (application_id, char_name, char_class, atul_score, item_level, is_main)
        VALUES (?, ?, ?, ?, ?, 1)
    ")->execute([$app_id, $char_name, $char_class, $atul_score, $item_level]);
    $char_id = (int)$pdo->lastInsertId();

    $pdo->commit();

    json_out([
        'success' => true,
        'char'    => [
            'id'         => $char_id,
            'app_id'     => $app_id,
            'char_name'  => $char_name,
            'char_class' => $char_class,
            'atul_score' => $atul_score,
            'item_level' => $item_level,
            'is_main'    => 1,
        ],
    ]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['success' => false, 'error' => 'db_error', 'detail' => $e->getMessage()], 500);
}
