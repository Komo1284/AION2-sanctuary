<?php
$notice_id = (int)($_GET['id'] ?? 0);
$n = null;
if ($notice_id) {
    $q = $pdo->prepare("SELECT * FROM sanctuary_notices WHERE id = ?");
    $q->execute([$notice_id]);
    $n = $q->fetch();
}

if (!$n):
?>
<div class="content-panel">
  <div class="panel-header"><div class="panel-title">📋 공지사항</div></div>
  <div class="panel-body">
    <div class="empty-state"><div class="empty-icon">📭</div><p>공지사항을 찾을 수 없습니다.</p></div>
    <div style="margin-top:16px;"><a href="?tab=notices&nav=list" class="btn btn-secondary">← 목록으로</a></div>
  </div>
</div>
<?php else: ?>
<div class="content-panel">
  <div class="panel-header">
    <div class="panel-title">📋 공지사항</div>
    <a href="?tab=notices&nav=list" class="btn btn-secondary" style="padding:6px 14px;font-size:12px;">← 목록</a>
  </div>
  <div class="panel-body">
    <div class="notice-detail">
      <div class="notice-detail-title"><?= htmlspecialchars($n['title']) ?></div>
      <div class="notice-detail-meta"><?= date('Y년 m월 d일 H:i', strtotime($n['created_at'] . ' UTC')) ?></div>
      <div class="notice-detail-body"><?= htmlspecialchars($n['content']) ?></div>
    </div>

    <?php if ($is_admin): ?>
    <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
      <form method="POST" onsubmit="return confirm('이 공지사항을 삭제하시겠습니까?');">
        <input type="hidden" name="delete_notice" value="1">
        <input type="hidden" name="notice_id" value="<?= $n['id'] ?>">
        <button type="submit" class="btn btn-danger" style="font-size:12px;padding:6px 14px;">🗑️ 삭제</button>
      </form>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
