<?php
$fmt = fn($n) => number_format((int)round($n));
$owned_options = ['없음'];
foreach (['진룡왕','백룡왕','명룡왕','천룡왕','현룡왕'] as $t) $owned_options[] = "{$t}의 {$acc}";
?><!DOCTYPE html><html lang="ko"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>응룡왕 제작효율 계산</title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@400;700;900&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Noto Sans KR',sans-serif;background:#0a0c14;color:#e8eaf0;padding:24px}
.wrap{max-width:1000px;margin:0 auto}
h1{font-size:22px;color:#f0c96a;margin-bottom:16px}
.controls{display:flex;gap:12px;flex-wrap:wrap;margin-bottom:20px}
select{padding:9px 12px;background:#141828;border:1px solid #1e2840;border-radius:6px;color:#e8eaf0;font-family:inherit}
.route-card{background:#141828;border:1px solid #1e2840;border-radius:10px;padding:16px;margin-bottom:12px}
.route-card.best{border-color:#c9a84c;box-shadow:0 0 0 1px rgba(201,168,76,.3)}
.route-head{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:10px}
.route-label{font-size:15px;font-weight:700}
.route-cost{font-size:20px;font-weight:900;color:#f0c96a}
.route-ev{font-size:12px;color:#8a9ab8}
.bd{width:100%;border-collapse:collapse;font-size:13px;margin-top:8px}
.bd th,.bd td{padding:5px 8px;border-bottom:1px solid rgba(30,40,64,.5);text-align:left}
.bd td.num{text-align:right}
.badge{display:inline-block;font-size:10px;padding:1px 6px;border-radius:3px;background:rgba(201,168,76,.15);color:#c9a84c;margin-left:6px}
.link{color:#5a9bf5;text-decoration:none;font-size:13px}
</style></head><body><div class="wrap">
<h1>⚒️ 응룡왕 제작효율 계산기</h1>
<form method="get" class="controls">
  <select name="acc" onchange="this.form.submit()">
    <?php foreach (['목걸이','귀걸이','반지'] as $a): ?>
      <option value="<?= $a ?>" <?= $a===$acc?'selected':'' ?>><?= $a ?></option>
    <?php endforeach ?>
  </select>
  <select name="owned" onchange="this.form.submit()">
    <?php foreach ($owned_options as $o): ?>
      <option value="<?= htmlspecialchars($o) ?>" <?= $o===$owned_sel?'selected':'' ?>>
        <?= $o==='없음'?'보유 아이템 없음':'보유: '.htmlspecialchars($o) ?></option>
    <?php endforeach ?>
  </select>
  <a class="link" href="craft.php?acc=<?= $acc ?>&owned=<?= urlencode($owned_sel) ?>#prices">↓ 재료 시세 편집</a>
</form>

<?php foreach ($routes as $i => $r): ?>
<div class="route-card <?= $i===0?'best':'' ?>">
  <div class="route-head">
    <div class="route-label"><?= $i===0?'⭐ ':'' ?><?= htmlspecialchars($r['label']) ?></div>
    <div style="text-align:right">
      <div class="route-cost"><?= $fmt($r['cost_fixed']) ?></div>
      <div class="route-ev">COMBO 기대값 <?= $fmt($r['cost_ev']) ?></div>
    </div>
  </div>
  <table class="bd"><thead><tr><th>재료</th><th class="num">수량</th><th class="num">단가</th><th class="num">소계</th></tr></thead><tbody>
  <?php foreach ($r['breakdown'] as $name => $b): $qty=$b['qty']; $unit=$b['unit']; ?>
    <tr>
      <td><?= htmlspecialchars($name) ?><?= !empty($b['core'])?'<span class="badge">코어·무료</span>':'' ?></td>
      <td class="num"><?= $fmt($qty) ?></td>
      <td class="num"><?= !empty($b['core'])?'0':$fmt($unit) ?></td>
      <td class="num"><?= $fmt($qty*$unit) ?></td>
    </tr>
  <?php endforeach ?>
  </tbody></table>
</div>
<?php endforeach ?>

<!-- 시세 편집표는 Task 6에서 추가(anchor id=prices) -->
</div></body></html>
