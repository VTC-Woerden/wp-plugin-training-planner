/**
 * Pijl-links / pijl-rechts: zelfde navigatie als de weekknoppen (?vtc_tp_week=).
 */
(function () {
	'use strict';

	document.addEventListener('keydown', function (e) {
		if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') {
			return;
		}
		var t = e.target;
		if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) {
			return;
		}
		var shell = document.querySelector('.vtc-tp-week-shell[data-prev-url][data-next-url]');
		if (!shell) {
			return;
		}
		var url = e.key === 'ArrowLeft' ? shell.getAttribute('data-prev-url') : shell.getAttribute('data-next-url');
		if (url) {
			e.preventDefault();
			window.location.assign(url);
		}
	});
})();
