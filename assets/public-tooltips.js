/**
 * Floating tooltips for calendar blocks — fixed to the viewport, no layout scroll.
 */
(function () {
	'use strict';

	var GAP = 8;
	var PAD = 8;
	var tipEl = null;
	var activeBlock = null;

	function ensureTip() {
		if (tipEl && tipEl.isConnected) {
			return tipEl;
		}
		tipEl = document.createElement('div');
		tipEl.className = 'vtc-tp-floating-tip';
		tipEl.setAttribute('role', 'tooltip');
		tipEl.hidden = true;
		document.body.appendChild(tipEl);
		return tipEl;
	}

	function hide() {
		if (!tipEl) {
			return;
		}
		tipEl.hidden = true;
		tipEl.textContent = '';
		if (activeBlock) {
			activeBlock.removeAttribute('aria-describedby');
			activeBlock = null;
		}
	}

	function place(block) {
		var text = block.getAttribute('data-vtc-tip') || '';
		if (!text) {
			hide();
			return;
		}
		var el = ensureTip();
		if (!el.id) {
			el.id = 'vtc-tp-floating-tip';
		}
		el.textContent = text;
		el.hidden = false;
		activeBlock = block;
		block.setAttribute('aria-describedby', el.id);

		// Measure after content is set.
		el.style.left = '0px';
		el.style.top = '0px';
		var rect = block.getBoundingClientRect();
		var tw = el.offsetWidth;
		var th = el.offsetHeight;
		var vw = window.innerWidth;
		var vh = window.innerHeight;

		var left = rect.left + rect.width / 2 - tw / 2;
		left = Math.max(PAD, Math.min(left, vw - tw - PAD));

		var topBelow = rect.bottom + GAP;
		var topAbove = rect.top - th - GAP;
		var top;
		if (topBelow + th + PAD <= vh) {
			top = topBelow;
			el.classList.remove('vtc-tp-floating-tip--above');
		} else if (topAbove >= PAD) {
			top = topAbove;
			el.classList.add('vtc-tp-floating-tip--above');
		} else {
			// Clamp inside viewport.
			top = Math.max(PAD, Math.min(topBelow, vh - th - PAD));
			el.classList.toggle('vtc-tp-floating-tip--above', top < rect.top);
		}

		el.style.left = Math.round(left) + 'px';
		el.style.top = Math.round(top) + 'px';
	}

	function blockFromEventTarget(t) {
		if (!t || !t.closest) {
			return null;
		}
		return t.closest('.vtc-tp-block[data-vtc-tip]');
	}

	function onOver(e) {
		var block = blockFromEventTarget(e.target);
		if (!block) {
			return;
		}
		place(block);
	}

	function onOut(e) {
		var block = blockFromEventTarget(e.target);
		if (!block) {
			return;
		}
		var to = e.relatedTarget;
		if (to && block.contains(to)) {
			return;
		}
		if (activeBlock === block) {
			hide();
		}
	}

	function onFocusIn(e) {
		var block = blockFromEventTarget(e.target);
		if (block) {
			place(block);
		}
	}

	function onFocusOut(e) {
		var block = blockFromEventTarget(e.target);
		if (!block) {
			return;
		}
		var to = e.relatedTarget;
		if (to && block.contains(to)) {
			return;
		}
		if (activeBlock === block) {
			hide();
		}
	}

	function onScrollOrResize() {
		if (activeBlock && tipEl && !tipEl.hidden) {
			place(activeBlock);
		}
	}

	function init() {
		if (window.vtcTpTooltipsBound) {
			return;
		}
		window.vtcTpTooltipsBound = true;
		document.addEventListener('mouseover', onOver);
		document.addEventListener('mouseout', onOut);
		document.addEventListener('focusin', onFocusIn);
		document.addEventListener('focusout', onFocusOut);
		window.addEventListener('scroll', onScrollOrResize, true);
		window.addEventListener('resize', onScrollOrResize);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
