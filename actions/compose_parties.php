<?php
/**
 * [자동 파티 구성 알고리즘 — 비활성화됨]
 *
 * 본캐/부캐 동일 포스 배정 버그 등 알고리즘 문제로 인해 비활성화.
 * 대신 관리자 드래그앤드롭 수동 배정 방식으로 전환.
 * 재활성화 필요 시 주석을 해제하세요.
 */
return; // ← 수동 배정 방식 사용 중: 이 파일은 실행되지 않습니다.

/*
/**
 * 파티 구성 알고리즘 v5
 *
 * 규칙 (우선순위 순):
 * 1. 포스 수 F = min(floor(치유수/2), floor(전체캐릭수/8)), 최소 1
 * 2. 치유성을 점수 높은 순으로 스네이크 드래프트 배정 (1F1P→2F1P→…→NFP1→NFP2→…→1F2P), 배정 후 이동 불가
 * 3. 배치된 치유의 깐부를 같은 파티에 배정 (combined avg 기준 균형 선택)
 * 4. 나머지 캐릭터(미배정 치유 포함)를 점수 내림차순으로 포스avg 낮은 포스 → 파티avg 낮은 파티 순으로 배정
 * 5. 4번 배정 시 깐부가 있으면 같은 파티에 배정 (파티 슬롯 부족 시 다음 최적 파티)
 * 6. 같은 플레이어 캐릭터는 한 포스에 1개만 참가
 * 7. 치유성 아툴 70% 보정 (계산·표시)
 */

$season_id = (int)($_POST['season_id'] ?? $current_season_id);

try {
    $pdo->beginTransaction();

    // ── 1. 기존 포스/파티/멤버 삭제 ─────────────────────────────────────
    $ef = $pdo->prepare("SELECT id FROM sanctuary_forces WHERE season_id = ?");
    $ef->execute([$season_id]);
    foreach ($ef->fetchAll(PDO::FETCH_COLUMN) as $fid) {
        $ep = $pdo->prepare("SELECT id FROM sanctuary_parties WHERE force_id = ?");
        $ep->execute([$fid]);
        foreach ($ep->fetchAll(PDO::FETCH_COLUMN) as $pid) {
            $pdo->prepare("DELETE FROM sanctuary_party_members WHERE party_id = ?")->execute([$pid]);
        }
        $pdo->prepare("DELETE FROM sanctuary_parties WHERE force_id = ?")->execute([$fid]);
    }
    $pdo->prepare("DELETE FROM sanctuary_forces WHERE season_id = ?")->execute([$season_id]);

    // ── 2. 이전 깐부 합성 application 정리 ───────────────────────────────
    $synth = $pdo->prepare("SELECT id FROM sanctuary_applications WHERE season_id = ? AND applicant_ip = 'buddy_synthesized'");
    $synth->execute([$season_id]);
    foreach ($synth->fetchAll(PDO::FETCH_COLUMN) as $aid) {
        $pdo->prepare("DELETE FROM sanctuary_characters WHERE application_id = ?")->execute([$aid]);
    }
    $pdo->prepare("DELETE FROM sanctuary_applications WHERE season_id = ? AND applicant_ip = 'buddy_synthesized'")->execute([$season_id]);

    // ── 3. 깐부 데이터 로드 ──────────────────────────────────────────────
    $bq = $pdo->prepare("
        SELECT sb.application_id AS orig_app_id, sb.buddy_group,
               sb.buddy_name, sb.buddy_class, sb.buddy_score, sb.is_main
        FROM sanctuary_buddies sb
        JOIN sanctuary_applications sa ON sa.id = sb.application_id
        WHERE sa.season_id = ?
          AND sb.buddy_name != ''
          AND sb.buddy_score > 0
        ORDER BY sb.application_id, sb.buddy_group, sb.is_main DESC, sb.buddy_score DESC
    ");
    $bq->execute([$season_id]);
    $raw_buddy = $bq->fetchAll();

    // ── 4. 깐부 캐릭터 → sanctuary_characters 삽입 (buddy_synthesized app) ──
    $buddy_app_map     = []; // "orig_app_id_buddy_group" => new_app_id
    $buddy_app_reverse = []; // new_app_id => ['orig_app_id' => int, 'buddy_group' => int]

    foreach ($raw_buddy as $bc) {
        $key = $bc['orig_app_id'] . '_' . $bc['buddy_group'];
        if (!isset($buddy_app_map[$key])) {
            $pdo->prepare("INSERT INTO sanctuary_applications (season_id, applicant_ip, status, applied_at) VALUES (?, 'buddy_synthesized', '대기', NOW())")
                ->execute([$season_id]);
            $new_id = (int)$pdo->lastInsertId();
            $buddy_app_map[$key] = $new_id;
            $buddy_app_reverse[$new_id] = [
                'orig_app_id'  => (int)$bc['orig_app_id'],
                'buddy_group'  => (int)$bc['buddy_group'],
            ];
        }
        $pdo->prepare("INSERT INTO sanctuary_characters (application_id, char_name, char_class, atul_score, is_main) VALUES (?, ?, ?, ?, ?)")
            ->execute([$buddy_app_map[$key], $bc['buddy_name'], $bc['buddy_class'], (int)$bc['buddy_score'], (int)$bc['is_main']]);
    }

    // ── 5. 전체 캐릭터 로드 ──────────────────────────────────────────────
    $cq = $pdo->prepare("
        SELECT sc.id, sc.char_name, sc.char_class, sc.atul_score, sc.is_main, sa.id AS app_id
        FROM sanctuary_characters sc
        JOIN sanctuary_applications sa ON sa.id = sc.application_id
        WHERE sa.season_id = ?
        ORDER BY sc.atul_score DESC
    ");
    $cq->execute([$season_id]);
    $raw_all = $cq->fetchAll();

    if (empty($raw_all)) {
        $pdo->rollBack();
        $message = '신청된 캐릭터가 없습니다.';
        $message_type = 'error';
        return;
    }

    // ── 6. player_key 부여 ──────────────────────────────────────────────
    // 직접 신청자: "app_{app_id}"   깐부: "buddy_{orig_app_id}_{buddy_group}"
    $all_chars = [];
    foreach ($raw_all as $c) {
        $aid = (int)$c['app_id'];
        if (isset($buddy_app_reverse[$aid])) {
            $orig = $buddy_app_reverse[$aid]['orig_app_id'];
            $grp  = $buddy_app_reverse[$aid]['buddy_group'];
            $c['player_key'] = "buddy_{$orig}_{$grp}";
        } else {
            $c['player_key'] = "app_{$aid}";
        }
        $c['app_id'] = $aid;
        $all_chars[] = $c;
    }

    // ── 7. 플레이어별 캐릭터 풀 구성 ────────────────────────────────────
    $player_chars = []; // player_key => [chars, ...]
    foreach ($all_chars as $c) {
        $player_chars[$c['player_key']][] = $c;
    }
    foreach ($player_chars as &$pc) {
        usort($pc, fn($a, $b) => $b['is_main'] - $a['is_main'] ?: $b['atul_score'] - $a['atul_score']);
    }
    unset($pc);

    // ── 8. 깐부 링크 구성 (bidirectional) ──────────────────────────────
    $buddy_links = []; // player_key => [buddy_player_keys]
    foreach ($buddy_app_map as $key => $new_app_id) {
        [$orig_str, $grp_str] = explode('_', $key, 2);
        $applicant_pk = "app_{$orig_str}";
        $buddy_pk     = "buddy_{$orig_str}_{$grp_str}";
        $buddy_links[$applicant_pk][] = $buddy_pk;
        $buddy_links[$buddy_pk][]     = $applicant_pk;
    }
    foreach ($buddy_links as &$bl) $bl = array_values(array_unique($bl));
    unset($bl);

    // ── 9. 포스 수 계산 ─────────────────────────────────────────────────
    $total_cnt  = count($all_chars);
    $healer_cnt = count(array_filter($all_chars, fn($c) => $c['char_class'] === '치유성'));

    if ($healer_cnt < 2) {
        $F = max(1, (int)floor($total_cnt / 8));
    } else {
        $F = min((int)floor($healer_cnt / 2), (int)floor($total_cnt / 8));
        $F = max(1, $F);
    }

    // ── 10. 캐릭터 제외 (2단계) ─────────────────────────────────────────
    // 총 슬롯 = F × 8.
    //
    // 보호 대상 (제외 불가):
    //   - 본캐 (is_main = 1): 항상 보호
    //   - 상위 2*F 치유성 (점수 높은 순): 스네이크 드래프트 배정에 필요하므로 보호
    //
    // 1단계: 물리적 불가능 제외 (per-player)
    //   - 플레이어 캐릭 수 > F → 보호 캐릭터 제외하고 최저 점수 부캐부터 제외
    // 2단계: 전역 부캐 제외
    //   - 1단계 후에도 총 캐릭 수 > F×8 → 보호 대상 제외, 점수 낮은 순으로 추가 제외

    $total_slots = $F * 8;

    // 스네이크 배정에 필요한 치유성 상위 2*F명을 보호
    $all_healers_sorted = array_values(array_filter($all_chars, fn($c) => $c['char_class'] === '치유성'));
    usort($all_healers_sorted, fn($a, $b) => $b['atul_score'] <=> $a['atul_score']);
    $reserved_healer_ids = [];
    foreach (array_slice($all_healers_sorted, 0, $F * 2) as $rh) {
        $reserved_healer_ids[$rh['id']] = true;
    }

    // 제외 가능 여부 판단 함수
    $can_exclude = fn(array $c): bool =>
        !$c['is_main'] && !isset($reserved_healer_ids[$c['id']]);

    $excluded_ids = [];

    // ▸ 1단계: 물리적 불가능 (per-player)
    foreach ($player_chars as $pk => $pc) {
        if (count($pc) <= $F) continue;

        // 제외 대상: 보호 대상이 아닌 부캐 → 점수 낮은 순으로 초과분만큼 제외
        $excl_candidates = array_values(array_filter($pc, $can_exclude));
        usort($excl_candidates, fn($a, $b) => $a['atul_score'] <=> $b['atul_score']);

        $excess = count($pc) - $F;
        for ($i = 0; $i < $excess && $i < count($excl_candidates); $i++) {
            $excluded_ids[$excl_candidates[$i]['id']] = true;
        }
    }

    // 풀 갱신
    foreach ($player_chars as &$pc) {
        $pc = array_values(array_filter($pc, fn($c) => !isset($excluded_ids[$c['id']])));
    }
    unset($pc);
    $player_chars = array_filter($player_chars, fn($pc) => !empty($pc));

    // ▸ 2단계: 전역 부캐 제외 (총 수 > F×8 인 경우)
    $cur_total = array_sum(array_map('count', $player_chars));
    if ($cur_total > $total_slots) {
        $excess = $cur_total - $total_slots;

        // 보호 대상 제외하고 점수 낮은 순으로 정렬
        $global_excl_candidates = [];
        foreach ($player_chars as $pc) {
            foreach ($pc as $c) {
                if ($can_exclude($c) && !isset($excluded_ids[$c['id']])) {
                    $global_excl_candidates[] = $c;
                }
            }
        }
        usort($global_excl_candidates, fn($a, $b) => $a['atul_score'] <=> $b['atul_score']);

        for ($i = 0; $i < $excess && $i < count($global_excl_candidates); $i++) {
            $excluded_ids[$global_excl_candidates[$i]['id']] = true;
        }

        // 풀 재갱신
        foreach ($player_chars as &$pc) {
            $pc = array_values(array_filter($pc, fn($c) => !isset($excluded_ids[$c['id']])));
        }
        unset($pc);
        $player_chars = array_filter($player_chars, fn($pc) => !empty($pc));
    }

    // 최종 캐릭터 목록
    $final_chars = [];
    foreach ($player_chars as $pc) {
        foreach ($pc as $c) $final_chars[] = $c;
    }
    $char_map = [];
    foreach ($final_chars as $c) $char_map[$c['id']] = $c;

    if (count($final_chars) < 2) {
        $pdo->rollBack();
        $message = '캐릭터 수가 너무 적어 포스를 구성할 수 없습니다.';
        $message_type = 'error';
        return;
    }

    // ── 11. 포스/파티 구조 초기화 ───────────────────────────────────────
    $forces = [];
    for ($f = 0; $f < $F; $f++) {
        $forces[$f] = [0 => [], 1 => []];
    }

    $assigned        = []; // char_id => true
    $player_force_map = []; // player_key => [force_indices already used]

    // 유틸리티 ─────────────────────────────────────────────────────────
    $eff_score = fn(array $c): float =>
        ($c['char_class'] === '치유성') ? $c['atul_score'] * 0.7 : (float)$c['atul_score'];

    $party_eff_avg = function(int $f, int $p) use (&$forces, $eff_score): float {
        if (empty($forces[$f][$p])) return 0.0;
        return array_sum(array_map($eff_score, $forces[$f][$p])) / count($forces[$f][$p]);
    };

    $force_eff_avg = function(int $f) use (&$forces, $eff_score): float {
        $all = array_merge($forces[$f][0], $forces[$f][1]);
        if (empty($all)) return 0.0;
        return array_sum(array_map($eff_score, $all)) / count($all);
    };

    $do_assign = function(array $char, int $f, int $p) use (&$forces, &$assigned, &$player_force_map): void {
        $assigned[$char['id']] = true;
        $forces[$f][$p][] = $char;
        $pk = $char['player_key'];
        if (!isset($player_force_map[$pk])) $player_force_map[$pk] = [];
        if (!in_array($f, $player_force_map[$pk], true)) $player_force_map[$pk][] = $f;
    };

    // 깐부 캐릭터 배정 도우미: 배치된 $anchor 와 같은 파티에 깐부의 최적 캐릭터 배정
    // 균형 공식: target = 2 * combined_avg - anchor_eff → 그에 가장 가까운 깐부 캐릭터 선택
    $place_buddy_chars = function(
        array $anchor, int $af, int $ap,
        array $buddy_keys
    ) use (
        &$forces, &$assigned, &$player_force_map,
        &$player_chars, $do_assign, $eff_score
    ): void {
        $anchor_pk  = $anchor['player_key'];
        $anchor_eff = $eff_score($anchor);

        foreach ($buddy_keys as $bpk) {
            if (count($forces[$af][$ap]) >= 4) break;
            if (in_array($af, $player_force_map[$bpk] ?? [], true)) continue;

            $bchars_unplaced = array_values(array_filter(
                $player_chars[$bpk] ?? [],
                fn($c) => !isset($assigned[$c['id']])
            ));
            if (empty($bchars_unplaced)) continue;

            // combined avg (실제 atul_score 기준, 효과점수 아님)
            $a_scores = array_map(fn($c) => (float)$c['atul_score'], $player_chars[$anchor_pk] ?? []);
            $b_scores = array_map(fn($c) => (float)$c['atul_score'], $player_chars[$bpk] ?? []);
            $cnt = count($a_scores) + count($b_scores);
            $combined_avg = $cnt > 0 ? (array_sum($a_scores) + array_sum($b_scores)) / $cnt : 0.0;

            $target = 2.0 * $combined_avg - $anchor_eff;

            usort($bchars_unplaced, fn($a, $b) =>
                abs($a['atul_score'] - $target) <=> abs($b['atul_score'] - $target)
            );

            $do_assign($bchars_unplaced[0], $af, $ap);
        }
    };

    // ── 12. 치유성 스네이크 드래프트 배정 ──────────────────────────────
    // 스네이크 순서: F1P1 → F2P1 → … → FFP1 → FFP2 → … → F1P2
    $snake_pos = [];
    for ($f = 0; $f < $F; $f++) $snake_pos[] = [$f, 0];
    for ($f = $F - 1; $f >= 0; $f--) $snake_pos[] = [$f, 1];

    $healers_pool = array_values(array_filter($final_chars, fn($c) => $c['char_class'] === '치유성'));
    usort($healers_pool, fn($a, $b) => $b['atul_score'] <=> $a['atul_score']);

    $placed_healers = []; // [f, p, char] in order placed

    foreach ($snake_pos as [$sf, $sp]) {
        // 이 파티에 이미 치유성 있거나 가득 찼으면 skip
        $heal_here = count(array_filter($forces[$sf][$sp], fn($c) => $c['char_class'] === '치유성'));
        if ($heal_here > 0 || count($forces[$sf][$sp]) >= 4) continue;

        // 가장 높은 점수의 가능한 치유성 찾기
        foreach ($healers_pool as $h) {
            if (isset($assigned[$h['id']])) continue;
            $hpk = $h['player_key'];
            if (in_array($sf, $player_force_map[$hpk] ?? [], true)) continue;

            $do_assign($h, $sf, $sp);
            $placed_healers[] = [$sf, $sp, $h];
            break;
        }
    }

    // ── 13. 배치된 치유성의 깐부를 같은 파티에 배정 ─────────────────────
    foreach ($placed_healers as [$hf, $hp, $h]) {
        $hpk = $h['player_key'];
        $hbuddies = $buddy_links[$hpk] ?? [];
        if (empty($hbuddies)) continue;

        $place_buddy_chars($h, $hf, $hp, $hbuddies);
    }

    // ── 14. 나머지 캐릭터 배정 (목표 avg 편차 최소화) ──────────────────
    // 배치 기준: 이 캐릭터를 추가했을 때 포스 avg가 전체 목표 avg에
    // 가장 가까워지는 포스·파티에 배정.
    // → 치유성 70% 보정으로 인한 avg 왜곡을 자동 보정하고,
    //   고점수 캐릭터가 한 포스에 쏠리는 현상을 방지.
    // 파티 내 균형은 파티 avg 낮은 쪽 우선(보조 기준).

    // 전체 배정 목표 avg (final_chars의 eff_score 평균)
    $total_eff_sum   = array_sum(array_map($eff_score, $final_chars));
    $global_target   = count($final_chars) > 0 ? $total_eff_sum / count($final_chars) : 0.0;

    $pool = array_values(array_filter($final_chars, fn($c) => !isset($assigned[$c['id']])));
    usort($pool, fn($a, $b) => $b['atul_score'] <=> $a['atul_score']);

    foreach ($pool as $char) {
        if (isset($assigned[$char['id']])) continue;

        $cpk      = $char['player_key'];
        $char_eff = $eff_score($char);
        $c_buddy_keys = $buddy_links[$cpk] ?? [];

        // 깐부의 미배정 캐릭터 수집
        $buddy_unplaced_map = [];
        foreach ($c_buddy_keys as $bpk) {
            $bchars = array_values(array_filter(
                $player_chars[$bpk] ?? [],
                fn($c) => !isset($assigned[$c['id']])
            ));
            if (!empty($bchars)) $buddy_unplaced_map[$bpk] = $bchars;
        }
        $has_buddy    = !empty($buddy_unplaced_map);
        $needed_slots = $has_buddy ? 1 + count($buddy_unplaced_map) : 1;

        // 후보 목록 구성
        $candidates = [];
        for ($f = 0; $f < $F; $f++) {
            if (in_array($f, $player_force_map[$cpk] ?? [], true)) continue;

            $buddy_f_ok = true;
            if ($has_buddy) {
                foreach (array_keys($buddy_unplaced_map) as $bpk) {
                    if (in_array($f, $player_force_map[$bpk] ?? [], true)) {
                        $buddy_f_ok = false; break;
                    }
                }
            }

            $f_all     = array_merge($forces[$f][0], $forces[$f][1]);
            $f_eff_sum = array_sum(array_map($eff_score, $f_all));
            $f_count   = count($f_all);
            // 이 캐릭터를 추가했을 때의 포스 avg
            $projected = ($f_eff_sum + $char_eff) / ($f_count + 1);
            $deviation = abs($projected - $global_target);

            for ($p = 0; $p < 2; $p++) {
                $slots = 4 - count($forces[$f][$p]);
                if ($slots < 1) continue;
                $candidates[] = [
                    'f'         => $f,
                    'p'         => $p,
                    'slots'     => $slots,
                    'buddy_ok'  => $buddy_f_ok,
                    'deviation' => $deviation,  // 작을수록 목표 avg에 가까움
                    'p_avg'     => $party_eff_avg($f, $p),
                ];
            }
        }

        // 편차 작은 순, 같으면 파티 avg 낮은 순
        usort($candidates, fn($a, $b) =>
            $a['deviation'] <=> $b['deviation'] ?: $a['p_avg'] <=> $b['p_avg']
        );

        $best_f = null; $best_p = null;

        if ($has_buddy) {
            // ① buddy_ok + needed_slots 충족
            foreach ($candidates as $cand) {
                if ($cand['buddy_ok'] && $cand['slots'] >= $needed_slots) {
                    $best_f = $cand['f']; $best_p = $cand['p']; break;
                }
            }
            // ② buddy_ok + 최소 2슬롯
            if ($best_f === null) {
                foreach ($candidates as $cand) {
                    if ($cand['buddy_ok'] && $cand['slots'] >= 2) {
                        $best_f = $cand['f']; $best_p = $cand['p']; break;
                    }
                }
            }
            // ③ 깐부 제약 무시, 1슬롯만 있어도 배정
            if ($best_f === null) {
                foreach ($candidates as $cand) {
                    if ($cand['slots'] >= 1) {
                        $best_f = $cand['f']; $best_p = $cand['p']; break;
                    }
                }
            }
        } else {
            foreach ($candidates as $cand) {
                if ($cand['slots'] >= 1) {
                    $best_f = $cand['f']; $best_p = $cand['p']; break;
                }
            }
        }

        if ($best_f === null) continue;

        $do_assign($char, $best_f, $best_p);

        // 깐부 배정
        if ($has_buddy) {
            $place_buddy_chars($char, $best_f, $best_p, array_keys($buddy_unplaced_map));
        }
    }

    // ── 14.5 최후 보루: 미배정 캐릭터 강제 배정 ────────────────────────
    // 플레이어 유일성·깐부 제약을 무시하고 빈 슬롯에 점수 높은 순 강제 배정.
    // 정상 로직에서 같은 플레이어의 다른 캐릭터가 마지막 빈 슬롯을 먼저 차지하여
    // player_force_map 충돌로 배정 불가가 된 경우에만 발동.
    $leftover = array_values(array_filter($final_chars, fn($c) => !isset($assigned[$c['id']])));
    usort($leftover, fn($a, $b) => $b['atul_score'] <=> $a['atul_score']);
    foreach ($leftover as $lc) {
        if (isset($assigned[$lc['id']])) continue;
        for ($f = 0; $f < $F; $f++) {
            for ($p = 0; $p < 2; $p++) {
                if (count($forces[$f][$p]) < 4) {
                    $do_assign($lc, $f, $p);
                    break 2;
                }
            }
        }
    }

    // ── 15. 포스 평균 계산 및 번호 부여 (효과 평균 높은 순) ─────────────
    $force_avgs = [];
    for ($f = 0; $f < $F; $f++) {
        $all_in = array_merge($forces[$f][0], $forces[$f][1]);
        $force_avgs[$f] = empty($all_in) ? 0.0
            : array_sum(array_map($eff_score, $all_in)) / count($all_in);
    }
    arsort($force_avgs);

    // ── 16. DB 저장 ──────────────────────────────────────────────────────
    $force_number = 1;
    foreach ($force_avgs as $fi => $f_avg) {
        $pdo->prepare("INSERT INTO sanctuary_forces (season_id, force_number, avg_atul) VALUES (?, ?, ?)")
            ->execute([$season_id, $force_number, round($f_avg)]);
        $force_id = (int)$pdo->lastInsertId();

        for ($pi = 0; $pi < 2; $pi++) {
            $pmembers = $forces[$fi][$pi];
            $p_effs   = empty($pmembers) ? [0.0] : array_map($eff_score, $pmembers);
            $p_avg    = empty($pmembers) ? 0 : array_sum($p_effs) / count($pmembers);

            $pdo->prepare("INSERT INTO sanctuary_parties (force_id, party_number, avg_atul) VALUES (?, ?, ?)")
                ->execute([$force_id, $pi + 1, round($p_avg)]);
            $party_id = (int)$pdo->lastInsertId();

            foreach ($pmembers as $m) {
                $pdo->prepare("INSERT INTO sanctuary_party_members (party_id, character_id) VALUES (?, ?)")
                    ->execute([$party_id, $m['id']]);

                // 치유성 70% 보정값 저장 (표시용)
                if ($m['char_class'] === '치유성') {
                    $pdo->prepare("UPDATE sanctuary_characters SET atul_adjusted = ? WHERE id = ?")
                        ->execute([round($m['atul_score'] * 0.7), $m['id']]);
                } else {
                    $pdo->prepare("UPDATE sanctuary_characters SET atul_adjusted = NULL WHERE id = ?")
                        ->execute([$m['id']]);
                }
            }
        }

        $force_number++;
    }

    $pdo->commit();
    $message = "파티 구성 완료! 총 {$F}개 포스가 생성되었습니다.";
    $message_type = 'success';

    header("Location: index.php?tab=season&season={$season_id}&nav=admin&msg=composed");
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $message = '파티 구성 중 오류: ' . $e->getMessage();
    $message_type = 'error';
}
*/
