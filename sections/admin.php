<?php
$season_status = $current_season['status'] ?? '구성중';
if ($season_status === '모집중') $season_status = '구성중';

// 통계 - 신청자 캐릭터 (buddy_synthesized 제외)
$stats = $pdo->prepare("
    SELECT
        COUNT(DISTINCT sa.id)                                        AS total_apps,
        COUNT(CASE WHEN sc.is_main = 1 THEN 1 END)                  AS total_main,
        COUNT(CASE WHEN sc.is_main = 0 THEN 1 END)                  AS total_sub,
        COUNT(CASE WHEN sc.char_class = '치유성' THEN 1 END)        AS total_heal
    FROM sanctuary_applications sa
    LEFT JOIN sanctuary_characters sc ON sc.application_id = sa.id
    WHERE sa.season_id = ? AND sa.applicant_ip != 'buddy_synthesized'
");
$stats->execute([$current_season_id]);
$stat = $stats->fetch();

$total_main  = (int)($stat['total_main'] ?? 0);
$total_sub   = (int)($stat['total_sub']  ?? 0);
$total_heal  = (int)($stat['total_heal'] ?? 0);
$total_chars = $total_main + $total_sub;

$fc_stmt = $pdo->prepare("SELECT COUNT(*) FROM sanctuary_forces WHERE season_id = ?");
$fc_stmt->execute([$current_season_id]);
$force_count = (int)$fc_stmt->fetchColumn();

// 신청자 목록
$apps_list = $pdo->prepare("SELECT sa.* FROM sanctuary_applications sa WHERE sa.season_id = ? AND sa.applicant_ip != 'buddy_synthesized' ORDER BY sa.applied_at DESC");
$apps_list->execute([$current_season_id]);
$all_apps = $apps_list->fetchAll();
?>

<div class="content-panel">
  <div class="panel-header">
    <div class="panel-title">⚙️ 관리자 패널 — <?= htmlspecialchars($current_season['name']) ?></div>
    <span class="status-badge <?= $season_status ?>"><?= $season_status ?></span>
  </div>
  <div class="panel-body">

    <!-- 통계 카드 -->
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:20px;">
      <div class="stat-card"><div class="stat-value"><?= $stat['total_apps'] ?? 0 ?></div><div class="stat-label">총 신청자</div></div>
      <div class="stat-card"><div class="stat-value"><?= $total_main ?></div><div class="stat-label">본캐</div></div>
      <div class="stat-card"><div class="stat-value"><?= $total_sub ?></div><div class="stat-label">부캐</div></div>
      <div class="stat-card"><div class="stat-value" style="color:var(--class-heal);"><?= $total_heal ?></div><div class="stat-label">치유성</div></div>
    </div>

    <!-- 신청자 목록 (구성중/모집종료 공통) -->
    <details style="margin-bottom:20px;background:var(--bg-card);border:1px solid var(--border);border-radius:8px;">
      <summary style="cursor:pointer;padding:12px 16px;font-size:14px;font-weight:700;color:var(--text-secondary);user-select:none;">
        📋 신청자 목록 관리 (총 <?= count($all_apps) ?>명)
        <span style="font-size:11px;color:var(--text-muted);font-weight:400;margin-left:8px;">— 클릭하여 펼치기</span>
      </summary>
      <div style="padding:0 16px 16px;">
        <?php if (empty($all_apps)): ?>
        <div class="empty-state" style="padding:20px 0;"><div class="empty-icon">📭</div><p>신청자가 없습니다.</p></div>
        <?php else: ?>
        <div style="overflow-x:auto;">
        <table class="applicant-table">
          <thead>
            <tr>
              <th>#</th><th>본캐</th><th>직업</th><th>아툴</th><th>부캐</th><th>신청일시</th><th>작업</th>
            </tr>
          </thead>
          <tbody>
            <?php $row_num = 0; foreach ($all_apps as $app):
              $app_chars_q = $pdo->prepare("SELECT * FROM sanctuary_characters WHERE application_id = ? ORDER BY is_main DESC");
              $app_chars_q->execute([$app['id']]);
              $chars = $app_chars_q->fetchAll();
              $main_char = null; $sub_chars = [];
              foreach ($chars as $c) { if ($c['is_main']) $main_char = $c; else $sub_chars[] = $c; }
            ?>
            <tr>
              <td style="color:var(--text-muted);font-size:12px;"><?= ++$row_num ?></td>
              <td><?php if ($main_char): ?><span style="font-weight:600;"><?= htmlspecialchars($main_char['char_name']) ?></span><?php endif; ?></td>
              <td><?php if ($main_char): ?><span class="cls cls-<?= htmlspecialchars($main_char['char_class']) ?>"><?= htmlspecialchars($main_char['char_class']) ?></span><?php endif; ?></td>
              <td>
                <?php if ($main_char): ?>
                  <span class="score-badge"><?= number_format($main_char['atul_score']) ?></span>
                  <?php if (!empty($main_char['item_level'])): ?><span style="font-size:10px;color:var(--text-muted);display:block;margin-top:2px;">Lv<?= (int)$main_char['item_level'] ?></span><?php endif; ?>
                <?php endif; ?>
              </td>
              <td>
                <?php foreach ($sub_chars as $sc): ?>
                <div style="font-size:11px;color:var(--text-muted);white-space:nowrap;">
                  <?= htmlspecialchars($sc['char_name']) ?>
                  <span class="cls cls-<?= htmlspecialchars($sc['char_class']) ?>" style="font-size:10px;"><?= htmlspecialchars($sc['char_class']) ?></span>
                  <span class="score-badge-sm"><?= number_format($sc['atul_score']) ?></span>
                </div>
                <?php endforeach; ?>
                <?php if (empty($sub_chars)): ?><span style="color:var(--text-muted);font-size:11px;">-</span><?php endif; ?>
              </td>
              <td class="notice-date"><?= date('m-d H:i', strtotime($app['applied_at'] . ' UTC')) ?></td>
              <td>
                <form method="POST" onsubmit="return confirm('이 신청을 취소하시겠습니까? 배치된 포스에서도 제거됩니다.');" style="display:inline;">
                  <input type="hidden" name="cancel_application" value="1">
                  <input type="hidden" name="app_id" value="<?= $app['id'] ?>">
                  <button type="submit" class="btn btn-danger" style="padding:4px 10px;font-size:11px;">취소</button>
                </form>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        </div>
        <?php endif; ?>
      </div>
    </details>

<?php if ($season_status === '구성중'): ?>
<!-- 구성중 화면: 드래그앤드롭 포스 편집기 + 상시 신청 받음 -->

<?php
// 이 시즌의 전체 캐릭터 (buddy_synthesized 제외)
$pool_q = $pdo->prepare("
    SELECT sc.id, sc.application_id, sc.char_name, sc.char_class, sc.atul_score, sc.item_level, sc.is_main, sa.applicant_ip, sa.play_times
    FROM sanctuary_characters sc
    JOIN sanctuary_applications sa ON sa.id = sc.application_id
    WHERE sa.season_id = ? AND sa.applicant_ip != 'buddy_synthesized'
    ORDER BY sc.atul_score DESC
");
$pool_q->execute([$current_season_id]);
$dnd_all_chars = $pool_q->fetchAll();

// 기존 임시 저장 포스 로드
$placed_char_ids = [];
$dnd_init_forces = [];
$df_q = $pdo->prepare("SELECT * FROM sanctuary_forces WHERE season_id = ? ORDER BY force_number");
$df_q->execute([$current_season_id]);
foreach ($df_q->fetchAll() as $df) {
    $pq = $pdo->prepare("SELECT * FROM sanctuary_parties WHERE force_id = ? ORDER BY party_number");
    $pq->execute([$df['id']]);
    $parties_raw = $pq->fetchAll();
    $parties = [[null,null,null,null],[null,null,null,null]];
    foreach ([0,1] as $pi) {
        $party = $parties_raw[$pi] ?? null;
        if (!$party) continue;
        $mq = $pdo->prepare("
            SELECT sc.id, sc.application_id, sc.char_name, sc.char_class, sc.atul_score, sc.item_level, sc.is_main, sa.applicant_ip, sa.play_times
            FROM sanctuary_party_members pm
            JOIN sanctuary_characters sc ON sc.id = pm.character_id
            JOIN sanctuary_applications sa ON sa.id = sc.application_id
            WHERE pm.party_id = ?
            ORDER BY sc.atul_score DESC
        ");
        $mq->execute([$party['id']]);
        foreach ($mq->fetchAll() as $mi => $m) {
            if ($mi < 4) { $parties[$pi][$mi] = $m; $placed_char_ids[] = $m['id']; }
        }
    }
    $raid_time = $df['raid_time'] ?? '';
    // 레거시 시간 정규화: "20" → "20:00", "20:0" → "20:00"
    if (preg_match('/^(\d{1,2})$/', $raid_time, $m2)) $raid_time = sprintf('%02d:00', (int)$m2[1]);
    elseif (preg_match('/^(\d{1,2}):(\d{1,2})$/', $raid_time, $m2)) $raid_time = sprintf('%02d:%02d', (int)$m2[1], (int)$m2[2]);
    $dnd_init_forces[] = ['parties' => $parties, 'raid_time' => $raid_time];
}
$placed_set = array_flip($placed_char_ids);
$dnd_init_pool = array_values(array_filter($dnd_all_chars, fn($c) => !isset($placed_set[$c['id']])));

// JS용 데이터 변환
function charToJs(array $c): array {
    return ['id'=>(int)$c['id'],'app_id'=>(int)$c['application_id'],'char_name'=>$c['char_name'],
            'char_class'=>$c['char_class'],'atul_score'=>(int)$c['atul_score'],
            'item_level'=>$c['item_level']!==null?(int)$c['item_level']:null,
            'is_main'=>(int)$c['is_main']];
}
$js_pool   = json_encode(array_map('charToJs', $dnd_init_pool));
$js_forces = json_encode(array_map(fn($f) => [
    'parties'   => array_map(fn($party) => array_map(fn($m) => $m ? charToJs($m) : null, $party), $f['parties']),
    'raid_time' => $f['raid_time'] ?? '',
], $dnd_init_forces));
$js_all_chars = json_encode(array_map('charToJs', $dnd_all_chars));
?>

<div class="alert alert-warning" style="margin-bottom:16px;">
  ⚙️ <strong>포스 구성 중</strong> — 신청은 상시 받습니다. 드래그앤드롭으로 캐릭터를 배치한 후 <strong style="color:var(--gold-light);">포스 구성 완료</strong>를 클릭하면 일반 유저에게 공개됩니다.
</div>

<!-- 상단 컨트롤 (JS로 렌더링) -->
<div id="dnd-controls" style="display:flex;gap:8px;align-items:center;margin-bottom:16px;flex-wrap:wrap;"></div>

<!-- 경고 토스트 -->
<div id="dnd-warn-toast" style="display:none;position:fixed;top:80px;left:50%;transform:translateX(-50%);
  background:rgba(180,30,30,0.97);color:#fff;padding:11px 22px;border-radius:8px;font-size:13px;font-weight:700;
  z-index:9999;box-shadow:0 4px 24px rgba(0,0,0,0.6);border:1px solid rgba(231,76,60,0.8);
  pointer-events:none;white-space:nowrap;"></div>

<!-- 드래그앤드롭 영역 -->
<div id="dnd-main" style="display:flex;gap:16px;height:calc(100vh - 280px);min-height:520px;">

  <!-- 캐릭터 풀 (왼쪽) -->
  <div style="width:210px;flex-shrink:0;display:flex;flex-direction:column;">
    <div style="font-size:11px;font-weight:700;color:var(--text-muted);letter-spacing:1px;margin-bottom:8px;text-transform:uppercase;flex-shrink:0;">
      미배정 캐릭터 <span id="pool-count" style="color:var(--gold);font-weight:900;"></span>
    </div>
    <div id="dnd-pool" data-drop-zone="pool" class="dnd-scroll"
      style="flex:1;overflow-y:auto;background:var(--bg-dark);border:1px solid var(--border);border-radius:8px;padding:8px;">
    </div>
  </div>

  <!-- 포스 영역 (오른쪽) -->
  <div style="flex:1;min-width:0;display:flex;flex-direction:column;">
    <div style="font-size:11px;font-weight:700;color:var(--text-muted);letter-spacing:1px;margin-bottom:8px;text-transform:uppercase;flex-shrink:0;">
      포스 구성 <span id="force-count" style="color:var(--gold);font-weight:900;"></span>
    </div>
    <div id="dnd-forces" class="dnd-scroll" style="flex:1;overflow-y:auto;display:flex;flex-wrap:wrap;gap:12px;align-content:flex-start;">
    </div>
  </div>

</div>

<!-- 폼: 임시 저장 -->
<form id="draft-form" method="POST" style="display:none;">
  <input type="hidden" name="save_draft_forces" value="1">
  <input type="hidden" name="season_id" value="<?= $current_season_id ?>">
  <input type="hidden" name="forces_data" id="draft-forces-data">
</form>
<!-- 폼: 구성 완료 확정 -->
<form id="finalize-form" method="POST" style="display:none;">
  <input type="hidden" name="finalize_forces" value="1">
  <input type="hidden" name="season_id" value="<?= $current_season_id ?>">
  <input type="hidden" name="forces_data" id="finalize-forces-data">
</form>

<style>
.dnd-char-card {
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(255,255,255,0.06);
  border-radius: 6px;
  padding: 6px 8px;
  cursor: grab;
  margin-bottom: 4px;
  transition: background 0.1s, opacity 0.15s;
  user-select: none;
}
.dnd-char-card:hover { background: rgba(255,255,255,0.08); }
.dnd-char-card.is-dragging { opacity: 0.25; cursor: grabbing; }
.dnd-slot {
  border: 1px dashed rgba(255,255,255,0.1);
  border-radius: 6px;
  min-height: 46px;
  padding: 2px;
  margin-bottom: 4px;
  transition: border-color 0.15s, background 0.15s;
  display: flex;
  align-items: center;
}
.dnd-slot.drag-over { border-color: var(--gold); background: rgba(201,168,76,0.1); }
.dnd-slot.empty-slot { padding: 8px 10px; }
#dnd-pool.drag-over { border-color: var(--blue); background: rgba(58,123,213,0.06); }
.dnd-force-card {
  width: 260px;
  background: var(--bg-card);
  border: 1px solid var(--border);
  border-radius: 10px;
  overflow: hidden;
  transition: border-color 0.2s;
}
.dnd-force-card:hover { border-color: var(--border-bright); }
.dnd-force-card.force-valid-drop {
  border-color: #3a7bd5;
  box-shadow: 0 0 0 2px rgba(58,123,213,0.35), 0 0 18px rgba(58,123,213,0.25);
  transition: border-color 0.15s, box-shadow 0.15s;
}
.dnd-force-card.force-reorder-target {
  border-color: var(--gold-light);
  box-shadow: -4px 0 0 0 var(--gold-light), 0 0 18px rgba(201,168,76,0.35);
  transition: border-color 0.15s, box-shadow 0.15s;
}
.dnd-force-card.is-dragging { opacity: 0.4; }
.dnd-force-header:active { cursor: grabbing !important; }
.dnd-force-header {
  padding: 10px 14px;
  background: linear-gradient(135deg, rgba(201,168,76,0.08), rgba(201,168,76,0.03));
  border-bottom: 1px solid var(--border);
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.force-schedule-row {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 7px 12px;
  background: rgba(108,61,201,0.06);
  border-bottom: 1px solid var(--border);
}
.schedule-select {
  flex: 1;
  padding: 4px 6px;
  background: var(--bg-dark);
  border: 1px solid var(--border);
  border-radius: 4px;
  color: var(--text-primary);
  font-size: 11px;
  font-family: inherit;
  outline: none;
}
.schedule-select:focus { border-color: var(--purple-light); }
.dnd-party-section { padding: 8px 12px; border-bottom: 1px solid rgba(30,40,64,0.5); }
.dnd-party-section:last-child { border-bottom: none; }
.dnd-party-label {
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 1px;
  color: var(--text-muted);
  text-transform: uppercase;
  margin-bottom: 6px;
}
.dnd-scroll::-webkit-scrollbar { width: 4px; }
.dnd-scroll::-webkit-scrollbar-track { background: transparent; }
.dnd-scroll::-webkit-scrollbar-thumb {
  background: transparent;
  border-radius: 4px;
  transition: background 0.3s;
}
.dnd-scroll.scrolling::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.22); }
.dnd-scroll { scrollbar-width: thin; scrollbar-color: transparent transparent; }
.dnd-scroll.scrolling { scrollbar-color: rgba(255,255,255,0.22) transparent; }
.btn-purple {
  background: linear-gradient(135deg, #4a1a8a, #7b3fc0);
  border-color: var(--purple-light);
  color: #fff;
}
.btn-purple:hover { filter: brightness(1.15); transform: translateY(-1px); }
.btn-green {
  background: linear-gradient(135deg, #1a5a30, #2a8a50);
  border-color: #2ecc71;
  color: #fff;
}
.btn-green:hover { filter: brightness(1.15); transform: translateY(-1px); }
</style>

<script>
(function() {
  const CLS_MAP = {수호성:'guardian',검성:'sword',살성:'kill',궁성:'bow',호법성:'hobeop',정령성:'spirit',마도성:'mage',치유성:'heal'};

  const ALL_CHARS = <?= $js_all_chars ?>;
  // 30분 간격 시간 슬롯 (19:00 ~ 23:00)
  const ALL_TIMES = (() => {
    const arr = [];
    for (let h = 19; h <= 23; h++) {
      arr.push(`${String(h).padStart(2,'0')}:00`);
      if (h < 23) arr.push(`${String(h).padStart(2,'0')}:30`);
    }
    return arr;
  })();

  let pool = <?= $js_pool ?>;
  let forces = <?= $js_forces ?>;

  // 드래그 추적
  let dragCharId = null;
  let dragSrc = null;
  let dragEl = null;

  function clsKey(cls) { return CLS_MAP[cls] || 'secondary'; }

  function avg(chars) {
    const valid = chars.filter(c => c !== null);
    if (!valid.length) return 0;
    return Math.round(valid.reduce((s, c) => s + c.atul_score, 0) / valid.length);
  }

  function charCardHtml(char, extraStyle) {
    const cls = clsKey(char.char_class);
    const subTag = !char.is_main ? '<span style="font-size:9px;color:var(--text-muted);background:rgba(74,90,120,0.3);border-radius:2px;padding:1px 4px;margin-left:3px;">부캐</span>' : '';
    return `<div class="dnd-char-card" draggable="true" data-char-id="${char.id}" ${extraStyle||''} style="border-left:3px solid var(--class-${cls});">
      <div style="font-size:12px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
        ${char.char_name}${subTag}
      </div>
      <div style="display:flex;justify-content:space-between;align-items:center;margin-top:2px;">
        <span style="font-size:11px;color:var(--class-${cls});">${char.char_class}</span>
        <div style="text-align:right;">
          <div style="font-size:11px;color:var(--gold);font-weight:600;">${char.atul_score.toLocaleString()}</div>
          ${char.item_level ? `<div style="font-size:10px;color:var(--text-muted);">Lv${char.item_level}</div>` : ''}
        </div>
      </div>
    </div>`;
  }

  function renderControls() {
    document.getElementById('dnd-controls').innerHTML = `
      <button onclick="document.getElementById('addForceModal').classList.add('active')" class="btn btn-primary">+ 포스 추가</button>
      <button onclick="placeAll()" class="btn btn-green">⚡ 자동배치</button>
      <button onclick="openFinalizeModal()" class="btn btn-gold" style="padding:10px 24px;font-size:14px;">✅ 포스 구성 완료</button>
      <button onclick="saveDraft()" class="btn btn-secondary">💾 임시 저장</button>
      <span id="dnd-status-msg" style="font-size:12px;color:var(--text-muted);margin-left:8px;"></span>
    `;
  }

  function render() {
    // 풀
    const poolEl = document.getElementById('dnd-pool');
    document.getElementById('pool-count').textContent = `(${pool.length}명)`;
    poolEl.innerHTML = pool.length === 0
      ? '<div style="text-align:center;color:var(--text-muted);font-size:12px;padding:20px 0;">모든 캐릭터 배치 완료</div>'
      : pool.map((c, idx) => charCardHtml(c, `data-drag-src="pool" data-pool-idx="${idx}"`)).join('');

    // 포스
    const forcesEl = document.getElementById('dnd-forces');
    document.getElementById('force-count').textContent = `(${forces.length}개)`;
    if (forces.length === 0) {
      forcesEl.innerHTML = '<div style="color:var(--text-muted);font-size:13px;padding:20px;">위 "+ 포스 추가" 버튼으로 포스를 추가하세요.</div>';
    } else {
      forcesEl.innerHTML = forces.map((force, fi) => {
        const allChars = force.parties.flat();
        const forceAvg = avg(allChars);
        const memberCount = allChars.filter(c => c !== null).length;
        const partiesHtml = [0,1].map(pi => {
          const pAvg = avg(force.parties[pi]);
          const slotsHtml = [0,1,2,3].map(si => {
            const char = force.parties[pi][si];
            if (char) {
              return `<div class="dnd-slot" data-drop-zone="slot" data-fi="${fi}" data-pi="${pi}" data-si="${si}">
                ${charCardHtml(char, `data-drag-src="slot" data-fi="${fi}" data-pi="${pi}" data-si="${si}" style="width:100%;"`)}
              </div>`;
            }
            return `<div class="dnd-slot empty-slot" data-drop-zone="slot" data-fi="${fi}" data-pi="${pi}" data-si="${si}">
              <div style="width:4px;height:28px;background:#333;border-radius:2px;flex-shrink:0;margin-right:8px;"></div>
              <span style="font-size:11px;color:var(--text-muted);">공팟 (빈 자리)</span>
            </div>`;
          }).join('');
          return `<div class="dnd-party-section">
            <div class="dnd-party-label">${pi+1}파티<span style="font-weight:400;font-size:10px;color:var(--text-muted);margin-left:6px;">평균 ${pAvg.toLocaleString()}</span></div>
            ${slotsHtml}
          </div>`;
        }).join('');
        const rt = force.raid_time || '';
        const timeOpts = ALL_TIMES.map(t =>
          `<option value="${t}" ${rt===t?'selected':''}>${t}</option>`
        ).join('');
        return `<div class="dnd-force-card" data-fi="${fi}" data-drop-zone="force">
          <div class="dnd-force-header" draggable="true" data-drag-src="force" data-fi="${fi}" title="드래그하여 포스 순서 변경" style="cursor:grab;">
            <div>
              <span style="color:var(--text-muted);font-size:14px;margin-right:4px;user-select:none;">⋮⋮</span>
              <span class="force-number">${fi+1}포스</span>
              <span class="force-avg" style="margin-left:8px;">평균 <strong>${forceAvg.toLocaleString()}</strong></span>
            </div>
            <div style="display:flex;align-items:center;gap:6px;">
              <span style="font-size:11px;color:var(--text-muted);">${memberCount}명</span>
              <button onclick="removeForce(${fi})" class="btn btn-danger" draggable="false" style="padding:3px 8px;font-size:11px;">✕</button>
            </div>
          </div>
          <div class="force-schedule-row">
            <span style="font-size:11px;color:var(--purple-light);white-space:nowrap;font-weight:600;">⏰ 수요일</span>
            <select class="schedule-select" data-fi="${fi}" data-field="raid_time">
              <option value="">시간 미정</option>${timeOpts}
            </select>
          </div>
          ${partiesHtml}
        </div>`;
      }).join('');
    }

    renderControls();
  }

  // 포스 추가 (시간 지정)
  window.addForceAtTime = function(time) {
    forces.push({ parties: [[null,null,null,null],[null,null,null,null]], raid_time: time });
    document.getElementById('addForceModal').classList.remove('active');
    render();
  };

  window.addForce = function() {
    document.getElementById('addForceModal').classList.add('active');
  };

  window.removeForce = function(fi) {
    const chars = forces[fi].parties.flat().filter(c => c !== null);
    chars.forEach(c => pool.push(c));
    pool.sort((a, b) => b.atul_score - a.atul_score);
    forces.splice(fi, 1);
    render();
  };

  // ── 공통 유틸 ────────────────────────────────────────────────────────────────
  // 포스 현재 평균 계산
  function forceAvgScore(fi) {
    const chars = forces[fi].parties.flat().filter(c => c !== null);
    return chars.length ? chars.reduce((s, c) => s + c.atul_score, 0) / chars.length : 0;
  }

  // 특정 파티에 배치 후 예상 평균 (파티 단위)
  function projectedAvg(fi, pi, score) {
    const chars = forces[fi].parties[pi].filter(c => c !== null);
    return chars.length
      ? (chars.reduce((s, c) => s + c.atul_score, 0) + score) / (chars.length + 1)
      : score;
  }

  // 풀에서 캐릭터 제거
  function removeFromPool(charId) {
    const idx = pool.findIndex(c => c.id === charId);
    if (idx !== -1) pool.splice(idx, 1);
  }

  // ── 단일 캐릭터 배치 (규칙 준수) ─────────────────────────────────────────────
  // 우선순위:
  //   1. 같은 플레이어 같은 포스 절대 불가 (하드)
  //   2. 치유 배치 시 → 이미 치유 있는 파티 불가 (하드)
  //   3. 비치유 배치 시 → 치유 있는 파티 선호 (소프트, 페널티)
  //   4. 25~30만 평균 유지 (소프트)
  // opts.preferEmptyParty: 치유 배치 시 비치유 본캐가 없는 파티 우선
  function autoPlaceOne(char, opts = {}) {
    const TARGET = 275000;
    const isHealer = char.char_class === '치유성';

    let bestSlot = null;
    let bestScore = Infinity;

    for (let fi = 0; fi < forces.length; fi++) {
      const f = forces[fi];
      // 규칙 1: 같은 플레이어 같은 포스 불가
      if (f.parties.flat().some(c => c !== null && c.app_id === char.app_id)) continue;

      for (let pi = 0; pi < 2; pi++) {
        const party = f.parties[pi];
        const partyHealerCount = party.filter(c => c !== null && c.char_class === '치유성').length;
        const emptySlot = party.findIndex(c => c === null);
        if (emptySlot === -1) continue;

        // 규칙 2: 치유 배치 시 해당 파티에 치유 이미 있으면 불가
        if (isHealer && partyHealerCount >= 1) continue;

        // 소프트 페널티
        let placementPenalty = 0;
        if (isHealer && opts.preferEmptyParty) {
          // 치유 배치: 비치유 본캐가 이미 있는 파티 회피
          const partyHasNonHealerMain = party.some(
            c => c !== null && c.char_class !== '치유성' && c.is_main
          );
          if (partyHasNonHealerMain) placementPenalty = 2000000;
        } else if (!isHealer) {
          // 비치유 캐릭터 파티 배치 우선순위
          //   치유 없는 파티                          → 최하위 (5,000,000)
          //   비치유 본캐: 비치유본캐 없음 + 부캐치유  → 최우선 (0)
          //   비치유 본캐: 비치유본캐 없음 + 본캐치유  → 2순위  (1,000,000)
          //   비치유 본캐: 비치유본캐 이미 있음 + 부캐치유 → 3순위 (3,000,000)
          //   비치유 본캐: 비치유본캐 이미 있음 + 본캐치유 → 4순위 (4,000,000)
          //   비치유 부캐: 치유 있으면 페널티 없음 (0)
          if (partyHealerCount === 0) {
            placementPenalty = 5000000;
          } else if (char.is_main) {
            const partyHasNonHealerMain = party.some(
              c => c !== null && c.char_class !== '치유성' && c.is_main
            );
            const partyHasSubHealer = party.some(
              c => c !== null && c.char_class === '치유성' && !c.is_main
            );
            if (!partyHasNonHealerMain) {
              placementPenalty = partyHasSubHealer ? 0 : 1000000;
            } else {
              placementPenalty = partyHasSubHealer ? 3000000 : 4000000;
            }
          }
        }

        // 전투력 평균 점수 (파티 단위)
        const proj = projectedAvg(fi, pi, char.atul_score);
        const avgScore = Math.abs(proj - TARGET);

        const total = avgScore + placementPenalty;
        if (total < bestScore) {
          bestScore = total;
          bestSlot = { fi, pi, si: emptySlot };
        }
      }
    }

    if (!bestSlot) return false;
    forces[bestSlot.fi].parties[bestSlot.pi][bestSlot.si] = char;
    removeFromPool(char.id);
    return true;
  }

  // ── 배치 순서 정렬 헬퍼 ──────────────────────────────────────────────────────
  // 우선순위: ① 치유본캐 ② 치유부캐 ③ 비치유본캐 ④ 비치유부캐
  // 같은 그룹 내에서는 전투력 높은 순 (포스 평균 균형용)
  function sortedByRule(chars) {
    const healMain  = chars.filter(c => c.char_class === '치유성' &&  c.is_main).sort((a,b) => b.atul_score - a.atul_score);
    const healSub   = chars.filter(c => c.char_class === '치유성' && !c.is_main).sort((a,b) => b.atul_score - a.atul_score);
    const otherMain = chars.filter(c => c.char_class !== '치유성' &&  c.is_main).sort((a,b) => b.atul_score - a.atul_score);
    const otherSub  = chars.filter(c => c.char_class !== '치유성' && !c.is_main).sort((a,b) => b.atul_score - a.atul_score);
    return [...healMain, ...healSub, ...otherMain, ...otherSub];
  }

  // ── ⚡ 자동배치 ──────────────────────────────────────────────────────────────
  // Phase 1: 치유 (본캐 → 부캐) — 파티당 1명 우선 확보
  // Phase 2: 비치유 본캐 (부캐는 사용자가 직접 드래그)
  window.placeAll = function() {
    if (pool.length === 0) {
      showWarning('⚡ 풀에 배치할 캐릭터가 없습니다.');
      return;
    }
    let placed = 0;

    // Phase 1: 치유 본캐 → 치유 부캐 (각 그룹 내 점수 내림차순)
    const healers = [
      ...pool.filter(c => c.char_class === '치유성' &&  c.is_main).sort((a,b) => b.atul_score - a.atul_score),
      ...pool.filter(c => c.char_class === '치유성' && !c.is_main).sort((a,b) => b.atul_score - a.atul_score),
    ];
    for (const char of healers) {
      if (!pool.find(c => c.id === char.id)) continue;
      if (autoPlaceOne(char)) placed++;
    }

    // Phase 2: 비치유 본캐 (점수 내림차순)
    const mains = pool.filter(c => c.is_main && c.char_class !== '치유성')
                      .sort((a,b) => b.atul_score - a.atul_score);
    for (const char of mains) {
      if (!pool.find(c => c.id === char.id)) continue;
      if (autoPlaceOne(char)) placed++;
    }

    render();
    if (placed === 0) showWarning('⚡ 배치 가능한 슬롯이 없습니다. 포스를 추가하거나 슬롯을 확인하세요.');
  };

  function findCharById(charId) {
    const fromPool = pool.find(c => c.id === charId);
    if (fromPool) return fromPool;
    for (const f of forces) {
      for (const party of f.parties) {
        const c = party.find(c => c !== null && c.id === charId);
        if (c) return c;
      }
    }
    return null;
  }

  function findAndRemove(charId, src) {
    if (src.type === 'pool') {
      const idx = pool.findIndex(c => c.id === charId);
      if (idx === -1) return null;
      return pool.splice(idx, 1)[0];
    } else {
      const char = forces[src.fi].parties[src.pi][src.si];
      forces[src.fi].parties[src.pi][src.si] = null;
      return char;
    }
  }

  function samePlayerInForce(char, fi, excludeCharId) {
    return forces[fi].parties.flat().some(c =>
      c !== null && c.id !== excludeCharId && c.app_id === char.app_id
    );
  }

  function showWarning(msg) {
    const toast = document.getElementById('dnd-warn-toast');
    toast.textContent = msg;
    toast.style.display = 'block';
    toast.style.opacity = '1';
    clearTimeout(toast._timer);
    toast._timer = setTimeout(() => {
      toast.style.transition = 'opacity 0.4s';
      toast.style.opacity = '0';
      setTimeout(() => { toast.style.display = 'none'; toast.style.transition = ''; }, 400);
    }, 2800);
  }

  function handleDrop(charId, src, dst) {
    if (dst.type === 'slot') {
      const { fi, pi, si } = dst;
      const srcFi = src.type === 'slot' ? src.fi : -1;
      if (fi !== srcFi) {
        const char = src.type === 'pool'
          ? pool.find(c => c.id === charId)
          : forces[src.fi].parties[src.pi][src.si];
        if (!char) return;
        // 규칙 1: 같은 플레이어 같은 포스 불가
        if (samePlayerInForce(char, fi, charId)) {
          showWarning(`⚠️ ${fi+1}포스에 같은 플레이어의 캐릭터가 이미 있습니다.`);
          return;
        }
        if (src.type === 'slot') {
          const dstChar = forces[fi].parties[pi][si];
          if (dstChar) {
            const srcForceOthers = forces[src.fi].parties.flat().filter(c => c !== null && c.id !== charId);
            if (srcForceOthers.some(c => c.app_id === dstChar.app_id)) {
              showWarning(`⚠️ 교체 시 ${src.fi+1}포스에 같은 플레이어의 캐릭터가 겹칩니다.`);
              return;
            }
          }
        }
      }
    }

    const char = findAndRemove(charId, src);
    if (!char) return;
    if (dst.type === 'pool') {
      pool.push(char);
      pool.sort((a, b) => b.atul_score - a.atul_score);
    } else {
      const { fi, pi, si } = dst;
      const existing = forces[fi].parties[pi][si];
      if (existing) {
        if (src.type === 'pool') {
          pool.push(existing);
          pool.sort((a, b) => b.atul_score - a.atul_score);
        } else {
          forces[src.fi].parties[src.pi][src.si] = existing;
        }
      }
      forces[fi].parties[pi][si] = char;
    }
    render();
  }

  // 이벤트 위임
  const workspace = document.getElementById('dnd-main');

  let dragForceFi = null;

  workspace.addEventListener('dragstart', e => {
    // 1) 포스 헤더 드래그 (포스 자체 순서 변경)
    const forceHeader = e.target.closest('[data-drag-src="force"]');
    if (forceHeader && !e.target.closest('button')) {
      dragForceFi = parseInt(forceHeader.dataset.fi);
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', 'force:' + dragForceFi);
      forceHeader.closest('.dnd-force-card').classList.add('is-dragging');
      return;
    }

    const card = e.target.closest('[data-char-id][draggable="true"]');
    if (!card) return;
    dragCharId = parseInt(card.dataset.charId);
    const srcType = card.dataset.dragSrc;
    dragSrc = srcType === 'pool'
      ? { type: 'pool' }
      : { type: 'slot', fi: parseInt(card.dataset.fi), pi: parseInt(card.dataset.pi), si: parseInt(card.dataset.si) };
    dragEl = card;
    card.classList.add('is-dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', String(dragCharId));

    // 드래그 중인 캐릭터가 배치 가능한 포스 파란 테두리 강조
    const char = findCharById(dragCharId);
    if (char) {
      workspace.querySelectorAll('.dnd-force-card[data-fi]').forEach(forceEl => {
        const fi = parseInt(forceEl.dataset.fi);
        const f = forces[fi];
        if (!f) return;
        // 같은 플레이어 다른 캐릭터가 없는 포스만 강조
        const hasConflict = f.parties.flat().some(c => c !== null && c.app_id === char.app_id && c.id !== char.id);
        if (hasConflict) return;
        forceEl.classList.add('force-valid-drop');
      });
    }
  });

  workspace.addEventListener('dragend', () => {
    if (dragEl) dragEl.classList.remove('is-dragging');
    dragEl = null;
    workspace.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
    workspace.querySelectorAll('.force-valid-drop').forEach(el => el.classList.remove('force-valid-drop'));
    workspace.querySelectorAll('.force-reorder-target').forEach(el => el.classList.remove('force-reorder-target'));
    workspace.querySelectorAll('.dnd-force-card.is-dragging').forEach(el => el.classList.remove('is-dragging'));
    dragForceFi = null;
  });

  workspace.addEventListener('dragover', e => {
    // 포스 순서 변경 드래그
    if (dragForceFi !== null) {
      const targetCard = e.target.closest('.dnd-force-card[data-drop-zone="force"]');
      if (targetCard) {
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        workspace.querySelectorAll('.force-reorder-target').forEach(el => el.classList.remove('force-reorder-target'));
        if (parseInt(targetCard.dataset.fi) !== dragForceFi) targetCard.classList.add('force-reorder-target');
      }
      return;
    }

    const zone = e.target.closest('[data-drop-zone]');
    if (!zone || zone.dataset.dropZone === 'force') return; // 포스 카드는 캐릭터 드롭존이 아님
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    workspace.querySelectorAll('.drag-over').forEach(el => el.classList.remove('drag-over'));
    zone.classList.add('drag-over');
  });

  workspace.addEventListener('dragleave', e => {
    const zone = e.target.closest('[data-drop-zone]');
    if (zone && !zone.contains(e.relatedTarget)) zone.classList.remove('drag-over');
    const fcard = e.target.closest('.dnd-force-card');
    if (fcard && !fcard.contains(e.relatedTarget)) fcard.classList.remove('force-reorder-target');
  });

  workspace.addEventListener('drop', e => {
    e.preventDefault();

    // 포스 순서 변경 드롭
    if (dragForceFi !== null) {
      const targetCard = e.target.closest('.dnd-force-card[data-drop-zone="force"]');
      if (targetCard) {
        const targetFi = parseInt(targetCard.dataset.fi);
        if (targetFi !== dragForceFi) {
          const [moved] = forces.splice(dragForceFi, 1);
          const insertAt = (dragForceFi < targetFi) ? targetFi - 1 : targetFi;
          forces.splice(insertAt, 0, moved);
          render();
        }
      }
      dragForceFi = null;
      workspace.querySelectorAll('.force-reorder-target').forEach(el => el.classList.remove('force-reorder-target'));
      workspace.querySelectorAll('.dnd-force-card.is-dragging').forEach(el => el.classList.remove('is-dragging'));
      return;
    }

    const zone = e.target.closest('[data-drop-zone]');
    if (!zone || dragCharId === null || zone.dataset.dropZone === 'force') return;
    zone.classList.remove('drag-over');
    const dz = zone.dataset.dropZone;
    const dst = dz === 'pool'
      ? { type: 'pool' }
      : { type: 'slot', fi: parseInt(zone.dataset.fi), pi: parseInt(zone.dataset.pi), si: parseInt(zone.dataset.si) };
    handleDrop(dragCharId, dragSrc, dst);
    dragCharId = null; dragSrc = null;
  });

  function collectForcesData() {
    return forces.map(f => ({
      parties:   f.parties.map(party => party.map(c => c ? c.id : null)),
      raid_day:  '수',
      raid_time: f.raid_time || '',
    }));
  }

  // 시간대 변경 이벤트
  document.getElementById('dnd-forces').addEventListener('change', function(e) {
    const el = e.target;
    const fi = parseInt(el.dataset.fi);
    const field = el.dataset.field;
    if (!isNaN(fi) && field && forces[fi]) {
      forces[fi][field] = el.value;
    }
  });

  window.saveDraft = function() {
    document.getElementById('draft-forces-data').value = JSON.stringify(collectForcesData());
    document.getElementById('dnd-status-msg').textContent = '저장 중...';
    document.getElementById('draft-form').submit();
  };

  window.openFinalizeModal = function() {
    document.getElementById('finalize-forces-data').value = JSON.stringify(collectForcesData());
    document.getElementById('finalizeModal').classList.add('active');
  };

  render();

  // 스크롤바 표시
  ['dnd-pool', 'dnd-forces'].forEach(id => {
    const el = document.getElementById(id);
    if (!el) return;
    let t;
    el.addEventListener('scroll', () => {
      el.classList.add('scrolling');
      clearTimeout(t);
      t = setTimeout(() => el.classList.remove('scrolling'), 800);
    }, { passive: true });
  });
})();
</script>

<?php else: // 모집종료 ?>
<!-- 모집종료 화면: 재구성 빨간버튼 + 포스 목록 -->

    <!-- 포스 구성 수정하기 버튼 -->
    <div style="
        background:rgba(58,123,213,0.08);
        border:1px solid rgba(58,123,213,0.4);
        border-radius:10px;
        padding:16px 20px;
        margin-bottom:12px;
        display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
      <div>
        <div style="font-size:13px;font-weight:700;color:var(--blue-light);margin-bottom:4px;">✏️ 포스 구성 수정하기</div>
        <div style="font-size:12px;color:var(--text-muted);">
          공개를 취소하고 <strong style="color:var(--blue-light);">현재 포스 배치를 유지한 채</strong>
          드래그앤드롭 편집 화면으로 돌아갑니다.
        </div>
      </div>
      <form method="POST" style="flex-shrink:0;">
        <input type="hidden" name="revert_to_composing" value="1">
        <input type="hidden" name="season_id" value="<?= $current_season_id ?>">
        <button type="submit" class="btn btn-primary"
                style="font-weight:700;padding:11px 22px;font-size:14px;
                       box-shadow:0 0 16px rgba(58,123,213,0.3);">
          ✏️ 구성 수정하기
        </button>
      </form>
    </div>

    <!-- 재구성 버튼 -->
    <div style="
        background:rgba(192,57,43,0.08);
        border:1px solid rgba(192,57,43,0.4);
        border-radius:10px;
        padding:16px 20px;
        margin-bottom:24px;
        display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;">
      <div>
        <div style="font-size:13px;font-weight:700;color:var(--red-light);margin-bottom:4px;">⚠️ 위험 구역</div>
        <div style="font-size:12px;color:var(--text-muted);">
          파티 재구성 시 <strong style="color:var(--red-light);">모든 신청 데이터와 포스 구성이 초기화</strong>되고
          시즌이 <strong style="color:var(--gold-light);">구성중</strong>으로 되돌아갑니다.
        </div>
      </div>
      <button onclick="document.getElementById('recomposeModal').classList.add('active')"
              class="btn"
              style="background:linear-gradient(135deg,#7a0f0f,#c0392b);border-color:#e74c3c;color:#fff;
                     font-weight:700;padding:11px 22px;font-size:14px;flex-shrink:0;
                     box-shadow:0 0 20px rgba(231,76,60,0.35);">
        🔄 파티 재구성하기
      </button>
    </div>

    <!-- 구성된 포스 목록 -->
    <?php
    $forces_data = $pdo->prepare("
        SELECT f.*, COUNT(pm.id) as member_count
        FROM sanctuary_forces f
        LEFT JOIN sanctuary_parties p  ON p.force_id  = f.id
        LEFT JOIN sanctuary_party_members pm ON pm.party_id = p.id
        WHERE f.season_id = ?
        GROUP BY f.id
        ORDER BY f.force_number ASC
    ");
    $forces_data->execute([$current_season_id]);
    $admin_forces = $forces_data->fetchAll();

    // 포스에 배정되지 못한 캐릭터
    $unplaced_q = $pdo->prepare("
        SELECT sc.char_name, sc.char_class, sc.atul_score, sc.item_level, sc.is_main,
               sa.applicant_ip
        FROM sanctuary_characters sc
        JOIN sanctuary_applications sa ON sa.id = sc.application_id
        WHERE sa.season_id = ?
          AND sc.id NOT IN (
              SELECT pm.character_id
              FROM sanctuary_party_members pm
              JOIN sanctuary_parties p  ON p.id  = pm.party_id
              JOIN sanctuary_forces   f ON f.id  = p.force_id
              WHERE f.season_id = ?
          )
        ORDER BY sc.is_main DESC, sc.atul_score DESC
    ");
    $unplaced_q->execute([$current_season_id, $current_season_id]);
    $unplaced_chars = $unplaced_q->fetchAll();
    ?>

    <?php if (!empty($unplaced_chars)): ?>
    <div style="
        background:rgba(180,100,0,0.08);
        border:1px solid rgba(180,100,0,0.35);
        border-radius:10px;
        padding:16px 18px;
        margin-bottom:20px;">
      <div style="font-size:13px;font-weight:700;color:#e8a040;margin-bottom:10px;">
        ⚠️ 포스 미배정 캐릭터 (총 <?= count($unplaced_chars) ?>명)
      </div>
      <div style="display:flex;flex-wrap:wrap;gap:8px;">
        <?php
        $cls_map = ['수호성'=>'guardian','검성'=>'sword','살성'=>'kill','궁성'=>'bow',
                    '호법성'=>'hobeop','정령성'=>'spirit','마도성'=>'mage','치유성'=>'heal'];
        foreach ($unplaced_chars as $uc):
          $cls_key = $cls_map[$uc['char_class']] ?? 'secondary';
        ?>
        <div style="
            background:rgba(255,255,255,0.04);
            border:1px solid rgba(255,255,255,0.1);
            border-radius:6px;
            padding:6px 10px;
            display:flex;align-items:center;gap:8px;font-size:12px;">
          <span style="width:4px;height:28px;background:var(--class-<?= $cls_key ?>);border-radius:2px;flex-shrink:0;"></span>
          <div>
            <div style="font-weight:600;color:var(--text-primary);">
              <?= htmlspecialchars($uc['char_name']) ?>
              <?php if (!$uc['is_main']): ?>
                <span style="font-size:10px;color:var(--text-muted);margin-left:4px;">부캐</span>
              <?php endif; ?>
            </div>
            <div style="color:var(--class-<?= $cls_key ?>);font-size:11px;"><?= $uc['char_class'] ?></div>
          </div>
          <div style="text-align:right;margin-left:4px;">
            <div style="color:var(--text-muted);font-size:11px;"><?= number_format($uc['atul_score']) ?></div>
            <?php if (!empty($uc['item_level'])): ?><div style="color:var(--text-muted);font-size:10px;">Lv<?= (int)$uc['item_level'] ?></div><?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php if (empty($admin_forces)): ?>
    <div class="empty-state"><div class="empty-icon">⚔️</div><p>구성된 포스가 없습니다.</p></div>
    <?php else: ?>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;flex-wrap:wrap;">
      <div style="font-size:13px;font-weight:700;color:var(--text-secondary);">
        ⚔️ 구성된 포스 (총 <?= count($admin_forces) ?>개)
      </div>
      <button type="button" onclick="exportForcesImage(event)" class="btn btn-primary"
              style="padding:8px 16px;font-size:12px;font-weight:700;">
        📸 포스 이미지 저장
      </button>
    </div>
    <div id="force-grid-capture" class="force-grid">
      <?php foreach ($admin_forces as $force): ?>
      <?php
        $parties_q = $pdo->prepare("SELECT * FROM sanctuary_parties WHERE force_id = ? ORDER BY party_number ASC");
        $parties_q->execute([$force['id']]);
        $party_list = $parties_q->fetchAll();
      ?>
      <div class="force-card">
        <div class="force-card-header">
          <div>
            <div class="force-number"><?= $force['force_number'] ?>포스</div>
            <div class="force-avg">평균 전투력: <strong><?= number_format((float)($force['avg_atul'] ?? 0)) ?></strong></div>
            <?php if (!empty($force['raid_time'])):
              $rt_disp = $force['raid_time'];
              if (preg_match('/^(\d{1,2})$/', $rt_disp, $mm)) $rt_disp = sprintf('%02d:00', (int)$mm[1]);
              elseif (preg_match('/^(\d{1,2}):(\d{1,2})$/', $rt_disp, $mm)) $rt_disp = sprintf('%02d:%02d', (int)$mm[1], (int)$mm[2]);
            ?>
            <div style="font-size:11px;color:var(--purple-light);margin-top:3px;">
              ⏰ 수요일 <?= htmlspecialchars($rt_disp) ?>
            </div>
            <?php endif; ?>
          </div>
          <div style="font-size:11px;color:var(--text-muted);"><?= $force['member_count'] ?>명</div>
        </div>
        <?php foreach ($party_list as $party): ?>
        <?php
          $members_q = $pdo->prepare("
              SELECT pm.*, sc.char_name, sc.char_class, sc.atul_score, sc.item_level, sc.is_main, sc.atul_adjusted
              FROM sanctuary_party_members pm
              JOIN sanctuary_characters sc ON sc.id = pm.character_id
              WHERE pm.party_id = ?
              ORDER BY sc.atul_score DESC
          ");
          $members_q->execute([$party['id']]);
          $member_list = $members_q->fetchAll();
        ?>
        <div class="party-section">
          <div class="party-label">
            <?= $party['party_number'] ?>파티
            <span style="color:var(--text-muted);font-weight:400;font-size:10px;margin-left:6px;">
              평균전투력 <?= number_format((float)($party['avg_atul'] ?? 0)) ?>
            </span>
          </div>
          <?php foreach ($member_list as $m): ?>
          <?php $has_adj = isset($m['atul_adjusted']) && $m['atul_adjusted'] !== null && $m['atul_adjusted'] != $m['atul_score']; ?>
          <div class="member-row">
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="<?= $m['is_main'] ? 'main-char-badge' : 'sub-char-badge' ?>"></div>
              <div>
                <div class="member-name"><?= htmlspecialchars($m['char_name']) ?>
                  <?php if (!$m['is_main']): ?><span class="tag tag-sub" style="font-size:9px;">부캐</span><?php endif; ?>
                </div>
                <div class="cls cls-<?= htmlspecialchars($m['char_class']) ?>" style="font-size:11px;"><?= htmlspecialchars($m['char_class']) ?></div>
              </div>
            </div>
            <div class="member-info">
              <div style="text-align:right;">
                <div class="member-score"><?= number_format($m['atul_score']) ?></div>
                <?php if (!empty($m['item_level'])): ?><div style="font-size:10px;color:var(--text-muted);">Lv<?= (int)$m['item_level'] ?></div><?php endif; ?>
                <?php if ($has_adj): ?><div style="font-size:10px;color:var(--class-heal);margin-top:1px;">보정: <?= number_format($m['atul_adjusted']) ?></div><?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php for ($empty_slot = count($member_list); $empty_slot < 4; $empty_slot++): ?>
          <div class="member-row" style="opacity:0.35;border:1px dashed rgba(255,255,255,0.1);border-radius:6px;background:rgba(255,255,255,0.02);">
            <div style="display:flex;align-items:center;gap:8px;">
              <div style="width:4px;height:28px;background:#444;border-radius:2px;flex-shrink:0;"></div>
              <div><div style="font-size:12px;color:var(--text-muted);font-weight:500;">공팟</div><div style="font-size:10px;color:#555;">빈 자리</div></div>
            </div>
          </div>
          <?php endfor; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

<?php endif; ?>

  </div>
</div>


<!-- 모달: 포스 추가 시간 선택 -->
<div id="addForceModal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('active')">
  <div class="modal" style="width:340px;">
    <div class="modal-title">⚔️ 포스 추가 — 시간 선택</div>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">추가할 포스의 시간대를 선택하세요. (30분 간격)</p>
    <?php
      $time_slots = [];
      for ($h = 19; $h <= 23; $h++) {
        $time_slots[] = sprintf('%02d:00', $h);
        if ($h < 23) $time_slots[] = sprintf('%02d:30', $h);
      }
    ?>
    <div class="form-group">
      <label class="form-label">시간대</label>
      <select id="addForceTimeSelect" class="form-select">
        <?php foreach ($time_slots as $slot): ?>
        <option value="<?= $slot ?>"><?= $slot ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="modal-actions">
      <button class="btn btn-secondary" onclick="document.getElementById('addForceModal').classList.remove('active')">취소</button>
      <button class="btn btn-primary" onclick="addForceAtTime(document.getElementById('addForceTimeSelect').value)">＋ 포스 추가</button>
    </div>
  </div>
</div>


<!-- 모달: 포스 구성 완료 확정 -->
<div id="finalizeModal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('active')">
  <div class="modal" style="border-color:rgba(201,168,76,0.5);">
    <div class="modal-title" style="color:var(--gold-light);">✅ 포스 구성 완료 확정</div>
    <p style="font-size:13px;color:var(--text-secondary);margin-bottom:8px;">
      현재 배치된 포스 구성을 확정하고 일반 유저에게 공개합니다.
    </p>
    <p style="font-size:12px;color:var(--text-muted);margin-bottom:16px;">
      확정 후에도 관리자 패널에서 "구성 수정하기"를 통해 다시 편집할 수 있습니다.
    </p>
    <div class="modal-actions">
      <button type="button" class="btn btn-secondary"
              onclick="document.getElementById('finalizeModal').classList.remove('active')">취소</button>
      <button type="button" class="btn btn-gold"
              onclick="document.getElementById('finalize-form').submit()">✅ 확정하고 공개</button>
    </div>
  </div>
</div>


<!-- 모달: 파티 재구성 (전체 초기화) -->
<div id="recomposeModal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('active')">
  <div class="modal" style="border-color:rgba(231,76,60,0.5);">
    <div class="modal-title" style="color:var(--red-light);">⚠️ 전체 초기화 확인</div>
    <div style="background:rgba(192,57,43,0.1);border:1px solid rgba(192,57,43,0.3);border-radius:6px;
                padding:12px;margin-bottom:16px;font-size:13px;color:var(--text-secondary);line-height:1.8;">
      이 작업은 <strong style="color:var(--red-light);">되돌릴 수 없습니다.</strong><br>
      • 구성된 모든 포스 / 파티 삭제<br>
      • 모든 신청자 데이터 삭제<br>
      • 시즌 상태 → <strong style="color:var(--gold-light);">구성중</strong> 초기화
    </div>
    <form method="POST">
      <input type="hidden" name="full_reset_and_recompose" value="1">
      <input type="hidden" name="season_id" value="<?= $current_season_id ?>">
      <div class="form-group">
        <label class="form-label" style="color:var(--red-light);">관리자 비밀번호 확인</label>
        <input type="password" name="confirm_password" class="form-input"
               style="border-color:rgba(192,57,43,0.5);" placeholder="비밀번호 입력" required>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary"
                onclick="document.getElementById('recomposeModal').classList.remove('active')">취소</button>
        <button type="submit" class="btn"
                style="background:linear-gradient(135deg,#7a0f0f,#c0392b);border-color:#e74c3c;
                       color:#fff;font-weight:700;">
          모두 초기화 후 구성중으로
        </button>
      </div>
    </form>
  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
function exportForcesImage(ev) {
  const src = document.getElementById('force-grid-capture');
  if (!src) return;
  const btn = ev ? ev.currentTarget : (window.event && window.event.currentTarget);
  if (btn) { btn.disabled = true; btn.textContent = '⏳ 생성 중...'; }

  const wrap = document.createElement('div');
  wrap.style.cssText = 'position:fixed;left:-99999px;top:0;padding:24px;background:#0f1419;';
  const row = src.cloneNode(true);
  row.style.display = 'flex';
  row.style.flexWrap = 'nowrap';
  row.style.gap = '16px';
  row.style.width = 'auto';
  row.querySelectorAll('.force-card').forEach(c => {
    c.style.flex = '0 0 340px';
    c.style.width = '340px';
  });
  wrap.appendChild(row);
  document.body.appendChild(wrap);

  html2canvas(wrap, { backgroundColor: '#0f1419', scale: 2, useCORS: true, logging: false })
    .then(canvas => {
      const link = document.createElement('a');
      const ts = new Date().toISOString().slice(0,16).replace(/[:T]/g,'-');
      link.download = `sanctuary-forces-${ts}.png`;
      link.href = canvas.toDataURL('image/png');
      link.click();
    })
    .catch(err => { alert('이미지 생성 실패: ' + err.message); })
    .finally(() => {
      document.body.removeChild(wrap);
      if (btn) { btn.disabled = false; btn.textContent = '📸 포스 이미지 저장'; }
    });
}
</script>
