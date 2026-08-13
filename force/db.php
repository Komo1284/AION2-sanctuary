<?php
// PDO 커넥션 하나만 담당한다. 스키마도 쿼리도 모른다.

function fc_pdo() {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = new PDO(
            'mysql:host=localhost;dbname=budget_manager;charset=utf8mb4',
            'budget_user',
            'budget2026!'
        );
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    }
    return $pdo;
}
