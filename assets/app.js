'use strict';

// 서버가 심어준 초기 스냅샷. 이후 폴링으로 교체된다.
var FC = {
  state: window.FC_STATE,
  activeRaidId: null,
};

var CLASS_COLORS = {
  '수호성': '#5b8def', '검성': '#e0574a', '살성': '#8e6bd8', '궁성': '#3fa86a',
  '호법성': '#d99a3c', '정령성': '#37a7a0', '마도성': '#c956a5', '치유성': '#e0c04a'
};
var DAYS = ['월', '화', '수', '목', '금', '토', '일'];

FC.classColor = function (cls) {
  return CLASS_COLORS[cls] || '#4a5a78';
};

FC.el = function (tag, attrs, children) {
  var node = document.createElement(tag);
  attrs = attrs || {};
  Object.keys(attrs).forEach(function (k) {
    if (k === 'class') node.className = attrs[k];
    else if (k === 'text') node.textContent = attrs[k];
    else if (k === 'html') node.innerHTML = attrs[k];
    else if (attrs[k] !== null && attrs[k] !== undefined) node.setAttribute(k, attrs[k]);
  });
  (children || []).forEach(function (c) { if (c) node.appendChild(c); });
  return node;
};

FC.byId = function (list, id) {
  var found = null;
  (list || []).forEach(function (x) { if (Number(x.id) === Number(id)) found = x; });
  return found;
};

FC.charsOfPlayer = function (playerId) {
  return (FC.state.characters || []).filter(function (c) {
    return Number(c.player_id) === Number(playerId);
  });
};

FC.mainOf = function (playerId) {
  var mains = FC.charsOfPlayer(playerId).filter(function (c) { return Number(c.is_main) === 1; });
  return mains.length ? mains[0] : null;
};

// 이 플레이어의 캐릭터가 해당 레이드에 몇 칸 배치되어 있는지
FC.placedCount = function (playerId, raidId) {
  if (!raidId) return 0;
  var forceIds = (FC.state.forces || [])
    .filter(function (f) { return Number(f.raid_id) === Number(raidId); })
    .map(function (f) { return Number(f.id); });
  var myCharIds = FC.charsOfPlayer(playerId).map(function (c) { return Number(c.id); });
  return (FC.state.slots || []).filter(function (s) {
    return s.character_id !== null &&
           forceIds.indexOf(Number(s.force_id)) !== -1 &&
           myCharIds.indexOf(Number(s.character_id)) !== -1;
  }).length;
};

FC.dupCharIds = function (raidId) {
  var byRaid = (FC.state.duplicates || {})[String(raidId)] || [];
  return byRaid.map(function (d) { return Number(d.character_id); });
};

// ── 사이드바 ────────────────────────────────────────────────
FC.renderSidebar = function () {
  var host = document.getElementById('fc-sidebar');
  host.innerHTML = '';
  host.appendChild(FC.el('div', { class: 'fc-side-title', text: '캐릭터 대기창' }));

  var search = FC.el('input', {
    class: 'fc-search', id: 'fc-search', type: 'text',
    placeholder: '이름 검색', value: FC.searchTerm || ''
  });
  search.addEventListener('input', function () {
    FC.searchTerm = search.value;
    FC.renderSidebar();
    var again = document.getElementById('fc-search');
    again.focus();
    again.setSelectionRange(again.value.length, again.value.length);
  });
  host.appendChild(search);

  var list = FC.el('div', { class: 'fc-roster' });
  var term = (FC.searchTerm || '').trim().toLowerCase();
  var players = FC.state.players || [];

  if (!players.length) {
    list.appendChild(FC.el('p', {
      class: 'fc-empty', text: '아직 등록된 인원이 없어요. 「명단 관리」에서 추가하세요.'
    }));
  }

  players.forEach(function (p) {
    var main = FC.mainOf(p.id);
    if (!main) return;
    if (term) {
      var hit = FC.charsOfPlayer(p.id).some(function (c) {
        return c.name.toLowerCase().indexOf(term) !== -1;
      });
      if (!hit) return;
    }
    var subCount = FC.charsOfPlayer(p.id).length - 1;
    var placed = FC.placedCount(p.id, FC.activeRaidId);

    var card = FC.el('div', { class: 'fc-roster-card', 'data-player-id': p.id }, [
      FC.el('span', { class: 'fc-dot', style: 'background:' + FC.classColor(main.class) }),
      FC.el('span', { class: 'fc-roster-name', text: main.name }),
      FC.el('span', { class: 'fc-roster-meta',
        text: (main.atul ? main.atul.toLocaleString() : '—') + (subCount > 0 ? ' · 부캐 ' + subCount : '') }),
      FC.el('span', { class: 'fc-badge' + (placed === 0 ? ' is-zero' : ''), text: String(placed) })
    ]);
    list.appendChild(card);
  });

  host.appendChild(list);
  host.appendChild(FC.el('button', { class: 'fc-btn fc-block', id: 'fc-add-player', text: '+ 인원 추가' }));
};

// ── 레이드 탭 ───────────────────────────────────────────────
FC.renderTabs = function () {
  var host = document.getElementById('fc-tabs');
  host.innerHTML = '';
  (FC.state.raids || []).forEach(function (r) {
    var tab = FC.el('button', {
      class: 'fc-tab' + (Number(r.id) === Number(FC.activeRaidId) ? ' is-active' : ''),
      'data-raid-id': r.id, type: 'button', text: r.name
    });
    host.appendChild(tab);
  });
  host.appendChild(FC.el('button', { class: 'fc-tab fc-tab-add', id: 'fc-add-raid', type: 'button', text: '+' }));

  if (FC.activeRaidId) {
    host.appendChild(FC.el('span', { class: 'fc-spacer' }));
    host.appendChild(FC.el('button', { class: 'fc-btn', id: 'fc-add-force', type: 'button', text: '+ 포스 추가' }));
    host.appendChild(FC.el('button', { class: 'fc-btn', id: 'fc-edit-raid', type: 'button', text: '레이드 수정' }));
  }
};

// ── 보드 ────────────────────────────────────────────────────
FC.renderBoard = function () {
  var host = document.getElementById('fc-board');
  host.innerHTML = '';

  if (!(FC.state.raids || []).length) {
    host.appendChild(FC.el('div', { class: 'fc-empty-big' }, [
      FC.el('p', { text: '레이드를 먼저 만들어주세요' }),
      FC.el('button', { class: 'fc-btn fc-btn-primary', id: 'fc-add-raid-big', type: 'button', text: '+ 레이드 추가' })
    ]));
    return;
  }

  var raid = FC.byId(FC.state.raids, FC.activeRaidId);
  if (raid && raid.memo) {
    host.appendChild(FC.el('div', { class: 'fc-raid-memo', text: raid.memo }));
  }

  var dupIds = FC.dupCharIds(FC.activeRaidId);
  if (dupIds.length) {
    var names = dupIds.map(function (cid) {
      var c = FC.byId(FC.state.characters, cid);
      return c ? c.name : '?';
    });
    host.appendChild(FC.el('div', { class: 'fc-warn', text: '⚠ ' + names.join(', ') + ' 이(가) 같은 레이드에 중복 배치되어 있어요' }));
  }

  var forces = (FC.state.forces || []).filter(function (f) {
    return Number(f.raid_id) === Number(FC.activeRaidId);
  });

  if (!forces.length) {
    host.appendChild(FC.el('div', { class: 'fc-empty-big' }, [
      FC.el('p', { text: '아직 포스가 없어요' }),
      FC.el('button', { class: 'fc-btn fc-btn-primary', id: 'fc-add-force-big', type: 'button', text: '+ 포스 추가' })
    ]));
    return;
  }

  forces.forEach(function (f) {
    host.appendChild(FC.renderForce(f, dupIds));
  });
};

FC.renderForce = function (force, dupIds) {
  var slots = (FC.state.slots || []).filter(function (s) {
    return Number(s.force_id) === Number(force.id);
  });
  var filled = slots.filter(function (s) { return s.character_id !== null; }).length;

  var when = (force.day_of_week || '') + (force.start_time ? ' ' + force.start_time : '');
  var head = FC.el('div', { class: 'fc-force-head' }, [
    FC.el('span', { class: 'fc-force-no', text: force.force_no + '포스' }),
    FC.el('span', { class: 'fc-force-when', text: when || '시간 미정' }),
    FC.el('span', { class: 'fc-force-count', text: filled + '/10' }),
    FC.el('span', { class: 'fc-spacer' }),
    FC.el('button', { class: 'fc-icon-btn fc-force-edit', 'data-force-id': force.id, type: 'button', text: '수정' }),
    FC.el('button', { class: 'fc-icon-btn fc-force-del', 'data-force-id': force.id, type: 'button', text: '삭제' })
  ]);

  var body = FC.el('div', { class: 'fc-force-body' });
  [1, 2].forEach(function (party) {
    var row = FC.el('div', { class: 'fc-party' }, [
      FC.el('span', { class: 'fc-party-label', text: party + '파티' })
    ]);
    slots.filter(function (s) { return Number(s.party_no) === party; })
         .sort(function (a, b) { return a.slot_no - b.slot_no; })
         .forEach(function (s) { row.appendChild(FC.renderSlot(s, dupIds)); });
    body.appendChild(row);
  });

  var card = FC.el('div', { class: 'fc-force', 'data-force-id': force.id }, [head, body]);
  if (force.memo) card.appendChild(FC.el('div', { class: 'fc-force-memo', text: force.memo }));
  return card;
};

FC.renderSlot = function (slot, dupIds) {
  if (slot.character_id === null) {
    return FC.el('div', { class: 'fc-slot is-empty', 'data-slot-id': slot.id, text: '＋' });
  }
  var c = FC.byId(FC.state.characters, slot.character_id);
  if (!c) return FC.el('div', { class: 'fc-slot is-empty', 'data-slot-id': slot.id, text: '＋' });

  var main = FC.mainOf(c.player_id);
  var isDup = dupIds.indexOf(Number(c.id)) !== -1;
  var isPh = Number(c.is_placeholder) === 1;
  var node = FC.el('div', {
    class: 'fc-slot is-filled' + (isDup ? ' is-dup' : '') + (isPh ? ' is-placeholder' : ''),
    'data-slot-id': slot.id, 'data-character-id': c.id, draggable: 'true',
    style: '--slot-color:' + FC.classColor(c.class)
  }, [
    FC.el('span', { class: 'fc-slot-name', text: c.name }),
    FC.el('span', { class: 'fc-slot-owner',
      text: isPh ? (main ? main.name + ' · 미정' : '미정')
                 : (main && main.id !== c.id ? main.name : c.class || '') })
  ]);
  node.appendChild(FC.el('button', { class: 'fc-slot-x', type: 'button', 'data-slot-id': slot.id, text: '×' }));
  return node;
};

FC.render = function () {
  var raids = FC.state.raids || [];
  if (FC.activeRaidId && !FC.byId(raids, FC.activeRaidId)) FC.activeRaidId = null;
  if (!FC.activeRaidId && raids.length) FC.activeRaidId = raids[0].id;
  FC.renderSidebar();
  FC.renderTabs();
  FC.renderBoard();
};

FC.busy = false;

FC.toast = function (message, kind) {
  var wrap = document.getElementById('fc-toast');
  var node = FC.el('div', { class: 'fc-toast is-' + (kind || 'ok'), text: message });
  wrap.appendChild(node);
  setTimeout(function () {
    node.classList.add('is-out');
    setTimeout(function () { if (node.parentNode) node.parentNode.removeChild(node); }, 250);
  }, 3000);
};

FC.setConnected = function (ok) {
  document.getElementById('fc-conn').hidden = !!ok;
};

FC.api = function (action, payload) {
  var body = Object.assign({ action: action }, payload || {});
  return fetch('force/api.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  }).then(function (res) {
    return res.json().catch(function () { throw new Error('bad_response'); });
  }).then(function (json) {
    FC.setConnected(true);
    if (!json.ok) throw new Error(json.error || 'unknown_error');
    if (typeof json.revision === 'number') FC.state.revision = json.revision;
    return json.data;
  }).catch(function (err) {
    if (err.message === 'bad_response' || err instanceof TypeError) FC.setConnected(false);
    throw err;
  });
};

FC.refresh = function () {
  return FC.api('state', {}).then(function (state) {
    FC.state = state;
    FC.render();
  });
};

var ERROR_TEXT = {
  'bad_request': '입력값이 올바르지 않아요',
  'unauthorized': '세션이 만료됐어요. 새로고침 후 다시 로그인하세요',
  'not_found': '대상을 찾을 수 없어요',
  'lookup_failed': '아툴 조회에 실패했어요',
  'slot_not_found': '슬롯을 찾을 수 없어요',
  'empty_name': '이름을 입력하세요',
  'unknown_action': '알 수 없는 요청이에요',
  'bad_json': '요청 형식에 문제가 있어요. 새로고침 후 다시 시도하세요',
  'server_error': '서버에 문제가 생겼어요. 잠시 후 다시 시도하세요'
};

FC.errorText = function (err) {
  var msg = err && err.message ? err.message : '';
  if (msg.indexOf('duplicate_name') === 0) {
    var who = msg.slice('duplicate_name:'.length) || '';
    return '이미 등록된 캐릭명이에요' + (who ? ' — ' + who : '');
  }
  return ERROR_TEXT[msg] || ('저장 실패: ' + (msg || '알 수 없는 오류'));
};

// 10초마다 revision만 확인한다. 드래그 중이거나 팝오버/모달이 열려 있으면 건너뛴다 —
// 손에 든 카드가 사라지면 안 된다.
FC.startPolling = function () {
  setInterval(function () {
    if (FC.busy) return;
    var known = FC.state.revision; // FC.api가 응답 즉시 FC.state.revision을 갱신하므로 호출 전에 스냅샷 떠야 한다
    FC.api('state', {}).then(function (state) {
      if (Number(state.revision) === Number(known) &&
          (FC.state.slots || []).length === (state.slots || []).length) return;
      FC.state = state;
      FC.render();
    }).catch(function () { /* 배너는 FC.api가 이미 띄웠다 */ });
  }, 10000);
};

// 모달이 열리거나 닫힐 때마다 증가하는 세대 토큰. 요청을 시작한 시점의 토큰과
// 응답이 돌아온 시점의 토큰이 다르면, 그 사이 사용자가 이미 다른 모달로 넘어간
// 것이므로 콜백이 화면을 강제로 갈아엎지 않는다 (데이터 갱신 자체는 그대로 한다).
FC.modalGen = 0;

FC.closeModal = function () {
  var host = document.getElementById('fc-modal');
  host.hidden = true;
  host.innerHTML = '';
  FC.busy = false;
  FC.modalGen++;
};

FC.openModal = function (title, contentEl) {
  FC.modalGen++;
  var host = document.getElementById('fc-modal');
  host.innerHTML = '';
  var panel = FC.el('div', { class: 'fc-modal-panel' }, [
    FC.el('div', { class: 'fc-modal-head' }, [
      FC.el('span', { class: 'fc-modal-title', text: title }),
      FC.el('button', { class: 'fc-icon-btn', id: 'fc-modal-close', type: 'button', text: '닫기' })
    ]),
    contentEl
  ]);
  host.appendChild(panel);
  host.hidden = false;
  FC.busy = true;
  document.getElementById('fc-modal-close').addEventListener('click', FC.closeModal);
  host.addEventListener('click', function (e) { if (e.target === host) FC.closeModal(); });
};

FC.openAddPlayer = function () {
  var mainInput = FC.el('input', { class: 'fc-input', type: 'text', placeholder: '본캐명 (필수)' });
  var subsWrap = FC.el('div', { class: 'fc-subs' });

  function addSubRow(value) {
    var row = FC.el('input', { class: 'fc-input fc-sub-input', type: 'text', placeholder: '부캐명' });
    if (value) row.value = value;
    subsWrap.appendChild(row);
  }
  addSubRow('');
  addSubRow('');

  var addMore = FC.el('button', { class: 'fc-btn fc-block', type: 'button', text: '+ 부캐 칸 추가' });
  addMore.addEventListener('click', function () { addSubRow(''); });

  var save = FC.el('button', { class: 'fc-btn fc-btn-primary fc-block', type: 'button', text: '등록' });
  var hint = FC.el('p', { class: 'fc-hint',
    text: '캐릭명만 넣으면 직업·아툴점수·아이템레벨을 자동으로 불러옵니다. 조회에 실패해도 등록은 됩니다.' });

  save.addEventListener('click', function () {
    var main = mainInput.value.trim();
    if (!main) { FC.toast('본캐명을 입력하세요', 'err'); return; }
    var subs = Array.prototype.slice.call(subsWrap.querySelectorAll('.fc-sub-input'))
      .map(function (i) { return i.value.trim(); })
      .filter(function (v) { return v !== ''; });

    save.disabled = true;
    save.textContent = '조회 중…';
    FC.api('player.create', { main_name: main, subs: subs }).then(function () {
      FC.toast(main + ' 등록 완료', 'ok');
      FC.closeModal();
      return FC.refresh();
    }).catch(function (err) {
      FC.toast(FC.errorText(err), 'err');
      save.disabled = false;
      save.textContent = '등록';
    });
  });

  FC.openModal('인원 추가', FC.el('div', { class: 'fc-form' }, [mainInput, subsWrap, addMore, hint, save]));
  mainInput.focus();
};

FC.openRoster = function () {
  var list = FC.el('div', { class: 'fc-roster-manage' });

  (FC.state.players || []).forEach(function (p) {
    var main = FC.mainOf(p.id);
    if (!main) return;
    var chars = FC.charsOfPlayer(p.id);

    var rows = FC.el('div', { class: 'fc-manage-chars' });
    chars.forEach(function (c) {
      var isPh = Number(c.is_placeholder) === 1;
      var row = FC.el('div', { class: 'fc-manage-char' + (isPh ? ' is-placeholder' : '') }, [
        FC.el('span', { class: 'fc-dot', style: 'background:' + (isPh ? '#4a5a78' : FC.classColor(c.class)) }),
        FC.el('span', { class: 'fc-manage-name', text: (Number(c.is_main) === 1 ? '⭐ ' : '') + c.name }),
        FC.el('span', { class: 'fc-manage-meta',
          text: isPh ? '임시 · 미정'
                     : ((c.class || '직업?') + ' · ' + (c.atul ? c.atul.toLocaleString() : '점수?')) })
      ]);
      // 임시 캐릭터는 아툴 갱신 대신 "확정" — 실제 캐릭명을 받아 조회까지 한 번에 한다
      if (isPh) {
        row.appendChild(FC.el('button', { class: 'fc-icon-btn fc-char-promote',
          'data-character-id': c.id, type: 'button', text: '확정' }));
      } else {
        row.appendChild(FC.el('button', { class: 'fc-icon-btn fc-char-refresh',
          'data-character-id': c.id, type: 'button', text: '갱신' }));
      }
      row.appendChild(FC.el('button', { class: 'fc-icon-btn fc-char-del',
        'data-character-id': c.id, type: 'button', text: '삭제' }));
      rows.appendChild(row);
    });

    var addSub = FC.el('div', { class: 'fc-manage-add' }, [
      FC.el('input', { class: 'fc-input fc-add-sub-name', type: 'text', placeholder: '부캐명 추가' }),
      FC.el('button', { class: 'fc-btn fc-add-sub-go', 'data-player-id': p.id, type: 'button', text: '추가' }),
      FC.el('button', { class: 'fc-btn fc-add-ph-go', 'data-player-id': p.id, type: 'button', text: '임시로 추가' })
    ]);

    list.appendChild(FC.el('div', { class: 'fc-manage-player' }, [
      FC.el('div', { class: 'fc-manage-head' }, [
        FC.el('strong', { text: main.name }),
        FC.el('span', { class: 'fc-spacer' }),
        FC.el('button', { class: 'fc-icon-btn fc-player-del', 'data-player-id': p.id, type: 'button', text: '이 사람 전체 삭제' })
      ]),
      rows, addSub
    ]));
  });

  if (!(FC.state.players || []).length) {
    list.appendChild(FC.el('p', { class: 'fc-empty', text: '아직 등록된 인원이 없어요.' }));
  }

  var addBtn = FC.el('button', { class: 'fc-btn fc-btn-primary fc-block', type: 'button', text: '+ 인원 추가' });
  addBtn.addEventListener('click', function () { FC.closeModal(); FC.openAddPlayer(); });

  FC.openModal('명단 관리', FC.el('div', {}, [addBtn, list]));
};

FC.bindGlobalEvents = function () {
  document.addEventListener('click', function (e) {
    var t = e.target;

    if (t.id === 'fc-open-roster') { FC.openRoster(); return; }
    if (t.id === 'fc-add-player')  { FC.openAddPlayer(); return; }

    if (t.classList.contains('fc-char-del')) {
      var cid = Number(t.getAttribute('data-character-id'));
      var ch = FC.byId(FC.state.characters, cid);
      if (!confirm((ch ? ch.name : '이 캐릭터') + ' 을(를) 삭제할까요? 배치된 자리도 비워집니다.')) return;
      var genDel = FC.modalGen;
      FC.api('character.delete', { character_id: cid })
        .then(function () { return FC.refresh(); })
        .then(function () {
          // 요청이 도는 사이 사용자가 이미 다른 모달로 넘어갔으면 데이터만 갱신하고 화면은 건드리지 않는다
          if (genDel !== FC.modalGen) return;
          FC.toast('삭제했어요', 'ok');
          FC.closeModal();
          FC.openRoster();
        })
        .catch(function (err) { FC.toast(FC.errorText(err), 'err'); });
      return;
    }

    if (t.classList.contains('fc-player-del')) {
      var pid = Number(t.getAttribute('data-player-id'));
      var m = FC.mainOf(pid);
      var n = FC.charsOfPlayer(pid).length;
      if (!confirm((m ? m.name : '이 사람') + ' 의 캐릭터 ' + n + '개를 전부 삭제할까요?')) return;
      var genPlayerDel = FC.modalGen;
      FC.api('player.delete', { player_id: pid })
        .then(function () { return FC.refresh(); })
        .then(function () {
          if (genPlayerDel !== FC.modalGen) return;
          FC.toast('삭제했어요', 'ok');
          FC.closeModal();
          FC.openRoster();
        })
        .catch(function (err) { FC.toast(FC.errorText(err), 'err'); });
      return;
    }

    if (t.classList.contains('fc-char-refresh')) {
      var rid = Number(t.getAttribute('data-character-id'));
      var genRefresh = FC.modalGen;
      t.disabled = true; t.textContent = '조회중';
      FC.api('atul.refresh', { character_id: rid })
        .then(function () { return FC.refresh(); })
        .then(function () {
          if (genRefresh !== FC.modalGen) return;
          FC.toast('갱신했어요', 'ok');
          FC.closeModal();
          FC.openRoster();
        })
        .catch(function (err) {
          FC.toast(FC.errorText(err), 'err');
          t.disabled = false; t.textContent = '갱신';
        });
      return;
    }

    if (t.classList.contains('fc-add-sub-go')) {
      var ownerId = Number(t.getAttribute('data-player-id'));
      var input = t.parentNode.querySelector('.fc-add-sub-name');
      var name = input.value.trim();
      if (!name) { FC.toast('부캐명을 입력하세요', 'err'); return; }
      var genAddSub = FC.modalGen;
      t.disabled = true; t.textContent = '조회중';
      FC.api('character.add', { player_id: ownerId, name: name })
        .then(function () { return FC.refresh(); })
        .then(function () {
          if (genAddSub !== FC.modalGen) return;
          FC.toast(name + ' 추가 완료', 'ok');
          FC.closeModal();
          FC.openRoster();
        })
        .catch(function (err) {
          FC.toast(FC.errorText(err), 'err');
          t.disabled = false; t.textContent = '추가';
        });
      return;
    }

    // 어떤 캐릭터를 보낼지 안 정했을 때 쓰는 자리표시 카드.
    // 입력칸이 비어 있으면 <본캐명>부캐N 을 자동으로 붙인다.
    if (t.classList.contains('fc-add-ph-go')) {
      var phOwnerId = Number(t.getAttribute('data-player-id'));
      var phInput = t.parentNode.querySelector('.fc-add-sub-name');
      var phName = phInput.value.trim();
      if (!phName) {
        var phMainChar = FC.mainOf(phOwnerId);
        var phCount = FC.charsOfPlayer(phOwnerId).filter(function (c) {
          return Number(c.is_placeholder) === 1;
        }).length;
        phName = (phMainChar ? phMainChar.name : '') + '부캐' + (phCount + 1);
      }
      var genAddPh = FC.modalGen;
      t.disabled = true;
      FC.api('character.add', { player_id: phOwnerId, name: phName, is_placeholder: true })
        .then(function () { return FC.refresh(); })
        .then(function () {
          if (genAddPh !== FC.modalGen) return;
          FC.toast(phName + ' 추가 완료', 'ok');
          FC.closeModal();
          FC.openRoster();
        })
        .catch(function (err) { FC.toast(FC.errorText(err), 'err'); t.disabled = false; });
      return;
    }

    if (t.classList.contains('fc-char-promote')) {
      var promoteId = Number(t.getAttribute('data-character-id'));
      var realName = prompt('실제 캐릭명을 입력하면 직업·아툴점수를 조회해 확정합니다.\n배치된 자리는 그대로 유지됩니다.', '');
      if (realName === null) return;
      realName = realName.trim();
      if (!realName) { FC.toast('캐릭명을 입력하세요', 'err'); return; }
      var genPromote = FC.modalGen;
      t.disabled = true; t.textContent = '조회중';
      FC.api('character.promote', { character_id: promoteId, name: realName })
        .then(function (data) {
          var lookedUp = !!(data && data.looked_up);
          return FC.refresh().then(function () { return lookedUp; });
        })
        .then(function (lookedUp) {
          if (genPromote !== FC.modalGen) return;
          FC.toast(lookedUp ? realName + ' 확정 완료' : realName + ' 확정 (조회 실패 — 직업은 직접 입력)', 'ok');
          FC.closeModal();
          FC.openRoster();
        })
        .catch(function (err) {
          FC.toast(FC.errorText(err), 'err');
          t.disabled = false; t.textContent = '확정';
        });
      return;
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') FC.closeModal();
  });
};

(function boot() {
  var raids = FC.state.raids || [];
  FC.activeRaidId = raids.length ? raids[0].id : null;
  console.log('[fc] state loaded', {
    revision: FC.state.revision,
    players: (FC.state.players || []).length,
    characters: (FC.state.characters || []).length,
    raids: raids.length,
    forces: (FC.state.forces || []).length,
    slots: (FC.state.slots || []).length
  });
  FC.render();
  FC.startPolling();
  FC.bindGlobalEvents();
})();
