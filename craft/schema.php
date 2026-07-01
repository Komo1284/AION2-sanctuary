<?php
require_once __DIR__ . '/seed_data.php';

function craft_init_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS craft_materials (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL UNIQUE,
        unit_price BIGINT NOT NULL DEFAULT 0,
        is_core TINYINT NOT NULL DEFAULT 0,
        category VARCHAR(40) DEFAULT '',
        updated_at DATETIME NULL DEFAULT NULL,
        updated_ip VARCHAR(64) DEFAULT ''
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS craft_recipes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        accessory VARCHAR(20) NOT NULL,
        output_name VARCHAR(120) NOT NULL,
        recipe_type VARCHAR(30) NOT NULL,
        tier INT NOT NULL DEFAULT 0,
        kina_cost BIGINT NOT NULL DEFAULT 0,
        combo_rate DECIMAL(4,3) NOT NULL DEFAULT 0.250,
        is_estimated TINYINT NOT NULL DEFAULT 0,
        note VARCHAR(255) DEFAULT ''
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS craft_recipe_inputs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        recipe_id INT NOT NULL,
        material_name VARCHAR(120) NOT NULL,
        qty INT NOT NULL DEFAULT 1
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // 비어있을 때만 seed
    $count = (int)$pdo->query("SELECT COUNT(*) FROM craft_recipes")->fetchColumn();
    if ($count > 0) return;

    $data = craft_seed_data();
    $insMat = $pdo->prepare("INSERT IGNORE INTO craft_materials (name,is_core,category) VALUES (?,?,?)");
    foreach ($data['materials'] as $m) { $insMat->execute([$m[0], $m[1], $m[2]]); }

    $insRec = $pdo->prepare("INSERT INTO craft_recipes (accessory,output_name,recipe_type,tier,kina_cost,is_estimated) VALUES (?,?,?,?,?,?)");
    $insInp = $pdo->prepare("INSERT INTO craft_recipe_inputs (recipe_id,material_name,qty) VALUES (?,?,?)");
    foreach ($data['recipes'] as $r) {
        $insRec->execute([$r[0], $r[1], $r[2], $r[3], $r[4], $r[5]]);
        $rid = (int)$pdo->lastInsertId();
        foreach ($r[6] as $inp) { $insInp->execute([$rid, $inp[0], (int)$inp[1]]); }
    }
    // 크래프트 결과물(중간산출 아이템)도 materials에 없으면 등록(가격0, 비코어) → 조회 편의
    $outNames = array_unique(array_map(fn($r)=>$r[1], $data['recipes']));
    foreach ($outNames as $on) { $insMat->execute([$on, 0, '산출물']); }
}
