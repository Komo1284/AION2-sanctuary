/* 경품 추첨 — 서버 저장 없이 브라우저 안에서만 동작한다.
 * 업로드한 명단 전원 중에서 정해진 인원을 무작위로 뽑는다.
 */
(function () {
  'use strict';

  var SHEET_MAIN = '추첨명단';

  var state = {
    people: [],      // 추첨 대상 전체 {name, phone, key}
    winners: [],
    drawing: false
  };

  var $ = function (id) { return document.getElementById(id); };

  // ---------- 난수 ----------
  function randInt(n) {
    var buf = new Uint32Array(1);
    crypto.getRandomValues(buf);
    return buf[0] % n;
  }
  function shuffle(arr) {
    var a = arr.slice();
    for (var i = a.length - 1; i > 0; i--) {
      var j = randInt(i + 1), t = a[i]; a[i] = a[j]; a[j] = t;
    }
    return a;
  }

  // ---------- 추첨 로직 ----------
  function pickWinners(n) {
    return shuffle(state.people).slice(0, n);
  }

  // ---------- 엑셀 ----------
  function normPhone(v) {
    var d = String(v == null ? '' : v).replace(/\D/g, '');
    if (d.length === 10 && d.charAt(0) === '1') d = '0' + d;   // 엑셀이 숫자로 바꿔 앞의 0이 빠진 경우
    if (d.length === 11) return d.replace(/(\d{3})(\d{4})(\d{4})/, '$1-$2-$3');
    if (d.length === 10) return d.replace(/(\d{3})(\d{3})(\d{4})/, '$1-$2-$3');
    return String(v == null ? '' : v).trim();
  }

  function readSheet(ws) {
    var rows = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '', raw: false });
    var hi = -1, ni = 0, pi = 1;
    for (var r = 0; r < Math.min(rows.length, 10); r++) {
      var idx = rows[r].map(function (c) { return String(c).trim(); }).indexOf('이름');
      if (idx >= 0) {
        hi = r; ni = idx;
        rows[r].forEach(function (c, i) { if (String(c).indexOf('전화') >= 0) pi = i; });
        break;
      }
    }
    var out = [];
    for (var k = hi + 1; k < rows.length; k++) {
      var name = String(rows[k][ni] || '').trim();
      if (!name) continue;
      var phone = normPhone(rows[k][pi]);
      out.push({ name: name, phone: phone, key: name + '|' + phone.replace(/\D/g, '') });
    }
    return out;
  }

  function loadWorkbook(wb) {
    var name = wb.SheetNames.indexOf(SHEET_MAIN) >= 0 ? SHEET_MAIN : wb.SheetNames[0];
    var seen = {};
    return readSheet(wb.Sheets[name]).filter(function (p) {   // 같은 이름+전화번호 중복 제거
      if (seen[p.key]) return false;
      seen[p.key] = 1; return true;
    });
  }

  function makeSheet(rows) {
    var ws = XLSX.utils.aoa_to_sheet(rows);
    ws['!cols'] = [{ wch: 16 }, { wch: 20 }];
    return ws;
  }

  function downloadTemplate() {
    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, makeSheet([['이름', '전화번호']]), SHEET_MAIN);
    XLSX.writeFile(wb, '추첨명단_양식.xlsx');
  }

  function downloadResult() {
    var rows = [['순번', '이름', '전화번호']];
    state.winners.forEach(function (p, i) { rows.push([i + 1, p.name, p.phone]); });
    var ws = XLSX.utils.aoa_to_sheet(rows);
    ws['!cols'] = [{ wch: 6 }, { wch: 16 }, { wch: 20 }];
    var wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, '당첨자');
    XLSX.writeFile(wb, '추첨결과.xlsx');
  }

  // ---------- 화면 ----------
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function showPhone(p) {
    if (!$('maskPhone').checked) return p;
    var m = p.match(/^(\d{2,3})-(\d{3,4})-(\d{4})$/);
    return m ? m[1] + '-****-' + m[3] : p;
  }
  function toast(msg) {
    var t = $('toast');
    t.textContent = msg; t.hidden = false;
    clearTimeout(toast.timer);
    toast.timer = setTimeout(function () { t.hidden = true; }, 2600);
  }

  function renderList() {
    var winKeys = {};
    state.winners.forEach(function (p) { winKeys[p.key] = 1; });
    $('listCount').textContent = state.people.length;
    $('listEmpty').hidden = state.people.length > 0;
    $('listTable').hidden = state.people.length === 0;
    $('listBody').innerHTML = state.people.map(function (p, i) {
      return '<tr' + (winKeys[p.key] ? ' class="is-win"' : '') + '><td>' + (i + 1) + '</td><td>' +
        esc(p.name) + '</td><td>' + esc(showPhone(p.phone)) + '</td></tr>';
    }).join('');
  }

  function winnerItem(p, i) {
    var li = document.createElement('li');
    li.innerHTML = '<div class="w-no">' + (i + 1) + '번째 당첨</div><div class="w-name">' + esc(p.name) +
      '</div><div class="w-phone">' + esc(showPhone(p.phone)) + '</div>';
    return li;
  }
  function renderWinners() {
    var ol = $('winners');
    ol.innerHTML = '';
    state.winners.forEach(function (p, i) { ol.appendChild(winnerItem(p, i)); });
    $('winnerCount').textContent = state.winners.length;
  }

  function updateControls() {
    $('btnDraw').disabled = state.drawing || state.people.length === 0;
    $('drawCount').max = state.people.length || 1;
  }

  // ---------- 추첨 연출 ----------
  function sleep(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

  function roll(ms, final) {
    var roller = $('roller'), nameEl = $('rollerName'), phoneEl = $('rollerPhone');
    roller.classList.remove('is-final');
    return new Promise(function (resolve) {
      var start = Date.now();
      (function tick() {
        var elapsed = Date.now() - start;
        if (elapsed >= ms) {
          nameEl.textContent = final.name;
          phoneEl.textContent = showPhone(final.phone);
          void roller.offsetWidth;   // 애니메이션 재시작
          roller.classList.add('is-final');
          return resolve();
        }
        var p = state.people[randInt(state.people.length)];
        nameEl.textContent = p.name;
        phoneEl.textContent = showPhone(p.phone);
        setTimeout(tick, 40 + Math.pow(elapsed / ms, 3) * 260);   // 점점 느려지며 멈춘다
      })();
    });
  }

  async function draw() {
    var n = parseInt($('drawCount').value, 10);
    var max = state.people.length;
    if (!(n >= 1)) return toast('추첨 인원을 1명 이상 입력해주세요');
    if (n > max) return toast('추첨 가능한 인원은 최대 ' + max + '명입니다');

    state.drawing = true;
    state.winners = [];
    updateControls();
    renderList();
    renderWinners();

    var picked = pickWinners(n);
    $('stageIdle').hidden = true;
    $('winnersWrap').hidden = true;
    $('roller').hidden = false;

    if ($('oneByOne').checked) {
      for (var i = 0; i < picked.length; i++) {
        await roll(i === 0 ? 2600 : 1400, picked[i]);
        await sleep(900);
        state.winners.push(picked[i]);
        if (i === 0) { $('winnersWrap').hidden = false; }
        $('winners').appendChild(winnerItem(picked[i], i));
        $('winnerCount').textContent = state.winners.length;
        renderList();
      }
      await sleep(400);
    } else {
      await roll(3000, picked[0]);
      await sleep(700);
      state.winners = picked;
      renderWinners();
      renderList();
    }
    $('roller').hidden = true;
    $('winnersWrap').hidden = false;
    state.drawing = false;
    updateControls();
  }

  // ---------- 이벤트 ----------
  $('btnTemplate').addEventListener('click', downloadTemplate);
  $('btnResult').addEventListener('click', downloadResult);
  $('btnDraw').addEventListener('click', draw);

  $('fileInput').addEventListener('change', function (e) {
    var file = e.target.files[0];
    if (!file) return;
    if (state.drawing) { e.target.value = ''; return toast('추첨이 끝난 뒤 업로드해주세요'); }
    var reader = new FileReader();
    reader.onload = function (ev) {
      try {
        var people = loadWorkbook(XLSX.read(ev.target.result, { type: 'array' }));
        if (people.length === 0) return toast('명단을 찾지 못했습니다. 양식의 「이름」 칸을 확인해주세요');
        state.people = people;
        state.winners = [];
        $('fileName').textContent = file.name;
        $('stageIdle').textContent = '추첨 시작 버튼을 눌러주세요';
        $('stageIdle').hidden = false;
        $('winnersWrap').hidden = true;
        $('roller').hidden = true;
        renderList();
        updateControls();
        toast(state.people.length + '명의 명단을 불러왔습니다');
      } catch (err) {
        toast('엑셀 파일을 읽지 못했습니다');
      }
    };
    reader.readAsArrayBuffer(file);
    e.target.value = '';
  });

  $('maskPhone').addEventListener('change', function () { renderList(); renderWinners(); });

  updateControls();
})();
