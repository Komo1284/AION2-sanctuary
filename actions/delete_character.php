<?php
// 관리자가 풀에서 추가한 캐릭터를 삭제 (포스 배치/임시저장 멤버에서도 제거)
require_once __DIR__ . '/_bootstrap.php';

if (!$is_admin) {
    json_out(['success' => false, 'error' => 'unauthorized'], 403);
}

$char_id = (int)($_POST['char_id'] ?? 0);
if ($char_id <= 0) {
    json_out(['success' => false, 'error' => 'invalid_input'], 400);
}

try {
    // 캐릭터 + 소속 application 확인 (admin_added 만 삭제 허용)
    $q = $pdo->prepare("
        SELECT sc.id, sc.application_id, sa.applicant_ip
        FROM sanctuary_characters sc
        JOIN sanctuary_applications sa ON sa.id = sc.application_id
        WHERE sc.id = ?
    ");
    $q->execute([$char_id]);
    $row = $q->fetch();
    if (!$row) {
        json_out(['success' => false, 'error' => 'not_found'], 404);
    }
    // 신청 기능이 폐지되어 대기열의 모든 캐릭터는 관리자가 직접 삭제 가능.
    // buddy_synthesized(자동 합성)만 보호.
    if ($row['applicant_ip'] === 'buddy_synthesized') {
        json_out(['success' => false, 'error' => 'not_deletable'], 400);
    }

    $app_id = (int)$row['application_id'];

    $pdo->beginTransaction();
    // 임시저장된 포스 멤버에서 제거
    $pdo->prepare("DELETE FROM sanctuary_party_members WHERE character_id = ?")->execute([$char_id]);
    // 캐릭터 삭제
    $pdo->prepare("DELETE FROM sanctuary_characters WHERE id = ?")->execute([$char_id]);
    // 1캐릭터=1application 구조이므로 빈 application 도 정리
    $cnt = $pdo->prepare("SELECT COUNT(*) FROM sanctuary_characters WHERE application_id = ?");
    $cnt->execute([$app_id]);
    if ((int)$cnt->fetchColumn() === 0) {
        $pdo->prepare("DELETE FROM sanctuary_applications WHERE id = ?")->execute([$app_id]);
    }
    $pdo->commit();

    json_out(['success' => true]);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['success' => false, 'error' => 'db_error', 'detail' => $e->getMessage()], 500);
}
