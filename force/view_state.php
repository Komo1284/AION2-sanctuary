<?php
// 읽기 전용 공개 상태 엔드포인트 — view.php(공유 보기)가 30초마다 폴링한다.
// 인증이 없다. 내려가는 정보는 캐릭명·직업·전투력·편성표로, 모두 aion2.plaync.com에서
// 누구나 조회할 수 있는 값이다. 쓰기 액션은 전부 api.php에 있고 거기는 세션 인증을 요구한다.
// GET만 받는다 — 이 파일은 상태를 바꾸는 코드가 없지만, 미래에 누가 실수로 넣지 못하게 막아둔다.
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/store.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = fc_pdo();
    fc_init_schema($pdo);
    echo json_encode(['ok' => true, 'data' => fc_state($pdo)], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'server_error'], JSON_UNESCAPED_UNICODE);
}
