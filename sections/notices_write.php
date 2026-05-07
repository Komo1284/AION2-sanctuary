<?php if (!$is_admin): ?>
<div class="content-panel">
  <div class="panel-body">
    <div class="empty-state"><div class="empty-icon">🔐</div><p>관리자만 접근할 수 있습니다.</p></div>
  </div>
</div>
<?php else: ?>
<div class="content-panel">
  <div class="panel-header">
    <div class="panel-title">✏️ 공지사항 작성</div>
    <a href="?tab=notices&nav=list" class="btn btn-secondary" style="padding:6px 14px;font-size:12px;">← 목록</a>
  </div>
  <div class="panel-body">
    <form method="POST" style="max-width:700px;">
      <input type="hidden" name="write_notice" value="1">
      <div class="form-group">
        <label class="form-label">제목</label>
        <input type="text" name="notice_title" class="form-input" placeholder="공지 제목을 입력하세요" required>
      </div>
      <div class="form-group">
        <label class="form-label">내용</label>
        <textarea name="notice_content" class="form-textarea" rows="12" placeholder="공지 내용을 입력하세요" required></textarea>
      </div>
      <div style="display:flex;gap:8px;justify-content:flex-end;">
        <a href="?tab=notices&nav=list" class="btn btn-secondary">취소</a>
        <button type="submit" class="btn btn-gold">📌 등록</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>
