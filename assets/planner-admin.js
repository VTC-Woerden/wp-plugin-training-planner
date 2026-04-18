/**
 * Visuele blauwdruk: teamrooster + inhuur — Team-app-lay-out:
 * dagen onder elkaar, velden als rijen (swimlanes), tijd horizontaal.
 */
(function () {
	'use strict';

	var cfg = window.vtcTpPlanner;
	if (!cfg || !cfg.rest) {
		return;
	}

	/**
	 * Bouwt de volledige REST-URL. Voegt base en route samen met één slash.
	 * Bij plain permalinks is de basis `index.php?rest_route=/vtc-tp/v1/` — extra query (blueprint_id, iso_week)
	 * moet dan met `&` worden geplakt, niet met `?` (anders eindigt `rest_route` verkeerd en krijg je 404).
	 */
	function restPath(path) {
		var base = String(cfg.rest || '');
		var p = String(path || '').replace(/^\//, '');
		if (!base) {
			return p;
		}
		if (base.slice(-1) !== '/') {
			base += '/';
		}
		var qPos = p.indexOf('?');
		var routePart = qPos >= 0 ? p.slice(0, qPos) : p;
		var extraQuery = qPos >= 0 ? p.slice(qPos + 1) : '';
		var url = base + routePart;
		if (extraQuery) {
			url += (url.indexOf('?') !== -1 ? '&' : '?') + extraQuery;
		}
		return url;
	}

	var HOUR_START = 8;
	var HOUR_END = 23;
	var SNAP = 15;
	var DEFAULT_LEN = 90;
	var TOTAL_MIN = (HOUR_END - HOUR_START) * 60;
	var WORK_MODE_KEY = 'vtcTpWorkMode';
	var SCHEDULE_VIEW_KEY = 'vtcTpScheduleView';
	var ISO_WEEK_KEY = 'vtcTpIsoWeek';
	var BLUEPRINT_ID_KEY = 'vtcTpBlueprintId';

	var TEAM_COLORS = [
		'#e74c3c', '#3498db', '#2ecc71', '#f39c12', '#9b59b6',
		'#1abc9c', '#e67e22', '#2c3e50', '#e84393', '#00b894',
		'#6c5ce7', '#fd79a8', '#0984e3', '#d63031', '#00cec9'
	];

	function loadWorkMode() {
		try {
			var m = sessionStorage.getItem(WORK_MODE_KEY);
			return m === 'inhuur' ? 'inhuur' : 'teams';
		} catch (e) {
			return 'teams';
		}
	}

	function loadScheduleView() {
		try {
			var v = sessionStorage.getItem(SCHEDULE_VIEW_KEY);
			return v === 'week' ? 'week' : 'blueprint';
		} catch (e) {
			return 'blueprint';
		}
	}

	function loadIsoWeek() {
		try {
			var w = sessionStorage.getItem(ISO_WEEK_KEY);
			if (w) return w;
		} catch (e) { /* ignore */ }
		return cfg.currentIsoWeek || '2026-W01';
	}

	function loadBlueprintId() {
		try {
			var b = sessionStorage.getItem(BLUEPRINT_ID_KEY);
			if (b) return parseInt(b, 10) || 0;
		} catch (e2) { /* ignore */ }
		return cfg.baseBlueprintId ? parseInt(cfg.baseBlueprintId, 10) : 0;
	}

	function persistBlueprintId(id) {
		try {
			sessionStorage.setItem(BLUEPRINT_ID_KEY, String(id));
		} catch (e3) { /* ignore */ }
	}

	function normalizeIsoWeekStr(s) {
		s = String(s || '').trim();
		var m = /^(\d{4})[-_]?[Ww](\d{1,2})$/.exec(s);
		if (!m) return null;
		var y = parseInt(m[1], 10);
		var w = parseInt(m[2], 10);
		if (w < 1 || w > 53) return null;
		return y + '-W' + (w < 10 ? '0' : '') + w;
	}

	function addOneWeekIso(iso, delta) {
		var norm = normalizeIsoWeekStr(iso);
		if (!norm) return iso;
		var p = norm.match(/^(\d{4})-W(\d{2})$/);
		if (!p) return iso;
		var y = parseInt(p[1], 10);
		var w = parseInt(p[2], 10) + delta;
		if (w < 1) {
			y -= 1;
			w = 52;
		}
		if (w > 52) {
			y += 1;
			w = 1;
		}
		return y + '-W' + (w < 10 ? '0' : '') + w;
	}

	function isWeekScope() {
		return !!(state.data && state.data.planner_scope === 'week');
	}

	function plannerApiGet() {
		if (state.scheduleView === 'week') {
			var iw = normalizeIsoWeekStr(state.isoWeek) || state.isoWeek;
			return api('admin/planner-week?iso_week=' + encodeURIComponent(iw));
		}
		var path = 'admin/planner';
		var bid = state.blueprintId || cfg.baseBlueprintId || 0;
		if (bid) {
			path += '?blueprint_id=' + encodeURIComponent(String(bid));
		}
		return api(path);
	}

	function applyLoadedPlannerData(data) {
		if (!data.unavailability) data.unavailability = [];
		state.data = data;
		state.lastLoadError = null;
		// Alleen in blauwdruk-modus server-blauwdruk overnemen; weekweergave toont effectieve BP en mag je keuze voor het blauwdruk-tabblad niet overschrijven.
		if (data.planner_scope === 'blueprint' && data.blueprint_id) {
			state.blueprintId = data.blueprint_id;
			persistBlueprintId(data.blueprint_id);
			if (data.blueprint_name) {
				var found = state.blueprintsList.some(function (b) {
					return Number(b.id) === Number(data.blueprint_id);
				});
				if (!found) {
					state.blueprintsList = state.blueprintsList.concat([{
						id: data.blueprint_id,
						name: data.blueprint_name,
						kind: data.blueprint_kind != null ? data.blueprint_kind : 0
					}]);
				}
			}
		}
		if (data.iso_week) state.isoWeek = data.iso_week;
		state.selectedId = null;
		state.selectedUnavailId = null;
		state.selectedSlotIds.clear();
		resetPending();
		state.localDirty = false;
		state.tempSlotId = -1;
		state.tempUnavailId = -1;
		state.teamPickerOpen = false;
		state.teamPickerShowAll = false;
	}

	var state = {
		data: null,
		blueprintsList: [],
		blueprintId: loadBlueprintId(),
		selectedId: null,
		selectedSlotIds: new Set(),
		selectedUnavailId: null,
		scheduleView: loadScheduleView(),
		isoWeek: loadIsoWeek(),
		workMode: loadWorkMode(),
		toastTimer: null,
		keydownBound: false,
		beforeunloadBound: false,
		localDirty: false,
		saving: false,
		tempSlotId: -1,
		tempUnavailId: -1,
		lastLoadError: null,
		teamPickerOpen: false,
		teamPickerShowAll: false,
		teamPickerAnchorX: 0,
		teamPickerAnchorY: 0,
		teamPickerVenueId: 0,
		teamPickerDow: 0,
		teamPickerStart: '',
		teamPickerEnd: '',
		teamPickerDocBound: false
	};

	var pending = {
		slotDeletes: new Set(),
		slotPatches: Object.create(null),
		unavailDeletes: new Set(),
		unavailPatches: Object.create(null)
	};

	var drag = null;
	var paint = null;

	function __(t) {
		return (cfg.i18n && cfg.i18n[t]) || t;
	}

	function setWorkMode(m) {
		if (state.scheduleView === 'week' && m === 'inhuur') {
			return;
		}
		state.workMode = m === 'inhuur' ? 'inhuur' : 'teams';
		try {
			sessionStorage.setItem(WORK_MODE_KEY, state.workMode);
		} catch (e) { /* ignore */ }
		state.selectedId = null;
		state.selectedUnavailId = null;
		state.selectedSlotIds.clear();
		state.teamPickerOpen = false;
		state.teamPickerShowAll = false;
		render();
	}

	function confirmDiscardIfDirty() {
		if (!state.localDirty) return true;
		return window.confirm(__('reloadLose'));
	}

	function setScheduleView(view) {
		var v = view === 'week' ? 'week' : 'blueprint';
		if (v === state.scheduleView) return;
		if (!confirmDiscardIfDirty()) return;
		state.scheduleView = v;
		try {
			sessionStorage.setItem(SCHEDULE_VIEW_KEY, v);
		} catch (e) { /* ignore */ }
		if (v === 'week') {
			state.workMode = 'teams';
			try {
				sessionStorage.setItem(WORK_MODE_KEY, 'teams');
			} catch (e2) { /* ignore */ }
		}
		resetPending();
		state.localDirty = false;
		loadPlanner({ force: true, initial: true });
	}

	function setIsoWeekAndLoad(iso) {
		var norm = normalizeIsoWeekStr(iso);
		if (!norm) {
			showToast(__('badWeek'), false);
			return;
		}
		if (!confirmDiscardIfDirty()) return;
		state.isoWeek = norm;
		try {
			sessionStorage.setItem(ISO_WEEK_KEY, norm);
		} catch (e) { /* ignore */ }
		resetPending();
		state.localDirty = false;
		if (state.scheduleView === 'week') {
			loadPlanner({ force: true, initial: true });
		}
	}

	function api(path, opts) {
		opts = opts || {};
		var headers = Object.assign(
			{ 'X-WP-Nonce': cfg.nonce },
			opts.headers || {}
		);
		if (opts.body && typeof opts.body === 'string') {
			headers['Content-Type'] = 'application/json';
		}
		return fetch(restPath(path), {
			method: opts.method || 'GET',
			body: opts.body,
			credentials: 'same-origin',
			headers: headers
		}).then(function (r) {
			return r.text().then(function (text) {
				var j = null;
				if (text) {
					try {
						j = JSON.parse(text);
					} catch (parseErr) {
						j = null;
					}
				}
				if (!r.ok) {
					var msg = (j && j.message) || (j && j.code) || (text && text.slice(0, 120)) || String(r.status);
					throw new Error(msg);
				}
				if (j == null) {
					throw new Error(__('loadErr'));
				}
				return j;
			});
		});
	}

	function timeToMin(t) {
		var p = String(t).split(':');
		var h = parseInt(p[0], 10) || 0;
		var m = parseInt(p[1], 10) || 0;
		return h * 60 + m;
	}

	function minToTime(m) {
		m = Math.round(m / SNAP) * SNAP;
		var lo = HOUR_START * 60;
		var hi = HOUR_END * 60;
		m = Math.max(lo, Math.min(hi - SNAP, m));
		var h = Math.floor(m / 60);
		var mm = m % 60;
		return (h < 10 ? '0' : '') + h + ':' + (mm < 10 ? '0' : '') + mm;
	}

	function minRel(m) {
		return m - HOUR_START * 60;
	}

	function slotStyleLeftPct(start) {
		return (minRel(timeToMin(start)) / TOTAL_MIN) * 100;
	}

	function slotStyleWidthPct(start, end) {
		var d = timeToMin(end) - timeToMin(start);
		return (d / TOTAL_MIN) * 100;
	}

	function pxPerMinFromBody(body) {
		var w = body.getBoundingClientRect().width;
		return w > 0 ? w / TOTAL_MIN : 1;
	}

	/**
	 * Baan onder de cursor tijdens slepen: het slepende blok zit bovenop de stack.
	 * Sla dat element expliciet over (closest('.is-dragging') faalt zodra het blok al verplaatst is).
	 */
	function laneBodyUnderPointExcludingBlock(clientX, clientY, blockEl) {
		var stack = document.elementsFromPoint(clientX, clientY);
		for (var i = 0; i < stack.length; i++) {
			var node = stack[i];
			if (!node || node.nodeType !== 1) {
				continue;
			}
			if (blockEl && (node === blockEl || blockEl.contains(node))) {
				continue;
			}
			var lb = node.closest ? node.closest('.vtc-tppl-lane-body') : null;
			if (lb) {
				return lb;
			}
		}
		return null;
	}

	function teamColor(id) {
		return TEAM_COLORS[Math.abs(id) % TEAM_COLORS.length];
	}

	function syncSlotSelectionDom() {
		document.querySelectorAll('.vtc-tppl-block').forEach(function (b) {
			var sid = parseInt(b.getAttribute('data-slot-id'), 10);
			b.classList.toggle('is-selected', state.selectedSlotIds.has(sid));
		});
	}

	function teamTrainingsRequired(t) {
		var n = t.trainings_per_week;
		if (n == null || n === '') return 2;
		return Math.max(0, parseInt(n, 10) || 0);
	}

	function slotsListForTeamCount(weekReadonly) {
		if (!state.data) return [];
		if (weekReadonly) return state.data.baseline_slots || [];
		return state.data.slots || [];
	}

	function countSlotsForTeam(teamId, weekReadonly) {
		var list = slotsListForTeamCount(weekReadonly);
		var c = 0;
		for (var i = 0; i < list.length; i++) {
			if (list[i].team_id === teamId) c++;
		}
		return c;
	}

	function getTeamOverviewRows(weekReadonly, showAll) {
		if (!state.data || !state.data.teams) return [];
		var out = [];
		state.data.teams.forEach(function (t) {
			var req = teamTrainingsRequired(t);
			var cnt = countSlotsForTeam(t.id, weekReadonly);
			if (showAll) {
				out.push({ id: t.id, display_name: t.display_name, count: cnt, required: req });
			} else if (req > 0 && cnt < req) {
				out.push({ id: t.id, display_name: t.display_name, count: cnt, required: req });
			}
		});
		return out;
	}

	function computePlacementFromPoint(body, clientX) {
		var venueId = parseInt(body.getAttribute('data-venue-id'), 10);
		var dow = parseInt(body.getAttribute('data-dow'), 10);
		var rect = body.getBoundingClientRect();
		var x = clientX - rect.left;
		var frac = Math.max(0, Math.min(1, rect.width > 0 ? x / rect.width : 0));
		var m = HOUR_START * 60 + frac * TOTAL_MIN;
		m = Math.round(m / SNAP) * SNAP;
		var start = minToTime(m);
		var end = minToTime(m + DEFAULT_LEN);
		if (timeToMin(end) > HOUR_END * 60) {
			end = minToTime(HOUR_END * 60);
			start = minToTime(timeToMin(end) - DEFAULT_LEN);
		}
		return { venue_id: venueId, day_of_week: dow, start_time: start, end_time: end };
	}

	function clampTeamPickerPosition(left, top) {
		var pw = 240;
		var ph = 280;
		var margin = 8;
		var vw = window.innerWidth || 800;
		var vh = window.innerHeight || 600;
		return {
			left: Math.min(Math.max(margin, left), vw - pw - margin),
			top: Math.min(Math.max(margin, top), vh - ph - margin)
		};
	}

	function buildTeamPickerPopHtml(weekReadonly, inhuur, hasTeams) {
		if (!state.teamPickerOpen || inhuur || !hasTeams) return '';
		var pos = clampTeamPickerPosition(state.teamPickerAnchorX + 4, state.teamPickerAnchorY + 8);
		var oRows = getTeamOverviewRows(weekReadonly, state.teamPickerShowAll);
		var h = '<div id="vtc-tppl-team-picker" class="vtc-tppl-team-picker" role="dialog" aria-label="' + esc(__('teamOverviewTitle')) + '" style="left:' + pos.left + 'px;top:' + pos.top + 'px">';
		h += '<ul class="vtc-tppl-team-picker-list">';
		if (!oRows.length) {
			h += '<li class="vtc-tppl-team-picker-empty">' + esc(__('teamOverviewEmpty')) + '</li>';
		} else {
			oRows.forEach(function (row) {
				h += '<li><button type="button" class="vtc-tppl-team-picker-item" data-team-id="' + row.id + '">';
				h += '<span class="vtc-tppl-team-picker-dot" style="background:' + teamColor(row.id) + '"></span>';
				h += '<span class="vtc-tppl-team-picker-name">' + esc(row.display_name) + '</span>';
				h += '</button></li>';
			});
		}
		h += '</ul>';
		h += '<hr class="vtc-tppl-team-picker-divider" />';
		h += '<div class="vtc-tppl-team-picker-footer">';
		h += '<label class="vtc-tppl-team-picker-all"><input type="checkbox" id="vtc-tppl-team-picker-show-all"' + (state.teamPickerShowAll ? ' checked' : '') + ' /> ' + esc(__('teamOverviewShowAll')) + '</label>';
		h += '</div></div>';
		return h;
	}

	function placeTeamAtPickerSlot(teamId) {
		if (!state.data) return;
		if (isWeekScope() && !state.data.has_exception && !state.data.uses_deviation_blueprint) return;
		var team = findTeam(teamId);
		var tid = allocSlotTempId();
		mergeSlot({
			id: tid,
			team_id: teamId,
			venue_id: state.teamPickerVenueId,
			day_of_week: state.teamPickerDow,
			start_time: state.teamPickerStart,
			end_time: state.teamPickerEnd,
			team_name: team ? team.display_name : ''
		});
		state.teamPickerOpen = false;
		state.teamPickerShowAll = false;
		markDirty();
		render();
		showToast(__('added'), true);
	}

	function removeTeamPickerDom() {
		var p = document.getElementById('vtc-tppl-team-picker');
		if (p && p.parentNode) {
			p.parentNode.removeChild(p);
		}
	}

	function onDocumentMouseDownClosePicker(e) {
		if (!state.teamPickerOpen) return;
		var p = document.getElementById('vtc-tppl-team-picker');
		if (p && p.contains(e.target)) return;
		state.teamPickerOpen = false;
		state.teamPickerShowAll = false;
		removeTeamPickerDom();
	}

	function onLaneClickOpenTeamPicker(e) {
		if (e.target !== e.currentTarget) return;
		if (state.workMode !== 'teams') return;
		if (!state.data || !state.data.teams || !state.data.teams.length) return;
		var weekScope = isWeekScope();
		var weekReadonly = weekScope && !state.data.has_exception && !state.data.uses_deviation_blueprint;
		if (weekReadonly) return;
		var place = computePlacementFromPoint(e.currentTarget, e.clientX);
		state.teamPickerVenueId = place.venue_id;
		state.teamPickerDow = place.day_of_week;
		state.teamPickerStart = place.start_time;
		state.teamPickerEnd = place.end_time;
		state.teamPickerAnchorX = e.clientX;
		state.teamPickerAnchorY = e.clientY;
		state.teamPickerOpen = true;
		state.teamPickerShowAll = false;
		e.preventDefault();
		e.stopPropagation();
		render();
	}

	function showToast(msg, ok) {
		var el = document.getElementById('vtc-tppl-toast');
		if (!el) return;
		el.textContent = msg;
		el.className = 'vtc-tppl-toast ' + (ok ? 'vtc-tppl-toast--ok' : 'vtc-tppl-toast--err');
		clearTimeout(state.toastTimer);
		state.toastTimer = setTimeout(function () {
			el.textContent = '';
			el.className = 'vtc-tppl-toast';
		}, 4200);
	}

	function findSlot(id) {
		if (!state.data || !state.data.slots) return null;
		for (var i = 0; i < state.data.slots.length; i++) {
			if (state.data.slots[i].id === id) return state.data.slots[i];
		}
		return null;
	}

	function findUnavail(id) {
		var list = state.data.unavailability || [];
		for (var i = 0; i < list.length; i++) {
			if (list[i].id === id) return list[i];
		}
		return null;
	}

	function mergeSlot(updated) {
		var list = state.data.slots || [];
		var i;
		for (i = 0; i < list.length; i++) {
			if (list[i].id === updated.id) {
				list[i] = updated;
				return;
			}
		}
		list.push(updated);
	}

	function mergeUnavail(u) {
		var list = state.data.unavailability || [];
		var i;
		for (i = 0; i < list.length; i++) {
			if (list[i].id === u.id) {
				list[i] = u;
				return;
			}
		}
		list.push(u);
	}

	function removeSlot(id) {
		state.data.slots = (state.data.slots || []).filter(function (s) {
			return s.id !== id;
		});
	}

	function removeUnavail(id) {
		state.data.unavailability = (state.data.unavailability || []).filter(function (u) {
			return u.id !== id;
		});
	}

	function resetPending() {
		pending.slotDeletes = new Set();
		pending.slotPatches = Object.create(null);
		pending.unavailDeletes = new Set();
		pending.unavailPatches = Object.create(null);
	}

	function markDirty() {
		state.localDirty = true;
	}

	function findTeam(teamId) {
		var list = state.data && state.data.teams;
		if (!list) return null;
		for (var i = 0; i < list.length; i++) {
			if (list[i].id === teamId) return list[i];
		}
		return null;
	}

	function allocSlotTempId() {
		return state.tempSlotId--;
	}

	function allocUnavailTempId() {
		return state.tempUnavailId--;
	}

	function recordSlotChange(id, patch) {
		var slot = findSlot(id);
		if (!slot) return;
		Object.assign(slot, patch);
		if (id < 0) {
			markDirty();
			return;
		}
		if (pending.slotDeletes.has(id)) return;
		pending.slotPatches[id] = Object.assign({}, pending.slotPatches[id] || {}, patch);
		markDirty();
	}

	function recordUnavailChange(id, patch) {
		var u = findUnavail(id);
		if (!u) return;
		Object.assign(u, patch);
		if (id < 0) {
			markDirty();
			return;
		}
		if (pending.unavailDeletes.has(id)) return;
		pending.unavailPatches[id] = Object.assign({}, pending.unavailPatches[id] || {}, patch);
		markDirty();
	}

	function syncDirtyUi() {
		var root = document.getElementById('vtc-tp-planner-root');
		if (!root) return;
		var wrap = root.querySelector('.vtc-tppl');
		var saveBtn = document.getElementById('vtc-tppl-save');
		var pubBtn = document.getElementById('vtc-tppl-publish');
		var reloadBtn = document.getElementById('vtc-tppl-reload');
		var banner = document.getElementById('vtc-tppl-dirty-banner');
		if (wrap) wrap.classList.toggle('vtc-tppl--dirty', state.localDirty);
		if (saveBtn) {
			saveBtn.disabled = !state.localDirty || state.saving;
			saveBtn.textContent = state.saving ? __('saving') : __('saveDraft');
		}
		if (pubBtn) {
			pubBtn.disabled = state.saving;
			var hidePub = isWeekScope() && !(state.data && state.data.uses_deviation_blueprint && !state.data.has_exception);
			pubBtn.style.display = hidePub ? 'none' : '';
		}
		if (reloadBtn) reloadBtn.disabled = state.saving;
		if (banner) {
			banner.hidden = !state.localDirty;
		}
	}

	function effectiveBlueprintIdForApi() {
		var bid = state.blueprintId || cfg.baseBlueprintId || 0;
		return bid ? bid : 0;
	}

	function bpQuery() {
		var bid = effectiveBlueprintIdForApi();
		return bid ? ('?blueprint_id=' + encodeURIComponent(String(bid))) : '';
	}

	function withBlueprintBody(obj) {
		var o = obj || {};
		o.blueprint_id = effectiveBlueprintIdForApi();
		return o;
	}

	function runBlueprintSlotSaveChain(delSlots, delUn, patchSlotIds, patchUnIds, newSlots, newUn) {
		var q = bpQuery();
		return Promise.all(
			delSlots.map(function (sid) {
				return api('admin/slots/' + sid + q, { method: 'DELETE' });
			})
		)
			.then(function () {
				return Promise.all(
					delUn.map(function (uid) {
						return api('admin/unavailability/' + uid + q, { method: 'DELETE' });
					})
				);
			})
			.then(function () {
				return Promise.all(
					patchSlotIds.map(function (sk) {
						var id = parseInt(sk, 10);
						return api('admin/slots/' + id + q, {
							method: 'PATCH',
							body: JSON.stringify(withBlueprintBody(pending.slotPatches[sk]))
						});
					})
				);
			})
			.then(function () {
				return Promise.all(
					patchUnIds.map(function (uk) {
						var id = parseInt(uk, 10);
						return api('admin/unavailability/' + id + q, {
							method: 'PATCH',
							body: JSON.stringify(withBlueprintBody(pending.unavailPatches[uk]))
						});
					})
				);
			})
			.then(function () {
				return Promise.all(
					newSlots.map(function (s) {
						return api('admin/slots' + q, {
							method: 'POST',
							body: JSON.stringify(withBlueprintBody({
								team_id: s.team_id,
								venue_id: s.venue_id,
								day_of_week: s.day_of_week,
								start_time: s.start_time,
								end_time: s.end_time
							}))
						});
					})
				);
			})
			.then(function () {
				return Promise.all(
					newUn.map(function (u) {
						return api('admin/unavailability' + q, {
							method: 'POST',
							body: JSON.stringify(withBlueprintBody({
								venue_id: u.venue_id,
								day_of_week: u.day_of_week,
								start_time: u.start_time,
								end_time: u.end_time
							}))
						});
					})
				);
			});
	}

	function saveAll() {
		if (!state.localDirty || state.saving) {
			return Promise.resolve();
		}
		if (isWeekScope() && !state.data.has_exception && !state.data.uses_deviation_blueprint) {
			return Promise.resolve();
		}
		state.saving = true;
		syncDirtyUi();

		var delSlots = Array.from(pending.slotDeletes);
		var delUn = Array.from(pending.unavailDeletes);
		var patchSlotIds = Object.keys(pending.slotPatches);
		var patchUnIds = Object.keys(pending.unavailPatches);
		var newSlots = (state.data.slots || []).filter(function (s) {
			return s.id < 0;
		}).sort(function (a, b) {
			return a.id - b.id;
		});
		var newUn = (state.data.unavailability || []).filter(function (u) {
			return u.id < 0;
		}).sort(function (a, b) {
			return a.id - b.id;
		});

		var chain;
		var weekException = isWeekScope() && state.data.has_exception;
		if (weekException) {
			var ewid = state.data.exception_week_id;
			chain = Promise.all(
				delSlots.map(function (sid) {
					return api('admin/exception-slots/' + sid, { method: 'DELETE' });
				})
			)
				.then(function () {
					return Promise.all(
						patchSlotIds.map(function (sk) {
							var id = parseInt(sk, 10);
							return api('admin/exception-slots/' + id, {
								method: 'PATCH',
								body: JSON.stringify(pending.slotPatches[sk])
							});
						})
					);
				})
				.then(function () {
					return Promise.all(
						newSlots.map(function (s) {
							return api('admin/exception-slots', {
								method: 'POST',
								body: JSON.stringify({
									exception_week_id: ewid,
									team_id: s.team_id,
									venue_id: s.venue_id,
									day_of_week: s.day_of_week,
									start_time: s.start_time,
									end_time: s.end_time
								})
							});
						})
					);
				});
		} else {
			chain = runBlueprintSlotSaveChain(delSlots, delUn, patchSlotIds, patchUnIds, newSlots, newUn);
		}

		return chain
			.then(function () {
				return plannerApiGet();
			})
			.then(function (data) {
				applyLoadedPlannerData(data);
				state.saving = false;
				render();
				showToast(__('savedAll'), true);
			})
			.catch(function (err) {
				state.saving = false;
				syncDirtyUi();
				showToast(err.message || __('saveErr'), false);
				return loadPlanner({ force: true }).then(function () {
					return Promise.reject(err);
				});
			});
	}

	function loadPlanner(opts) {
		opts = opts || {};
		if (state.localDirty && !opts.force && !opts.initial) {
			if (!window.confirm(__('reloadLose'))) {
				return Promise.resolve();
			}
		}
		var previousData = state.data;
		var attemptedView = state.scheduleView;
		return plannerApiGet()
			.then(function (data) {
				applyLoadedPlannerData(data);
				render();
			})
			.catch(function (err) {
				var msg = (err && err.message) ? String(err.message) : __('loadErr');
				state.lastLoadError = msg;
				state.data = previousData;
				if (state.data && state.data.iso_week) {
					state.isoWeek = state.data.iso_week;
				}
				if (state.data && attemptedView === 'week' && state.data.planner_scope === 'blueprint') {
					state.scheduleView = 'blueprint';
					try {
						sessionStorage.setItem(SCHEDULE_VIEW_KEY, 'blueprint');
					} catch (e1) { /* ignore */ }
				}
				showToast(msg, false);
				if (!state.data) {
					if (attemptedView === 'week') {
						state.scheduleView = 'blueprint';
						try {
							sessionStorage.setItem(SCHEDULE_VIEW_KEY, 'blueprint');
						} catch (e2) { /* ignore */ }
						return plannerApiGet()
							.then(function (data) {
								applyLoadedPlannerData(data);
								render();
							})
							.catch(function (err2) {
								state.lastLoadError = (err2 && err2.message) ? String(err2.message) : __('loadErr');
								state.data = null;
								showToast(state.lastLoadError, false);
								render();
							});
					}
					render();
					return Promise.resolve();
				}
				render();
				return Promise.resolve();
			});
	}

	function onBeforeUnload(e) {
		if (state.localDirty) {
			e.preventDefault();
			e.returnValue = '';
		}
	}

	function bodiesIntersectingRect(dayEl, x1, y1, x2, y2) {
		var L = Math.min(x1, x2);
		var R = Math.max(x1, x2);
		var T = Math.min(y1, y2);
		var B = Math.max(y1, y2);
		var out = [];
		dayEl.querySelectorAll('.vtc-tppl-lane-body').forEach(function (body) {
			var r = body.getBoundingClientRect();
			if (r.right > L && r.left < R && r.bottom > T && r.top < B) {
				out.push(body);
			}
		});
		return out;
	}

	function xRangeToStartEndOnBody(refBody, xLeft, xRight) {
		var r = refBody.getBoundingClientRect();
		var l = Math.min(xLeft, xRight);
		var ri = Math.max(xLeft, xRight);
		var fl = (l - r.left) / r.width;
		var fr = (ri - r.left) / r.width;
		fl = Math.max(0, Math.min(1, fl));
		fr = Math.max(0, Math.min(1, fr));
		if (fr <= fl) {
			fr = Math.min(1, fl + SNAP / TOTAL_MIN);
		}
		var mStart = HOUR_START * 60 + fl * TOTAL_MIN;
		var mEnd = HOUR_START * 60 + fr * TOTAL_MIN;
		mStart = Math.round(mStart / SNAP) * SNAP;
		mEnd = Math.round(mEnd / SNAP) * SNAP;
		if (mEnd - mStart < SNAP) {
			mEnd = mStart + SNAP;
		}
		if (mEnd > HOUR_END * 60) {
			mEnd = HOUR_END * 60;
			mStart = Math.max(HOUR_START * 60, mEnd - SNAP);
		}
		return { start: minToTime(mStart), end: minToTime(mEnd) };
	}

	function renderLoadErrorShell() {
		var root = document.getElementById('vtc-tp-planner-root');
		if (!root) return;
		var weekSel = state.scheduleView === 'week';
		var msg = state.lastLoadError || __('loadErr');
		var html = '';
		html += '<div class="vtc-tppl vtc-tppl--load-error">';
		html += '<div class="vtc-tppl-toolbar">';
		html += '<div class="vtc-tppl-toolbar-row vtc-tppl-toolbar-row--main">';
		html += '<h2>' + esc(__('loadErr')) + '</h2>';
		html += '<div class="vtc-tppl-schedule-view" role="tablist">';
		html += '<button type="button" class="button vtc-tppl-view-btn' + (!weekSel ? ' is-active' : '') + '" data-schedule-view="blueprint">' + esc(__('viewBlueprint')) + '</button>';
		html += '<button type="button" class="button vtc-tppl-view-btn' + (weekSel ? ' is-active' : '') + '" data-schedule-view="week">' + esc(__('viewWeek')) + '</button>';
		html += '</div>';
		html += '<div class="vtc-tppl-actions">';
		html += '<button type="button" class="button" id="vtc-tppl-reload">' + esc(__('reload')) + '</button>';
		html += '<span id="vtc-tppl-toast" class="vtc-tppl-toast" role="status"></span>';
		html += '</div></div></div>';
		html += '<p class="vtc-tppl-empty vtc-tppl-load-error-detail">' + esc(msg) + '</p>';
		html += '<p class="vtc-tppl-load-error-hint">' + esc(__('loadErrHint')) + '</p>';
		html += '</div>';
		root.innerHTML = html;
		bind();
		syncDirtyUi();
	}

	function render() {
		var root = document.getElementById('vtc-tp-planner-root');
		if (!root) return;

		if (!state.data) {
			renderLoadErrorShell();
			return;
		}

		var d = state.data;
		if (!d.venues || !d.venues.length) {
			root.innerHTML = '<p class="vtc-tppl-empty">' + esc(__('noVenues')) +
				' <a href="' + esc(cfg.stam) + '">Stamdata</a></p>';
			return;
		}

		var weekScope = isWeekScope();
		var inhuur = state.workMode === 'inhuur' && !weekScope;
		var hasTeams = d.teams && d.teams.length > 0;
		var weekReadonly = weekScope && !d.has_exception && !d.uses_deviation_blueprint;
		var iwForInput = normalizeIsoWeekStr(d.iso_week || state.isoWeek) || state.isoWeek;

		var html = '';
		html += '<div class="vtc-tppl' + (inhuur ? ' vtc-tppl--inhuur' : '') + (weekScope ? ' vtc-tppl--week-scope' : '') + '">';
		html += '<div class="vtc-tppl-toolbar">';
		html += '<div class="vtc-tppl-toolbar-row vtc-tppl-toolbar-row--main">';
		html += '<h2>' + esc(weekScope ? (String(__('viewWeek')) + ': ' + (d.iso_week || state.isoWeek)) : String(__('viewBlueprint'))) + '</h2>';
		html += '<div class="vtc-tppl-schedule-view" role="tablist">';
		html += '<button type="button" class="button vtc-tppl-view-btn' + (!weekScope ? ' is-active' : '') + '" data-schedule-view="blueprint">' + esc(__('viewBlueprint')) + '</button>';
		html += '<button type="button" class="button vtc-tppl-view-btn' + (weekScope ? ' is-active' : '') + '" data-schedule-view="week">' + esc(__('viewWeek')) + '</button>';
		html += '</div>';
		html += '<div class="vtc-tppl-mode" role="tablist">';
		html += '<button type="button" class="button vtc-tppl-mode-btn' + (!inhuur ? ' is-active' : '') + '" data-mode="teams">' + esc(__('modeTeams')) + '</button>';
		html += '<button type="button" class="button vtc-tppl-mode-btn' + (inhuur ? ' is-active' : '') + '" data-mode="inhuur"' + (weekScope ? ' disabled' : '') + ' title="' + esc(__('modeInhuur')) + '">' + esc(__('modeInhuur')) + '</button>';
		html += '</div>';
		html += '<div class="vtc-tppl-actions">';
		html += '<button type="button" class="button button-primary" id="vtc-tppl-save" disabled>' + esc(__('saveDraft')) + '</button>';
		html += '<button type="button" class="button" id="vtc-tppl-publish">' + esc(__('publish')) + '</button>';
		html += '<button type="button" class="button" id="vtc-tppl-reload">' + esc(__('reload')) + '</button>';
		html += '<span id="vtc-tppl-toast" class="vtc-tppl-toast" role="status"></span>';
		html += '</div>';
		html += '</div>';
		if (weekScope) {
			html += '<div class="vtc-tppl-toolbar-row vtc-tppl-week-bar">';
			html += '<label class="vtc-tppl-week-field"><span>' + esc(__('weekIsoLabel')) + '</span> ';
			html += '<input type="week" id="vtc-tppl-iso-week-input" value="' + esc(iwForInput) + '" />';
			html += '</label>';
			html += '<button type="button" class="button" id="vtc-tppl-week-prev" aria-label="' + esc(__('weekNavPrev')) + '">‹</button>';
			html += '<button type="button" class="button" id="vtc-tppl-week-next" aria-label="' + esc(__('weekNavNext')) + '">›</button>';
			if (d.exception_weeks && d.exception_weeks.length) {
				html += '<label class="vtc-tppl-week-field"><span>' + esc(__('existingExceptions')) + '</span> ';
				html += '<select id="vtc-tppl-ew-jump">';
				html += '<option value="">' + esc('—') + '</option>';
				d.exception_weeks.forEach(function (ew) {
					html += '<option value="' + esc(ew.iso_week) + '"' + (ew.iso_week === d.iso_week ? ' selected' : '') + '>' + esc(ew.iso_week) + '</option>';
				});
				html += '</select></label>';
			}
			if (!d.has_exception) {
				html += '<button type="button" class="button button-primary" id="vtc-tppl-create-exception">' + esc(__('createExceptionWeek')) + '</button>';
			} else {
				html += '<button type="button" class="button" id="vtc-tppl-delete-exception">' + esc(__('deleteExceptionWeek')) + '</button>';
			}
			html += '</div>';
		}
		var curBp = effectiveBlueprintIdForApi();
		var bpOptions = (state.blueprintsList && state.blueprintsList.length)
			? state.blueprintsList
			: (d.blueprint_id ? [{ id: d.blueprint_id, name: d.blueprint_name || ('#' + d.blueprint_id), kind: d.blueprint_kind != null ? d.blueprint_kind : 0 }] : []);
		if (!weekScope && bpOptions.length) {
			html += '<div class="vtc-tppl-toolbar-row vtc-tppl-blueprint-bar">';
			html += '<label class="vtc-tppl-blueprint-field"><span>' + esc(__('blueprintLabel')) + '</span> ';
			html += '<select id="vtc-tppl-blueprint-select">';
			bpOptions.forEach(function (b) {
				var sel = Number(b.id) === Number(curBp) ? ' selected' : '';
				var lab = b.name + (Number(b.kind) === 1 ? ' (afw.)' : '');
				html += '<option value="' + esc(String(b.id)) + '"' + sel + '>' + esc(lab) + '</option>';
			});
			html += '</select></label></div>';
		}
		if (!weekScope && d.versions && d.versions.length) {
			var activeVid = d.editing_version_id || d.published_version_id;
			html += '<div class="vtc-tppl-toolbar-row vtc-tppl-version-bar">';
			html += '<label class="vtc-tppl-version-field"><span>' + esc(__('versionLabel')) + '</span> ';
			html += '<select id="vtc-tppl-version-select">';
			d.versions.forEach(function (v) {
				var sel = Number(v.id) === Number(activeVid) ? ' selected' : '';
				var lab = (v.label || ('#' + v.id)) + (v.is_published ? ' ' + __('versionLive') : '');
				html += '<option value="' + esc(String(v.id)) + '"' + sel + '>' + esc(lab) + '</option>';
			});
			html += '</select></label> ';
			html += '<button type="button" class="button" id="vtc-tppl-new-version">' + esc(__('newConceptVersion')) + '</button>';
			html += '</div>';
		}
		if (!weekScope && d.draft_differs) {
			html += '<p class="vtc-tppl-draft-banner">' + esc(__('draftHint')) + '</p>';
		}
		if (weekScope && d.uses_deviation_blueprint && !d.has_exception && d.draft_differs) {
			html += '<p class="vtc-tppl-draft-banner">' + esc(__('draftHint')) + '</p>';
		}
		html += '</div>';
		html += '<p id="vtc-tppl-dirty-banner" class="vtc-tppl-dirty-banner" hidden>' + esc(__('dirtyBanner')) + '</p>';
		if (weekScope) {
			html += '<p class="vtc-tppl-help">' + esc(__('weekHelp')) + '</p>';
			if (d.uses_deviation_blueprint && !d.has_exception) {
				html += '<p class="vtc-tppl-week-hint vtc-tppl-week-hint--deviation">' + esc(__('deviationActiveWeek')) + '</p>';
			}
			html += '<p class="vtc-tppl-week-hint">' + esc(d.has_exception ? __('weekHasExceptionHint') : __('weekNoExceptionHint')) + '</p>';
		}
		if (inhuur) {
			html += '<p class="vtc-tppl-inhuur-banner">' + esc(__('inhuurBanner')) + '</p>';
			html += '<p class="vtc-tppl-help">' + esc(__('inhuurHelp')) + '</p>';
		} else if (!weekScope) {
			html += '<p class="vtc-tppl-help">' + esc(__('helpDrag')) + '</p>';
		}

		html += '<div class="vtc-tppl-layout">';
		html += '<aside class="vtc-tppl-sidebar">';
		if (inhuur) {
			html += '<h3>' + esc(__('modeInhuur')) + '</h3>';
			html += '<p class="vtc-tppl-sidebar-hint">' + esc(__('inhuurHelp')) + '</p>';
		} else {
			html += '<h3>Teams</h3>';
			if (hasTeams) {
				if (!weekReadonly) {
					html += '<p class="vtc-tppl-sidebar-hint vtc-tppl-sidebar-hint--overview">' + esc(__('teamOverviewLaneHelp')) + '</p>';
				}
				d.teams.forEach(function (t) {
					html += '<button type="button" class="vtc-tppl-team-chip" draggable="true" data-team-id="' + t.id + '" style="border-left:4px solid ' + teamColor(t.id) + '">' + esc(t.display_name) + '</button>';
				});
			} else {
				html += '<p class="vtc-tppl-sidebar-hint">' + esc(__('noTeamsSidebar')) + '</p>';
			}
		}
		html += '</aside>';

		html += '<div class="vtc-tppl-main"><div class="vtc-tppl-days">';
		for (var dow = 0; dow <= 6; dow++) {
			html += '<div class="vtc-tppl-day" data-dow="' + dow + '">';
			html += '<div class="vtc-tppl-day-head">' + esc((d.day_names && d.day_names[dow]) || ('Dag ' + dow)) + '</div>';
			html += '<div class="vtc-tppl-day-grid"><div class="vtc-tppl-day-grid-inner">';
			html += '<div class="vtc-tppl-corner" aria-hidden="true"></div>';
			html += '<div class="vtc-tppl-time-ruler" aria-hidden="true">';
			for (var hr = HOUR_START; hr < HOUR_END; hr++) {
				var leftPct = ((hr * 60 - HOUR_START * 60) / TOTAL_MIN) * 100;
				html += '<span class="vtc-tppl-time-tick" style="left:' + leftPct + '%">' + hr + ':00</span>';
			}
			html += '</div>';
			d.venues.forEach(function (v) {
				html += '<div class="vtc-tppl-lane-label">' + esc(v.label) + '</div>';
				html += '<div class="vtc-tppl-lane-body" data-venue-id="' + v.id + '" data-dow="' + dow + '">';
				(d.unavailability || []).forEach(function (u) {
					if (u.day_of_week !== dow || u.venue_id !== v.id) return;
					var usel = u.id === state.selectedUnavailId ? ' is-selected' : '';
					var unavailEditable = inhuur && !weekScope;
					var unRo = !unavailEditable ? ' vtc-tppl-unavail--readonly' : '';
					html += '<div class="vtc-tppl-unavail' + usel + unRo + '" data-unavail-id="' + u.id + '" style="left:' + slotStyleLeftPct(u.start_time) + '%;width:' + slotStyleWidthPct(u.start_time, u.end_time) + '%">';
					html += '<div class="vtc-tppl-unavail-handle vtc-tppl-unavail-handle--left" data-unavail-id="' + u.id + '"></div>';
					html += '<span class="vtc-tppl-unavail-label">' + esc(u.start_time + '–' + u.end_time) + '</span>';
					if (unavailEditable) {
						html += '<button type="button" class="vtc-tppl-unavail-x" data-unavail-id="' + u.id + '">&times;</button>';
					}
					html += '<div class="vtc-tppl-unavail-handle vtc-tppl-unavail-handle--right" data-unavail-id="' + u.id + '"></div>';
					html += '</div>';
				});
				if (hasTeams || !inhuur) {
					var slotList = weekReadonly ? (d.baseline_slots || []) : (d.slots || []);
					slotList.forEach(function (s) {
						if (s.day_of_week !== dow || s.venue_id !== v.id) return;
						var sel = !weekReadonly && state.selectedSlotIds.has(s.id) ? ' is-selected' : '';
						var baseCls = weekReadonly ? ' vtc-tppl-block--baseline' : '';
						html += '<div class="vtc-tppl-block' + baseCls + sel + '" data-slot-id="' + s.id + '" style="left:' + slotStyleLeftPct(s.start_time) + '%;width:' + slotStyleWidthPct(s.start_time, s.end_time) + '%;background:' + teamColor(s.team_id) + '">';
						if (!weekReadonly) {
							html += '<div class="vtc-tppl-block-resize vtc-tppl-block-resize--left" data-slot-id="' + s.id + '"></div>';
							html += '<button type="button" class="vtc-tppl-block-x" data-slot-id="' + s.id + '" title="Verwijderen">&times;</button>';
						}
						html += '<span class="vtc-tppl-block-title">' + esc(s.team_name) + '</span>';
						html += '<span class="vtc-tppl-block-time">' + esc(s.start_time + '–' + s.end_time) + '</span>';
						if (!weekReadonly) {
							html += '<div class="vtc-tppl-block-resize vtc-tppl-block-resize--right" data-slot-id="' + s.id + '"></div>';
						}
						html += '</div>';
					});
				}
				html += '</div>';
			});
			html += '</div></div></div>';
		}
		html += '</div></div></div></div>';
		html += buildTeamPickerPopHtml(weekReadonly, inhuur, hasTeams);

		root.innerHTML = html;
		bind();
		syncDirtyUi();
	}

	function esc(s) {
		var d = document.createElement('div');
		d.textContent = s;
		return d.innerHTML;
	}

	function bind() {
		var root = document.getElementById('vtc-tp-planner-root');
		if (!root) return;

		var reloadBtn = document.getElementById('vtc-tppl-reload');
		if (reloadBtn) {
			reloadBtn.addEventListener('click', function () {
				loadPlanner({ force: true, initial: true });
			});
		}

		var bpSel = document.getElementById('vtc-tppl-blueprint-select');
		if (bpSel) {
			bpSel.addEventListener('change', function () {
				var nid = parseInt(bpSel.value, 10);
				var cur = effectiveBlueprintIdForApi();
				if (!nid || Number(nid) === Number(cur)) return;
				if (!confirmDiscardIfDirty()) {
					bpSel.value = String(cur);
					return;
				}
				state.blueprintId = nid;
				persistBlueprintId(nid);
				resetPending();
				state.localDirty = false;
				loadPlanner({ force: true, initial: true });
			});
		}

		var verSel = document.getElementById('vtc-tppl-version-select');
		if (verSel && state.data) {
			verSel.addEventListener('change', function () {
				var nid = parseInt(verSel.value, 10);
				var cur = state.data.editing_version_id || state.data.published_version_id;
				if (!nid || Number(nid) === Number(cur)) return;
				if (!confirmDiscardIfDirty()) {
					verSel.value = String(cur);
					return;
				}
				api('admin/blueprint-editing-version', {
					method: 'POST',
					body: JSON.stringify({ blueprint_id: effectiveBlueprintIdForApi(), version_id: nid })
				})
					.then(function () {
						resetPending();
						state.localDirty = false;
						return loadPlanner({ force: true, initial: true });
					})
					.catch(function (err) {
						verSel.value = String(cur);
						showToast((err && err.message) || __('versionSwitchErr'), false);
					});
			});
		}

		var newVerBtn = document.getElementById('vtc-tppl-new-version');
		if (newVerBtn) {
			newVerBtn.addEventListener('click', function () {
				var lab = window.prompt(__('newConceptVersionPrompt'), '');
				if (lab === null) return;
				api('admin/blueprint-versions', {
					method: 'POST',
					body: JSON.stringify({ blueprint_id: effectiveBlueprintIdForApi(), label: lab })
				})
					.then(function () {
						showToast(__('versionCreated'), true);
						resetPending();
						state.localDirty = false;
						return loadPlanner({ force: true, initial: true });
					})
					.catch(function (err) {
						showToast((err && err.message) || __('saveErr'), false);
					});
			});
		}

		document.querySelectorAll('.vtc-tppl-view-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				setScheduleView(btn.getAttribute('data-schedule-view'));
			});
		});

		var saveBtn = document.getElementById('vtc-tppl-save');
		if (saveBtn) {
			saveBtn.addEventListener('click', function () {
				saveAll();
			});
		}
		var pubBtn = document.getElementById('vtc-tppl-publish');
		if (pubBtn) {
			pubBtn.addEventListener('click', onPublish);
		}

		if (!state.data) {
			syncDirtyUi();
			return;
		}

		document.querySelectorAll('.vtc-tppl-mode-btn').forEach(function (btn) {
			btn.addEventListener('click', function () {
				setWorkMode(btn.getAttribute('data-mode'));
			});
		});

		var weekScope = isWeekScope();
		var weekReadonly = weekScope && !state.data.has_exception && !state.data.uses_deviation_blueprint;
		var inhuur = state.workMode === 'inhuur' && !weekScope;

		var isoWeekInput = document.getElementById('vtc-tppl-iso-week-input');
		if (isoWeekInput) {
			isoWeekInput.addEventListener('change', function () {
				setIsoWeekAndLoad(isoWeekInput.value);
			});
		}
		var weekPrev = document.getElementById('vtc-tppl-week-prev');
		if (weekPrev) {
			weekPrev.addEventListener('click', function () {
				var cur = normalizeIsoWeekStr(state.isoWeek) || state.isoWeek;
				setIsoWeekAndLoad(addOneWeekIso(cur, -1));
			});
		}
		var weekNext = document.getElementById('vtc-tppl-week-next');
		if (weekNext) {
			weekNext.addEventListener('click', function () {
				var cur = normalizeIsoWeekStr(state.isoWeek) || state.isoWeek;
				setIsoWeekAndLoad(addOneWeekIso(cur, 1));
			});
		}
		var ewJump = document.getElementById('vtc-tppl-ew-jump');
		if (ewJump) {
			ewJump.addEventListener('change', function () {
				if (!ewJump.value) return;
				setIsoWeekAndLoad(ewJump.value);
			});
		}
		var createEx = document.getElementById('vtc-tppl-create-exception');
		if (createEx) {
			createEx.addEventListener('click', function () {
				var iw = normalizeIsoWeekStr(state.isoWeek) || state.isoWeek;
				api('admin/exception-weeks', {
					method: 'POST',
					body: JSON.stringify({ iso_week: iw })
				})
					.then(function () {
						showToast(__('exceptionCreated'), true);
						return loadPlanner({ force: true, initial: true });
					})
					.catch(function (err) {
						showToast((err && err.message) || __('saveErr'), false);
					});
			});
		}
		var delEx = document.getElementById('vtc-tppl-delete-exception');
		if (delEx) {
			delEx.addEventListener('click', function () {
				var ewid = state.data.exception_week_id;
				if (!ewid) return;
				if (!window.confirm(__('deleteExceptionConfirm'))) return;
				api('admin/exception-weeks/' + ewid, { method: 'DELETE' })
					.then(function () {
						showToast(__('exceptionDeleted'), true);
						return loadPlanner({ force: true, initial: true });
					})
					.catch(function (err) {
						showToast((err && err.message) || __('saveErr'), false);
					});
			});
		}

		if (!inhuur && state.data.teams && state.data.teams.length) {
			root.querySelectorAll('.vtc-tppl-team-chip').forEach(function (chip) {
				chip.addEventListener('dragstart', function (e) {
					e.dataTransfer.setData('text/plain', String(chip.getAttribute('data-team-id')));
					e.dataTransfer.effectAllowed = 'copy';
				});
			});
		}

		root.querySelectorAll('.vtc-tppl-lane-body').forEach(function (body) {
			if (inhuur) {
				body.addEventListener('pointerdown', onInhuurPaintDown);
			} else if (!weekReadonly) {
				body.addEventListener('dragover', function (e) {
					e.preventDefault();
					e.dataTransfer.dropEffect = 'copy';
					body.classList.add('is-drop-target');
				});
				body.addEventListener('dragleave', function () {
					body.classList.remove('is-drop-target');
				});
				body.addEventListener('drop', onDropTeam);
				if (state.data.teams && state.data.teams.length) {
					body.addEventListener('click', onLaneClickOpenTeamPicker);
				}
			}
		});

		var picker = document.getElementById('vtc-tppl-team-picker');
		if (picker) {
			picker.querySelectorAll('.vtc-tppl-team-picker-item').forEach(function (btn) {
				btn.addEventListener('click', function (ev) {
					ev.preventDefault();
					ev.stopPropagation();
					var tid = parseInt(btn.getAttribute('data-team-id'), 10);
					if (tid) placeTeamAtPickerSlot(tid);
				});
			});
			var pAll = document.getElementById('vtc-tppl-team-picker-show-all');
			if (pAll) {
				pAll.addEventListener('change', function () {
					state.teamPickerShowAll = !!pAll.checked;
					render();
				});
			}
		}
		if (!state.teamPickerDocBound) {
			state.teamPickerDocBound = true;
			document.addEventListener('mousedown', onDocumentMouseDownClosePicker, true);
		}

		if (!inhuur) {
			root.querySelectorAll('.vtc-tppl-block:not(.vtc-tppl-block--baseline)').forEach(function (block) {
				block.addEventListener('pointerdown', onBlockPointerDown);
				block.addEventListener('dblclick', onBlockDblClick);
			});
			root.querySelectorAll('.vtc-tppl-block-x').forEach(function (btn) {
				btn.addEventListener('click', function (e) {
					e.stopPropagation();
					e.preventDefault();
					deleteSlot(parseInt(btn.getAttribute('data-slot-id'), 10));
				});
			});
			root.querySelectorAll('.vtc-tppl-block-resize').forEach(function (rz) {
				rz.addEventListener('pointerdown', function (e) {
					e.stopPropagation();
					e.preventDefault();
					var edge = rz.classList.contains('vtc-tppl-block-resize--left') ? 'left' : 'right';
					startResize(e, parseInt(rz.getAttribute('data-slot-id'), 10), edge);
				});
			});
		}

		if (!weekScope && inhuur) {
			root.querySelectorAll('.vtc-tppl-unavail').forEach(function (el) {
				el.addEventListener('pointerdown', onUnavailPointerDown);
				el.addEventListener('dblclick', onUnavailDblClick);
			});
			root.querySelectorAll('.vtc-tppl-unavail-x').forEach(function (btn) {
				btn.addEventListener('click', function (e) {
					e.stopPropagation();
					e.preventDefault();
					deleteUnavail(parseInt(btn.getAttribute('data-unavail-id'), 10));
				});
			});
			root.querySelectorAll('.vtc-tppl-unavail-handle--left').forEach(function (h) {
				h.addEventListener('pointerdown', function (e) {
					e.stopPropagation();
					e.preventDefault();
					startUnavailResize(e, parseInt(h.getAttribute('data-unavail-id'), 10), 'left');
				});
			});
			root.querySelectorAll('.vtc-tppl-unavail-handle--right').forEach(function (h) {
				h.addEventListener('pointerdown', function (e) {
					e.stopPropagation();
					e.preventDefault();
					startUnavailResize(e, parseInt(h.getAttribute('data-unavail-id'), 10), 'right');
				});
			});
		}

		if (!state.keydownBound) {
			state.keydownBound = true;
			document.addEventListener('keydown', onKeyDown);
		}
		if (!state.beforeunloadBound) {
			state.beforeunloadBound = true;
			window.addEventListener('beforeunload', onBeforeUnload);
		}
	}

	function onKeyDown(e) {
		var t = e.target;
		if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) return;
		if (e.key === 'Escape' && state.teamPickerOpen) {
			state.teamPickerOpen = false;
			state.teamPickerShowAll = false;
			removeTeamPickerDom();
			return;
		}
		if ((e.key === 'Delete' || e.key === 'Backspace') && state.workMode === 'teams' && state.selectedSlotIds.size > 0) {
			e.preventDefault();
			var toDel = Array.from(state.selectedSlotIds);
			toDel.forEach(function (sid) {
				deleteSlot(sid, { skipRender: true });
			});
			state.selectedSlotIds.clear();
			state.selectedId = null;
			render();
			return;
		}
		if ((e.key === 'Delete' || e.key === 'Backspace') && state.selectedUnavailId && state.workMode === 'inhuur' && !isWeekScope()) {
			e.preventDefault();
			deleteUnavail(state.selectedUnavailId);
		}
	}

	function onInhuurPaintDown(e) {
		if (e.button !== 0) return;
		if (e.target.closest('.vtc-tppl-unavail')) return;
		if (e.target.closest('.vtc-tppl-block')) return;
		var body = e.currentTarget;
		var dayEl = body.closest('.vtc-tppl-day');
		if (!dayEl) return;
		e.preventDefault();
		e.stopPropagation();
		try {
			body.setPointerCapture(e.pointerId);
		} catch (err) { /* ignore */ }

		var refBody = body;
		var x0 = e.clientX;
		var y0 = e.clientY;
		var xMin = x0;
		var xMax = x0;
		var yMin = y0;
		var yMax = y0;
		var previews = new Map();

		function clearPreviews() {
			previews.forEach(function (pr, b) {
				if (pr && pr.parentNode) pr.remove();
				b.classList.remove('vtc-tppl-lane-painting');
			});
			previews.clear();
		}

		function updatePreview(clientX, clientY) {
			xMin = Math.min(xMin, clientX, x0);
			xMax = Math.max(xMax, clientX, x0);
			yMin = Math.min(yMin, clientY, y0);
			yMax = Math.max(yMax, clientY, y0);

			var times = xRangeToStartEndOnBody(refBody, xMin, xMax);
			var bodies = bodiesIntersectingRect(dayEl, xMin, yMin, xMax, yMax);
			if (!bodies.length) bodies = [refBody];

			var seen = new Set();
			bodies.forEach(function (b) {
				seen.add(b);
				var pr = previews.get(b);
				if (!pr) {
					pr = document.createElement('div');
					pr.className = 'vtc-tppl-unavail-preview';
					b.appendChild(pr);
					previews.set(b, pr);
					b.classList.add('vtc-tppl-lane-painting');
				}
				pr.style.left = slotStyleLeftPct(times.start) + '%';
				pr.style.width = slotStyleWidthPct(times.start, times.end) + '%';
			});
			previews.forEach(function (pr, b) {
				if (!seen.has(b)) {
					if (pr && pr.parentNode) pr.remove();
					b.classList.remove('vtc-tppl-lane-painting');
					previews.delete(b);
				}
			});
		}

		updatePreview(x0, y0);

		function onMove(ev) {
			if (ev.pointerId !== e.pointerId) return;
			updatePreview(ev.clientX, ev.clientY);
		}

		function onUp(ev) {
			if (ev.pointerId !== e.pointerId) return;
			body.removeEventListener('pointermove', onMove);
			body.removeEventListener('pointerup', onUp);
			body.removeEventListener('pointercancel', onUp);
			try {
				body.releasePointerCapture(e.pointerId);
			} catch (err2) { /* ignore */ }
			clearPreviews();

			xMin = Math.min(xMin, ev.clientX, x0);
			xMax = Math.max(xMax, ev.clientX, x0);
			yMin = Math.min(yMin, ev.clientY, y0);
			yMax = Math.max(yMax, ev.clientY, y0);

			var dx = Math.abs(xMax - xMin);
			var dy = Math.abs(yMax - yMin);
			var times;
			if (dx < 10 && dy < 10) {
				var r = refBody.getBoundingClientRect();
				var fx = (x0 - r.left) / r.width;
				fx = Math.max(0, Math.min(1, fx));
				var m0 = HOUR_START * 60 + fx * TOTAL_MIN;
				m0 = Math.round(m0 / SNAP) * SNAP;
				times = { start: minToTime(m0), end: minToTime(m0 + SNAP) };
			} else {
				times = xRangeToStartEndOnBody(refBody, xMin, xMax);
			}

			var targets = bodiesIntersectingRect(dayEl, xMin, yMin, xMax, yMax);
			if (!targets.length) targets = [refBody];

			var dow = parseInt(dayEl.getAttribute('data-dow'), 10);
			targets.forEach(function (b) {
				var vid = parseInt(b.getAttribute('data-venue-id'), 10);
				var u = {
					id: allocUnavailTempId(),
					venue_id: vid,
					day_of_week: dow,
					start_time: times.start,
					end_time: times.end
				};
				mergeUnavail(u);
			});
			markDirty();
			render();
		}

		body.addEventListener('pointermove', onMove);
		body.addEventListener('pointerup', onUp);
		body.addEventListener('pointercancel', onUp);
	}

	function onUnavailDblClick(e) {
		var id = parseInt(e.currentTarget.getAttribute('data-unavail-id'), 10);
		if (id) deleteUnavail(id);
	}

	function onUnavailPointerDown(e) {
		if (e.target.closest('.vtc-tppl-unavail-handle') || e.target.closest('.vtc-tppl-unavail-x')) return;
		var el = e.currentTarget;
		var id = parseInt(el.getAttribute('data-unavail-id'), 10);
		state.selectedUnavailId = id;
		state.selectedId = null;
		document.querySelectorAll('.vtc-tppl-unavail').forEach(function (u) {
			u.classList.toggle('is-selected', parseInt(u.getAttribute('data-unavail-id'), 10) === id);
		});
		document.querySelectorAll('.vtc-tppl-block').forEach(function (b) {
			b.classList.remove('is-selected');
		});
		if (e.button !== 0) return;
		var u = findUnavail(id);
		if (!u) return;
		var body = el.closest('.vtc-tppl-lane-body');
		if (!body) return;
		drag = {
			kind: 'unavail',
			id: id,
			body: body,
			startX: e.clientX,
			pxPerMin: pxPerMinFromBody(body),
			origStart: timeToMin(u.start_time),
			origEnd: timeToMin(u.end_time),
			u: u
		};
		el.classList.add('is-dragging');
		el.setPointerCapture(e.pointerId);
		el.addEventListener('pointermove', onUnavailDragMove);
		el.addEventListener('pointerup', onUnavailDragUp);
		el.addEventListener('pointercancel', onUnavailDragUp);
	}

	function onUnavailDragMove(e) {
		if (!drag || drag.kind !== 'unavail') return;
		var dx = e.clientX - drag.startX;
		var dMin = Math.round(dx / drag.pxPerMin / SNAP) * SNAP;
		var len = drag.origEnd - drag.origStart;
		var ns = drag.origStart + dMin;
		var ne = drag.origEnd + dMin;
		ns = Math.max(HOUR_START * 60, Math.min(HOUR_END * 60 - len, ns));
		ne = ns + len;
		drag.u.start_time = minToTime(ns);
		drag.u.end_time = minToTime(ne);
		var el = document.querySelector('.vtc-tppl-unavail[data-unavail-id="' + drag.id + '"]');
		if (el) {
			el.style.left = slotStyleLeftPct(drag.u.start_time) + '%';
			el.style.width = slotStyleWidthPct(drag.u.start_time, drag.u.end_time) + '%';
			var lb = el.querySelector('.vtc-tppl-unavail-label');
			if (lb) lb.textContent = drag.u.start_time + '–' + drag.u.end_time;
		}
		var under = document.elementFromPoint(e.clientX, e.clientY);
		var nb = under && under.closest ? under.closest('.vtc-tppl-lane-body') : null;
		document.querySelectorAll('.vtc-tppl-lane-body').forEach(function (b) {
			b.classList.toggle('is-drop-target', nb === b && b !== drag.body);
		});
	}

	function onUnavailDragUp(e) {
		if (!drag || drag.kind !== 'unavail') return;
		var el = document.querySelector('.vtc-tppl-unavail[data-unavail-id="' + drag.id + '"]');
		if (el) {
			el.classList.remove('is-dragging');
			try {
				el.releasePointerCapture(e.pointerId);
			} catch (err) { /* ignore */ }
			el.removeEventListener('pointermove', onUnavailDragMove);
			el.removeEventListener('pointerup', onUnavailDragUp);
			el.removeEventListener('pointercancel', onUnavailDragUp);
		}
		document.querySelectorAll('.vtc-tppl-lane-body').forEach(function (b) {
			b.classList.remove('is-drop-target');
		});
		var patch = {
			start_time: drag.u.start_time,
			end_time: drag.u.end_time
		};
		var under = document.elementFromPoint(e.clientX, e.clientY);
		var newBody = under && under.closest ? under.closest('.vtc-tppl-lane-body') : null;
		if (newBody && newBody !== drag.body) {
			patch.venue_id = parseInt(newBody.getAttribute('data-venue-id'), 10);
			patch.day_of_week = parseInt(newBody.getAttribute('data-dow'), 10);
		}
		var id = drag.id;
		drag = null;
		recordUnavailChange(id, patch);
		render();
	}

	function startUnavailResize(e, id, edge) {
		var u = findUnavail(id);
		if (!u) return;
		var block = document.querySelector('.vtc-tppl-unavail[data-unavail-id="' + id + '"]');
		if (!block) return;
		var laneBody = block.closest('.vtc-tppl-lane-body');
		drag = {
			kind: 'unavailResize',
			edge: edge,
			id: id,
			startX: e.clientX,
			pxPerMin: laneBody ? pxPerMinFromBody(laneBody) : 1,
			origStart: timeToMin(u.start_time),
			origEnd: timeToMin(u.end_time),
			u: u
		};
		block.classList.add('is-dragging');
		block.setPointerCapture(e.pointerId);
		block.addEventListener('pointermove', onUnavailResizeMove);
		block.addEventListener('pointerup', onUnavailResizeUp);
		block.addEventListener('pointercancel', onUnavailResizeUp);
	}

	function onUnavailResizeMove(e) {
		if (!drag || drag.kind !== 'unavailResize') return;
		var dx = e.clientX - drag.startX;
		var dMin = Math.round(dx / drag.pxPerMin / SNAP) * SNAP;
		var u = drag.u;
		if (drag.edge === 'right') {
			var ne = drag.origEnd + dMin;
			ne = Math.max(drag.origStart + SNAP, Math.min(HOUR_END * 60, ne));
			ne = Math.round(ne / SNAP) * SNAP;
			u.end_time = minToTime(ne);
		} else {
			var ns = drag.origStart + dMin;
			ns = Math.max(HOUR_START * 60, Math.min(drag.origEnd - SNAP, ns));
			ns = Math.round(ns / SNAP) * SNAP;
			u.start_time = minToTime(ns);
		}
		var el = document.querySelector('.vtc-tppl-unavail[data-unavail-id="' + drag.id + '"]');
		if (el) {
			el.style.left = slotStyleLeftPct(u.start_time) + '%';
			el.style.width = slotStyleWidthPct(u.start_time, u.end_time) + '%';
			var lb = el.querySelector('.vtc-tppl-unavail-label');
			if (lb) lb.textContent = u.start_time + '–' + u.end_time;
		}
	}

	function onUnavailResizeUp(e) {
		if (!drag || drag.kind !== 'unavailResize') return;
		var block = document.querySelector('.vtc-tppl-unavail[data-unavail-id="' + drag.id + '"]');
		if (block) {
			block.classList.remove('is-dragging');
			try {
				block.releasePointerCapture(e.pointerId);
			} catch (err) { /* ignore */ }
			block.removeEventListener('pointermove', onUnavailResizeMove);
			block.removeEventListener('pointerup', onUnavailResizeUp);
			block.removeEventListener('pointercancel', onUnavailResizeUp);
		}
		var id = drag.id;
		var u = drag.u;
		drag = null;
		recordUnavailChange(id, { start_time: u.start_time, end_time: u.end_time });
		render();
	}

	function deleteUnavail(id) {
		if (id < 0) {
			removeUnavail(id);
			if (state.selectedUnavailId === id) state.selectedUnavailId = null;
			markDirty();
			render();
			return;
		}
		pending.unavailDeletes.add(id);
		delete pending.unavailPatches[id];
		removeUnavail(id);
		if (state.selectedUnavailId === id) state.selectedUnavailId = null;
		markDirty();
		render();
	}

	function onBlockDblClick(e) {
		var id = parseInt(e.currentTarget.getAttribute('data-slot-id'), 10);
		if (id) deleteSlot(id);
	}

	function onBlockPointerDown(e) {
		if (e.target.closest('.vtc-tppl-block-x') || e.target.closest('.vtc-tppl-block-resize')) return;
		var block = e.currentTarget;
		var id = parseInt(block.getAttribute('data-slot-id'), 10);
		var multi = e.ctrlKey || e.metaKey;
		if (multi) {
			if (state.selectedSlotIds.has(id)) {
				state.selectedSlotIds.delete(id);
			} else {
				state.selectedSlotIds.add(id);
			}
			state.selectedId = state.selectedSlotIds.size ? id : null;
			state.selectedUnavailId = null;
			document.querySelectorAll('.vtc-tppl-unavail').forEach(function (u) {
				u.classList.remove('is-selected');
			});
			syncSlotSelectionDom();
			return;
		}
		if (!state.selectedSlotIds.has(id) || state.selectedSlotIds.size <= 1) {
			state.selectedSlotIds.clear();
			state.selectedSlotIds.add(id);
		}
		state.selectedId = id;
		state.selectedUnavailId = null;
		document.querySelectorAll('.vtc-tppl-unavail').forEach(function (u) {
			u.classList.remove('is-selected');
		});
		syncSlotSelectionDom();
		if (e.button !== 0) return;
		var slot = findSlot(id);
		if (!slot) return;
		var body = block.closest('.vtc-tppl-lane-body');
		if (!body) return;
		var isGroup = state.selectedSlotIds.size > 1;
		var items = [];
		if (isGroup) {
			state.selectedSlotIds.forEach(function (sid) {
				var sl = findSlot(sid);
				if (!sl) return;
				items.push({
					id: sid,
					slot: sl,
					origStart: timeToMin(sl.start_time),
					origEnd: timeToMin(sl.end_time)
				});
			});
			if (items.length < 2) {
				isGroup = false;
			}
		}
		if (isGroup) {
			drag = {
				type: 'moveGroup',
				primaryId: id,
				body: body,
				startX: e.clientX,
				pxPerMin: pxPerMinFromBody(body),
				items: items
			};
			document.querySelectorAll('.vtc-tppl-block.is-selected').forEach(function (b) {
				b.classList.add('is-dragging');
			});
			block.setPointerCapture(e.pointerId);
			block.addEventListener('pointermove', onBlockPointerMove);
			block.addEventListener('pointerup', onBlockPointerUp);
			block.addEventListener('pointercancel', onBlockPointerUp);
		} else {
			drag = {
				type: 'move',
				id: id,
				body: body,
				blockEl: block,
				pointerId: e.pointerId,
				startX: e.clientX,
				pxPerMin: pxPerMinFromBody(body),
				origStart: timeToMin(slot.start_time),
				origEnd: timeToMin(slot.end_time),
				slot: slot
			};
			block.classList.add('is-dragging');
			/* Geen setPointerCapture op het blok: reparent (appendChild) laat capture in sommige browsers vallen.
			 * Document-listeners (capture) blijven tot loslaten werken. */
			document.addEventListener('pointermove', onBlockPointerMove, true);
			document.addEventListener('pointerup', onBlockPointerUp, true);
			document.addEventListener('pointercancel', onBlockPointerUp, true);
		}
	}

	function startResize(e, id, edge) {
		var slot = findSlot(id);
		if (!slot) return;
		var block = document.querySelector('.vtc-tppl-block[data-slot-id="' + id + '"]');
		if (!block) return;
		var body = block.closest('.vtc-tppl-lane-body');
		if (!body) return;
		state.selectedSlotIds.clear();
		state.selectedSlotIds.add(id);
		state.selectedId = id;
		syncSlotSelectionDom();
		drag = {
			type: 'resize',
			edge: edge === 'left' ? 'left' : 'right',
			id: id,
			body: body,
			startX: e.clientX,
			pxPerMin: pxPerMinFromBody(body),
			origStart: timeToMin(slot.start_time),
			origEnd: timeToMin(slot.end_time),
			slot: slot
		};
		block.classList.add('is-dragging');
		block.setPointerCapture(e.pointerId);
		block.addEventListener('pointermove', onBlockPointerMove);
		block.addEventListener('pointerup', onBlockPointerUp);
		block.addEventListener('pointercancel', onBlockPointerUp);
	}

	function onBlockPointerMove(e) {
		if (!drag || drag.kind) return;
		if (drag.type === 'move' && e.pointerId !== drag.pointerId) {
			return;
		}
		var dx = e.clientX - drag.startX;
		var dMin = Math.round(dx / drag.pxPerMin / SNAP) * SNAP;
		var slot = drag.slot;
		if (drag.type === 'moveGroup') {
			var low = -1e9;
			var high = 1e9;
			drag.items.forEach(function (it) {
				low = Math.max(low, HOUR_START * 60 - it.origStart);
				high = Math.min(high, HOUR_END * 60 - it.origEnd);
			});
			var dClamped = Math.max(low, Math.min(high, dMin));
			drag.items.forEach(function (it) {
				var ns = it.origStart + dClamped;
				var ne = it.origEnd + dClamped;
				it.slot.start_time = minToTime(ns);
				it.slot.end_time = minToTime(ne);
				var elg = document.querySelector('.vtc-tppl-block[data-slot-id="' + it.id + '"]');
				if (elg) {
					elg.style.left = slotStyleLeftPct(it.slot.start_time) + '%';
					elg.style.width = slotStyleWidthPct(it.slot.start_time, it.slot.end_time) + '%';
					var tmg = elg.querySelector('.vtc-tppl-block-time');
					if (tmg) tmg.textContent = it.slot.start_time + '–' + it.slot.end_time;
				}
			});
			document.querySelectorAll('.vtc-tppl-lane-body').forEach(function (b) {
				b.classList.remove('is-drop-target');
			});
			return;
		}
		if (drag.type === 'move') {
			var ns = drag.origStart + dMin;
			var ne = drag.origEnd + dMin;
			var len = drag.origEnd - drag.origStart;
			ns = Math.max(HOUR_START * 60, Math.min(HOUR_END * 60 - len, ns));
			ne = ns + len;
			slot.start_time = minToTime(ns);
			slot.end_time = minToTime(ne);
			var el = drag.blockEl;
			if (el) {
				el.style.left = slotStyleLeftPct(slot.start_time) + '%';
				el.style.width = slotStyleWidthPct(slot.start_time, slot.end_time) + '%';
				var tm = el.querySelector('.vtc-tppl-block-time');
				if (tm) tm.textContent = slot.start_time + '–' + slot.end_time;
			}
			var newBody = laneBodyUnderPointExcludingBlock(e.clientX, e.clientY, el);
			if (newBody && el && newBody !== el.parentNode) {
				newBody.appendChild(el);
			}
		} else if (drag.type === 'resize' && drag.edge === 'left') {
			var nsL = drag.origStart + dMin;
			nsL = Math.max(HOUR_START * 60, Math.min(drag.origEnd - SNAP, nsL));
			nsL = Math.round(nsL / SNAP) * SNAP;
			slot.start_time = minToTime(nsL);
			var elL = document.querySelector('.vtc-tppl-block[data-slot-id="' + drag.id + '"]');
			if (elL) {
				elL.style.left = slotStyleLeftPct(slot.start_time) + '%';
				elL.style.width = slotStyleWidthPct(slot.start_time, slot.end_time) + '%';
				var tmL = elL.querySelector('.vtc-tppl-block-time');
				if (tmL) tmL.textContent = slot.start_time + '–' + slot.end_time;
			}
		} else {
			var ne2 = drag.origEnd + dMin;
			ne2 = Math.max(drag.origStart + SNAP, Math.min(HOUR_END * 60, ne2));
			ne2 = Math.round(ne2 / SNAP) * SNAP;
			slot.end_time = minToTime(ne2);
			var el2 = document.querySelector('.vtc-tppl-block[data-slot-id="' + drag.id + '"]');
			if (el2) {
				el2.style.width = slotStyleWidthPct(slot.start_time, slot.end_time) + '%';
				var tm2 = el2.querySelector('.vtc-tppl-block-time');
				if (tm2) tm2.textContent = slot.start_time + '–' + slot.end_time;
			}
		}
	}

	function onBlockPointerUp(e) {
		if (!drag || drag.kind) return;
		if (drag.type === 'move' && e.pointerId !== drag.pointerId) {
			return;
		}
		var capBlock = drag.type === 'move' && drag.blockEl
			? drag.blockEl
			: document.querySelector('.vtc-tppl-block[data-slot-id="' + (drag.type === 'moveGroup' ? drag.primaryId : drag.id) + '"]');
		if (drag.type === 'move') {
			document.removeEventListener('pointermove', onBlockPointerMove, true);
			document.removeEventListener('pointerup', onBlockPointerUp, true);
			document.removeEventListener('pointercancel', onBlockPointerUp, true);
		}
		if (drag.type === 'moveGroup') {
			document.querySelectorAll('.vtc-tppl-block.is-dragging').forEach(function (b) {
				b.classList.remove('is-dragging');
			});
		} else if (capBlock) {
			capBlock.classList.remove('is-dragging');
		}
		if (capBlock && drag.type !== 'move') {
			try {
				capBlock.releasePointerCapture(e.pointerId);
			} catch (err) { /* ignore */ }
			capBlock.removeEventListener('pointermove', onBlockPointerMove);
			capBlock.removeEventListener('pointerup', onBlockPointerUp);
			capBlock.removeEventListener('pointercancel', onBlockPointerUp);
		}
		document.querySelectorAll('.vtc-tppl-lane-body').forEach(function (b) {
			b.classList.remove('is-drop-target');
		});
		var slot = drag.slot;
		if (drag.type === 'moveGroup') {
			drag.items.forEach(function (it) {
				recordSlotChange(it.id, { start_time: it.slot.start_time, end_time: it.slot.end_time });
			});
			drag = null;
			render();
			return;
		}
		var patch = {};
		if (drag.type === 'move') {
			patch.start_time = slot.start_time;
			patch.end_time = slot.end_time;
			var finalLane = capBlock && capBlock.closest ? capBlock.closest('.vtc-tppl-lane-body') : null;
			if (finalLane && finalLane !== drag.body) {
				patch.venue_id = parseInt(finalLane.getAttribute('data-venue-id'), 10);
				patch.day_of_week = parseInt(finalLane.getAttribute('data-dow'), 10);
			}
		} else if (drag.type === 'resize' && drag.edge === 'left') {
			patch.start_time = slot.start_time;
			patch.end_time = slot.end_time;
		} else {
			patch.end_time = slot.end_time;
		}
		var id = drag.id;
		drag = null;
		recordSlotChange(id, patch);
		render();
	}

	function onDropTeam(e) {
		e.preventDefault();
		if (isWeekScope() && !state.data.has_exception && !state.data.uses_deviation_blueprint) return;
		var body = e.currentTarget;
		body.classList.remove('is-drop-target');
		var teamId = parseInt(e.dataTransfer.getData('text/plain'), 10);
		if (!teamId) return;
		var place = computePlacementFromPoint(body, e.clientX);
		var tid = allocSlotTempId();
		var team = findTeam(teamId);
		var slot = {
			id: tid,
			team_id: teamId,
			venue_id: place.venue_id,
			day_of_week: place.day_of_week,
			start_time: place.start_time,
			end_time: place.end_time,
			team_name: team ? team.display_name : ''
		};
		mergeSlot(slot);
		markDirty();
		render();
	}

	function deleteSlot(id, opts) {
		opts = opts || {};
		if (isWeekScope() && !state.data.has_exception && !state.data.uses_deviation_blueprint) return;
		state.selectedSlotIds.delete(id);
		if (state.selectedSlotIds.size === 0) {
			state.selectedId = null;
		} else if (state.selectedId === id) {
			state.selectedId = state.selectedSlotIds.values().next().value;
		}
		if (id < 0) {
			removeSlot(id);
			markDirty();
			if (!opts.skipRender) render();
			return;
		}
		pending.slotDeletes.add(id);
		delete pending.slotPatches[id];
		removeSlot(id);
		markDirty();
		if (!opts.skipRender) render();
	}

	function onPublish() {
		if (isWeekScope() && !(state.data && state.data.uses_deviation_blueprint && !state.data.has_exception)) return;
		var msg = state.localDirty ? __('publishSaveFirst') : __('publishConfirm');
		if (!window.confirm(msg)) return;
		var afterSave = function () {
			return api('admin/publish' + bpQuery(), {
				method: 'POST',
				body: JSON.stringify(withBlueprintBody({}))
			})
				.then(function () {
					showToast(__('published'), true);
					return loadPlanner({ force: true });
				})
				.catch(function (err) {
					showToast(err.message || __('saveErr'), false);
				});
		};
		if (state.localDirty) {
			saveAll()
				.then(function () {
					return afterSave();
				})
				.catch(function () {
					/* save mislukt: geen publiceren */
				});
		} else {
			afterSave();
		}
	}

	function bootstrapPlanner() {
		api('admin/blueprints')
			.then(function (r) {
				state.blueprintsList = r.blueprints || [];
				if (r.base_blueprint_id && !state.blueprintId) {
					state.blueprintId = r.base_blueprint_id;
				}
				loadPlanner({ initial: true });
			})
			.catch(function () {
				state.blueprintsList = [];
				if (!state.blueprintId && cfg.baseBlueprintId) {
					state.blueprintId = parseInt(cfg.baseBlueprintId, 10);
				}
				loadPlanner({ initial: true });
			});
	}

	bootstrapPlanner();
})();
