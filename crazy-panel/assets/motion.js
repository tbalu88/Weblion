/* Crazy panel – közös mozgás (v2.9.23)
   Oldalsó panel (#cpx-modal): becsúszás jobbról valódi megnyitáskor, kicsúszás ugyanarra záráskor.
   Tartalomcserénél (nyitott panelben új tartalom) nem mozog. Csökkentett mozgásnál csak elhalványul. */
(function () {
	'use strict';
	if (window.cpxSheetMo) return;
	const EO = 'cubic-bezier(0.23, 1, 0.32, 1)', DR = 'cubic-bezier(0.32, 0.72, 0, 1)';
	const BG = 'rgba(20, 28, 51, 0.55)', BG0 = 'rgba(20, 28, 51, 0)';
	const calm = () => !!(window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches);

	function enter(m) {
		const s = m.querySelector('.cpx-sheet');
		if (!m.animate) return;
		const r = calm();
		m.animate([{ backgroundColor: BG0 }, { backgroundColor: BG }], { duration: 200, easing: 'ease' });
		if (s) s.animate(r ? [{ opacity: 0 }, { opacity: 1 }] : [{ transform: 'translateX(100%)' }, { transform: 'translateX(0)' }], { duration: r ? 150 : 240, easing: r ? 'ease' : DR });
	}
	function watch() {
		const m = document.getElementById('cpx-modal');
		if (!m || m._cpxMo) return m;
		m._cpxMo = true;
		let was = m.hidden;
		new MutationObserver(() => { if (was && !m.hidden) enter(m); was = m.hidden; }).observe(m, { attributes: true, attributeFilter: ['hidden'] });
		return m;
	}
	function close(m) {
		m = m || document.getElementById('cpx-modal');
		if (!m || m.hidden) return;
		const s = m.firstElementChild;
		const now = () => { m.hidden = true; m.innerHTML = ''; };
		if (!s || !m.animate) { now(); return; }
		if (m._cpxClosing) return;
		m._cpxClosing = true;
		m.style.pointerEvents = 'none';
		/* ha a hívó rögtön új tartalmat tesz be (csere), nincs kicsúszás */
		queueMicrotask(() => {
			const reset = () => { m._cpxClosing = false; m.style.pointerEvents = ''; m.getAnimations().forEach(a => a.cancel()); };
			if (m.firstElementChild !== s || m.hidden) { reset(); return; }
			const r = calm();
			m.animate([{ backgroundColor: BG }, { backgroundColor: BG0 }], { duration: 200, easing: 'ease', fill: 'forwards' });
			const a = s.animate(r ? [{ opacity: 1 }, { opacity: 0 }] : [{ transform: 'translateX(0)' }, { transform: 'translateX(100%)' }], { duration: r ? 150 : 200, easing: r ? 'ease' : EO, fill: 'forwards' });
			a.onfinish = a.oncancel = () => { const same = m.firstElementChild === s; reset(); if (same) now(); };
		});
	}
	window.cpxSheetMo = { watch: watch, close: close };
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', watch); else watch();
})();
