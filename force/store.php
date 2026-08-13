<?php
// fc_* 테이블 조작 전담. HTTP도 HTML도 모른다.

function fc_revision(PDO $pdo) {
    return (int)$pdo->query("SELECT v FROM fc_meta WHERE k = 'revision'")->fetchColumn();
}

function fc_bump_revision(PDO $pdo) {
    $pdo->exec("UPDATE fc_meta SET v = v + 1 WHERE k = 'revision'");
    return fc_revision($pdo);
}

// $lookup: function(string $name): ?array{class:string, atul:?int, item_level:?int}
function fc_apply_lookup($lookup, $name) {
    $blank = ['class' => '', 'atul' => null, 'item_level' => null];
    if (!is_callable($lookup)) return $blank;
    $got = call_user_func($lookup, $name);
    if (!is_array($got)) return $blank;
    return [
        'class'      => isset($got['class']) ? (string)$got['class'] : '',
        'atul'       => isset($got['atul']) && $got['atul'] !== null ? (int)$got['atul'] : null,
        'item_level' => isset($got['item_level']) && $got['item_level'] !== null ? (int)$got['item_level'] : null,
    ];
}

// 실제 캐릭명은 전역에서 유일해야 한다. 임시명("부캐1")은 여러 사람이 쓰므로
// 같은 플레이어 안에서만 유일하면 된다. $excludeId는 수정 시 자기 자신을 제외하는 용도.
function fc_name_exists(PDO $pdo, $name, $isPlaceholder, $playerId, $excludeId = 0) {
    if ($isPlaceholder) {
        $st = $pdo->prepare("SELECT 1 FROM fc_characters
                             WHERE char_name = ? AND player_id = ? AND id <> ?");
        $st->execute([$name, $playerId, (int)$excludeId]);
    } else {
        $st = $pdo->prepare("SELECT 1 FROM fc_characters
                             WHERE char_name = ? AND is_placeholder = 0 AND id <> ?");
        $st->execute([$name, (int)$excludeId]);
    }
    return (bool)$st->fetch();
}

// 캐릭터를 만드는 모든 경로가 이 함수를 지난다 — 중복 검사에 우회로를 두지 않는다.
function fc_insert_character(PDO $pdo, $playerId, $name, $isMain, $sortOrder, $lookup, $isPlaceholder = false) {
    $name = trim($name);
    if ($name === '') throw new RuntimeException('empty_name');
    if (fc_name_exists($pdo, $name, $isPlaceholder, $playerId)) {
        throw new RuntimeException('duplicate_name:' . $name);
    }

    // 임시 캐릭터는 외부 API에 없는 이름이므로 조회를 건너뛴다
    $info = $isPlaceholder ? ['class' => '', 'atul' => null, 'item_level' => null]
                           : fc_apply_lookup($lookup, $name);

    $st = $pdo->prepare("INSERT INTO fc_characters
        (player_id, char_name, char_class, atul_score, item_level, is_main, is_placeholder, sort_order, atul_updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $st->execute([
        $playerId, $name, $info['class'], $info['atul'], $info['item_level'],
        $isMain ? 1 : 0, $isPlaceholder ? 1 : 0, $sortOrder,
        $info['atul'] === null ? null : date('Y-m-d H:i:s'),
    ]);
    return (int)$pdo->lastInsertId();
}

function fc_create_player(PDO $pdo, $mainName, array $subNames = [], $lookup = null) {
    $mainName = trim($mainName);
    if ($mainName === '') throw new RuntimeException('empty_name');

    // 중복은 어떤 캐릭터도 만들기 전에 전부 확인한다 — 절반만 등록되는 상태를 만들지 않는다
    $all = array_merge([$mainName], array_map('trim', $subNames));
    $all = array_values(array_filter($all, function ($n) { return $n !== ''; }));
    if (count($all) !== count(array_unique($all))) throw new RuntimeException('duplicate_name:입력 안에 같은 이름');
    foreach ($all as $n) {
        // player.create로는 실제 캐릭터만 만든다 — 임시 캐릭터는 나중에 character.add로 붙인다
        if (fc_name_exists($pdo, $n, false, 0)) throw new RuntimeException('duplicate_name:' . $n);
    }

    $nextOrder = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM fc_players")->fetchColumn();
    $pdo->prepare("INSERT INTO fc_players (sort_order) VALUES (?)")->execute([$nextOrder]);
    $playerId = (int)$pdo->lastInsertId();

    foreach ($all as $i => $n) {
        fc_insert_character($pdo, $playerId, $n, $i === 0, $i, $lookup);
    }
    fc_bump_revision($pdo);
    return $playerId;
}

function fc_add_character(PDO $pdo, $playerId, $name, $lookup = null, $isPlaceholder = false) {
    $st = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM fc_characters WHERE player_id = ?");
    $st->execute([$playerId]);
    $order = (int)$st->fetchColumn();

    $id = fc_insert_character($pdo, $playerId, $name, false, $order, $lookup, $isPlaceholder);
    fc_bump_revision($pdo);
    return $id;
}

// 임시 → 실제 전환도 이 함수로 한다. 슬롯은 character_id를 가리키므로
// 배치된 자리는 그대로 유지되고 내용만 확정된다.
function fc_update_character(PDO $pdo, $id, array $fields) {
    $cur = $pdo->prepare("SELECT player_id, char_name, is_placeholder FROM fc_characters WHERE id = ?");
    $cur->execute([$id]);
    $row = $cur->fetch();
    if (!$row) throw new RuntimeException('not_found');

    // 이름 중복 검사는 "바뀐 뒤의 is_placeholder"와 "바뀐 뒤의 이름" 기준으로 한다.
    // name을 안 건드리고 is_placeholder만 바꾸는 승격(임시→실제)도 전역 유일성을
    // 깨뜨릴 수 있으므로, name 필드가 없어도 현재 이름으로 검사를 돌린다.
    $nextPlaceholder = array_key_exists('is_placeholder', $fields)
        ? (bool)$fields['is_placeholder'] : (bool)$row['is_placeholder'];
    $nextName = array_key_exists('name', $fields) ? trim($fields['name']) : $row['char_name'];
    if ($nextName === '') throw new RuntimeException('empty_name');
    if (fc_name_exists($pdo, $nextName, $nextPlaceholder, (int)$row['player_id'], $id)) {
        throw new RuntimeException('duplicate_name:' . $nextName);
    }

    $map = ['name' => 'char_name', 'class' => 'char_class', 'atul' => 'atul_score',
            'item_level' => 'item_level', 'is_placeholder' => 'is_placeholder'];
    $sets = [];
    $args = [];
    foreach ($map as $key => $col) {
        if (!array_key_exists($key, $fields)) continue;
        if ($key === 'name') {
            $sets[] = "$col = ?"; $args[] = $nextName;
        } elseif ($key === 'is_placeholder') {
            $sets[] = "$col = ?"; $args[] = $nextPlaceholder ? 1 : 0;
        } else {
            $sets[] = "$col = ?";
            $args[] = $fields[$key] === null || $fields[$key] === '' ? null : $fields[$key];
        }
    }
    if (!$sets) return;
    $args[] = $id;
    $pdo->prepare("UPDATE fc_characters SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
    fc_bump_revision($pdo);
}

function fc_delete_character(PDO $pdo, $id) {
    // 배치된 슬롯은 비우되 슬롯 행 자체는 남긴다 (포스의 칸 구조는 유지)
    $pdo->prepare("UPDATE fc_slots SET character_id = NULL WHERE character_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM fc_characters WHERE id = ?")->execute([$id]);
    fc_bump_revision($pdo);
}

function fc_delete_player(PDO $pdo, $id) {
    $st = $pdo->prepare("SELECT id FROM fc_characters WHERE player_id = ?");
    $st->execute([$id]);
    foreach ($st->fetchAll() as $row) {
        $pdo->prepare("UPDATE fc_slots SET character_id = NULL WHERE character_id = ?")->execute([$row['id']]);
    }
    $pdo->prepare("DELETE FROM fc_characters WHERE player_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM fc_players WHERE id = ?")->execute([$id]);
    fc_bump_revision($pdo);
}

function fc_create_raid(PDO $pdo, $name) {
    $name = trim($name);
    if ($name === '') throw new RuntimeException('empty_name');
    $order = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM fc_raids")->fetchColumn();
    $pdo->prepare("INSERT INTO fc_raids (name, sort_order) VALUES (?, ?)")->execute([$name, $order]);
    $id = (int)$pdo->lastInsertId();
    fc_bump_revision($pdo);
    return $id;
}

function fc_update_raid(PDO $pdo, $id, array $fields) {
    $sets = [];
    $args = [];
    if (array_key_exists('name', $fields)) {
        $n = trim($fields['name']);
        if ($n === '') throw new RuntimeException('empty_name');
        $sets[] = 'name = ?'; $args[] = $n;
    }
    if (array_key_exists('memo', $fields)) {
        $sets[] = 'memo = ?'; $args[] = (string)$fields['memo'];
    }
    if (!$sets) return;
    $args[] = $id;
    $pdo->prepare("UPDATE fc_raids SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
    fc_bump_revision($pdo);
}

function fc_delete_raid(PDO $pdo, $id) {
    $st = $pdo->prepare("SELECT id FROM fc_forces WHERE raid_id = ?");
    $st->execute([$id]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $fid) {
        $pdo->prepare("DELETE FROM fc_slots WHERE force_id = ?")->execute([(int)$fid]);
    }
    $pdo->prepare("DELETE FROM fc_forces WHERE raid_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM fc_raids WHERE id = ?")->execute([$id]);
    fc_bump_revision($pdo);
}

// 포스 번호는 레이드별 MAX+1. 삭제해도 남은 포스의 번호는 다시 매기지 않는다 —
// "3포스 토 7시"라고 공지해둔 약속이 어긋나면 안 된다.
function fc_create_force(PDO $pdo, $raidId, $day, $time, $memo = '') {
    $st = $pdo->prepare("SELECT COALESCE(MAX(force_no), 0) + 1 FROM fc_forces WHERE raid_id = ?");
    $st->execute([$raidId]);
    $no = (int)$st->fetchColumn();

    $st2 = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) + 1 FROM fc_forces WHERE raid_id = ?");
    $st2->execute([$raidId]);
    $order = (int)$st2->fetchColumn();

    $pdo->prepare("INSERT INTO fc_forces (raid_id, force_no, day_of_week, start_time, memo, sort_order)
                   VALUES (?, ?, ?, ?, ?, ?)")
        ->execute([$raidId, $no, $day !== '' ? $day : null, $time !== '' ? $time : null, (string)$memo, $order]);
    $forceId = (int)$pdo->lastInsertId();

    $ins = $pdo->prepare("INSERT INTO fc_slots (force_id, party_no, slot_no, character_id) VALUES (?, ?, ?, NULL)");
    for ($party = 1; $party <= 2; $party++) {
        for ($slot = 1; $slot <= 5; $slot++) {
            $ins->execute([$forceId, $party, $slot]);
        }
    }
    fc_bump_revision($pdo);
    return $forceId;
}

function fc_update_force(PDO $pdo, $id, array $fields) {
    $allowed = ['day_of_week', 'start_time', 'memo'];
    $sets = [];
    $args = [];
    foreach ($allowed as $col) {
        if (!array_key_exists($col, $fields)) continue;
        $val = $fields[$col];
        if ($col === 'memo') {
            $sets[] = "$col = ?"; $args[] = (string)$val;
        } else {
            $sets[] = "$col = ?"; $args[] = ($val === '' || $val === null) ? null : $val;
        }
    }
    if (!$sets) return;
    $args[] = $id;
    $pdo->prepare("UPDATE fc_forces SET " . implode(', ', $sets) . " WHERE id = ?")->execute($args);
    fc_bump_revision($pdo);
}

function fc_delete_force(PDO $pdo, $id) {
    $pdo->prepare("DELETE FROM fc_slots WHERE force_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM fc_forces WHERE id = ?")->execute([$id]);
    fc_bump_revision($pdo);
}

// 테스트 전용: zzTest_ 접두사가 붙은 것만 지운다. 실제 데이터는 건드리지 않는다.
function fc_cleanup_test_data(PDO $pdo) {
    $pids = $pdo->query("SELECT DISTINCT player_id FROM fc_characters WHERE char_name LIKE 'zzTest\\_%'")
                ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($pids as $pid) {
        fc_delete_player($pdo, (int)$pid);
    }
    $rids = $pdo->query("SELECT id FROM fc_raids WHERE name LIKE 'zzTest\\_%'")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rids as $rid) {
        $fids = $pdo->query("SELECT id FROM fc_forces WHERE raid_id = " . (int)$rid)->fetchAll(PDO::FETCH_COLUMN);
        foreach ($fids as $fid) {
            $pdo->exec("DELETE FROM fc_slots WHERE force_id = " . (int)$fid);
        }
        $pdo->exec("DELETE FROM fc_forces WHERE raid_id = " . (int)$rid);
        $pdo->exec("DELETE FROM fc_raids WHERE id = " . (int)$rid);
    }
}
