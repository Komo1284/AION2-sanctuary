<?php
// 매일 05:00(KST) 크론이 돌리는 전투력 갱신 스크립트. CLI 전용이며 웹으로 접근되면 안 된다.
// `php force/cron_refresh_atul.php` 로 실행한다. 크론탭 설정은 docs/superpowers/specs/
// 2026-08-13-atul-daily-refresh-and-slot-card-design.md 참고.

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

// CLI 기본 타임존은 UTC다. index.php는 Asia/Seoul을 쓰므로 여기서도 명시적으로 맞추지
// 않으면 atul_updated_at이 웹 요청과 CLI에서 서로 다른 타임존으로 저장된다.
date_default_timezone_set('Asia/Seoul');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/atul.php';

$pdo = fc_pdo();
fc_init_schema($pdo);

$started = microtime(true);
$result  = fc_refresh_all_atul($pdo, 'fc_atul_lookup', 300);
$elapsed = microtime(true) - $started;

printf(
    "[%s] 아툴 갱신 — 성공 %d / 실패 %d / 건너뜀 %d (%.1f초)\n",
    date('Y-m-d H:i:s'),
    $result['updated'],
    $result['failed'],
    $result['skipped'],
    $elapsed
);
