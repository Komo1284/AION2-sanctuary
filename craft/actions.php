<?php
// 공개 시세 갱신(비밀번호 불요) + 관리자 레시피 편집(Task 후속)
if (isset($_POST['update_price'])) {
    $name = trim($_POST['material'] ?? '');
    $price = max(0, (int)($_POST['price'] ?? 0));
    if ($name !== '') {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        $pdo->prepare("UPDATE craft_materials SET unit_price=?, updated_at=NOW(), updated_ip=? WHERE name=? AND is_core=0")
            ->execute([$price, $ip, $name]);
    }
    $q = http_build_query(['acc'=>$_POST['acc']??'목걸이','owned'=>$_POST['owned']??'없음']);
    header("Location: craft.php?$q#prices"); exit;
}

if (isset($_POST['edit_recipe']) && !empty($_SESSION['sanctuary_admin'])) {
    $rid = (int)($_POST['recipe_id'] ?? 0);
    $kina = max(0, (int)($_POST['kina_cost'] ?? 0));
    $est = isset($_POST['is_estimated']) ? 1 : 0;
    $inputs = json_decode($_POST['inputs'] ?? '[]', true);
    if ($rid && is_array($inputs)) {
        $pdo->prepare("UPDATE craft_recipes SET kina_cost=?, is_estimated=? WHERE id=?")->execute([$kina,$est,$rid]);
        $pdo->prepare("DELETE FROM craft_recipe_inputs WHERE recipe_id=?")->execute([$rid]);
        $ins = $pdo->prepare("INSERT INTO craft_recipe_inputs (recipe_id,material_name,qty) VALUES (?,?,?)");
        foreach ($inputs as $it) {
            $nm = trim($it[0] ?? ''); $q = (int)($it[1] ?? 0);
            if ($nm !== '' && $q > 0) $ins->execute([$rid, $nm, $q]);
        }
    }
    $q = http_build_query(['acc'=>$_POST['acc']??'목걸이','owned'=>'없음']);
    header("Location: craft.php?$q#recipes"); exit;
}
