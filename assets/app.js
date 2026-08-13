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
})();
