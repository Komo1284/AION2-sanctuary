<?php
// HTTP/JSON 경계. 요청을 검증하고 store를 호출한다. SQL을 직접 쓰지 않는다.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/atul.php';

function fc_req_int(array $req, $key) {
    if (!isset($req[$key])) return 0;
    // 배열/객체를 (int)로 캐스팅하면 PHP가 조용히 1(비어있지 않음) 또는 0으로 뭉갠다.
    // 예: force.delete에 force_id:[99999]를 보내면 (int)[99999] === 1이 되어 엉뚱한
    // 1번 포스가 삭제된다. fc_req_str과 대칭으로 여기서도 명시적으로 막는다.
    if (is_array($req[$key]) || is_object($req[$key])) throw new RuntimeException('bad_request');
    return (int)$req[$key];
}

function fc_req_str(array $req, $key, $default = '') {
    if (!isset($req[$key]) || $req[$key] === null) return $default;
    if (is_array($req[$key]) || is_object($req[$key])) throw new RuntimeException('bad_request');
    return trim((string)$req[$key]);
}

// store로 넘길 $fields를 채우기 전에 배열/객체를 걸러낸다. store는 값을 그대로
// 바인딩만 하고 타입을 검사하지 않으므로, 여기서 막지 않으면 (예: atul에 배열을
// 넣으면) 조용히 잘못된 값으로 덮어써진다. null은 "값 비우기"로 허용한다.
function fc_req_scalar($value) {
    if ($value === null) return null;
    if (is_array($value) || is_object($value)) throw new RuntimeException('bad_request');
    return $value;
}

// HTTP를 모르는 순수 디스패처. 실패는 RuntimeException으로 던진다.
function fc_api_dispatch(PDO $pdo, array $req, $lookup) {
    $action = fc_req_str($req, 'action');

    switch ($action) {
        case 'state':
            return fc_state($pdo);

        case 'player.create':
            $main = fc_req_str($req, 'main_name');
            if ($main === '') throw new RuntimeException('bad_request');
            $subs = isset($req['subs']) && is_array($req['subs']) ? $req['subs'] : [];
            return ['player_id' => fc_create_player($pdo, $main, $subs, $lookup)];

        case 'player.delete':
            $pid = fc_req_int($req, 'player_id');
            if ($pid <= 0) throw new RuntimeException('bad_request');
            fc_delete_player($pdo, $pid);
            return ['deleted' => $pid];

        case 'character.add':
            $pid  = fc_req_int($req, 'player_id');
            $name = fc_req_str($req, 'name');
            if ($pid <= 0 || $name === '') throw new RuntimeException('bad_request');
            $isPh = !empty($req['is_placeholder']);
            return ['character_id' => fc_add_character($pdo, $pid, $name, $lookup, $isPh)];

        case 'character.update':
            $cid = fc_req_int($req, 'character_id');
            if ($cid <= 0) throw new RuntimeException('bad_request');
            $fields = [];
            foreach (['name', 'class', 'atul', 'item_level', 'is_placeholder'] as $k) {
                if (array_key_exists($k, $req)) $fields[$k] = fc_req_scalar($req[$k]);
            }
            fc_update_character($pdo, $cid, $fields);
            return ['updated' => $cid];

        // 임시 캐릭터를 실제 캐릭터로 확정한다. 이름을 바꾸고 아툴을 조회해 한 번에 채운다.
        // 배치된 슬롯은 character_id를 가리키므로 그대로 유지된다.
        case 'character.promote':
            $cid  = fc_req_int($req, 'character_id');
            $name = fc_req_str($req, 'name');
            if ($cid <= 0 || $name === '') throw new RuntimeException('bad_request');
            $info = is_callable($lookup) ? call_user_func($lookup, $name) : null;
            $fields = ['name' => $name, 'is_placeholder' => 0];
            if (is_array($info)) {
                $fields['class']      = $info['class'];
                $fields['atul']       = $info['atul'];
                $fields['item_level'] = $info['item_level'];
            }
            fc_update_character($pdo, $cid, $fields);
            return ['character_id' => $cid, 'looked_up' => is_array($info)];

        case 'character.delete':
            $cid = fc_req_int($req, 'character_id');
            if ($cid <= 0) throw new RuntimeException('bad_request');
            fc_delete_character($pdo, $cid);
            return ['deleted' => $cid];

        case 'raid.create':
            $name = fc_req_str($req, 'name');
            if ($name === '') throw new RuntimeException('bad_request');
            return ['raid_id' => fc_create_raid($pdo, $name)];

        case 'raid.update':
            $rid = fc_req_int($req, 'raid_id');
            if ($rid <= 0) throw new RuntimeException('bad_request');
            $fields = [];
            foreach (['name', 'memo'] as $k) {
                if (array_key_exists($k, $req)) $fields[$k] = fc_req_scalar($req[$k]);
            }
            fc_update_raid($pdo, $rid, $fields);
            return ['updated' => $rid];

        case 'raid.delete':
            $rid = fc_req_int($req, 'raid_id');
            if ($rid <= 0) throw new RuntimeException('bad_request');
            fc_delete_raid($pdo, $rid);
            return ['deleted' => $rid];

        case 'force.create':
            $rid = fc_req_int($req, 'raid_id');
            if ($rid <= 0) throw new RuntimeException('bad_request');
            return ['force_id' => fc_create_force(
                $pdo, $rid, fc_req_str($req, 'day_of_week'),
                fc_req_str($req, 'start_time'), fc_req_str($req, 'memo')
            )];

        case 'force.update':
            $fid = fc_req_int($req, 'force_id');
            if ($fid <= 0) throw new RuntimeException('bad_request');
            $fields = [];
            foreach (['day_of_week', 'start_time', 'memo'] as $k) {
                if (array_key_exists($k, $req)) $fields[$k] = fc_req_scalar($req[$k]);
            }
            fc_update_force($pdo, $fid, $fields);
            return ['updated' => $fid];

        case 'force.delete':
            $fid = fc_req_int($req, 'force_id');
            if ($fid <= 0) throw new RuntimeException('bad_request');
            fc_delete_force($pdo, $fid);
            return ['deleted' => $fid];

        case 'slot.assign':
            $sid = fc_req_int($req, 'slot_id');
            if ($sid <= 0) throw new RuntimeException('bad_request');
            $cid = array_key_exists('character_id', $req) && $req['character_id'] !== null
                 ? (int)$req['character_id'] : null;
            fc_assign_slot($pdo, $sid, $cid);
            return ['slot_id' => $sid, 'character_id' => $cid];

        case 'slot.swap':
            $a = fc_req_int($req, 'slot_id_a');
            $b = fc_req_int($req, 'slot_id_b');
            if ($a <= 0 || $b <= 0) throw new RuntimeException('bad_request');
            fc_swap_slots($pdo, $a, $b);
            return ['slot_id_a' => $a, 'slot_id_b' => $b];

        case 'atul.refresh':
            $cid = fc_req_int($req, 'character_id');
            if ($cid <= 0) throw new RuntimeException('bad_request');
            $st = $pdo->prepare("SELECT char_name FROM fc_characters WHERE id = ?");
            $st->execute([$cid]);
            $name = $st->fetchColumn();
            if ($name === false) throw new RuntimeException('not_found');
            $info = is_callable($lookup) ? call_user_func($lookup, $name) : null;
            if (!is_array($info)) throw new RuntimeException('lookup_failed');
            fc_update_character($pdo, $cid, [
                'class'      => $info['class'],
                'atul'       => $info['atul'],
                'item_level' => $info['item_level'],
            ]);
            return ['character_id' => $cid, 'atul' => $info['atul'], 'class' => $info['class']];
    }

    throw new RuntimeException('unknown_action');
}

// 이 파일이 직접 호출되었을 때만 HTTP 응답을 낸다 (test_api.php에서 require해도 안전하게)
if (isset($_SERVER['REQUEST_METHOD']) && basename($_SERVER['SCRIPT_FILENAME']) === 'api.php') {
    session_start();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if (empty($_SESSION['sanctuary_site_auth'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Content-Type이 application/json이 아니면 거부한다. text/plain으로 보내는 요청은
    // <form enctype="text/plain">으로 브라우저가 프리플라이트 없이 실어 나를 수 있어
    // (로그인된 운영자가 공격자 페이지를 열면 raid.delete/player.delete가 실행되는) CSRF에
    // 노출된다. PHP 7.4라 str_starts_with 대신 strpos를 쓴다.
    $contentType = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
    if (strpos($contentType, 'application/json') !== 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_content_type'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $raw = file_get_contents('php://input');
    $req = json_decode($raw, true);
    if (!is_array($req)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'bad_json'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    try {
        $pdo = fc_pdo();
        fc_init_schema($pdo);
        $data = fc_api_dispatch($pdo, $req, 'fc_atul_lookup');
        echo json_encode(['ok' => true, 'data' => $data, 'revision' => fc_revision($pdo)],
                         JSON_UNESCAPED_UNICODE);
    } catch (PDOException $e) {
        // PDOException은 RuntimeException을 상속하므로 아래 catch(RuntimeException)보다
        // 먼저 잡아야 한다. 안 그러면 DB 장애가 400 + 드라이버 원문(SQLSTATE 등)으로
        // 그대로 노출되고, JSON 파싱은 성공하니 FC.setConnected(true)가 불려 연결 끊김
        // 배너도 안 뜬다.
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'server_error'], JSON_UNESCAPED_UNICODE);
    } catch (RuntimeException $e) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'server_error'], JSON_UNESCAPED_UNICODE);
    }
}
