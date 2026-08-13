'use strict';

// 서버가 심어준 초기 스냅샷. 이후 폴링으로 교체된다.
var FC = {
  state: window.FC_STATE,
  activeRaidId: null,
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
})();
