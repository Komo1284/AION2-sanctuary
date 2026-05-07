<?php
// 신청은 시즌 상태와 무관하게 상시 받는다
$season_status = $current_season['status'] ?? '';
$can_apply = true;
?>
<div class="content-panel">
  <div class="panel-header">
    <div class="panel-title">✍️ 포스 신청 — <?= htmlspecialchars($current_season['name']) ?></div>
    <span class="status-badge <?= $season_status ?>"><?= $season_status ?></span>
  </div>
  <div class="panel-body">

    <?php if (!$can_apply): ?>
    <div class="alert alert-warning">
      ⚠️ 현재 <strong><?= $season_status ?></strong> 상태입니다. 포스 신청이 불가능합니다.
    </div>
    <?php else: ?>

    <div class="alert alert-info" style="margin-bottom:20px;">
        ℹ️ 캐릭터명 입력 후 🔍 조회 버튼을 눌러 아툴 점수를 확인하세요. 자동조회가 불가한 경우 직접 입력할 수 있습니다.
      </div>

      <form method="POST" onsubmit="return validateApply()">
        <input type="hidden" name="apply_force" value="1">
        <input type="hidden" name="season_id" value="<?= $current_season_id ?>">

        <!-- 본캐 -->
        <div style="margin-bottom:12px;">
          <div style="font-size:12px;font-weight:700;color:var(--text-muted);letter-spacing:1px;text-transform:uppercase;margin-bottom:8px;">본캐 정보 <span style="color:var(--red-light);">*</span></div>
          <div class="char-row">
            <span class="char-type">본캐</span>
            <input type="text" name="main_name" class="char-name-input" placeholder="본캐 캐릭터명" required style="flex:2;" oninput="clearCharScore(this)">
            <input type="hidden" name="main_score" class="hidden-score" value="">
            <input type="hidden" name="main_class" class="hidden-class" value="">
            <input type="hidden" name="main_item_level" class="hidden-item-level" value="">
            <input type="hidden" name="main_legion" class="hidden-legion" value="">
            <button type="button" class="btn btn-secondary" style="padding:7px 12px;font-size:12px;white-space:nowrap;"
                    onclick="fetchAtulScore(
                        this.closest('.char-row').querySelector('input[name=main_name]'),
                        this.nextElementSibling,
                        this
                    )">🔍 조회</button>
            <span class="char-result"></span>
          </div>
        </div>

        <!-- 부캐 -->
        <div style="margin-bottom:12px;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
            <div style="font-size:12px;font-weight:700;color:var(--text-muted);letter-spacing:1px;text-transform:uppercase;">부캐 추가 (선택)</div>
            <button type="button" class="btn btn-secondary" style="padding:5px 12px;font-size:12px;" onclick="addSubChar()">+ 부캐 추가</button>
          </div>
          <div id="subCharsContainer"></div>
        </div>

        <div class="divider"></div>

        <div style="display:flex;gap:10px;align-items:center;">
          <button type="submit" class="btn btn-gold" style="padding:11px 24px;font-size:14px;">⚔️ 포스 신청하기</button>
          <span style="font-size:12px;color:var(--text-muted);">신청 후 관리자 확인까지 대기해주세요</span>
        </div>
      </form>

    <?php endif; ?>

    <!-- 신청 내역 확인 -->
    <?php
    $user_ip = $_SERVER['REMOTE_ADDR'];
    $apps = $pdo->prepare("
        SELECT sa.*, sc.char_name, sc.char_class, sc.atul_score
        FROM sanctuary_applications sa
        LEFT JOIN sanctuary_characters sc ON sc.application_id = sa.id AND sc.is_main = 1
        WHERE sa.season_id = ? AND sa.applicant_ip = ?
        ORDER BY sa.applied_at DESC LIMIT 3
    ");
    $apps->execute([$current_season_id, $user_ip]);
    $my_apps = $apps->fetchAll();
    ?>

    <?php if (!empty($my_apps)): ?>
    <div class="divider"></div>
    <div style="margin-top:4px;">
      <div style="font-size:13px;font-weight:700;color:var(--text-secondary);margin-bottom:12px;">📌 내 신청 내역</div>
      <?php foreach ($my_apps as $app): ?>
      <?php
        $chars = $pdo->prepare("SELECT * FROM sanctuary_characters WHERE application_id = ? ORDER BY is_main DESC");
        $chars->execute([$app['id']]);
        $app_chars = $chars->fetchAll();
      ?>
      <div style="background:var(--bg-card);border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:8px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:8px;">
          <div>
            <div style="font-size:12px;color:var(--text-muted);">신청일: <?= date('Y-m-d H:i', strtotime($app['applied_at'] . ' UTC')) ?></div>
          </div>
          <span class="status-badge <?= $app['status'] === '대기' ? '구성중' : '모집종료' ?>"><?= htmlspecialchars($app['status'] ?? '대기') ?></span>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
          <?php foreach ($app_chars as $ch): ?>
          <div style="display:flex;align-items:center;gap:6px;padding:5px 10px;background:var(--bg-dark);border-radius:5px;border:1px solid var(--border);">
            <span class="<?= $ch['is_main'] ? 'tag tag-main' : 'tag tag-sub' ?>"><?= $ch['is_main'] ? '본캐' : '부캐' ?></span>
            <span style="font-size:13px;font-weight:500;"><?= htmlspecialchars($ch['char_name']) ?></span>
            <span class="cls cls-<?= htmlspecialchars($ch['char_class']) ?>"><?= htmlspecialchars($ch['char_class']) ?></span>
            <span class="score-badge"><?= number_format($ch['atul_score']) ?></span>
            <?php if (!empty($ch['item_level'])): ?><span style="font-size:11px;color:var(--text-muted);">Lv<?= (int)$ch['item_level'] ?></span><?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>
</div>
