/**
 * Weekwissel via REST (geen volledige pagina-herlaad); pijltoetsen + toolbar-knoppen.
 */
(function () {
	'use strict';

	function qs(sel, root) {
		return (root || document).querySelector(sel);
	}

	function weekFromUrl() {
		var p = new URLSearchParams(window.location.search);
		return p.get('vtc_tp_week') || '';
	}

	function setUrlWeekParam(iso) {
		var u = new URL(window.location.href);
		if (iso) {
			u.searchParams.set('vtc_tp_week', iso);
		} else {
			u.searchParams.delete('vtc_tp_week');
		}
		history.pushState({ vtcTpWeek: iso }, '', u.toString());
	}

	/**
	 * Bij plain permalinks is de REST-basis bv. index.php?rest_route=/vtc-tp/v1/week-html —
	 * week moet dan met &week=, niet met een tweede ?.
	 */
	function weekHtmlFetchUrl(base, iso) {
		try {
			var u = new URL(base, window.location.href);
			u.searchParams.set('week', iso);
			return u.toString();
		} catch (e) {
			var sep = base.indexOf('?') === -1 ? '?' : '&';
			return base + sep + 'week=' + encodeURIComponent(iso);
		}
	}

	function loadWeek(shell, iso, usePushState) {
		var cfg = window.vtcTpWeekNav;
		if (!cfg || !cfg.url || !iso) {
			return Promise.resolve();
		}
		var inner = qs('.vtc-tp-week-ajax-inner', shell);
		var title = qs('.vtc-tp-week-title--toolbar', shell);
		if (!inner) {
			return Promise.resolve();
		}

		shell.classList.add('vtc-tp-week-shell--loading');
		var url = weekHtmlFetchUrl(cfg.url, iso);

		return fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
			.then(function (r) {
				if (!r.ok) {
					throw new Error('bad status');
				}
				return r.json();
			})
			.then(function (data) {
				if (!data || !data.html) {
					throw new Error('no html');
				}
				inner.innerHTML = data.html;
				if (title && data.week_label) {
					title.textContent = data.week_label;
				}
				if (data.prev_iso) {
					shell.setAttribute('data-prev-iso', data.prev_iso);
				}
				if (data.next_iso) {
					shell.setAttribute('data-next-iso', data.next_iso);
				}
				shell.setAttribute('data-current-iso', data.iso_week || iso);
				var tbBtns = shell.querySelectorAll('.vtc-tp-week-toolbar .vtc-tp-week-nav-btn');
				if (tbBtns[0] && data.prev_iso) {
					tbBtns[0].setAttribute('data-nav-week', data.prev_iso);
				}
				if (tbBtns[1] && data.next_iso) {
					tbBtns[1].setAttribute('data-nav-week', data.next_iso);
				}
				if (usePushState) {
					setUrlWeekParam(data.iso_week || iso);
				}
			})
			.catch(function () {
				inner.innerHTML =
					'<p class="vtc-tp-week-ajax-error" role="alert">' +
					(cfg.errMsg || 'Week laden mislukt.') +
					'</p>';
			})
			.finally(function () {
				shell.classList.remove('vtc-tp-week-shell--loading');
			});
	}

	function bindShell(shell) {
		if (!shell || shell.getAttribute('data-ajax-week') !== '1') {
			return;
		}

		shell.addEventListener('click', function (e) {
			var btn = e.target.closest('.vtc-tp-week-nav-btn[data-nav-week]');
			if (!btn || !shell.contains(btn)) {
				return;
			}
			e.preventDefault();
			var iso = btn.getAttribute('data-nav-week');
			if (iso) {
				loadWeek(shell, iso, true);
			}
		});
	}

	function shellForKeyboard() {
		return (
			document.querySelector('.vtc-tp-week-shell[data-ajax-week="1"]:focus-within') ||
			document.querySelector('.vtc-tp-week-shell[data-ajax-week="1"]')
		);
	}

	function initWeekNav() {
		document.querySelectorAll('.vtc-tp-week-shell[data-ajax-week="1"]').forEach(bindShell);

		if (window.vtcTpWeekNavKeyBound) {
			return;
		}
		window.vtcTpWeekNavKeyBound = true;

		document.addEventListener('keydown', function (e) {
			if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') {
				return;
			}
			var t = e.target;
			if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) {
				return;
			}
			var shell = shellForKeyboard();
			if (!shell) {
				return;
			}
			var iso = e.key === 'ArrowLeft' ? shell.getAttribute('data-prev-iso') : shell.getAttribute('data-next-iso');
			if (!iso) {
				return;
			}
			e.preventDefault();
			loadWeek(shell, iso, true);
		});

		window.addEventListener('popstate', function () {
			var fromUrl = weekFromUrl();
			var shell = document.querySelector('.vtc-tp-week-shell[data-ajax-week="1"]');
			if (!shell || !fromUrl) {
				return;
			}
			var cur = shell.getAttribute('data-current-iso') || '';
			if (fromUrl === cur) {
				return;
			}
			loadWeek(shell, fromUrl, false);
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initWeekNav);
	} else {
		initWeekNav();
	}
})();

