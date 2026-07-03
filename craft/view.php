<?php
$fmt = fn($n) => number_format((int)round($n));
$rageMats = $pdo->query("SELECT name FROM craft_materials WHERE category='분노' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$owned_options = ['없음'];
foreach (array_reverse($useful_tiers) as $t) { $owned_options[] = "{$t}의 {$acc}"; $owned_options[] = "빛나는 {$t}의 {$acc}"; }
?><!DOCTYPE html><html lang="ko"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>응룡왕 제작효율 계산</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;700;900&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Noto Sans KR',sans-serif;background:#0a0c14;color:#e8eaf0;padding:24px}
.wrap{max-width:1400px;margin:0 auto}
h1{font-size:22px;color:#f0c96a;margin-bottom:16px}
.controls{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
select{padding:9px 12px;background:#141828;border:1px solid #1e2840;border-radius:6px;color:#e8eaf0;font-family:inherit}
.routes-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;align-items:start;margin-bottom:20px}
@media(max-width:900px){.routes-grid{grid-template-columns:1fr}}
.route-card{background:#141828;border:1px solid #1e2840;border-radius:10px;padding:16px}
.route-card.best{border-color:#c9a84c;box-shadow:0 0 0 1px rgba(201,168,76,.3)}
.route-head{margin-bottom:10px;border-bottom:1px solid #1e2840;padding-bottom:10px}
.route-label{font-size:14px;font-weight:700;line-height:1.35;min-height:38px}
.route-cost{font-size:22px;font-weight:900;color:#f0c96a;margin-top:8px}
.route-ev{font-size:12px;color:#8a9ab8}
.bd{width:100%;border-collapse:collapse;font-size:12px;margin-top:4px}
.bd th,.bd td{padding:4px 6px;border-bottom:1px solid rgba(30,40,64,.5);text-align:left}
.bd th{color:#8a9ab8;font-weight:700}
.bd .num{text-align:right;white-space:nowrap}
.badge{display:inline-block;font-size:10px;padding:1px 6px;border-radius:3px;background:rgba(201,168,76,.15);color:#c9a84c;margin-left:6px}
.link{color:#5a9bf5;text-decoration:none;font-size:13px}
</style></head><body><div class="wrap">
<h1>⚒️ 응룡왕 제작효율 계산기</h1>
<?php $curGroup = craft_item_group($acc) ?? '장신구'; ?>
<div class="controls">
  <form method="get" style="display:inline-flex;gap:8px" id="pickForm">
    <select id="groupSel" onchange="swapItems()">
      <?php foreach (array_keys(CRAFT_CATEGORIES) as $g): ?>
        <option value="<?= $g ?>" <?= $g===$curGroup?'selected':'' ?>><?= $g ?></option>
      <?php endforeach ?>
    </select>
    <select name="acc" id="itemSel" onchange="this.form.submit()">
      <?php foreach (CRAFT_CATEGORIES[$curGroup] as $it): ?>
        <option value="<?= $it ?>" <?= $it===$acc?'selected':'' ?>><?= $it ?></option>
      <?php endforeach ?>
    </select>
  </form>
  <a class="link" href="craft.php?acc=<?= urlencode($acc) ?>#prices">↓ 재료 시세 편집</a>
</div>
<div id="ragePanel" style="background:#141828;border:1px solid #1e2840;border-radius:10px;padding:14px 16px;margin-bottom:20px">
  <div style="font-size:13px;font-weight:700;color:#f0c96a;margin-bottom:4px">🎒 보유 귀속(각인) 분노 재료</div>
  <div style="font-size:11px;color:#8a9ab8;margin-bottom:10px">보유 수량만큼 각 루트 비용에서 차감해 실질 비용을 보여줍니다. 이 정보는 내 브라우저에만 저장됩니다.</div>
  <div style="display:flex;flex-wrap:wrap;gap:8px 16px">
<?php foreach ($rageMats as $m): ?>
    <label style="display:flex;flex-direction:column;gap:3px;font-size:11px;color:#8a9ab8">
      <?= htmlspecialchars($m) ?>
      <input type="number" min="0" class="rage-input" data-mat="<?= htmlspecialchars($m) ?>" style="width:90px;padding:5px 8px;background:#0a0c14;border:1px solid #1e2840;border-radius:5px;color:#e8eaf0;font-family:inherit">
    </label>
<?php endforeach ?>
  </div>
</div>
<script>
const CRAFT_ITEMS = <?= json_encode(CRAFT_CATEGORIES, JSON_UNESCAPED_UNICODE) ?>;
function swapItems() {
  const g = document.getElementById('groupSel').value;
  const sel = document.getElementById('itemSel');
  sel.innerHTML = '';
  for (const it of CRAFT_ITEMS[g]) { const o = document.createElement('option'); o.value = it; o.textContent = it; sel.appendChild(o); }
  document.getElementById('pickForm').submit();   // 분류 바꾸면 그 분류 첫 품목으로 이동
}
</script>
<script>
const KEY = 'craft_owned_rage';

function getOwned() {
  try {
    const v = JSON.parse(localStorage.getItem(KEY) || '{}');
    return (v && typeof v === 'object' && !Array.isArray(v)) ? v : {};
  } catch (e) { return {}; }
}

function applyRage() {
  const owned = getOwned();
  document.querySelectorAll('.route-card').forEach(card => {
    const cost = parseInt(card.dataset.cost, 10);
    let ded = 0;
    card.querySelectorAll('tr[data-mat]').forEach(row => {
      const mat = row.dataset.mat;
      const qty = parseInt(row.dataset.qty, 10);
      const unit = parseInt(row.dataset.unit, 10);
      if (owned[mat] > 0 && unit > 0) {
        const use = Math.min(owned[mat], qty);
        ded += use * unit;
        // 귀속 차감 표시 (첫 번째 td에 span 추가/갱신)
        const td = row.querySelector('td');
        let sp = td.querySelector('.rage-ded');
        if (!sp) { sp = document.createElement('span'); sp.className = 'rage-ded'; sp.style.cssText = 'color:#2ecc71;font-size:10px;display:block'; td.appendChild(sp); }
        sp.textContent = '−' + use.toLocaleString('ko-KR') + '개 차감';
      } else {
        const sp = row.querySelector('.rage-ded');
        if (sp) sp.remove();
      }
    });
    const adjEl = card.querySelector('.route-adj');
    if (ded > 0) {
      adjEl.textContent = '귀속 차감 후 ' + (cost - ded).toLocaleString('ko-KR') + ' (−' + ded.toLocaleString('ko-KR') + ')';
      adjEl.style.display = '';
    } else {
      adjEl.style.display = 'none';
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  const owned = getOwned();
  document.querySelectorAll('.rage-input').forEach(inp => {
    const v = owned[inp.dataset.mat];
    if (v > 0) inp.value = v;
    inp.addEventListener('input', () => {
      const map = {};
      document.querySelectorAll('.rage-input').forEach(i => {
        const n = parseInt(i.value, 10);
        if (n > 0) map[i.dataset.mat] = n;
      });
      localStorage.setItem(KEY, JSON.stringify(map));
      applyRage();
      recalcPlanDeduction();
    });
  });
  applyRage();
  recalcPlanDeduction();
});
</script>

<script>
/* ── 캐릭터 검색 & 7부위 플랜 ── */
let lastPlanData = null;

async function searchCharacter() {
  const nickInput = document.getElementById('charNickInput');
  const nick = nickInput ? nickInput.value.trim() : '';
  if (!nick) return;

  const statusEl   = document.getElementById('charSearchStatus');
  const candidatesEl = document.getElementById('charCandidates');
  const planEl     = document.getElementById('charPlan');

  while (candidatesEl.firstChild) candidatesEl.removeChild(candidatesEl.firstChild);
  while (planEl.firstChild)       planEl.removeChild(planEl.firstChild);
  lastPlanData = null;

  statusEl.textContent  = '검색 중…';
  statusEl.style.display = '';

  try {
    const res  = await fetch('craft.php?plan=search&nick=' + encodeURIComponent(nick));
    const data = await res.json();

    if (!data.ok) {
      statusEl.textContent = data.error || '오류가 발생했습니다.';
      return;
    }

    if (!data.list || data.list.length === 0) {
      statusEl.textContent = '검색 결과가 없습니다.';
      return;
    }

    statusEl.style.display = 'none';

    for (const c of data.list) {
      const btn = document.createElement('button');
      btn.style.cssText = 'padding:6px 12px;background:#0a0c14;border:1px solid #1e2840;border-radius:6px;color:#e8eaf0;cursor:pointer;font-family:inherit;font-size:12px;transition:border-color .15s';
      btn.addEventListener('mouseenter', () => { btn.style.borderColor = '#c9a84c'; });
      btn.addEventListener('mouseleave', () => { btn.style.borderColor = '#1e2840'; });

      const nameSpan = document.createElement('span');
      nameSpan.style.fontWeight = '700';
      nameSpan.textContent = c.name;
      btn.appendChild(nameSpan);

      btn.appendChild(document.createTextNode(' · '));

      const serverSpan = document.createElement('span');
      serverSpan.style.color = '#8a9ab8';
      serverSpan.textContent = c.serverName;
      btn.appendChild(serverSpan);

      btn.appendChild(document.createTextNode(' · Lv.' + c.level));

      btn.addEventListener('click', () => loadCharPlan(c));
      candidatesEl.appendChild(btn);
    }
  } catch (e) {
    statusEl.textContent = '네트워크 오류: ' + e.message;
  }
}

async function loadCharPlan(c) {
  const planEl = document.getElementById('charPlan');
  while (planEl.firstChild) planEl.removeChild(planEl.firstChild);
  const loadingDiv = document.createElement('div');
  loadingDiv.style.cssText = 'font-size:12px;color:#8a9ab8;padding:8px 0';
  loadingDiv.textContent = '불러오는 중…';
  planEl.appendChild(loadingDiv);
  lastPlanData = null;

  try {
    const url = 'craft.php?plan=equip'
      + '&characterId=' + c.characterId   // already URL-encoded from search API
      + '&serverId='    + encodeURIComponent(c.serverId)
      + '&charName='    + encodeURIComponent(c.name)
      + '&serverName='  + encodeURIComponent(c.serverName);
    const res  = await fetch(url);
    const data = await res.json();

    while (planEl.firstChild) planEl.removeChild(planEl.firstChild);

    if (!data.ok) {
      const errDiv = document.createElement('div');
      errDiv.style.cssText = 'color:#e74c3c;font-size:13px;padding:6px 0';
      errDiv.textContent = data.error || '불러오기 실패';
      planEl.appendChild(errDiv);
      return;
    }

    lastPlanData = data;
    renderPlan(data);
  } catch (e) {
    while (planEl.firstChild) planEl.removeChild(planEl.firstChild);
    const errDiv = document.createElement('div');
    errDiv.style.cssText = 'color:#e74c3c;font-size:13px;padding:6px 0';
    errDiv.textContent = '네트워크 오류: ' + e.message;
    planEl.appendChild(errDiv);
  }
}

function renderPlan(data) {
  const planEl = document.getElementById('charPlan');
  while (planEl.firstChild) planEl.removeChild(planEl.firstChild);

  /* 헤더 */
  const hdr = document.createElement('div');
  hdr.style.cssText = 'font-size:14px;font-weight:700;color:#f0c96a;margin-bottom:10px;padding-top:8px;border-top:1px solid #1e2840';
  hdr.textContent = data.character.name + ' @' + data.character.serverName + ' — 7부위 응룡왕 플랜';
  planEl.appendChild(hdr);

  /* 슬롯 테이블 */
  const table = document.createElement('table');
  table.className = 'bd';
  table.style.marginBottom = '12px';

  const thead = document.createElement('thead');
  const htr   = document.createElement('tr');
  [['부위', ''], ['착용 장비', ''], ['상태', ''], ['추천 루트', ''], ['비용', 'num']].forEach(([t, cls]) => {
    const th = document.createElement('th');
    if (cls) th.className = cls;
    th.textContent = t;
    htr.appendChild(th);
  });
  thead.appendChild(htr);
  table.appendChild(thead);

  const tbody = document.createElement('tbody');
  const slotOrder   = ['무기', '가더', '목걸이', '귀걸이1', '귀걸이2', '반지1', '반지2'];
  const statusColor = {
    '완료':    '#2ecc71',
    '계승 제작': '#f0c96a',
    '신규 제작': '#e74c3c',
    '미착용':  '#8a9ab8',
    '판별 불가': '#8a9ab8'
  };

  const slotMap = {};
  for (const s of data.slots) slotMap[s.slot] = s;

  for (const slotName of slotOrder) {
    const s = slotMap[slotName];
    if (!s) continue;

    const tr = document.createElement('tr');

    const tdSlot = document.createElement('td');
    tdSlot.style.whiteSpace = 'nowrap';
    tdSlot.textContent = s.slot;
    tr.appendChild(tdSlot);

    const tdEquipped = document.createElement('td');
    tdEquipped.style.fontSize  = '11px';
    tdEquipped.style.color     = '#c8d0e0';
    tdEquipped.textContent = s.equipped || '—';
    tr.appendChild(tdEquipped);

    const tdStatus = document.createElement('td');
    const sBadge   = document.createElement('span');
    sBadge.style.color      = statusColor[s.status] || '#8a9ab8';
    sBadge.style.fontWeight = '700';
    sBadge.style.fontSize   = '11px';
    sBadge.textContent = s.status;
    tdStatus.appendChild(sBadge);
    tr.appendChild(tdStatus);

    const tdRoute = document.createElement('td');
    tdRoute.style.fontSize = '11px';
    tdRoute.style.color    = '#8a9ab8';
    tdRoute.textContent = s.route || '—';
    tr.appendChild(tdRoute);

    const tdCost = document.createElement('td');
    tdCost.className = 'num';
    if (s.status === '완료' || !s.cost) {
      tdCost.textContent  = '—';
      tdCost.style.color  = '#8a9ab8';
    } else {
      tdCost.textContent = Number(s.cost).toLocaleString('ko-KR');
    }
    tr.appendChild(tdCost);

    tbody.appendChild(tr);
  }
  table.appendChild(tbody);
  planEl.appendChild(table);

  /* 총 제작 비용 */
  const totalDiv   = document.createElement('div');
  totalDiv.style.cssText = 'font-size:13px;margin-bottom:8px';
  const totalLabel = document.createElement('span');
  totalLabel.style.color  = '#8a9ab8';
  totalLabel.textContent  = '총 제작 비용: ';
  const totalVal = document.createElement('span');
  totalVal.style.cssText  = 'font-weight:700;color:#f0c96a';
  totalVal.textContent    = Number(data.total || 0).toLocaleString('ko-KR');
  totalDiv.appendChild(totalLabel);
  totalDiv.appendChild(totalVal);
  planEl.appendChild(totalDiv);

  /* 분노 차감 컨테이너 (recalcPlanDeduction 이 채움) */
  const deductContainer = document.createElement('div');
  deductContainer.id = 'planDeductContainer';
  planEl.appendChild(deductContainer);

  /* 안내 */
  const noteDiv = document.createElement('div');
  noteDiv.style.cssText = 'font-size:11px;color:#8a9ab8;margin-top:8px';
  noteDiv.textContent = '※ 분노 귀속 보유 수량은 위 🎒 패널 값 기준';
  planEl.appendChild(noteDiv);

  recalcPlanDeduction();
}

function recalcPlanDeduction() {
  if (!lastPlanData) return;
  const owned      = getOwned();
  const rageTotals = lastPlanData.rage_totals || {};

  let save = 0;
  for (const [name, info] of Object.entries(rageTotals)) {
    save += Math.min(owned[name] || 0, info.need) * (info.unit || 0);
  }

  const container = document.getElementById('planDeductContainer');
  if (!container) return;
  while (container.firstChild) container.removeChild(container.firstChild);

  if (save > 0) {
    const total = lastPlanData.total || 0;

    const dedDiv = document.createElement('div');
    dedDiv.style.cssText = 'font-size:13px;color:#2ecc71;margin-bottom:4px';
    dedDiv.textContent   = '귀속 분노 차감: −' + save.toLocaleString('ko-KR');
    container.appendChild(dedDiv);

    const netDiv = document.createElement('div');
    netDiv.style.cssText = 'font-size:14px;font-weight:700;color:#f0c96a';
    netDiv.textContent   = '실질 총 비용: ' + (total - save).toLocaleString('ko-KR');
    container.appendChild(netDiv);
  }
}

document.addEventListener('DOMContentLoaded', () => {
  const nickInput = document.getElementById('charNickInput');
  if (nickInput) {
    nickInput.addEventListener('keydown', e => { if (e.key === 'Enter') searchCharacter(); });
  }
});
</script>

<div id="charPlanSection" style="background:#141828;border:1px solid #1e2840;border-radius:10px;padding:16px;margin-bottom:20px">
  <div style="font-size:13px;font-weight:700;color:#f0c96a;margin-bottom:6px">🔍 캐릭터로 한번에 계산</div>
  <div style="font-size:11px;color:#8a9ab8;margin-bottom:10px">캐릭터 닉네임으로 검색해 장착 아이템을 분석하고 7부위 제작 비용을 한번에 계산합니다.</div>
  <div style="display:flex;gap:8px;margin-bottom:10px">
    <input type="text" id="charNickInput" placeholder="캐릭터 닉네임"
      style="flex:1;min-width:0;padding:8px 12px;background:#0a0c14;border:1px solid #1e2840;border-radius:6px;color:#e8eaf0;font-family:inherit;font-size:13px">
    <button onclick="searchCharacter()"
      style="padding:8px 16px;background:linear-gradient(135deg,#8a6830,#c9a84c);border:none;border-radius:6px;color:#0a0c14;font-weight:700;cursor:pointer;font-family:inherit;white-space:nowrap">검색</button>
  </div>
  <div id="charSearchStatus" style="font-size:12px;color:#8a9ab8;display:none;margin-bottom:8px"></div>
  <div id="charCandidates" style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:4px"></div>
  <div id="charPlan"></div>
</div>

<div class="routes-grid">
<?php foreach ($routes as $i => $r):
  $unpriced = [];
  foreach ($r['breakdown'] as $nm => $b) {
      if (empty($b['core']) && (int)$b['unit'] === 0 && $b['qty'] > 0 && $nm !== '키나(통합)') $unpriced[] = $nm;
  }
?>
<div class="route-card <?= $i===0?'best':'' ?>" data-cost="<?= (int)round($r['cost_fixed']) ?>">
  <div class="route-head">
    <div class="route-label"><?= $i===0?'⭐ ':'' ?><?= htmlspecialchars($r['label']) ?></div>
    <div class="route-cost"><?= $fmt($r['cost_fixed']) ?></div>
    <div class="route-ev">COMBO 기대값 <?= $fmt($r['cost_ev']) ?></div>
    <div class="route-adj" style="display:none;font-size:13px;font-weight:700;color:#2ecc71;margin-top:4px"></div>
  </div>
  <?php if (!empty($r['is_owned_route'])): ?>
  <form method="get" style="margin-bottom:10px">
    <input type="hidden" name="acc" value="<?= htmlspecialchars($acc) ?>">
    <label style="display:block;font-size:11px;color:#8a9ab8;margin-bottom:4px">보유(장착)중인 아이템 선택 — 여기서부터 계승</label>
    <select name="owned" onchange="this.form.submit()" style="width:100%;padding:7px 10px;background:#0a0c14;border:1px solid #1e2840;border-radius:6px;color:#e8eaf0;font-family:inherit;font-size:12px">
      <?php foreach ($owned_options as $o): ?>
        <option value="<?= htmlspecialchars($o) ?>" <?= $o===$owned_sel?'selected':'' ?>><?= $o==='없음'?'보유 아이템 없음 (진룡왕부터)':htmlspecialchars($o) ?></option>
      <?php endforeach ?>
    </select>
  </form>
  <?php endif ?>
  <?php if ($unpriced): ?>
  <div style="margin:6px 0 10px;padding:6px 10px;background:rgba(231,76,60,.12);border:1px solid rgba(231,76,60,.4);border-radius:5px;color:#e74c3c;font-size:12px">
    ⚠ 미입력 재료 <?= count($unpriced) ?>종 포함 — 이 루트 비용은 부정확 (시세를 입력하세요): <?= htmlspecialchars(implode(', ', $unpriced)) ?>
  </div>
  <?php endif ?>
  <table class="bd"><thead><tr><th>재료</th><th class="num">수량</th><th class="num">단가</th><th class="num">소계</th></tr></thead><tbody>
  <?php foreach ($r['breakdown'] as $name => $b): $qty=$b['qty']; $unit=$b['unit']; ?>
    <tr data-mat="<?= htmlspecialchars($name) ?>" data-qty="<?= (int)round($qty) ?>" data-unit="<?= (int)$unit ?>">
      <td><?= htmlspecialchars($name) ?><?= !empty($b['core'])?'<span class="badge">무료</span>':'' ?></td>
      <td class="num"><?= $fmt($qty) ?></td>
      <td class="num"><?= !empty($b['core'])?'0':$fmt($unit) ?></td>
      <td class="num"><?= $fmt($qty*$unit) ?></td>
    </tr>
  <?php endforeach ?>
  </tbody></table>
</div>
<?php endforeach ?>
</div>
<?php if (empty($useful_tiers)): ?>
<p style="font-size:12px;color:#8a9ab8;margin:-8px 0 20px">ℹ 이 품목은 상위 계승 레시피가 없어 보유 아이템 반영이 불가합니다 — 응룡왕 직접제작만 가능합니다. (게임에 계승이 추가되면 자동 반영)</p>
<?php endif ?>

<h2 id="prices" style="font-size:18px;color:#f0c96a;margin:28px 0 12px">💰 재료 시세 (공개 편집)</h2>
<p style="font-size:12px;color:#8a9ab8;margin-bottom:12px">누구나 현재 시세로 갱신할 수 있습니다. 코어·계승석(영웅)은 무료라 항상 0입니다.<br>
· <b>제작 계승석</b>은 달인의 빛나는 악세 3종 중 최저가로 자동 계산됩니다(직접 입력 없음).<br>
· <b>찬란한 원석</b>은 찬란한 오드와 1:1 교환되어, 원석·오드 중 더 싼 쪽 가격이 적용됩니다.</p>
<table class="bd"><thead><tr><th>재료</th><th>분류</th><th class="num">단가</th><th>최종 갱신</th><th></th></tr></thead><tbody>
<?php
$mrows = $pdo->query("SELECT name,unit_price,is_core,category,updated_at,updated_ip FROM craft_materials WHERE is_core=0 AND category<>'산출물' AND category<>'키나' AND category<>'계승석' ORDER BY category,name")->fetchAll();
foreach ($mrows as $m): ?>
  <tr><form method="post">
    <input type="hidden" name="update_price" value="1">
    <input type="hidden" name="acc" value="<?= htmlspecialchars($acc) ?>">
    <input type="hidden" name="owned" value="<?= htmlspecialchars($owned_sel) ?>">
    <input type="hidden" name="material" value="<?= htmlspecialchars($m['name']) ?>">
    <td><?= htmlspecialchars($m['name']) ?></td>
    <td style="color:#8a9ab8"><?= htmlspecialchars($m['category']) ?></td>
    <td class="num"><input name="price" type="number" min="0" value="<?= (int)$m['unit_price'] ?>" style="width:120px;text-align:right;padding:5px;background:#0a0c14;border:1px solid #1e2840;border-radius:5px;color:#e8eaf0"></td>
    <td style="color:#8a9ab8;font-size:12px"><?= $m['updated_at'] ? htmlspecialchars($m['updated_at']) : '—' ?></td>
    <td><button style="padding:5px 12px;background:linear-gradient(135deg,#8a6830,#c9a84c);border:none;border-radius:5px;color:#0a0c14;font-weight:700;cursor:pointer">저장</button></td>
  </form></tr>
<?php endforeach ?>
</tbody></table>

<?php if ($is_admin): ?>
<h2 id="recipes" style="font-size:18px;color:#f0c96a;margin:28px 0 12px">🛠 레시피 편집 (관리자)</h2>
<?php
$recs = $pdo->prepare("SELECT * FROM craft_recipes WHERE accessory=? ORDER BY tier,recipe_type");
$recs->execute([$acc]);
foreach ($recs->fetchAll() as $rec):
  $inp = $pdo->prepare("SELECT material_name,qty FROM craft_recipe_inputs WHERE recipe_id=?");
  $inp->execute([$rec['id']]);
  $lines = array_map(fn($x)=>$x['material_name'].' x'.$x['qty'], $inp->fetchAll());
?>
<div class="route-card" style="<?= $rec['is_estimated']?'border-color:#e74c3c':'' ?>">
  <div style="font-weight:700;margin-bottom:8px"><?= htmlspecialchars($rec['output_name']) ?>
    <span class="badge"><?= htmlspecialchars($rec['recipe_type']) ?></span>
    <?= $rec['is_estimated']?'<span class="badge" style="background:rgba(231,76,60,.2);color:#e74c3c">추정치</span>':'' ?></div>
  <form method="post" onsubmit="this.inputs.value=JSON.stringify(this.raw.value.split('\n').map(l=>l.trim()).filter(Boolean).map(l=>{const m=l.match(/^(.*)\sx(\d+)$/);return m?[m[1].trim(),parseInt(m[2])]:null}).filter(Boolean))">
    <input type="hidden" name="edit_recipe" value="1">
    <input type="hidden" name="recipe_id" value="<?= (int)$rec['id'] ?>">
    <input type="hidden" name="acc" value="<?= htmlspecialchars($acc) ?>">
    <input type="hidden" name="inputs">
    <textarea name="raw" rows="<?= max(2,count($lines)) ?>" style="width:100%;background:#0a0c14;border:1px solid #1e2840;border-radius:6px;color:#e8eaf0;padding:8px;font-family:inherit"><?= htmlspecialchars(implode("\n",$lines)) ?></textarea>
    <div style="display:flex;gap:10px;align-items:center;margin-top:8px">
      <label style="font-size:13px">키나 <input name="kina_cost" type="number" min="0" value="<?= (int)$rec['kina_cost'] ?>" style="width:130px;padding:5px;background:#0a0c14;border:1px solid #1e2840;border-radius:5px;color:#e8eaf0"></label>
      <label style="font-size:13px"><input type="checkbox" name="is_estimated" <?= $rec['is_estimated']?'checked':'' ?>> 추정치</label>
      <button style="margin-left:auto;padding:6px 16px;background:linear-gradient(135deg,#8a6830,#c9a84c);border:none;border-radius:5px;color:#0a0c14;font-weight:700;cursor:pointer">저장</button>
    </div>
  </form>
</div>
<?php endforeach ?>
<?php endif ?>
</div></body></html>
