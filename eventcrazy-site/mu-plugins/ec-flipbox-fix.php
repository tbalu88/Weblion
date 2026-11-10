<?php
/**
 * EventCrazy – mobilos javítások (Elementor oldalak)
 * 1) Flip Box csempék: az egész csempe kattintható (mobilon a hátoldali gomb nem érhető el), link nélküli csempéhez tartalék cél.
 * 2) Mobilon a sorkizárt bekezdések balra zárva, kisebb betűköz; a képernyő széléig érő szövegblokkok oldalmargót kapnak.
 * 3) Greenbox oldal: a véletlenül bemásolt második fejléc-kártya (értékelés-jelvény, telefon, kép) rejtve.
 * 4) Csempe-címek: nem törnek szóközepen – a betűméret addig csökken, amíg a leghosszabb szó és a teljes tartalom elfér;
 *    asztali nézetben (200%-os nagyításon is) a hátoldal leírása kisebb lesz, hogy a gomb ne vágódjon le.
 * 5) Vélemények: a hosszú link a kártyán belül törik, nem lóg ki.
 * 6) Mozgás: szűrés után a csempék egymás után úsznak be, az „+ Ajánlathoz” gomb benyomódik; csökkentett mozgásnál csak elhalványulás.
 * Kikapcsolás: a fájl törlése (vagy átnevezése).
 */
if (!defined('ABSPATH')) { exit; }

function ec_fix_skip() {
	return is_admin() || (function_exists('wp_doing_ajax') && wp_doing_ajax()) || isset($_GET['elementor-preview']);
}

add_action('wp_head', function () {
	if (ec_fix_skip()) { return; }
	echo "<style id=\"ec-mobile-fix\">\n" . <<<'ECCSS'
/* Greenbox: a második (bemásolt) fejléc-kártya – értékelés-jelvény, telefon, kép – minden nézetben rejtve */
[data-id="3e25d98"] [data-id="076669a"]{display:none!important}
/* vélemények: a hosszú link (URL) a kártyán belül törik */
.elementor-image-box-description{overflow-wrap:anywhere}
/* „+ Ajánlathoz”: benyomódás kattintáskor; a régi „transition:all” helyett csak a szükséges tulajdonságok */
body .ec-add{transition:background-color .2s ease,border-color .2s ease,color .2s ease,box-shadow .2s ease,transform 120ms cubic-bezier(0.23,1,0.32,1)}
body .ec-add:active{transform:scale(.95)}
/* csökkentett mozgás: a csempe hátoldala és az ajánlatsáv csak elhalványul, nem csúszik */
/* 7) Ajánlatlista-sáv: a ✕ gomb saját stílust kap (a téma gombmargója és fókuszgyűrűje nélkül) – kerek, középre igazított jel, billentyűzetnél arany gyűrű */
body .ec-bar .ec-x{padding:0!important;border:0!important;border-radius:50%!important;display:inline-grid!important;place-items:center;line-height:1!important;box-shadow:none!important;outline:none!important;cursor:pointer}
body .ec-bar .ec-x:hover{background:rgba(255,255,255,.16)!important}
body .ec-bar .ec-x:focus{outline:none!important;box-shadow:none!important}
body .ec-bar .ec-x:focus-visible{outline:2px solid #F3C25A!important;outline-offset:2px!important}
body .ec-bar .ec-go:focus{outline:none}
body .ec-bar .ec-go:focus-visible{outline:2px solid #fff;outline-offset:2px}
@media (prefers-reduced-motion:reduce){
	body .ec-add:active{transform:none}
	.elementor-flip-box .elementor-flip-box__back{transform:none!important;opacity:0;transition:opacity .2s ease!important}
	.elementor-flip-box:hover .elementor-flip-box__back,.elementor-flip-box:focus-within .elementor-flip-box__back{opacity:1}
	.elementor-flip-box .elementor-flip-box__front{transform:none!important;transition:none!important}
	body .ec-bar{transition:opacity .25s ease,visibility .25s ease!important}
}
@media (max-width:767px){
	/* mobil: kisebb betűköz a folyószövegben */
	.elementor-widget-text-editor p,.elementor-widget-text-editor li,.elementor-icon-box-description{letter-spacing:.2px!important;word-spacing:normal!important;-webkit-hyphens:auto;hyphens:auto}
	/* mobil: a képernyő széléig érő szövegblokkok oldalmargót kapnak */
	[data-id="df309d3"],[data-id="d299a87"]{margin-left:0!important;margin-right:0!important;padding-left:0!important;padding-right:0!important;width:100%!important;max-width:100%!important}
	.elementor-widget-wrap:has(>[data-id="df309d3"]),.elementor-widget-wrap:has(>[data-id="d299a87"]),.e-con:has(>[data-id="df309d3"]),.e-con:has(>[data-id="d299a87"]){padding-left:0!important;padding-right:0!important}
	[data-id="df309d3"]>.elementor-widget-container,[data-id="d299a87"]>.elementor-widget-container{margin-left:0!important;margin-right:0!important;padding-left:20px!important;padding-right:20px!important}
}
ECCSS
	. "</style>\n";
}, 99);

add_action('wp_footer', function () {
	if (ec_fix_skip()) { return; }
	echo "<script nowprocket data-cfasync=\"false\">\n" . <<<'ECJS'
(function () {
	/* a hátoldali link nélküli csempék célja (a szerkesztőben pótolandó) */
	var FALLBACK = { 'bffd745': '/eventcrazy-rendezvenyfilm/' };
	function linkOf(w) {
		var back = w.querySelector('.elementor-flip-box__back');
		var a = back && (back.tagName === 'A' ? back : back.querySelector('a[href]'));
		var h = a ? (a.getAttribute('href') || '').trim() : '';
		if (!h || h === '#') { h = FALLBACK[w.getAttribute('data-id')] || ''; a = null; }
		return h ? { href: h, blank: !!(a && a.getAttribute('target') === '_blank') } : null;
	}
	document.addEventListener('click', function (e) {
		var w = e.target.closest && e.target.closest('.elementor-widget-flip-box');
		if (!w) return;
		/* a csempén lévő saját gomb (pl. „+ Ajánlathoz”) ne nyissa meg az aloldalt */
		if (e.target.closest('button, input, select, textarea, label, .ec-add')) return;
		var own = e.target.closest('a[href]');
		if (own && (own.getAttribute('href') || '').trim() && own.getAttribute('href') !== '#') return; /* saját működő link: hagyjuk */
		var l = linkOf(w);
		if (!l) return;
		e.preventDefault();
		if (l.blank) { window.open(l.href, '_blank', 'noopener'); } else { window.location.href = l.href; }
	}, true);
	var st = document.createElement('style');
	st.textContent = '.elementor-widget-flip-box{cursor:pointer;-webkit-tap-highlight-color:rgba(0,0,0,.08)}';
	(document.head || document.documentElement).appendChild(st);
})();

(function () {
	/* csempék: a cím ne törjön szóközepen, a tartalom ne vágódjon le – a betűméret a csempéhez igazodik */
	function shrink(el, min, over) {
		var fs = parseFloat(getComputedStyle(el).fontSize);
		while (over() && fs > min) { fs -= 1; el.style.setProperty('font-size', fs + 'px', 'important'); }
	}
	/* a leghosszabb szó szélessége (px) és a rendelkezésre álló hely */
	function widest(el) {
		var max = 0, w = document.createTreeWalker(el, NodeFilter.SHOW_TEXT), n, r = document.createRange();
		while ((n = w.nextNode())) {
			var re = /\S+/g, m;
			while ((m = re.exec(n.textContent))) { r.setStart(n, m.index); r.setEnd(n, m.index + m[0].length); max = Math.max(max, r.getBoundingClientRect().width); }
		}
		return max;
	}
	function room(el) {
		var cs = getComputedStyle(el);
		return el.clientWidth - parseFloat(cs.paddingLeft) - parseFloat(cs.paddingRight) - 2;
	}
	function fitLayer(l) {
		var inner = l.querySelector('.elementor-flip-box__layer__inner');
		var t = l.querySelector('.elementor-flip-box__layer__title');
		var d = l.querySelector('.elementor-flip-box__layer__description');
		var back = l.classList.contains('elementor-flip-box__back');
		[t, d].forEach(function (e) { if (e) { ['font-size', 'display', '-webkit-box-orient', 'overflow', '-webkit-line-clamp'].forEach(function (p) { e.style.removeProperty(p); }); } });
		if (!inner) { return; }
		if (!l.clientWidth) { return; } /* rejtett (szűrt) csempe: megjelenéskor igazítjuk */
		var tall = function () { return inner.getBoundingClientRect().height > l.clientHeight + 1; };
		if (t) {
			t.style.setProperty('overflow-wrap', 'normal', 'important');
			t.style.setProperty('word-break', 'normal', 'important');
			shrink(t, 12, function () { return widest(t) > room(t) || (!back && tall()); });
			t.style.setProperty('overflow-wrap', 'break-word', 'important');
		}
		/* a hátoldal csak egérrel (rámutatásra) látszik; érintőképernyőn az egész csempe link, ott nem igazítunk */
		if (back && window.matchMedia && matchMedia('(hover:hover)').matches) {
			if (d) { shrink(d, 11, tall); }
			if (t) { shrink(t, 14, tall); }
			/* végső tartalék: a leírás sorai vágódnak (…), a gomb mindig látszik */
			if (d && tall()) {
				d.style.display = '-webkit-box'; d.style.webkitBoxOrient = 'vertical'; d.style.overflow = 'hidden';
				for (var k = 6; k >= 1 && tall(); k--) { d.style.webkitLineClamp = k; }
			}
		}
	}
	function fitAll() {
		var ls = document.querySelectorAll('.elementor-flip-box__layer');
		for (var i = 0; i < ls.length; i++) { fitLayer(ls[i]); }
	}
	window.ecFlipFit = fitAll;
	var tm;
	function later() { clearTimeout(tm); tm = setTimeout(fitAll, 150); }
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', fitAll); } else { fitAll(); }
	window.addEventListener('load', fitAll);
	window.addEventListener('resize', later);
	/* szűrőgombok (Összes, Fotóautomaták…): az újonnan megjelenő csempék igazítása */
	document.addEventListener('click', function (e) {
		if (!(e.target.closest && e.target.closest('.ec-chip'))) { return; }
		later();
		/* szűrés után a megjelenő csempék egymás után úsznak be (max. 8 késleltetve, kattintani végig lehet) */
		if (!document.body.animate) { return; }
		var calm = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;
		var cols = [].slice.call(document.querySelectorAll('[data-ec-cat]')).filter(function (c) { return c.offsetParent !== null; });
		cols.forEach(function (c, i) {
			c.animate(calm ? [{ opacity: 0 }, { opacity: 1 }] : [{ opacity: 0, transform: 'scale(.97)' }, { opacity: 1, transform: 'none' }],
				{ duration: 200, delay: calm ? 0 : Math.min(i, 7) * 30, easing: 'cubic-bezier(0.23, 1, 0.32, 1)', fill: 'backwards' });
		});
	});
	if (document.fonts && document.fonts.ready) { document.fonts.ready.then(fitAll); }
})();

(function () {
	/* mobil: a sorkizárt bekezdések balra zárva (keskeny képernyőn a sorkizárás nagy lyukakat hagy a szavak között) */
	function unjustify() {
		if (window.innerWidth > 767) return;
		var els = document.querySelectorAll('.elementor-widget-text-editor, .elementor-widget-text-editor p, .elementor-widget-text-editor div, .elementor-widget-text-editor li, .elementor-icon-box-description, .elementor-image-box-description');
		for (var i = 0; i < els.length; i++) {
			if (getComputedStyle(els[i]).textAlign === 'justify') els[i].style.setProperty('text-align', 'left', 'important');
		}
	}
	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', unjustify); else unjustify();
	window.addEventListener('load', unjustify);
})();
ECJS
	. "\n</script>\n";
}, 99);

/* egyszeri gyorsítótár-ürítés (WP Rocket) minden új változat után */
add_action('wp_loaded', function () {
	$v = '4';
	if (get_option('ec_flipfix_purged') === $v) { return; }
	update_option('ec_flipfix_purged', $v, false);
	if (function_exists('rocket_clean_domain')) { rocket_clean_domain(); }
});
