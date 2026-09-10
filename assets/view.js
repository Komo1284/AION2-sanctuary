'use strict';

// 공유 보기(view.php) 전용 스크립트. 편집 페이지의 app.js와는 별개 파일이다 —
// app.js는 대부분이 드래그·팝오버·모달이라 읽기 전용 가드를 곳곳에 심는 것보다
// 렌더만 하는 짧은 파일을 따로 두는 쪽이 안전하다. 다만 직업 색상과 전투력 표기
// 규칙(atulShort)은 편집 화면과 반드시 같아야 하므로 app.js의 것을 그대로 옮겨 적었다.
// app.js에서 이 둘을 바꾸면 여기도 같이 바꿔야 한다.

var VW = {
  state: window.FC_STATE,
  activeRaidId: null
};

var CLASS_COLORS = {
  '수호성': '#5b8def', '검성': '#37a7a0', '권성': '#e0574a', '살성': '#8fd14f',
  '궁성': '#3fa86a', '호법성': '#d99a3c', '정령성': '#c956a5', '마도성': '#8e6bd8',
  '치유성': '#e0c04a'
};

VW.classColor = function (cls) { return CLASS_COLORS[cls] || '#4a5a78'; };

VW.atulShort = function (atul) {
  var n = Number(atul);
  if (!atul || !isFinite(n) || n <= 0) return '—';
  if (n < 1000) return String(Math.floor(n));
  if (n < 1000000) return Math.floor(n / 1000) + 'K';
  return (Math.floor(n / 1000) / 1000).toFixed(3) + 'M';
};

VW.el = function (tag, attrs, children) {
  var node = document.createElement(tag);
  Object.keys(attrs || {}).forEach(function (k) {
    if (attrs[k] === null || attrs[k] === undefined) return;
    if (k === 'text') node.textContent = attrs[k];
    else node.setAttribute(k, attrs[k]);
  });
  (children || []).forEach(function (c) { if (c) node.appendChild(c); });
  return node;
};

VW.byId = function (list, id) {
  for (var i = 0; i < (list || []).length; i++) {
    if (Number(list[i].id) === Number(id)) return list[i];
  }
  return null;
};

VW.mainOf = function (playerId) {
  var chars = (VW.state.characters || []).filter(function (c) {
    return Number(c.player_id) === Number(playerId) && Number(c.is_main) === 1;
  });
  return chars.length ? chars[0] : null;
};

VW.slotsOfForce = function (forceId, party) {
  return (VW.state.slots || [])
    .filter(function (s) {
      return Number(s.force_id) === Number(forceId) &&
             (party === undefined || Number(s.party_no) === Number(party));
    })
    .sort(function (a, b) { return (a.party_no - b.party_no) || (a.slot_no - b.slot_no); });
};

VW.isInactive = function (force) {
  return force.is_active !== undefined && Number(force.is_active) === 0;
};

// ── 렌더 ────────────────────────────────────────────────────
VW.renderTabs = function () {
  var host = document.getElementById('vw-tabs');
  host.innerHTML = '';
  (VW.state.raids || []).forEach(function (r) {
    var btn = VW.el('button', {
      class: 'vw-tab' + (Number(r.id) === Number(VW.activeRaidId) ? ' is-active' : ''),
      type: 'button', 'data-raid-id': r.id, text: r.name
    });
    host.appendChild(btn);
  });
  // 선택된 탭이 가로 스크롤 밖에 있으면 보이는 곳으로 끌어온다 (모바일에서 탭이 많을 때)
  var active = host.querySelector('.vw-tab.is-active');
  if (active && active.scrollIntoView) {
    active.scrollIntoView({ block: 'nearest', inline: 'nearest' });
  }
};

VW.renderSlot = function (slot) {
  if (slot.character_id === null) {
    return VW.el('li', { class: 'vw-slot is-empty' }, [
      VW.el('span', { class: 'vw-slot-no', text: slot.slot_no }),
      VW.el('span', { class: 'vw-slot-name', text: '빈 자리' })
    ]);
  }
  var c = VW.byId(VW.state.characters, slot.character_id);
  if (!c) return VW.renderSlot({ character_id: null, slot_no: slot.slot_no });

  var isMain = Number(c.is_main) === 1;
  var isPh = Number(c.is_placeholder) === 1;
  var main = isMain ? null : VW.mainOf(c.player_id);
  var metaText = isPh ? '미정' : ((c.class || '직업?') + ' · ' + VW.atulShort(c.atul));

  return VW.el('li', {
    class: 'vw-slot is-filled' + (isPh ? ' is-placeholder' : ''),
    style: '--slot-color:' + VW.classColor(c.class)
  }, [
    VW.el('span', { class: 'vw-slot-no', text: slot.slot_no }),
    VW.el('span', { class: 'vw-slot-body' }, [
      VW.el('span', { class: 'vw-slot-line' }, [
        VW.el('span', { class: 'vw-slot-name', text: c.name }),
        main ? VW.el('span', { class: 'vw-slot-owner', text: main.name }) : null
      ]),
      VW.el('span', { class: 'vw-slot-meta', text: metaText })
    ])
  ]);
};

VW.renderForce = function (force) {
  var slots = VW.slotsOfForce(force.id);
  var filled = 0, known = 0, sum = 0;
  slots.forEach(function (s) {
    if (s.character_id === null) return;
    filled++;
    var c = VW.byId(VW.state.characters, s.character_id);
    if (!c || !c.atul || Number(c.atul) <= 0) return;
    known++;
    sum += Number(c.atul);
  });
  var avgText = known ? '평균 ' + VW.atulShort(Math.round(sum / known))
                        + (known < filled ? ' (' + known + '명)' : '')
                      : '';
  var when = (force.day_of_week || '') + (force.start_time ? ' ' + force.start_time : '');
  var inactive = VW.isInactive(force);

  var head = VW.el('div', { class: 'vw-force-head' }, [
    VW.el('span', { class: 'vw-force-no', text: force.force_no + '포스' }),
    VW.el('span', { class: 'vw-force-when', text: when || '시간 미정' }),
    VW.el('span', { class: 'vw-spacer' }),
    VW.el('span', { class: 'vw-force-count', text: filled + '/10' }),
    avgText ? VW.el('span', { class: 'vw-force-avg', text: avgText }) : null
  ]);

  var parties = VW.el('div', { class: 'vw-parties' });
  [1, 2].forEach(function (party) {
    var list = VW.el('ul', { class: 'vw-party-list' });
    VW.slotsOfForce(force.id, party).forEach(function (s) { list.appendChild(VW.renderSlot(s)); });
    parties.appendChild(VW.el('section', { class: 'vw-party' }, [
      VW.el('h3', { class: 'vw-party-label', text: party + '파티' }),
      list
    ]));
  });

  var children = [head];
  if (inactive) children.push(VW.el('div', { class: 'vw-force-off', text: '⏸ 이번 주 미운영' }));
  if (force.memo) children.push(VW.el('div', { class: 'vw-force-memo', text: force.memo }));
  children.push(parties);

  return VW.el('article', { class: 'vw-force' + (inactive ? ' is-inactive' : '') }, children);
};

VW.renderBoard = function () {
  var host = document.getElementById('vw-board');
  host.innerHTML = '';
  var raid = VW.byId(VW.state.raids, VW.activeRaidId);
  if (!raid) {
    host.appendChild(VW.el('div', { class: 'vw-empty', text: '아직 편성된 레이드가 없습니다.' }));
    return;
  }
  if (raid.memo) host.appendChild(VW.el('div', { class: 'vw-raid-memo', text: raid.memo }));
  var forces = (VW.state.forces || [])
    .filter(function (f) { return Number(f.raid_id) === Number(raid.id); })
    .sort(function (a, b) { return (a.sort_order - b.sort_order) || (a.force_no - b.force_no); });
  if (!forces.length) {
    host.appendChild(VW.el('div', { class: 'vw-empty', text: '이 레이드에는 아직 포스가 없습니다.' }));
    return;
  }
  forces.forEach(function (f) { host.appendChild(VW.renderForce(f)); });
};

VW.render = function () {
  var raids = VW.state.raids || [];
  if (VW.activeRaidId && !VW.byId(raids, VW.activeRaidId)) VW.activeRaidId = null;
  if (!VW.activeRaidId && raids.length) VW.activeRaidId = raids[0].id;
  VW.renderTabs();
  VW.renderBoard();
};

VW.markUpdated = function (ok) {
  var el = document.getElementById('vw-updated');
  if (!ok) { el.textContent = '⚠ 갱신 실패 — 재시도 중'; el.classList.add('is-error'); return; }
  var d = new Date();
  var hh = String(d.getHours()).padStart(2, '0');
  var mm = String(d.getMinutes()).padStart(2, '0');
  el.textContent = hh + ':' + mm + ' 기준';
  el.classList.remove('is-error');
};

// ── 폴링 ────────────────────────────────────────────────────
// 공개 페이지이므로 편집 화면(10초)보다 느긋하게 30초. 탭이 안 보일 때는 건너뛴다.
VW.refresh = function () {
  if (document.hidden) return;
  fetch('force/view_state.php', { cache: 'no-store' })
    .then(function (r) { return r.json(); })
    .then(function (json) {
      if (!json || !json.ok) throw new Error('bad');
      if (json.data.revision !== VW.state.revision) {
        VW.state = json.data;
        VW.render();
      }
      VW.markUpdated(true);
    })
    .catch(function () { VW.markUpdated(false); });
};

document.getElementById('vw-tabs').addEventListener('click', function (e) {
  var tab = e.target.closest('.vw-tab');
  if (!tab) return;
  VW.activeRaidId = Number(tab.getAttribute('data-raid-id'));
  VW.render();
});

// 백그라운드 탭에서 돌아왔을 때 즉시 한 번 갱신한다
document.addEventListener('visibilitychange', function () { if (!document.hidden) VW.refresh(); });

VW.render();
VW.markUpdated(true);
setInterval(VW.refresh, 30000);
