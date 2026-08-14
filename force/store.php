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

// fc_update_character()와 달리 revision을 올리지 않는다. 매일 갱신 스크립트가
// 수십~수백 명을 갱신하는 동안 매번 revision을 올리면 접속 중인 브라우저가
// 그만큼 여러 번 다시 그리게 된다 — 그래서 여러 캐릭터를 갱신하는 호출자가
// 전부 끝난 뒤 fc_bump_revision()을 한 번만 부르는 패턴을 쓴다.
// 이름은 바꾸지 않으므로 이름 중복 검사도 필요 없다.
function fc_update_character_lookup_result(PDO $pdo, $id, array $info) {
    $pdo->prepare("UPDATE fc_characters
                   SET char_class = ?, atul_score = ?, item_level = ?, atul_updated_at = ?
                   WHERE id = ?")
        ->execute([
            (string)$info['class'],
            $info['atul'] === null ? null : (int)$info['atul'],
            $info['item_level'] === null ? null : (int)$info['item_level'],
            date('Y-m-d H:i:s'),
            $id,
        ]);
}

// 매일 갱신 스크립트의 본체. $lookup: function(string $name): ?array{class,atul,item_level}
// (fc_atul_lookup과 같은 형태). 조회 실패($lookup이 null을 돌려주면)는 기존 값을 그대로
// 둔다 — 외부 API 점검·순단·개명 등으로 흔히 일어나는데, 그때마다 값을 비우면 하루 만에
// 전체 명단의 전투력이 날아갈 수 있다. 임시 캐릭터(is_placeholder=1)는 외부 API에 없는
// 이름이므로 애초에 조회하지 않는다.
function fc_refresh_all_atul(PDO $pdo, $lookup, $sleepMs = 300) {
    $rows = $pdo->query("SELECT id, char_name, is_placeholder FROM fc_characters ORDER BY id")->fetchAll();

    $updated = 0;
    $failed  = 0;
    $skipped = 0;
    $first   = true;

    foreach ($rows as $row) {
        if ((int)$row['is_placeholder'] === 1) {
            $skipped++;
            continue;
        }

        if (!$first && $sleepMs > 0) usleep($sleepMs * 1000);
        $first = false;

        $got = is_callable($lookup) ? call_user_func($lookup, $row['char_name']) : null;
        // 배열이 왔다고 무조건 성공은 아니다. 외부 API가 200을 주면서
        // profile.combatPower가 비어 있는 경우 atul 키가 없거나 0으로 온다 —
        // 그런 응답으로 기존의 좋은 값을 0으로 덮어쓰면 이 함수가 막으려던 사고가
        // 그대로 재현된다. atul이 1 이상일 때만 성공으로 친다.
        $atulValid = is_array($got) && isset($got['atul']) && $got['atul'] !== null && (int)$got['atul'] > 0;
        if (!$atulValid) {
            $failed++;
            continue;
        }

        fc_update_character_lookup_result($pdo, (int)$row['id'], [
            'class'      => isset($got['class']) ? $got['class'] : '',
            'atul'       => isset($got['atul']) ? $got['atul'] : null,
            'item_level' => isset($got['item_level']) ? $got['item_level'] : null,
        ]);
        $updated++;
    }

    fc_bump_revision($pdo);

    return ['updated' => $updated, 'failed' => $failed, 'skipped' => $skipped];
}

// 본캐를 지우면 남은 캐릭터 중 sort_order가 가장 작은 것이 본캐를 승계한다.
// 승계자가 없으면(마지막 캐릭터였으면) 고아 fc_players 행도 함께 지운다.
// 이걸 빼먹으면 그 플레이어의 남은 부캐가 FC.mainOf()에서 찾아지지 않아
// 대기창/명단 관리 어디에도 나타나지 않는 유령이 된다 (app.js의 `if (!main) return;`).
function fc_delete_character(PDO $pdo, $id) {
    $cur = $pdo->prepare("SELECT player_id, is_main FROM fc_characters WHERE id = ?");
    $cur->execute([$id]);
    $row = $cur->fetch();
    if (!$row) return;
    $playerId = (int)$row['player_id'];
    $wasMain  = (int)$row['is_main'] === 1;

    // 배치된 슬롯은 비우되 슬롯 행 자체는 남긴다 (포스의 칸 구조는 유지)
    $pdo->prepare("UPDATE fc_slots SET character_id = NULL WHERE character_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM fc_characters WHERE id = ?")->execute([$id]);

    if ($wasMain) {
        $next = $pdo->prepare("SELECT id FROM fc_characters WHERE player_id = ?
                               ORDER BY sort_order, id LIMIT 1");
        $next->execute([$playerId]);
        $nextId = $next->fetchColumn();
        if ($nextId !== false) {
            $pdo->prepare("UPDATE fc_characters SET is_main = 1 WHERE id = ?")->execute([(int)$nextId]);
        } else {
            $pdo->prepare("DELETE FROM fc_players WHERE id = ?")->execute([$playerId]);
        }
    }

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

function fc_slot_ids(PDO $pdo, $forceId) {
    $st = $pdo->prepare("SELECT id, party_no, slot_no FROM fc_slots
                         WHERE force_id = ? ORDER BY party_no, slot_no");
    $st->execute([$forceId]);
    return $st->fetchAll();
}

function fc_assign_slot(PDO $pdo, $slotId, $characterId) {
    $cid = ($characterId === null || $characterId === '' || (int)$characterId === 0) ? null : (int)$characterId;
    $pdo->prepare("UPDATE fc_slots SET character_id = ? WHERE id = ?")->execute([$cid, $slotId]);
    fc_bump_revision($pdo);
}

// 빈 슬롯과의 교체도 지원한다 — 그 경우 사실상 이동이 된다.
function fc_swap_slots(PDO $pdo, $slotIdA, $slotIdB) {
    if ((int)$slotIdA === (int)$slotIdB) return;
    $st = $pdo->prepare("SELECT id, character_id FROM fc_slots WHERE id IN (?, ?)");
    $st->execute([$slotIdA, $slotIdB]);
    $rows = $st->fetchAll();
    if (count($rows) !== 2) throw new RuntimeException('slot_not_found');

    $byId = [];
    foreach ($rows as $r) { $byId[(int)$r['id']] = $r['character_id']; }

    $upd = $pdo->prepare("UPDATE fc_slots SET character_id = ? WHERE id = ?");
    $upd->execute([$byId[(int)$slotIdB], $slotIdA]);
    $upd->execute([$byId[(int)$slotIdA], $slotIdB]);
    fc_bump_revision($pdo);
}

// 순수 함수 — DB를 모른다. 같은 레이드 안에서 같은 캐릭터가 두 번 이상 배치된 경우를 찾는다.
// 같은 포스 안의 중복도 잡는다.
function fc_duplicates(array $forces, array $slots) {
    $raidOfForce = [];
    foreach ($forces as $f) { $raidOfForce[(int)$f['id']] = (int)$f['raid_id']; }

    $seen = [];   // raid_id => character_id => [force_id, ...]
    foreach ($slots as $s) {
        if ($s['character_id'] === null) continue;
        $fid = (int)$s['force_id'];
        if (!isset($raidOfForce[$fid])) continue;
        $rid = $raidOfForce[$fid];
        $cid = (int)$s['character_id'];
        if (!isset($seen[$rid])) $seen[$rid] = [];
        if (!isset($seen[$rid][$cid])) $seen[$rid][$cid] = [];
        $seen[$rid][$cid][] = $fid;
    }

    $out = [];
    foreach ($seen as $rid => $byChar) {
        foreach ($byChar as $cid => $forceIds) {
            if (count($forceIds) < 2) continue;
            if (!isset($out[(string)$rid])) $out[(string)$rid] = [];
            $out[(string)$rid][] = [
                'character_id' => (int)$cid,
                'force_ids'    => array_values(array_unique(array_map('intval', $forceIds))),
            ];
        }
    }
    return $out;
}

function fc_state(PDO $pdo) {
    $players = $pdo->query("SELECT id, sort_order FROM fc_players ORDER BY sort_order, id")->fetchAll();

    $characters = $pdo->query(
        "SELECT id, player_id, char_name AS name, char_class AS class,
                atul_score AS atul, item_level, is_main, is_placeholder, sort_order
         FROM fc_characters ORDER BY player_id, sort_order, id")->fetchAll();

    $raids  = $pdo->query("SELECT id, name, memo, sort_order FROM fc_raids ORDER BY sort_order, id")->fetchAll();
    $forces = $pdo->query("SELECT id, raid_id, force_no, day_of_week, start_time, memo, sort_order
                           FROM fc_forces ORDER BY raid_id, force_no, id")->fetchAll();
    $slots  = $pdo->query("SELECT id, force_id, party_no, slot_no, character_id
                           FROM fc_slots ORDER BY force_id, party_no, slot_no")->fetchAll();

    // 숫자 컬럼을 JSON에서 정수로 나가게 정리한다 (PDO는 문자열로 준다)
    $toInt = function (&$rows, array $intCols, array $nullableIntCols = []) {
        foreach ($rows as &$r) {
            foreach ($intCols as $c) { $r[$c] = (int)$r[$c]; }
            foreach ($nullableIntCols as $c) { $r[$c] = $r[$c] === null ? null : (int)$r[$c]; }
        }
        unset($r);
    };
    $toInt($players, ['id', 'sort_order']);
    $toInt($characters, ['id', 'player_id', 'is_main', 'is_placeholder', 'sort_order'], ['atul', 'item_level']);
    $toInt($raids, ['id', 'sort_order']);
    $toInt($forces, ['id', 'raid_id', 'force_no', 'sort_order']);
    $toInt($slots, ['id', 'force_id', 'party_no', 'slot_no'], ['character_id']);

    return [
        'revision'   => fc_revision($pdo),
        'players'    => $players,
        'characters' => $characters,
        'raids'      => $raids,
        'forces'     => $forces,
        'slots'      => $slots,
        'duplicates' => fc_duplicates($forces, $slots),
    ];
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
