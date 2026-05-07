<?php
$season_status = $current_season['status'] ?? '';

$forces_q = $pdo->prepare("
    SELECT f.*, COUNT(pm.id) as member_count
    FROM sanctuary_forces f
    LEFT JOIN sanctuary_parties p  ON p.force_id  = f.id
    LEFT JOIN sanctuary_party_members pm ON pm.party_id = p.id
    WHERE f.season_id = ?
    GROUP BY f.id
    ORDER BY f.force_number ASC
");
$forces_q->execute([$current_season_id]);
$pub_forces = $forces_q->fetchAll();
?>
<div class="content-panel">
  <div class="panel-header">
    <div class="panel-title">⚔️ 포스 리스트 — <?= htmlspecialchars($current_season['name']) ?></div>
    <span class="status-badge <?= $season_status ?>"><?= $season_status ?></span>
  </div>
  <div class="panel-body">

    <?php if ($season_status === '구성중'): ?>
    <div class="empty-state">
      <div class="empty-icon">⚙️</div>
      <p>포스 구성이 진행 중입니다.<br>확정되면 이 곳에 공개됩니다.</p>
    </div>
    <?php elseif (empty($pub_forces)): ?>
    <div class="empty-state">
      <div class="empty-icon">⚔️</div>
      <p>아직 포스가 구성되지 않았습니다.</p>
    </div>
    <?php else: ?>

    <?php
    $cls_map = ['수호성'=>'guardian','검성'=>'sword','살성'=>'kill','궁성'=>'bow',
                '호법성'=>'hobeop','정령성'=>'spirit','마도성'=>'mage','치유성'=>'heal'];
    ?>
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;flex-wrap:wrap;">
      <div style="font-size:13px;color:var(--text-muted);">
        총 <strong style="color:var(--gold-light);"><?= count($pub_forces) ?>개</strong> 포스가 구성되어 있습니다.
      </div>
      <button type="button" onclick="exportForcesImage(event)" class="btn btn-primary"
              style="padding:8px 16px;font-size:12px;font-weight:700;">
        📸 포스 이미지 저장
      </button>
    </div>

    <div id="force-grid-capture" class="force-grid">
      <?php foreach ($pub_forces as $force): ?>
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
          <?php foreach ($member_list as $m):
            $cls_key = $cls_map[$m['char_class']] ?? 'secondary';
          ?>
          <div class="member-row">
            <div style="display:flex;align-items:center;gap:8px;">
              <div style="width:4px;height:28px;background:var(--class-<?= $cls_key ?>);border-radius:2px;flex-shrink:0;"></div>
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
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php for ($empty_slot = count($member_list); $empty_slot < 4; $empty_slot++): ?>
          <div class="member-row" style="opacity:0.3;border:1px dashed rgba(255,255,255,0.1);border-radius:6px;background:rgba(255,255,255,0.02);">
            <div style="display:flex;align-items:center;gap:8px;">
              <div style="width:4px;height:28px;background:#444;border-radius:2px;flex-shrink:0;"></div>
              <div>
                <div style="font-size:12px;color:var(--text-muted);font-weight:500;">공팟</div>
                <div style="font-size:10px;color:#555;">빈 자리</div>
              </div>
            </div>
          </div>
          <?php endfor; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>
</div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script>
function exportForcesImage(ev) {
  const src = document.getElementById('force-grid-capture');
  if (!src) return;
  const btn = ev ? ev.currentTarget : null;
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
