'use strict';

// 서버가 심어준 초기 스냅샷. 이후 폴링으로 교체된다.
var FC = {
  state: window.FC_STATE,
  activeRaidId: null,
};

var CLASS_COLORS = {
  '수호성': '#5b8def',   // 파랑
  '검성':   '#37a7a0',   // 청록 — 원래 정령성 색을 물려받음
  '권성':   '#e0574a',   // 빨강 — 원래 검성 색을 물려받음. 색상표에 없어 회색으로 나오던 것을 이번에 추가
  '살성':   '#8fd14f',   // 연두 — 궁성보다 연하게
  '궁성':   '#3fa86a',   // 초록
  '호법성': '#d99a3c',   // 주황
  '정령성': '#c956a5',   // 분홍 — 원래 마도성 색을 물려받음
  '마도성': '#8e6bd8',   // 보라 — 원래 살성 색을 물려받음
  '치유성': '#e0c04a'    // 노랑
};
var DAYS = ['월', '화', '수', '목', '금', '토', '일'];

FC.classColor = function (cls) {
  return CLASS_COLORS[cls] || '#4a5a78';
};

// 전투력을 K 단위로 줄여 표시한다 (491,929 → "491K").
// 뒷 세 자리는 버린다 — 반올림하면 실제보다 높아 보여 오해를 부른다.
// 값이 없거나 0이면 '—'. 1,000 미만은 K로 줄이면 "0K"가 되므로 숫자를 그대로 쓴다.
FC.atulShort = function (atul) {
  var n = Number(atul);
  if (!atul || !isFinite(n) || n <= 0) return '—';
  if (n < 1000) return String(Math.floor(n));
  return Math.floor(n / 1000) + 'K';
};

FC.el = function (tag, attrs, children) {
  var node = document.createElement(tag);
  attrs = attrs || {};
  Object.keys(attrs).forEach(function (k) {
    if (k === 'class') node.className = attrs[k];
    else if (k === 'text') node.textContent = attrs[k];
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

// ── 보기 방식 (간략 / 상세) ─────────────────────────────────
// 레이드마다 따로 기억한다. 루드라처럼 오래된 레이드는 전투력을 안 보고 아무나
// 데려가므로 캐릭명만 있으면 되지만, 신규 레이드는 직업·전투력을 봐야 한다.
// 서버가 아니라 localStorage에 둔다 — 어디까지나 보는 사람의 취향이고,
// 저장하자고 스키마와 API를 늘릴 만한 값이 아니다. 저장에 실패해도(사생활 보호
// 모드 등) 기능 자체는 그대로 동작해야 하므로 전부 try/catch로 감싼다.
var VIEW_KEY = 'fc.viewModes';

FC.viewModes = (function () {
  try {
    var got = JSON.parse(window.localStorage.getItem(VIEW_KEY) || 'null');
    return (got && typeof got === 'object') ? got : {};
  } catch (e) { return {}; }
})();

FC.viewMode = function (raidId) {
  return FC.viewModes[String(raidId)] === 'compact' ? 'compact' : 'detail';
};

FC.setViewMode = function (raidId, mode) {
  if (!raidId) return;
  FC.viewModes[String(raidId)] = mode === 'compact' ? 'compact' : 'detail';
  try { window.localStorage.setItem(VIEW_KEY, JSON.stringify(FC.viewModes)); } catch (e) { /* 저장만 포기한다 */ }
  FC.renderTabs();
  FC.renderBoard();
};

// ── 사이드바 ────────────────────────────────────────────────
// #fc-search 인풋은 뼈대를 처음 만들 때 딱 한 번만 생성하고 이후로는 재사용한다.
// input 이벤트마다(한글 조합 중에도) 이 함수가 사이드바를 통째로 다시 그리면,
// 조합 중인 엘리먼트가 DOM에서 빠지는 순간 브라우저가 IME composition을 강제
// 종료시켜 "ㅎㅗㅇ"이 "홍"으로 합쳐지지 않는다. 폴링/새로고침으로 FC.render()가
// 사이드바를 다시 그릴 때도 같은 이유로 노드를 유지해야 하므로, 목록(.fc-roster)만
// 별도 함수(FC.renderRoster)로 갈아 끼운다.
FC.renderSidebar = function () {
  var host = document.getElementById('fc-sidebar');
  var search = document.getElementById('fc-search');

  if (!search) {
    host.innerHTML = '';
    host.appendChild(FC.el('div', { class: 'fc-side-title', text: '캐릭터 대기창' }));

    search = FC.el('input', {
      class: 'fc-search', id: 'fc-search', type: 'text',
      placeholder: '이름 검색', value: FC.searchTerm || ''
    });
    search.addEventListener('input', function () {
      FC.searchTerm = search.value;
      FC.renderRoster();
    });
    host.appendChild(search);
    host.appendChild(FC.el('div', { class: 'fc-roster' }));
    host.appendChild(FC.el('button', { class: 'fc-btn fc-block', id: 'fc-add-player', text: '+ 인원 추가' }));
  } else if (document.activeElement !== search && search.value !== (FC.searchTerm || '')) {
    // 코드에서 검색어를 바꾼 경우(예: 나중에 "검색 지우기" 기능이 생기면)에만 동기화한다.
    // 사용자가 지금 이 인풋에 포커스를 두고 타이핑 중이면 절대 값을 건드리지 않는다.
    search.value = FC.searchTerm || '';
  }

  FC.renderRoster();
};

FC.renderRoster = function () {
  var list = document.querySelector('#fc-sidebar .fc-roster');
  if (!list) return;
  list.innerHTML = '';

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
        text: FC.atulShort(main.atul) + (subCount > 0 ? ' · 부캐 ' + subCount : '') }),
      FC.el('span', { class: 'fc-badge' + (placed === 0 ? ' is-zero' : ''), text: String(placed) })
    ]);
    list.appendChild(card);
  });
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

  host.appendChild(FC.el('span', { class: 'fc-spacer' }));
  if (FC.activeRaidId) {
    var mode = FC.viewMode(FC.activeRaidId);
    host.appendChild(FC.el('div', { class: 'fc-viewseg' }, [
      FC.el('button', { class: 'fc-viewseg-btn' + (mode === 'compact' ? ' is-on' : ''),
        type: 'button', 'data-view-mode': 'compact', text: '간략' }),
      FC.el('button', { class: 'fc-viewseg-btn' + (mode === 'detail' ? ' is-on' : ''),
        type: 'button', 'data-view-mode': 'detail', text: '상세' })
    ]));
    host.appendChild(FC.el('button', { class: 'fc-btn', id: 'fc-add-force', type: 'button', text: '+ 포스 추가' }));
    host.appendChild(FC.el('button', { class: 'fc-btn', id: 'fc-edit-raid', type: 'button', text: '레이드 수정' }));
  }
  // 전체 갱신은 특정 레이드와 무관하므로 레이드를 안 골랐을 때도 보인다.
  // 갱신이 도는 중에는 진행 상황을 라벨에 찍고 다시 눌리지 않게 잠근다.
  host.appendChild(FC.el('button', {
    class: 'fc-btn', id: 'fc-refresh-all', type: 'button',
    text: FC.refreshAllLabel || '전체 갱신',
    disabled: FC.refreshAllRunning ? 'disabled' : null
  }));
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

  var compact = FC.viewMode(FC.activeRaidId) === 'compact';
  forces.forEach(function (f) {
    host.appendChild(compact ? FC.renderForceCompact(f, dupIds) : FC.renderForce(f, dupIds));
  });
};

// 한 포스의 슬롯을 party_no·slot_no 순으로 돌려준다. 상세/간략 두 렌더러가
// 같은 순서를 봐야 하므로 한 곳에서만 정렬한다.
FC.slotsOfForce = function (forceId, party) {
  return (FC.state.slots || [])
    .filter(function (s) {
      return Number(s.force_id) === Number(forceId) &&
             (party === undefined || Number(s.party_no) === Number(party));
    })
    .sort(function (a, b) { return (a.party_no - b.party_no) || (a.slot_no - b.slot_no); });
};

FC.renderForce = function (force, dupIds) {
  var slots = FC.slotsOfForce(force.id);
  var filled = slots.filter(function (s) { return s.character_id !== null; }).length;

  // 평균 전투력은 점수를 아는 인원만으로 낸다. 임시 캐릭터와 조회에 실패한 캐릭터는
  // 0으로 세면 평균이 실제보다 낮게 나와 포스가 약해 보인다.
  var known = 0;
  var sum = 0;
  slots.forEach(function (s) {
    if (s.character_id === null) return;
    var c = FC.byId(FC.state.characters, s.character_id);
    if (!c || !c.atul || Number(c.atul) <= 0) return;
    known++;
    sum += Number(c.atul);
  });
  var avgText = known ? '평균 ' + FC.atulShort(Math.round(sum / known))
                        + (known < filled ? ' (' + known + '명)' : '')
                      : '';

  var when = (force.day_of_week || '') + (force.start_time ? ' ' + force.start_time : '');
  var head = FC.el('div', { class: 'fc-force-head' }, [
    FC.el('span', { class: 'fc-force-no', text: force.force_no + '포스' }),
    FC.el('span', { class: 'fc-force-when', text: when || '시간 미정' }),
    FC.el('span', { class: 'fc-force-count', text: filled + '/10' }),
    avgText ? FC.el('span', { class: 'fc-force-avg', text: avgText }) : null,
    FC.el('span', { class: 'fc-spacer' }),
    FC.el('button', { class: 'fc-icon-btn fc-force-edit', 'data-force-id': force.id, type: 'button', text: '수정' }),
    FC.el('button', { class: 'fc-icon-btn fc-force-del', 'data-force-id': force.id, type: 'button', text: '삭제' })
  ]);

  var body = FC.el('div', { class: 'fc-force-body' });
  [1, 2].forEach(function (party) {
    var row = FC.el('div', { class: 'fc-party' }, [
      FC.el('span', { class: 'fc-party-label', text: party + '파티' })
    ]);
    FC.slotsOfForce(force.id, party).forEach(function (s) {
      row.appendChild(FC.renderSlot(s, dupIds));
    });
    body.appendChild(row);
  });

  var card = FC.el('div', { class: 'fc-force', 'data-force-id': force.id }, [head, body]);
  if (force.memo) card.appendChild(FC.el('div', { class: 'fc-force-memo', text: force.memo }));
  return card;
};

// 간략 보기 — 엑셀 표처럼 한 포스를 한 줄에 눕힌다.
// 1파티 5칸 · 구분선 · 2파티 5칸, 각 칸에는 캐릭명만. 직업은 카드 색이,
// 본캐/부캐와 전투력은 hover 툴팁이 대신 알려주므로 글자로 적지 않는다.
// 평균 전투력도 빼둔다 — 이 보기를 고르는 레이드는 애초에 점수를 안 본다.
FC.renderForceCompact = function (force, dupIds) {
  var slots = FC.slotsOfForce(force.id);
  var filled = slots.filter(function (s) { return s.character_id !== null; }).length;
  var when = (force.day_of_week || '') + (force.start_time ? ' ' + force.start_time : '');

  var head = FC.el('div', { class: 'fc-compact-head' }, [
    FC.el('span', { class: 'fc-force-no', text: force.force_no + '포스' }),
    FC.el('span', { class: 'fc-force-when', text: when || '미정' }),
    FC.el('span', { class: 'fc-force-count', text: filled + '/10' })
  ]);

  var row = FC.el('div', { class: 'fc-compact-row' });
  [1, 2].forEach(function (party) {
    if (party === 2) row.appendChild(FC.el('span', { class: 'fc-party-sep' }));
    var group = FC.el('div', { class: 'fc-compact-party', title: party + '파티' });
    FC.slotsOfForce(force.id, party).forEach(function (s) {
      group.appendChild(FC.renderSlot(s, dupIds, true));
    });
    row.appendChild(group);
  });

  var tools = FC.el('div', { class: 'fc-compact-tools' }, [
    FC.el('button', { class: 'fc-icon-btn fc-force-edit', 'data-force-id': force.id, type: 'button', text: '수정' }),
    FC.el('button', { class: 'fc-icon-btn fc-force-del', 'data-force-id': force.id, type: 'button', text: '삭제' })
  ]);

  var card = FC.el('div', { class: 'fc-force is-compact', 'data-force-id': force.id }, [
    FC.el('div', { class: 'fc-compact-line' }, [head, row, tools])
  ]);
  if (force.memo) card.appendChild(FC.el('div', { class: 'fc-force-memo is-compact', text: force.memo }));
  return card;
};

// compact=true면 캐릭명 한 줄만 그린다. 지우는 정보(본캐명·직업·전투력)는
// title 툴팁으로 옮겨서, 궁금할 때 카드에 마우스만 올리면 볼 수 있게 한다.
FC.renderSlot = function (slot, dupIds, compact) {
  var base = 'fc-slot' + (compact ? ' is-compact' : '');
  if (slot.character_id === null) {
    return FC.el('div', { class: base + ' is-empty', 'data-slot-id': slot.id, text: '＋' });
  }
  var c = FC.byId(FC.state.characters, slot.character_id);
  if (!c) return FC.el('div', { class: base + ' is-empty', 'data-slot-id': slot.id, text: '＋' });

  var main = FC.mainOf(c.player_id);
  var isDup = dupIds.indexOf(Number(c.id)) !== -1;
  var isMain = Number(c.is_main) === 1;
  var isPh = Number(c.is_placeholder) === 1;

  var metaText = isPh ? '미정' : ((c.class || '직업?') + ' · ' + FC.atulShort(c.atul));

  var body;
  if (compact) {
    body = [FC.el('span', { class: 'fc-slot-name', text: c.name })];
  } else {
    // 본캐는 이름만, 부캐·임시는 오른쪽에 소유자 본캐명. 소유자명이 있느냐 없느냐가
    // 이미 본캐/부캐를 구분해주므로 별표는 같은 정보를 두 번 말하는 셈이라 빼둔다.
    // (팝오버와 명단 관리는 소유자명을 안 보여주므로 거기서는 별표를 그대로 쓴다.)
    var top = [FC.el('span', { class: 'fc-slot-name', text: c.name })];
    if (!isMain && main) {
      top.push(FC.el('span', { class: 'fc-slot-owner', text: main.name }));
    }
    body = [
      FC.el('div', { class: 'fc-slot-top' }, top),
      FC.el('div', { class: 'fc-slot-meta', text: metaText })
    ];
  }

  var node = FC.el('div', {
    class: base + ' is-filled' + (isDup ? ' is-dup' : '') + (isPh ? ' is-placeholder' : ''),
    'data-slot-id': slot.id, 'data-character-id': c.id, draggable: 'true',
    title: compact ? (c.name + (!isMain && main ? ' (' + main.name + ')' : '') + ' · ' + metaText) : null,
    style: '--slot-color:' + FC.classColor(c.class)
  }, body);
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

// 모달 열림, 팝오버 열림, 드래그 중 여부를 한 곳에서만 판단해 FC.busy를 계산한다.
// 셋 중 하나라도 참이면 폴링(FC.startPolling)이 화면을 갈아엎지 않는다. busy를 여러
// 곳에서 각자 다른 조건으로 세우거나 풀면(모달 회귀 → 드래그 회귀 순으로 실제 발생했다)
// 회귀가 반복되므로, 상태가 바뀌는 모든 지점(모달/팝오버 열고 닫기, 드래그 시작/종료/드롭
// 처리)이 값을 직접 대입하지 말고 이 함수만 호출한다.
FC.recomputeBusy = function () {
  var popOpen = !document.getElementById('fc-popover').hidden;
  var modalOpen = !document.getElementById('fc-modal').hidden;
  FC.busy = !!FC.drag || popOpen || modalOpen;
};

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
    // render()는 #fc-board를 통째로 갈아엎는다. 드래그 중에 부르면 손에 든 슬롯
    // 노드가 DOM에서 분리돼 dragend가 document까지 안 올라가고 FC.busy가 영구
    // 고정된다. busy 전체가 아니라 FC.drag만 기준으로 삼는다 — 팝오버가 열려
    // 있어서 busy=true인 상태로 드롭 직후 refresh가 호출되는 정상 경로까지
    // 막으면 드롭 결과가 화면에 반영되지 않는다.
    if (FC.drag) { FC.state.revision = -1; return; }
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
  'server_error': '서버에 문제가 생겼어요. 잠시 후 다시 시도하세요',
  'bad_response': '서버 응답을 이해할 수 없어요. 잠시 후 다시 시도하세요',
  'unknown_error': '알 수 없는 오류가 났어요. 잠시 후 다시 시도하세요'
};

FC.errorText = function (err) {
  var msg = err && err.message ? err.message : '';
  if (msg.indexOf('player_conflict:') === 0) {
    return '같은 사람의 캐릭터가 이미 이 포스에 있어요 — ' + msg.slice('player_conflict:'.length);
  }
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
      // 요청을 보낸 뒤(busy=false) 응답이 오기 전(~10초 폴링 간격 안) 사용자가
      // 드래그를 시작했을 수 있다 — 응답 처리 시점에 다시 확인해야 render()가
      // 드래그 중인 슬롯 DOM을 갈아엎지 않는다.
      if (FC.busy) { FC.state.revision = known; return; }
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
  FC.recomputeBusy();
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
  FC.recomputeBusy();
  document.getElementById('fc-modal-close').addEventListener('click', FC.closeModal);

  // #fc-modal은 영속 엘리먼트라서, 열 때마다 매번 새 백드롭 클릭 리스너를 붙이면
  // 쌓이기만 하고 제거되지 않는다(닫기 버튼은 innerHTML로 매번 새로 만들어지니 문제
  // 없지만 host 자체는 그대로다). 한 번만 등록한다.
  if (!FC._modalBackdropBound) {
    host.addEventListener('click', function (e) { if (e.target === host) FC.closeModal(); });
    FC._modalBackdropBound = true;
  }
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

    var genRegister = FC.modalGen;
    save.disabled = true;
    save.textContent = '조회 중…';
    FC.api('player.create', { main_name: main, subs: subs }).then(function () {
      return FC.refresh();
    }).then(function () {
      FC.toast(main + ' 등록 완료', 'ok');
      // 요청이 도는 사이 사용자가 이미 이 모달을 닫고 다른 화면으로 넘어갔으면
      // 강제로 닫지 않는다 — 데이터는 이미 FC.refresh()로 갱신됐다.
      if (genRegister !== FC.modalGen) return;
      FC.closeModal();
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
                     : ((c.class || '직업?') + ' · ' + (c.atul ? FC.atulShort(c.atul) : '점수?')) })
      ]);
      // 임시 캐릭터는 아툴 갱신 대신 "확정" — 실제 캐릭명을 받아 조회까지 한 번에 한다
      if (isPh) {
        row.appendChild(FC.el('button', { class: 'fc-icon-btn fc-char-promote',
          'data-character-id': c.id, type: 'button', text: '확정' }));
      } else {
        row.appendChild(FC.el('button', { class: 'fc-icon-btn fc-char-refresh',
          'data-character-id': c.id, type: 'button', text: '갱신' }));
      }
      // 본캐 행에는 삭제 버튼을 두지 않는다 — 본캐를 지우면 그 사람 전체가 대기창/명단에서
      // 사라지는 것처럼 보이는 조작을 애초에 노출하지 않는다. 본캐를 없애려면
      // "이 사람 전체 삭제"를 쓰는 게 맞다.
      if (Number(c.is_main) !== 1) {
        row.appendChild(FC.el('button', { class: 'fc-icon-btn fc-char-del',
          'data-character-id': c.id, type: 'button', text: '삭제' }));
      }
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

// ── 레이드 추가/수정 ────────────────────────────────────────
FC.openRaidForm = function (raid) {
  var nameInput = FC.el('input', { class: 'fc-input', type: 'text', placeholder: '레이드 이름 (예: 루드라)' });
  var memoInput = FC.el('input', { class: 'fc-input', type: 'text', placeholder: '메모 (선택)' });
  if (raid) { nameInput.value = raid.name; memoInput.value = raid.memo || ''; }

  var save = FC.el('button', { class: 'fc-btn fc-btn-primary fc-block', type: 'button', text: raid ? '저장' : '추가' });
  save.addEventListener('click', function () {
    var name = nameInput.value.trim();
    if (!name) { FC.toast('레이드 이름을 입력하세요', 'err'); return; }
    save.disabled = true;
    var genSave = FC.modalGen;
    var call = raid
      ? FC.api('raid.update', { raid_id: raid.id, name: name, memo: memoInput.value.trim() })
      : FC.api('raid.create', { name: name });
    call.then(function (data) {
      if (!raid && data && data.raid_id) FC.activeRaidId = Number(data.raid_id);
      return FC.refresh();
    }).then(function () {
      FC.toast(raid ? '저장했어요' : '추가했어요', 'ok');
      // 요청이 도는 사이 사용자가 이미 이 모달을 닫고 다른 화면으로 넘어갔으면
      // 강제로 닫지 않는다 — 데이터는 이미 FC.refresh()로 갱신됐다.
      if (genSave !== FC.modalGen) return;
      FC.closeModal();
    }).catch(function (err) { FC.toast(FC.errorText(err), 'err'); save.disabled = false; });
  });

  var children = [nameInput];
  if (raid) children.push(memoInput);
  children.push(save);

  if (raid) {
    var del = FC.el('button', { class: 'fc-btn fc-block', type: 'button', text: '이 레이드 삭제' });
    del.addEventListener('click', function () {
      var n = (FC.state.forces || []).filter(function (f) {
        return Number(f.raid_id) === Number(raid.id);
      }).length;
      if (!confirm(raid.name + ' 을(를) 삭제할까요? 소속 포스 ' + n + '개가 함께 사라집니다.')) return;
      var genDel = FC.modalGen;
      FC.api('raid.delete', { raid_id: raid.id }).then(function () {
        FC.activeRaidId = null;
        return FC.refresh();
      }).then(function () {
        FC.toast('삭제했어요', 'ok');
        if (genDel !== FC.modalGen) return;
        FC.closeModal();
      }).catch(function (err) { FC.toast(FC.errorText(err), 'err'); });
    });
    children.push(del);
  }

  FC.openModal(raid ? '레이드 수정' : '레이드 추가', FC.el('div', { class: 'fc-form' }, children));
  nameInput.focus();
};

// ── 포스 추가/수정 ────────────────────────────────────────
FC.openForceForm = function (force) {
  var daySel = FC.el('select', { class: 'fc-input' });
  daySel.appendChild(FC.el('option', { value: '', text: '요일 미정' }));
  DAYS.forEach(function (d) {
    var opt = FC.el('option', { value: d, text: d + '요일' });
    if (force && force.day_of_week === d) opt.selected = true;
    daySel.appendChild(opt);
  });

  var timeInput = FC.el('input', { class: 'fc-input', type: 'time' });
  if (force && force.start_time) timeInput.value = force.start_time;

  var memoInput = FC.el('input', { class: 'fc-input', type: 'text', placeholder: '메모 (예: 남는자리 새싹)' });
  if (force) memoInput.value = force.memo || '';

  var save = FC.el('button', { class: 'fc-btn fc-btn-primary fc-block', type: 'button', text: force ? '저장' : '추가' });
  save.addEventListener('click', function () {
    save.disabled = true;
    var payload = {
      day_of_week: daySel.value,
      start_time: timeInput.value,
      memo: memoInput.value.trim()
    };
    var genForceSave = FC.modalGen;
    var call = force
      ? FC.api('force.update', Object.assign({ force_id: force.id }, payload))
      : FC.api('force.create', Object.assign({ raid_id: FC.activeRaidId }, payload));
    call.then(function () {
      return FC.refresh();
    }).then(function () {
      FC.toast(force ? '저장했어요' : '추가했어요', 'ok');
      if (genForceSave !== FC.modalGen) return;
      FC.closeModal();
    }).catch(function (err) { FC.toast(FC.errorText(err), 'err'); save.disabled = false; });
  });

  FC.openModal(force ? (force.force_no + '포스 수정') : '포스 추가',
    FC.el('div', { class: 'fc-form' }, [
      FC.el('label', { class: 'fc-hint', text: '무슨 요일 몇 시에 진행하나요?' }),
      daySel, timeInput, memoInput, save
    ]));
};

FC.bindGlobalEvents = function () {
  document.addEventListener('click', function (e) {
    var t = e.target;

    var card = t.closest ? t.closest('.fc-roster-card') : null;
    if (card) {
      if (card.classList.contains('is-open')) { FC.closePopover(); }
      else { FC.closePopover(); FC.openPopover(Number(card.getAttribute('data-player-id')), card); }
      return;
    }

    if (t.classList.contains('fc-slot-x')) {
      var xSlotId = Number(t.getAttribute('data-slot-id'));
      FC.api('slot.assign', { slot_id: xSlotId, character_id: null })
        .then(function () { return FC.refresh(); })
        .catch(function (err) { FC.toast(FC.errorText(err), 'err'); });
      return;
    }

    if (t.classList.contains('fc-pop-add-ph')) {
      var phPid = Number(t.getAttribute('data-player-id'));
      var phMain = FC.mainOf(phPid);
      var existing = FC.charsOfPlayer(phPid).filter(function (c) {
        return Number(c.is_placeholder) === 1;
      }).length;
      var suggested = (phMain ? phMain.name : '') + '부캐' + (existing + 1);
      var typed = prompt('임시 캐릭 이름', suggested);
      if (typed === null) return;
      typed = typed.trim();
      if (!typed) { FC.toast('이름을 입력하세요', 'err'); return; }

      FC.api('character.add', { player_id: phPid, name: typed, is_placeholder: true })
        .then(function () { return FC.refresh(); })
        .then(function () {
          FC.toast(typed + ' 추가 완료', 'ok');
          var again = document.querySelector('.fc-roster-card[data-player-id="' + phPid + '"]');
          if (again) FC.openPopover(phPid, again);
        })
        .catch(function (err) { FC.toast(FC.errorText(err), 'err'); });
      return;
    }

    if (!t.closest || !t.closest('#fc-popover')) FC.closePopover();

    if (t.id === 'fc-open-roster') { FC.openRoster(); return; }
    if (t.id === 'fc-add-player')  { FC.openAddPlayer(); return; }

    if (t.id === 'fc-add-raid' || t.id === 'fc-add-raid-big') { FC.openRaidForm(null); return; }
    if (t.id === 'fc-edit-raid') { FC.openRaidForm(FC.byId(FC.state.raids, FC.activeRaidId)); return; }
    if (t.id === 'fc-add-force' || t.id === 'fc-add-force-big') { FC.openForceForm(null); return; }
    if (t.id === 'fc-refresh-all') { FC.refreshAllAtul(); return; }

    if (t.classList.contains('fc-viewseg-btn')) {
      FC.setViewMode(FC.activeRaidId, t.getAttribute('data-view-mode'));
      return;
    }

    if (t.classList.contains('fc-tab') && t.hasAttribute('data-raid-id')) {
      FC.activeRaidId = Number(t.getAttribute('data-raid-id'));
      FC.render();
      return;
    }

    if (t.classList.contains('fc-force-edit')) {
      FC.openForceForm(FC.byId(FC.state.forces, Number(t.getAttribute('data-force-id'))));
      return;
    }

    if (t.classList.contains('fc-force-del')) {
      var delFid = Number(t.getAttribute('data-force-id'));
      var f = FC.byId(FC.state.forces, delFid);
      if (!confirm((f ? f.force_no + '포스' : '이 포스') + ' 를 삭제할까요?')) return;
      FC.api('force.delete', { force_id: delFid })
        .then(function () { FC.toast('삭제했어요', 'ok'); return FC.refresh(); })
        .catch(function (err) { FC.toast(FC.errorText(err), 'err'); });
      return;
    }

    if (t.classList.contains('fc-char-del')) {
      var cid = Number(t.getAttribute('data-character-id'));
      var ch = FC.byId(FC.state.characters, cid);
      if (!confirm((ch ? ch.name : '이 캐릭터') + ' 을(를) 삭제할까요? 배치된 자리도 비워집니다.')) return;
      var genDel = FC.modalGen;
      FC.api('character.delete', { character_id: cid })
        .then(function () { return FC.refresh(); })
        .then(function () {
          FC.toast('삭제했어요', 'ok');
          // 요청이 도는 사이 사용자가 이미 다른 모달로 넘어갔으면 화면은 건드리지 않는다
          // (토스트는 어느 화면에 있든 띄운다 — 성공 사실은 항상 알아야 한다)
          if (genDel !== FC.modalGen) return;
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
          FC.toast('삭제했어요', 'ok');
          if (genPlayerDel !== FC.modalGen) return;
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
          FC.toast('갱신했어요', 'ok');
          if (genRefresh !== FC.modalGen) return;
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
          FC.toast(name + ' 추가 완료', 'ok');
          if (genAddSub !== FC.modalGen) return;
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
          FC.toast(phName + ' 추가 완료', 'ok');
          if (genAddPh !== FC.modalGen) return;
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
          // character.update를 부르는 UI가 없어 직업/점수를 손으로 고칠 방법이 없다.
          // 조회 실패를 안내할 땐 존재하지 않는 기능을 권하지 말고, 이름을 다시 확인해
          // 재등록하라는 사실에 맞는 안내로 대체한다.
          FC.toast(lookedUp ? realName + ' 확정 완료'
                             : realName + ' 확정 (조회 실패 — 캐릭명을 확인 후 삭제하고 다시 등록해보세요)', 'ok');
          if (genPromote !== FC.modalGen) return;
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
    if (e.key === 'Escape') { FC.closeModal(); FC.closePopover(); }
  });
};

FC.drag = null;
FC.openPopoverPlayerId = null;

// 한 사람은 같은 포스에 두 번 들어갈 수 없다 — 동시에 두 캐릭터를 조종할 수 없기 때문이다.
// 본캐/부캐/임시 캐릭터를 구분하지 않고 같은 player_id면 전부 충돌로 본다.
// 서버 force/store.php 의 fc_force_player_conflict() 와 같은 규칙이다. 서버가 최종
// 권한이고, 이 검사는 드롭 전에 막아 카드가 들어갔다 되돌아오는 깜빡임을 없애는 용도다.
// excludeSlotId: 이번 조작으로 비워질 슬롯. 그 자리 캐릭터는 검사에서 뺀다.
// 충돌하는 캐릭터명을 돌려주고, 없으면 null.
FC.playerConflict = function (forceId, characterId, excludeSlotId) {
  var moving = FC.byId(FC.state.characters, characterId);
  if (!moving) return null;
  var found = null;
  (FC.state.slots || []).forEach(function (s) {
    if (found) return;
    if (Number(s.force_id) !== Number(forceId)) return;
    if (s.character_id === null) return;
    if (Number(s.id) === Number(excludeSlotId)) return;
    var sitting = FC.byId(FC.state.characters, s.character_id);
    if (sitting && Number(sitting.player_id) === Number(moving.player_id)) found = sitting.name;
  });
  return found;
};

FC.refreshAllRunning = false;
FC.refreshAllLabel = null;

// 명단 전체의 전투력을 다시 조회한다. 한 번에 다 돌면 웹 요청의 PHP 실행시간
// 제한(30초)에 걸리므로 서버가 offset/limit로 잘라주는 것을 이어서 부른다.
// 중간에 실패해도 그때까지 갱신된 캐릭터는 서버에 이미 저장되어 있다.
FC.refreshAllAtul = function () {
  if (FC.refreshAllRunning) return;
  FC.refreshAllRunning = true;

  var acc = { updated: 0, failed: 0, skipped: 0 };

  var setLabel = function (text) {
    FC.refreshAllLabel = text;
    var btn = document.getElementById('fc-refresh-all');
    if (btn) { btn.textContent = text; btn.disabled = true; }
  };

  var finish = function (err) {
    FC.refreshAllRunning = false;
    FC.refreshAllLabel = null;
    FC.refresh().catch(function () { FC.state.revision = -1; }).then(function () {
      if (err) {
        FC.toast('갱신이 중단됐어요 — ' + FC.errorText(err)
                 + ' (여기까지 ' + acc.updated + '건은 저장됨)', 'err');
      } else {
        FC.toast('갱신 ' + acc.updated + ' · 실패 ' + acc.failed + ' · 건너뜀 ' + acc.skipped, 'ok');
      }
    });
  };

  var step = function (offset) {
    return FC.api('atul.refresh_all', { offset: offset, limit: 15 }).then(function (r) {
      acc.updated += r.updated;
      acc.failed  += r.failed;
      acc.skipped += r.skipped;
      var seen = Math.min(offset + 15, r.total);
      setLabel('갱신 중 ' + seen + '/' + r.total);
      if (r.done || r.next_offset === null) { finish(null); return; }
      return step(r.next_offset);
    });
  };

  setLabel('갱신 중…');
  step(0).catch(function (err) { finish(err); });
};

FC.conflictText = function (name) {
  return '같은 사람의 캐릭터가 이미 이 포스에 있어요 — ' + name;
};

FC.closePopover = function () {
  var pop = document.getElementById('fc-popover');
  pop.hidden = true;
  pop.innerHTML = '';
  FC.openPopoverPlayerId = null;
  var opened = document.querySelector('.fc-roster-card.is-open');
  if (opened) opened.classList.remove('is-open');
  // 드래그 중이거나 모달이 열려 있으면 busy를 유지해야 한다 — FC.recomputeBusy()가
  // 그 판단을 전담한다(모달이 열린 채로 이 함수가 호출돼도, 예: 클릭 위임의 catch-all,
  // 모달 폼 작성 중 폴링이 재개되면 안 된다).
  FC.recomputeBusy();
};

// 드롭 성공/롤백 뒤 팝오버가 열려 있으면 같은 플레이어로 다시 그려서
// is-placed/포스 태그와 앵커 카드의 is-open 테두리를 최신 상태로 되돌린다.
FC.reopenPopoverIfOpen = function () {
  var pop = document.getElementById('fc-popover');
  if (pop.hidden || FC.openPopoverPlayerId === null) return;
  var pid = FC.openPopoverPlayerId;
  var card = document.querySelector('.fc-roster-card[data-player-id="' + pid + '"]');
  if (card) FC.openPopover(pid, card);
};

FC.openPopover = function (playerId, anchorEl) {
  var pop = document.getElementById('fc-popover');
  pop.innerHTML = '';

  var main = FC.mainOf(playerId);
  var chars = FC.charsOfPlayer(playerId);

  // 이 레이드에서 이 캐릭터가 배치된 포스 번호들
  var placedByChar = {};
  var forcesOfRaid = (FC.state.forces || []).filter(function (f) {
    return Number(f.raid_id) === Number(FC.activeRaidId);
  });
  forcesOfRaid.forEach(function (f) {
    (FC.state.slots || []).forEach(function (s) {
      if (Number(s.force_id) !== Number(f.id) || s.character_id === null) return;
      var key = String(s.character_id);
      if (!placedByChar[key]) placedByChar[key] = [];
      if (placedByChar[key].indexOf(f.force_no) === -1) placedByChar[key].push(f.force_no);
    });
  });

  pop.appendChild(FC.el('div', { class: 'fc-pop-title', text: (main ? main.name : '') + ' 의 캐릭터' }));

  // 한 사람은 같은 포스에 두 번 들어갈 수 없다. 이 플레이어가 이미 자리를 잡은 포스는
  // 어느 캐릭터로도 못 넣으므로, 끌어보기 전에 알려준다.
  var takenNos = [];
  forcesOfRaid.forEach(function (f) {
    var mine = (FC.state.slots || []).some(function (s) {
      if (Number(s.force_id) !== Number(f.id) || s.character_id === null) return false;
      var sitting = FC.byId(FC.state.characters, s.character_id);
      return sitting && Number(sitting.player_id) === Number(playerId);
    });
    if (mine && takenNos.indexOf(f.force_no) === -1) takenNos.push(f.force_no);
  });
  if (takenNos.length) {
    pop.appendChild(FC.el('div', { class: 'fc-pop-note',
      text: takenNos.sort(function (a, b) { return a - b; }).join(',') + '포스엔 이미 자리를 잡아서 못 넣어요' }));
  }

  chars.forEach(function (c) {
    var placed = placedByChar[String(c.id)] || [];
    var isPh = Number(c.is_placeholder) === 1;
    var row = FC.el('div', {
      class: 'fc-pop-row' + (placed.length ? ' is-placed' : '') + (isPh ? ' is-placeholder' : ''),
      draggable: 'true', 'data-character-id': c.id,
      style: '--slot-color:' + FC.classColor(c.class)
    }, [
      FC.el('span', { class: 'fc-dot', style: 'background:' + (isPh ? '#4a5a78' : FC.classColor(c.class)) }),
      FC.el('span', { class: 'fc-pop-name', text: (Number(c.is_main) === 1 ? '⭐ ' : '') + c.name }),
      FC.el('span', { class: 'fc-pop-meta',
        text: isPh ? '미정' : ((c.class || '직업?') + ' · ' + FC.atulShort(c.atul)) })
    ]);
    if (placed.length) {
      row.appendChild(FC.el('span', { class: 'fc-pop-tag', text: placed.join(',') + '포스' }));
    }
    pop.appendChild(row);
  });

  // 어떤 부캐를 보낼지 아직 안 정했을 때 쓰는 자리표시 카드
  pop.appendChild(FC.el('button', {
    class: 'fc-btn fc-block fc-pop-add-ph', type: 'button',
    'data-player-id': playerId, text: '+ 임시 캐릭 추가'
  }));

  if (!FC.activeRaidId) {
    pop.appendChild(FC.el('p', { class: 'fc-hint', text: '레이드를 먼저 선택하세요.' }));
  }

  pop.hidden = false;
  FC.openPopoverPlayerId = playerId;
  var rect = anchorEl.getBoundingClientRect();

  // 보드(#fc-board)를 절대 가리면 안 된다 — 팝오버는 사이드바 폭 안에 눕혀서라도
  // 오른쪽 끝이 보드 왼쪽 경계보다 왼쪽에 오도록 clamp한다. pop.hidden을 이미
  // false로 만든 뒤라서 offsetWidth가 0이 아닌 실제 렌더 폭을 준다.
  var popWidth = pop.offsetWidth;
  var left = rect.left;
  var boardEl = document.getElementById('fc-board');
  if (boardEl) {
    var boardRect = boardEl.getBoundingClientRect();
    left = Math.min(left, boardRect.left - 10 - popWidth);
  }
  left = Math.max(8, left);
  pop.style.left = (left + window.scrollX) + 'px';

  var top = Math.min(rect.top + window.scrollY, window.scrollY + window.innerHeight - pop.offsetHeight - 16);
  pop.style.top = Math.max(window.scrollY + 8, top) + 'px';

  anchorEl.classList.add('is-open');
  FC.recomputeBusy();
};

// 낙관적 UI: 화면을 먼저 바꾸고 뒤에서 저장한다. 실패하면 되돌린다.
FC.dropOnSlot = function (slotId) {
  var drag = FC.drag;
  if (!drag) return;

  // 여기서 곧바로 드래그 상태를 정리한다. FC.render()/FC.reopenPopoverIfOpen()이
  // 소스 노드(팝오버 행 또는 슬롯)를 DOM에서 분리시킬 수 있는데, 그러면 브라우저가
  // 원본 노드에 dragend를 발생시켜도 document까지 버블링되지 않아 FC.drag가 영원히
  // null로 안 돌아가고 FC.busy가 굳어 폴링이 영구 정지한다. dragend 핸들러가 나중에
  // 정상적으로 오더라도 이미 null인 값을 다시 null로 만들 뿐이라 멱등하다.
  FC.drag = null;
  FC.recomputeBusy();

  var slot = null;
  (FC.state.slots || []).forEach(function (s) { if (Number(s.id) === Number(slotId)) slot = s; });
  if (!slot) return;

  var snapshot = (FC.state.slots || []).map(function (s) {
    return { id: s.id, character_id: s.character_id };
  });
  var rollback = function () {
    var byId = {};
    snapshot.forEach(function (s) { byId[String(s.id)] = s.character_id; });
    (FC.state.slots || []).forEach(function (s) { s.character_id = byId[String(s.id)]; });
    // 다음 폴링이 반드시 서버 상태를 다시 가져오도록 revision을 무효화한다. FC.api는
    // slot.assign/swap이 성공하는 순간 이미 FC.state.revision을 서버의 새 값으로
    // 갱신해두므로, 되돌리지 않으면 다음 폴링이 "변경 없음"으로 오판하고 그대로
    // return해버려 화면이 새로고침 전까지 서버와 계속 어긋난다.
    FC.state.revision = -1;
    FC.render();
    FC.reopenPopoverIfOpen();
    FC.recomputeBusy();
  };

  var call;
  if (drag.type === 'slot') {
    if (Number(drag.fromSlotId) === Number(slotId)) return;
    var from = null;
    (FC.state.slots || []).forEach(function (s) { if (Number(s.id) === Number(drag.fromSlotId)) from = s; });
    if (!from) return;

    // 포스가 다를 때만 구성원이 바뀐다. 서로 상대 슬롯은 비워지므로 검사에서 뺀다.
    if (Number(from.force_id) !== Number(slot.force_id)) {
      var clashA = slot.character_id === null ? null
        : FC.playerConflict(from.force_id, slot.character_id, from.id);
      var clashB = from.character_id === null ? null
        : FC.playerConflict(slot.force_id, from.character_id, slot.id);
      var clash = clashA || clashB;
      if (clash) { FC.toast(FC.conflictText(clash), 'err'); return; }
    }

    var tmp = slot.character_id;
    slot.character_id = from.character_id;
    from.character_id = tmp;
    call = FC.api('slot.swap', { slot_id_a: drag.fromSlotId, slot_id_b: slotId });
  } else {
    var clashNew = FC.playerConflict(slot.force_id, drag.characterId, slot.id);
    if (clashNew) { FC.toast(FC.conflictText(clashNew), 'err'); return; }
    slot.character_id = drag.characterId;
    call = FC.api('slot.assign', { slot_id: slotId, character_id: drag.characterId });
  }

  FC.render();
  FC.reopenPopoverIfOpen();
  FC.recomputeBusy();
  // call(slot.assign/slot.swap)의 실패와 뒤이은 FC.refresh()의 실패를 구분한다.
  // call이 성공했으면 서버에는 이미 저장된 것이므로, 그 뒤 refresh(화면 갱신)만
  // 실패했다고 해서 rollback으로 이미 맞는 낙관적 UI를 되돌리면 안 된다 — 저장된
  // 변경을 화면에서만 지워버리는 꼴이 된다. 이 경우엔 revision만 무효화해서
  // 다음 폴링이 서버 상태를 다시 끌어오게 한다.
  call.then(function () {
    return FC.refresh().then(function () {
      FC.reopenPopoverIfOpen();
      FC.recomputeBusy();
    }).catch(function () {
      FC.state.revision = -1;
      FC.reopenPopoverIfOpen();
      FC.recomputeBusy();
    });
  }).catch(function (err) {
    rollback();
    FC.toast(FC.errorText(err), 'err');
  });
};

FC.bindDragEvents = function () {
  document.addEventListener('dragstart', function (e) {
    var row = e.target.closest ? e.target.closest('.fc-pop-row') : null;
    var slot = e.target.closest ? e.target.closest('.fc-slot.is-filled') : null;

    if (row) {
      if (!FC.activeRaidId) { e.preventDefault(); FC.toast('레이드를 먼저 선택하세요', 'err'); return; }
      FC.drag = { type: 'character', characterId: Number(row.getAttribute('data-character-id')), fromSlotId: null };
    } else if (slot) {
      FC.drag = {
        type: 'slot',
        characterId: Number(slot.getAttribute('data-character-id')),
        fromSlotId: Number(slot.getAttribute('data-slot-id'))
      };
    } else {
      return;
    }
    FC.recomputeBusy();
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', String(FC.drag.characterId));
  });

  document.addEventListener('dragover', function (e) {
    var slot = e.target.closest ? e.target.closest('.fc-slot') : null;
    if (!slot || !FC.drag) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    slot.classList.add('is-drop-target');
  });

  document.addEventListener('dragleave', function (e) {
    var slot = e.target.closest ? e.target.closest('.fc-slot') : null;
    if (slot) slot.classList.remove('is-drop-target');
  });

  document.addEventListener('drop', function (e) {
    var slot = e.target.closest ? e.target.closest('.fc-slot') : null;
    if (!slot || !FC.drag) return;
    e.preventDefault();
    slot.classList.remove('is-drop-target');
    FC.dropOnSlot(Number(slot.getAttribute('data-slot-id')));
  });

  document.addEventListener('dragend', function () {
    // 정상적으로 소스 노드가 살아있는 취소 경로(슬롯 밖에 드롭)에서는 이 핸들러가
    // 유일한 정리 지점이다. 드롭이 성공해 FC.dropOnSlot()이 이미 FC.drag를 null로
    // 만든 경우에도 다시 실행될 수 있지만 멱등하므로 문제없다.
    FC.drag = null;
    FC.recomputeBusy();
    Array.prototype.slice.call(document.querySelectorAll('.is-drop-target'))
      .forEach(function (n) { n.classList.remove('is-drop-target'); });
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
  FC.bindDragEvents();
})();
