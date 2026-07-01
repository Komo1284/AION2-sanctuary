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

    // seed 버전 등 메타 저장
    $pdo->exec("CREATE TABLE IF NOT EXISTS craft_meta (
        k VARCHAR(40) PRIMARY KEY,
        v VARCHAR(100) DEFAULT ''
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $data = craft_seed_data();

    // ── 재료: 새 재료만 추가한다. 기존 재료의 unit_price/updated_at 은 절대 건드리지 않는다(사용자 데이터). ──
    $insMat = $pdo->prepare("INSERT IGNORE INTO craft_materials (name,is_core,category) VALUES (?,?,?)");
    foreach ($data['materials'] as $m) { $insMat->execute([$m[0], $m[1], $m[2]]); }
    $outNames = array_unique(array_map(fn($r) => $r[1], $data['recipes']));
    foreach ($outNames as $on) { $insMat->execute([$on, 0, '산출물']); }

    // ── 레시피: SEED_VERSION 이 바뀐 경우에만 재구성. 재료(가격) 테이블은 손대지 않는다. ──
    $curVer = '';
    $vs = $pdo->query("SELECT v FROM craft_meta WHERE k='seed_version'")->fetch();
    if ($vs) $curVer = $vs['v'];
    $recipeCount = (int)$pdo->query("SELECT COUNT(*) FROM craft_recipes")->fetchColumn();

    if ($recipeCount === 0 || $curVer !== CRAFT_SEED_VERSION) {
        // 레시피 정의만 재구성(사용자 데이터 없음). craft_materials 는 DELETE/DROP 하지 않는다.
        $pdo->exec("DELETE FROM craft_recipe_inputs");
        $pdo->exec("DELETE FROM craft_recipes");
        // 재료 메타(is_core/category)를 seed 기준으로 동기화 — 가격/갱신정보는 미변경.
        $updMeta = $pdo->prepare("UPDATE craft_materials SET is_core=?, category=? WHERE name=?");
        foreach ($data['materials'] as $m) { $updMeta->execute([$m[1], $m[2], $m[0]]); }
        $insRec = $pdo->prepare("INSERT INTO craft_recipes (accessory,output_name,recipe_type,tier,kina_cost,is_estimated) VALUES (?,?,?,?,?,?)");
        $insInp = $pdo->prepare("INSERT INTO craft_recipe_inputs (recipe_id,material_name,qty) VALUES (?,?,?)");
        foreach ($data['recipes'] as $r) {
            $insRec->execute([$r[0], $r[1], $r[2], $r[3], $r[4], $r[5]]);
            $rid = (int)$pdo->lastInsertId();
            foreach ($r[6] as $inp) { $insInp->execute([$rid, $inp[0], (int)$inp[1]]); }
        }
        $pdo->prepare("INSERT INTO craft_meta (k,v) VALUES ('seed_version',?) ON DUPLICATE KEY UPDATE v=VALUES(v)")
            ->execute([CRAFT_SEED_VERSION]);
    }
}
