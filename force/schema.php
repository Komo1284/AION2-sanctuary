<?php
// fc_* 테이블 DDL. 멱등 생성만 담당한다.
// 기존 sanctuary_* / craft_* 테이블은 절대 건드리지 않는다.

function fc_init_schema(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fc_players (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        sort_order INT      NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fc_characters (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        player_id       INT          NOT NULL,
        char_name       VARCHAR(100) NOT NULL,
        char_class      VARCHAR(20)  NOT NULL DEFAULT '',
        atul_score      INT          NULL,
        item_level      INT          NULL,
        is_main         TINYINT(1)   NOT NULL DEFAULT 0,
        is_placeholder  TINYINT(1)   NOT NULL DEFAULT 0,
        sort_order      INT          NOT NULL DEFAULT 0,
        atul_updated_at DATETIME     NULL,
        KEY idx_player (player_id),
        KEY idx_char_name (char_name)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    // char_name에 UNIQUE를 걸지 않는다 — "실제 캐릭터일 때만 유일"은 MySQL 부분 유니크
    // 인덱스가 없어 표현할 수 없다. 검사는 fc_insert_character() 한 곳에서만 한다.

    $pdo->exec("CREATE TABLE IF NOT EXISTS fc_raids (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(50)  NOT NULL,
        memo       VARCHAR(200) NOT NULL DEFAULT '',
        sort_order INT          NOT NULL DEFAULT 0,
        created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fc_forces (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        raid_id     INT          NOT NULL,
        force_no    INT          NOT NULL,
        day_of_week VARCHAR(3)   NULL,
        start_time  VARCHAR(5)   NULL,
        memo        VARCHAR(200) NOT NULL DEFAULT '',
        sort_order  INT          NOT NULL DEFAULT 0,
        KEY idx_raid (raid_id)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fc_slots (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        force_id     INT     NOT NULL,
        party_no     TINYINT NOT NULL,
        slot_no      TINYINT NOT NULL,
        character_id INT     NULL,
        UNIQUE KEY uq_slot (force_id, party_no, slot_no),
        KEY idx_character (character_id)
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fc_meta (
        k VARCHAR(40) PRIMARY KEY,
        v BIGINT NOT NULL DEFAULT 0
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("INSERT IGNORE INTO fc_meta (k, v) VALUES ('revision', 0)");
}
