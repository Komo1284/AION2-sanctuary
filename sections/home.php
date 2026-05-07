<?php
// 멤버 목록
$members = $pdo->query("SELECT * FROM sanctuary_legion_members ORDER BY main_atul DESC")->fetchAll();

// 부캐 목록 (멤버 ID 기준으로 그룹핑)
$all_subs = [];
foreach ($pdo->query("SELECT * FROM sanctuary_legion_member_subs ORDER BY member_id, id")->fetchAll() as $s) {
    $all_subs[$s['member_id']][] = $s;
}
foreach ($members as &$m) {
    $m['subs'] = $all_subs[$m['id']] ?? [];
}
unset($m);

// 신청 현황: 직접 신청 + 깐부 등록 모두 SQL에서 JOIN으로 처리
// (PHP 문자열 비교 대신 MySQL collation 비교로 정확도 향상)
$applied_rows = $pdo->query("
    SELECT m.id AS member_id, all_apps.season_id
    FROM sanctuary_legion_members m
    INNER JOIN (
        SELECT sa.season_id, sc.char_name AS cname
        FROM sanctuary_applications sa
        JOIN sanctuary_characters sc ON sc.application_id = sa.id
        WHERE sa.applicant_ip != 'buddy_synthesized'
        UNION
        SELECT sa.season_id, sb.buddy_name AS cname
        FROM sanctuary_applications sa
        JOIN sanctuary_buddies sb ON sb.application_id = sa.id
    ) AS all_apps ON TRIM(m.main_name) = TRIM(all_apps.cname)
    GROUP BY m.id, all_apps.season_id
")->fetchAll();

$applied_map = [];
foreach ($applied_rows as $r) {
    $applied_map[$r['member_id']][] = (string)$r['season_id'];
}
foreach ($members as &$m) {
    $m['applied_seasons_arr'] = $applied_map[$m['id']] ?? [];
}
unset($m);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>숲 — 레기온 포스 관리</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@300;400;500;700;900&display=swap" rel="stylesheet">
<style>
:root {
  --bg-dark:#0a0c14; --bg-panel:#0f1220; --bg-card:#141828; --bg-hover:#1a2035;
  --border:#1e2840; --border-bright:#2a3a5c;
  --gold:#c9a84c; --gold-light:#f0c96a; --gold-dark:#8a6830;
  --blue:#3a7bd5; --blue-light:#5a9bf5;
  --red:#c0392b; --red-light:#e74c3c;
  --green-light:#2ecc71;
  --text-primary:#e8eaf0; --text-secondary:#8a9ab8; --text-muted:#4a5a78;
}
*{margin:0;padding:0;box-sizing:border-box;}
body{font-family:'Noto Sans KR',sans-serif;background:var(--bg-dark);color:var(--text-primary);min-height:100vh;
  background-image:radial-gradient(ellipse at 20% 50%,rgba(58,123,213,.05) 0%,transparent 60%),
                   radial-gradient(ellipse at 80% 20%,rgba(108,61,201,.05) 0%,transparent 60%);}

/* ── Header ── */
.h-bar{background:linear-gradient(180deg,#0d1525,#080c18);border-bottom:1px solid var(--border);
  padding:0 32px;height:60px;display:flex;align-items:center;justify-content:space-between;
  position:sticky;top:0;z-index:100;box-shadow:0 2px 20px rgba(0,0,0,.5);}
.h-logo{display:flex;align-items:baseline;gap:10px;text-decoration:none;}
.h-legion{font-size:28px;font-weight:900;color:var(--gold-light);letter-spacing:-1px;}
.h-sub{font-size:11px;color:var(--text-muted);letter-spacing:1px;}
.h-actions{display:flex;align-items:center;gap:8px;}
.admin-badge{display:inline-flex;align-items:center;gap:4px;padding:4px 10px;
  background:rgba(201,168,76,.15);border:1px solid var(--gold-dark);border-radius:4px;font-size:11px;color:var(--gold);}
.btn-sm{padding:6px 14px;border-radius:5px;font-size:12px;font-weight:500;cursor:pointer;
  border:1px solid;text-decoration:none;transition:all .2s;display:inline-flex;align-items:center;gap:4px;font-family:inherit;background:none;}
.btn-admin{background:rgba(201,168,76,.1);border-color:var(--gold-dark);color:var(--gold-light);}
.btn-admin:hover{background:rgba(201,168,76,.2);}
.btn-logout{background:rgba(192,57,43,.1);border-color:var(--red);color:var(--red-light);}
.btn-logout:hover{background:rgba(192,57,43,.2);}

/* ── Body ── */
.home-body{max-width:1200px;margin:0 auto;padding:36px 24px;}

/* ── Nav Cards ── */
.nav-cards{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;margin-bottom:40px;}
.nav-card{background:var(--bg-panel);border:1px solid var(--border);border-radius:14px;
  padding:28px 20px;text-decoration:none;text-align:center;
  display:flex;flex-direction:column;align-items:center;gap:10px;
  transition:all .2s;cursor:pointer;}
.nav-card:hover{border-color:var(--gold-dark);background:var(--bg-hover);transform:translateY(-3px);box-shadow:0 10px 28px rgba(0,0,0,.35);}
.nav-card-eyebrow{font-size:10px;font-weight:700;color:var(--text-muted);letter-spacing:1.5px;text-transform:uppercase;}
.nav-card-name{font-size:22px;font-weight:900;color:var(--text-primary);}
.nav-card-notice .nav-card-name{font-size:19px;color:var(--blue-light);}
.status-badge{display:inline-block;padding:2px 8px;border-radius:3px;font-size:10px;font-weight:700;letter-spacing:.5px;}
.status-badge.모집중{background:rgba(26,138,74,.2);color:var(--green-light);border:1px solid rgba(26,138,74,.4);}
.status-badge.모집종료{background:rgba(192,57,43,.2);color:var(--red-light);border:1px solid rgba(192,57,43,.4);}

/* ── Member Panel ── */
.mp{background:var(--bg-panel);border:1px solid var(--border);border-radius:10px;overflow:hidden;}
.mp-head{padding:16px 20px;border-bottom:1px solid var(--border);background:rgba(0,0,0,.2);
  display:flex;align-items:center;justify-content:space-between;}
.mp-title{font-size:15px;font-weight:700;color:var(--text-primary);}
.mt{width:100%;border-collapse:collapse;}
.mt th{padding:10px 16px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;
  letter-spacing:1px;border-bottom:1px solid var(--border);text-align:left;
  background:rgba(0,0,0,.15);white-space:nowrap;}
.mt td{padding:12px 16px;border-bottom:1px solid rgba(30,40,64,.4);vertical-align:middle;}
.mt tr:last-child td{border-bottom:none;}
.mt tr:hover td{background:var(--bg-hover);}
.m-char{display:flex;align-items:center;gap:6px;}
.bar-main{width:3px;height:16px;background:var(--gold);border-radius:2px;flex-shrink:0;}
.bar-sub{width:3px;height:14px;background:var(--border-bright);border-radius:2px;flex-shrink:0;}
.m-name{font-size:13px;font-weight:600;color:var(--text-primary);}
.m-name-sub{font-size:13px;font-weight:500;color:var(--text-secondary);}
.m-score{font-size:11px;color:var(--gold-light);font-weight:700;background:rgba(201,168,76,0.1);border:1px solid rgba(201,168,76,0.35);border-radius:4px;padding:2px 7px;letter-spacing:0.2px;}
.m-score-sub{font-size:10px;color:var(--gold-light);font-weight:600;background:rgba(201,168,76,0.07);border:1px solid rgba(201,168,76,0.25);border-radius:3px;padding:1px 5px;}
.cls{display:inline-flex;align-items:center;font-weight:600;font-size:12px;}
.cls-수호성{color:#5774B6}.cls-검성{color:#7DD3E2}.cls-살성{color:#91D191}.cls-궁성{color:#6BB693}
.cls-호법성{color:#B0824E}.cls-정령성{color:#8F5192}.cls-마도성{color:#A07FDA}.cls-치유성{color:#DECA74}
.ap-y{color:var(--green-light);font-size:16px;font-weight:900;}
.ap-n{color:var(--text-muted);font-size:14px;}
.td-center{text-align:center;}

/* ── Buttons ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:6px;
  font-size:13px;font-weight:600;cursor:pointer;border:1px solid;transition:all .2s;
  text-decoration:none;font-family:inherit;}
.btn-gold{background:linear-gradient(135deg,var(--gold-dark),var(--gold));border-color:var(--gold);color:#0a0c14;font-weight:700;}
.btn-gold:hover{filter:brightness(1.1);}
.btn-secondary{background:rgba(30,40,64,.5);border-color:var(--border);color:var(--text-secondary);}
.btn-secondary:hover{background:var(--bg-hover);color:var(--text-primary);}
.btn-icon{padding:5px 8px;border-radius:5px;font-size:13px;cursor:pointer;
  border:1px solid var(--border);background:rgba(30,40,64,.5);transition:all .2s;font-family:inherit;}
.btn-icon:hover{background:var(--bg-hover);border-color:var(--border-bright);}

/* ── Modal ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);
  z-index:1000;align-items:center;justify-content:center;}
.modal-overlay.active{display:flex;}
.modal{background:var(--bg-panel);border:1px solid var(--border-bright);border-radius:12px;padding:24px;
  width:480px;max-width:90vw;box-shadow:0 20px 60px rgba(0,0,0,.5);max-height:90vh;overflow-y:auto;}
.modal-title{font-size:16px;font-weight:700;color:var(--gold-light);margin-bottom:20px;display:flex;align-items:center;gap:8px;}
.modal-actions{display:flex;gap:8px;justify-content:flex-end;margin-top:20px;}
.form-group{margin-bottom:14px;}
.form-label{display:block;margin-bottom:6px;font-size:11px;font-weight:700;color:var(--text-muted);
  letter-spacing:.5px;text-transform:uppercase;}
.char-row{display:flex;gap:8px;align-items:center;background:var(--bg-card);border:1px solid var(--border);
  border-radius:8px;padding:10px;flex-wrap:wrap;}
.char-input{flex:1;min-width:120px;padding:7px 10px;background:var(--bg-dark);border:1px solid var(--border);
  border-radius:5px;color:var(--text-primary);font-size:13px;font-family:inherit;outline:none;}
.char-input:focus{border-color:var(--blue);}
.char-result{font-size:12px;min-width:120px;}
.atul-score{background:rgba(201,168,76,.12);border:1px solid rgba(201,168,76,.45);border-radius:4px;padding:3px 10px;color:var(--gold-light);font-weight:700;font-size:13px;letter-spacing:0.3px;}
.spinner{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.2);
  border-top-color:var(--gold);border-radius:50%;animation:spin .6s linear infinite;vertical-align:middle;}
@keyframes spin{to{transform:rotate(360deg);}}
.alert{padding:12px 16px;border-radius:6px;margin-bottom:24px;font-size:13px;display:flex;align-items:center;gap:8px;}
.alert-success{background:rgba(26,138,74,.15);border:1px solid rgba(26,138,74,.3);color:var(--green-light);}
.alert-error{background:rgba(192,57,43,.15);border:1px solid rgba(192,57,43,.3);color:var(--red-light);}
@media(max-width:768px){.home-body{padding:20px 12px;}.nav-cards{grid-template-columns:1fr 1fr;}.mt th,.mt td{padding:8px 10px;font-size:12px;}}
</style>
</head>
<body>

<header class="h-bar">
  <a href="index.php" class="h-logo">
    <span class="h-legion">숲</span>
    <span class="h-sub">AION 2 LEGION</span>
  </a>
  <div class="h-actions">
    <?php if ($is_admin): ?>
      <span class="admin-badge">👑 관리자</span>
      <a href="index.php?admin_logout=1" class="btn-sm btn-logout">로그아웃</a>
    <?php else: ?>
      <button onclick="document.getElementById('adminModal').classList.add('active')" class="btn-sm btn-admin">🔐 관리자</button>
    <?php endif; ?>
  </div>
</header>

<div class="home-body">

  <?php if ($message): ?>
  <div class="alert alert-<?= $message_type ?>">
    <?= $message_type === 'success' ? '✅' : '❌' ?> <?= htmlspecialchars($message) ?>
  </div>
  <?php endif; ?>

  <!-- 시즌·공지 네비 카드 -->
  <div class="nav-cards">
    <?php foreach ($seasons as $s): ?>
    <a href="index.php?tab=season&season=<?= $s['id'] ?>" class="nav-card">
      <span class="nav-card-eyebrow">시즌</span>
      <span class="nav-card-name"><?= htmlspecialchars($s['name']) ?></span>
      <span class="status-badge <?= $s['status'] ?>"><?= $s['status'] ?></span>
    </a>
    <?php endforeach; ?>
    <a href="index.php?tab=notices&nav=list" class="nav-card nav-card-notice">
      <span class="nav-card-eyebrow">게시판</span>
      <span class="nav-card-name">📋 공지사항</span>
    </a>
  </div>

  <!-- 레기온 멤버 리스트 -->
  <div class="mp">
    <div class="mp-head">
      <span class="mp-title">
        ⚔️ 레기온 멤버
        <span id="memberCount" style="font-size:13px;color:var(--text-muted);font-weight:400;margin-left:6px;">총 <?= count($members) ?>명</span>
      </span>
      <div style="display:flex;align-items:center;gap:8px;">
        <input type="text" id="memberSearch" placeholder="캐릭터명 검색..."
               style="padding:6px 12px;background:var(--bg-dark);border:1px solid var(--border);border-radius:6px;
                      color:var(--text-primary);font-size:13px;font-family:inherit;outline:none;width:180px;"
               oninput="filterMembers(this.value)">
        <?php if ($is_admin): ?>
        <button onclick="openAddModal()" class="btn btn-gold" style="padding:7px 16px;font-size:13px;">+ 멤버 추가</button>
        <?php endif; ?>
      </div>
    </div>

    <table class="mt">
      <thead>
        <tr>
          <th style="width:36px;">#</th>
          <th>본캐</th>
          <th>부캐</th>
          <?php foreach ($seasons as $s): ?>
          <th class="td-center"><?= htmlspecialchars($s['name']) ?></th>
          <?php endforeach; ?>
          <?php if ($is_admin): ?><th class="td-center">관리</th><?php endif; ?>
        </tr>
      </thead>
      <tbody id="memberTableBody">
        <?php foreach ($members as $i => $m):
          $app_s = $m['applied_seasons_arr'];
          $search_names = array_map('mb_strtolower', array_filter(
              array_merge([$m['main_name']], array_column($m['subs'], 'sub_name'))
          ));
        ?>
        <tr data-names="<?= htmlspecialchars(implode('|', $search_names)) ?>">
          <td style="color:var(--text-muted);font-size:12px;"><?= $i + 1 ?></td>
          <td>
            <div class="m-char">
              <span class="bar-main"></span>
              <span class="m-name"><?= htmlspecialchars($m['main_name']) ?></span>
              <?php if ($m['main_class']): ?>
              <span class="cls cls-<?= htmlspecialchars($m['main_class']) ?>"><?= htmlspecialchars($m['main_class']) ?></span>
              <?php endif; ?>
              <?php if ($m['main_atul']): ?>
              <span class="m-score"><?= number_format($m['main_atul']) ?></span>
              <?php endif; ?>
              <?php if (!empty($m['main_item_level'])): ?>
              <span style="font-size:11px;color:var(--text-muted);">Lv<?= (int)$m['main_item_level'] ?></span>
              <?php endif; ?>
            </div>
          </td>
          <td>
            <?php if (!empty($m['subs'])): ?>
              <?php foreach ($m['subs'] as $sub): ?>
              <div class="m-char" style="margin-bottom:3px;">
                <span class="bar-sub"></span>
                <span class="m-name-sub"><?= htmlspecialchars($sub['sub_name']) ?></span>
                <?php if ($sub['sub_class']): ?>
                <span class="cls cls-<?= htmlspecialchars($sub['sub_class']) ?>"><?= htmlspecialchars($sub['sub_class']) ?></span>
                <?php endif; ?>
                <?php if ($sub['sub_atul']): ?>
                <span class="m-score-sub"><?= number_format($sub['sub_atul']) ?></span>
                <?php endif; ?>
                <?php if (!empty($sub['sub_item_level'])): ?>
                <span style="font-size:10px;color:var(--text-muted);">Lv<?= (int)$sub['sub_item_level'] ?></span>
                <?php endif; ?>
              </div>
              <?php endforeach; ?>
            <?php else: ?>
            <span style="color:var(--text-muted);font-size:12px;">—</span>
            <?php endif; ?>
          </td>
          <?php foreach ($seasons as $s): ?>
          <td class="td-center">
            <?= in_array((string)$s['id'], $app_s)
              ? '<span class="ap-y">✓</span>'
              : '<span class="ap-n">✗</span>' ?>
          </td>
          <?php endforeach; ?>
          <?php if ($is_admin): ?>
          <td class="td-center">
            <div style="display:flex;gap:4px;justify-content:center;">
              <button onclick='openEditModal(<?= json_encode($m, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' class="btn-icon" title="수정">✏️</button>
              <form method="POST" onsubmit="return confirm('멤버를 삭제하시겠습니까?')" style="display:inline;">
                <input type="hidden" name="delete_member" value="1">
                <input type="hidden" name="member_id" value="<?= $m['id'] ?>">
                <button type="submit" class="btn-icon" title="삭제">🗑️</button>
              </form>
            </div>
          </td>
          <?php endif; ?>
        </tr>
        <?php endforeach; ?>
        <?php if (empty($members)): ?>
        <tr>
          <td colspan="<?= 3 + count($seasons) + ($is_admin ? 1 : 0) ?>"
              style="text-align:center;padding:52px 20px;color:var(--text-muted);">
            등록된 멤버가 없습니다.
          </td>
        </tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

</div>

<!-- 멤버 추가/수정 모달 -->
<div id="memberModal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('active')">
  <div class="modal">
    <div class="modal-title" id="modalTitle">⚔️ 멤버 추가</div>
    <form method="POST" id="memberForm">
      <input type="hidden" id="formAction" name="add_member" value="1">
      <input type="hidden" id="fMid" name="member_id" value="">

      <div class="form-group">
        <div class="form-label">본캐 정보 <span style="color:var(--red-light);">*</span></div>
        <div class="char-row">
          <input type="text" name="main_name" id="iMainName" class="char-input"
                 placeholder="본캐 캐릭터명" required oninput="clearScore()">
          <input type="hidden" name="main_class"      id="iMainClass"     value="">
          <input type="hidden" name="main_atul"       id="iMainAtul"      value="0">
          <input type="hidden" name="main_item_level" id="iMainItemLevel" value="">
          <button type="button" class="btn btn-secondary"
                  style="padding:6px 12px;font-size:12px;white-space:nowrap;"
                  onclick="fetchScore()">🔍 조회</button>
          <span id="rMain" class="char-result"></span>
        </div>
      </div>

      <div class="form-group">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
          <div class="form-label" style="margin:0;">부캐 정보 <span style="color:var(--text-muted);font-weight:400;">(선택)</span></div>
          <button type="button" class="btn btn-secondary"
                  style="padding:4px 10px;font-size:11px;"
                  onclick="addSubRow()">+ 부캐 추가</button>
        </div>
        <div id="subRowsContainer"></div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn btn-secondary"
                onclick="document.getElementById('memberModal').classList.remove('active')">취소</button>
        <button type="submit" id="modalBtn" class="btn btn-gold">추가</button>
      </div>
    </form>
  </div>
</div>

<!-- 관리자 로그인 모달 -->
<div id="adminModal" class="modal-overlay" onclick="if(event.target===this)this.classList.remove('active')">
  <div class="modal" style="width:360px;">
    <div class="modal-title">🔐 관리자 인증</div>
    <form method="POST">
      <div class="form-group">
        <label class="form-label">관리자 비밀번호</label>
        <input type="password" name="admin_password"
               style="width:100%;padding:9px 12px;background:var(--bg-dark);border:1px solid var(--border);
                      border-radius:6px;color:var(--text-primary);font-size:13px;font-family:inherit;outline:none;"
               placeholder="비밀번호 입력" autofocus>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary"
                onclick="document.getElementById('adminModal').classList.remove('active')">취소</button>
        <button type="submit" name="admin_login" class="btn btn-gold">로그인</button>
      </div>
    </form>
  </div>
</div>

<script>
function filterMembers(q) {
  q = q.trim().toLowerCase();
  const rows = document.querySelectorAll('#memberTableBody tr[data-names]');
  let visible = 0;
  rows.forEach(tr => {
    const match = !q || tr.dataset.names.includes(q);
    tr.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  document.getElementById('memberCount').textContent = q
    ? `${visible} / <?= count($members) ?>명`
    : `총 <?= count($members) ?>명`;
}

let subRowIdx = 0;
const CLS_LIST = ['수호성','검성','살성','궁성','호법성','정령성','마도성','치유성'];
const manualStyle = 'padding:5px 8px;background:var(--bg-dark);border:1px solid var(--border);border-radius:5px;color:var(--text-primary);font-size:12px;font-family:inherit;outline:none;';

function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// 조회 실패 시 결과 영역에 직접 입력 필드 삽입 (apply.php와 동일한 방식)
function showManualFields(resEl, hiddenClassEl, hiddenAtulEl) {
  resEl.innerHTML =
    `<span style="color:var(--red-light);font-size:11px;white-space:nowrap;">조회 실패</span>`
    + `<input type="number" placeholder="아툴점수" min="0" style="${manualStyle}width:110px;">`
    + `<select style="${manualStyle}">`
    + `<option value="">직업 선택</option>`
    + CLS_LIST.map(c => `<option>${c}</option>`).join('')
    + `</select>`;
  resEl.querySelector('input').addEventListener('input',  function() { hiddenAtulEl.value  = this.value || '0'; });
  resEl.querySelector('select').addEventListener('change', function() { hiddenClassEl.value = this.value; });
}

/* ── 본캐 ── */
function clearScore() {
  document.getElementById('iMainAtul').value      = '0';
  document.getElementById('iMainClass').value     = '';
  document.getElementById('iMainItemLevel').value = '';
  document.getElementById('rMain').innerHTML      = '';
}

async function fetchScore() {
  const name  = document.getElementById('iMainName').value.trim();
  const resEl = document.getElementById('rMain');
  if (!name) { alert('캐릭터명을 입력하세요.'); return; }
  resEl.innerHTML = '<span class="spinner"></span>';
  try {
    const res  = await fetch(`actions/fetch_atul.php?name=${encodeURIComponent(name)}`);
    const data = await res.json();
    if (data.success) {
      document.getElementById('iMainAtul').value      = data.score || 0;
      document.getElementById('iMainClass').value     = data.job || '';
      document.getElementById('iMainItemLevel').value = data.item_level || '';
      const scoreText = data.score ? Number(data.score).toLocaleString() : '-';
      const ilText    = data.item_level ? ` <span style="font-size:11px;color:var(--text-muted);">Lv${data.item_level}</span>` : '';
      resEl.innerHTML = `<span class="atul-score">${scoreText}</span>${ilText}`
        + (data.job ? ` <span class="cls cls-${data.job}">${data.job}</span>` : '');
    } else { throw new Error(); }
  } catch {
    showManualFields(resEl, document.getElementById('iMainClass'), document.getElementById('iMainAtul'));
  }
}

/* ── 부캐 ── */
function addSubRow(name, cls, atul, itemLevel) {
  const idx  = subRowIdx++;
  const wrap = document.createElement('div');
  wrap.style.marginBottom = '8px';
  wrap.dataset.subIdx = idx;
  wrap.innerHTML = `
    <div class="char-row">
      <input type="text" name="sub_name[]" class="char-input" placeholder="부캐 캐릭터명"
             style="flex:2;" oninput="clearSubScore(${idx})" value="${escHtml(name||'')}">
      <input type="hidden" name="sub_class[]"      id="sClass${idx}"   value="${escHtml(cls||'')}">
      <input type="hidden" name="sub_atul[]"        id="sAtul${idx}"    value="${atul||0}">
      <input type="hidden" name="sub_item_level[]" id="sItemLevel${idx}" value="${itemLevel||''}">
      <button type="button" class="btn btn-secondary"
              style="padding:6px 12px;font-size:12px;white-space:nowrap;"
              onclick="fetchSubScore(${idx})">🔍 조회</button>
      <span id="sResult${idx}" class="char-result"></span>
      <button type="button" onclick="this.closest('[data-sub-idx]').remove()"
              style="background:none;border:none;color:var(--red-light);font-size:16px;cursor:pointer;padding:0 4px;line-height:1;">✕</button>
    </div>`;
  document.getElementById('subRowsContainer').appendChild(wrap);
  if (atul !== undefined && atul !== null) {
    const scoreText = parseInt(atul) > 0 ? Number(atul).toLocaleString() : '-';
    const ilText    = itemLevel ? ` <span style="font-size:11px;color:var(--text-muted);">Lv${itemLevel}</span>` : '';
    document.getElementById('sResult' + idx).innerHTML =
      `<span class="atul-score">${scoreText}</span>${ilText}`
      + (cls ? ` <span class="cls cls-${cls}">${cls}</span>` : '');
  }
}

function clearSubScore(idx) {
  document.getElementById('sAtul'     + idx).value = '0';
  document.getElementById('sClass'    + idx).value = '';
  document.getElementById('sItemLevel'+ idx).value = '';
  document.getElementById('sResult'   + idx).innerHTML = '';
}

async function fetchSubScore(idx) {
  const wrap   = document.querySelector(`[data-sub-idx="${idx}"]`);
  const nameEl = wrap.querySelector('input[name="sub_name[]"]');
  const resEl  = document.getElementById('sResult' + idx);
  const name   = nameEl.value.trim();
  if (!name) { alert('캐릭터명을 입력하세요.'); return; }
  resEl.innerHTML = '<span class="spinner"></span>';
  try {
    const res  = await fetch(`actions/fetch_atul.php?name=${encodeURIComponent(name)}`);
    const data = await res.json();
    if (data.success) {
      document.getElementById('sAtul'     + idx).value = data.score || 0;
      document.getElementById('sClass'    + idx).value = data.job || '';
      document.getElementById('sItemLevel'+ idx).value = data.item_level || '';
      const scoreText = data.score ? Number(data.score).toLocaleString() : '-';
      const ilText    = data.item_level ? ` <span style="font-size:11px;color:var(--text-muted);">Lv${data.item_level}</span>` : '';
      resEl.innerHTML = `<span class="atul-score">${scoreText}</span>${ilText}`
        + (data.job ? ` <span class="cls cls-${data.job}">${data.job}</span>` : '');
    } else { throw new Error(); }
  } catch {
    showManualFields(resEl, document.getElementById('sClass' + idx), document.getElementById('sAtul' + idx));
  }
}

/* ── 모달 열기 ── */
function openAddModal() {
  document.getElementById('modalTitle').textContent = '⚔️ 멤버 추가';
  document.getElementById('formAction').name = 'add_member';
  document.getElementById('fMid').value = '';
  document.getElementById('memberForm').reset();
  clearScore();
  document.getElementById('subRowsContainer').innerHTML = '';
  subRowIdx = 0;
  document.getElementById('modalBtn').textContent = '추가';
  document.getElementById('memberModal').classList.add('active');
}

function openEditModal(m) {
  document.getElementById('modalTitle').textContent = '✏️ 멤버 수정';
  document.getElementById('formAction').name = 'edit_member';
  document.getElementById('fMid').value = m.id;
  document.getElementById('iMainName').value      = m.main_name       || '';
  document.getElementById('iMainClass').value     = m.main_class      || '';
  document.getElementById('iMainAtul').value      = m.main_atul       || '0';
  document.getElementById('iMainItemLevel').value = m.main_item_level || '';
  const rMain = document.getElementById('rMain');
  if (m.main_atul !== null && m.main_atul !== undefined) {
    const scoreText = parseInt(m.main_atul) > 0 ? Number(m.main_atul).toLocaleString() : '-';
    const ilText    = m.main_item_level ? ` <span style="font-size:11px;color:var(--text-muted);">Lv${m.main_item_level}</span>` : '';
    rMain.innerHTML = `<span class="atul-score">${scoreText}</span>${ilText}`
      + (m.main_class ? ` <span class="cls cls-${m.main_class}">${m.main_class}</span>` : '');
  } else { rMain.innerHTML = ''; }
  document.getElementById('subRowsContainer').innerHTML = '';
  subRowIdx = 0;
  if (m.subs && m.subs.length > 0) {
    m.subs.forEach(s => addSubRow(s.sub_name, s.sub_class, s.sub_atul, s.sub_item_level));
  }
  document.getElementById('modalBtn').textContent = '저장';
  document.getElementById('memberModal').classList.add('active');
}
</script>
</body>
</html>
