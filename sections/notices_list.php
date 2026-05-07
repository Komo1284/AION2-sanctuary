<?php
// sanctuary_notices 테이블이 없으면 생성
$pdo->exec("
    CREATE TABLE IF NOT EXISTS sanctuary_notices (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        title      VARCHAR(200) NOT NULL,
        content    TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT NOW()
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

$notices_q = $pdo->query("SELECT id, title, created_at FROM sanctuary_notices ORDER BY id DESC");
$notices = $notices_q->fetchAll();
?>
<div class="content-panel">
  <div class="panel-header">
    <div class="panel-title">📋 공지사항</div>
    <?php if ($is_admin): ?>
    <a href="?tab=notices&nav=write" class="btn btn-gold" style="padding:6px 14px;font-size:12px;">✏️ 글쓰기</a>
    <?php endif; ?>
  </div>
  <div class="panel-body">

    <?php if (empty($notices)): ?>
    <div class="empty-state">
      <div class="empty-icon">📭</div>
      <p>등록된 공지사항이 없습니다.</p>
    </div>
    <?php else: ?>
    <table class="notice-list-table">
      <thead>
        <tr>
          <th style="width:50px;">#</th>
          <th>제목</th>
          <th style="width:120px;">작성일</th>
          <?php if ($is_admin): ?><th style="width:60px;"></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($notices as $i => $n): ?>
        <tr>
          <td class="notice-date"><?= count($notices) - $i ?></td>
          <td>
            <a href="?tab=notices&nav=detail&id=<?= $n['id'] ?>" class="notice-title-link">
              <?= htmlspecialchars($n['title']) ?>
            </a>
          </td>
          <td class="notice-date"><?= date('Y-m-d', strtotime($n['created_at'] . ' UTC')) ?></td>
          <?php if ($is_admin): ?>
          <td>
            <form method="POST" onsubmit="return confirm('이 공지사항을 삭제하시겠습니까?');" style="display:inline;">
              <input type="hidden" name="delete_notice" value="1">
              <input type="hidden" name="notice_id" value="<?= $n['id'] ?>">
              <button type="submit" class="btn btn-danger" style="padding:3px 8px;font-size:11px;">삭제</button>
            </form>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>

  </div>
</div>
