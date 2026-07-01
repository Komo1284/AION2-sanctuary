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
