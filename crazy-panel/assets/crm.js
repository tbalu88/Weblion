/* Crazy panel – Deal-ek fül (v1.25, csak olvasás: a saját CRM-táblákból) */
(function () {
	'use strict';
	const C = window.CPX || {};
	const R = C.crm || {};
	const STAGES = R.stages || [];
	const USERS = R.users || {};
	const W = !!R.write;
	const ST_HU = { Open: 'Nyitott', Won: 'Megnyert', Lost: 'Elvesztett' };
	const HU_MS = ['jan.', 'febr.', 'márc.', 'ápr.', 'máj.', 'jún.', 'júl.', 'aug.', 'szept.', 'okt.', 'nov.', 'dec.'];
	const DOW = ['V', 'H', 'K', 'Sze', 'Cs', 'P', 'Szo'];

	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const pad = n => (n < 10 ? '0' : '') + n;
	const num = n => n == null || isNaN(n) ? '–' : Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
	const money = (v, cur) => v == null ? '–' : num(v) + (cur && cur !== 'HUF' ? ' ' + cur : ' Ft');
	const huD = s => { if (!s) return ''; const p = s.slice(0, 10).split('-').map(Number); const d = new Date(p[0], p[1] - 1, p[2]); return p[0] + '. ' + HU_MS[p[1] - 1] + ' ' + p[2] + '. (' + DOW[d.getDay()] + ')'; };
	const huDT = s => s ? s.slice(0, 4) + '.' + s.slice(5, 7) + '.' + s.slice(8, 10) + '. ' + s.slice(11, 16) : '';
	/* v2.8.8: deal Fájlok kártya – bélyegképek */
	const D3IMG = /\.(jpe?g|png|gif|webp|heic|heif)$/i;
	const d3Ext = n => (((/\.([a-z0-9]{1,5})$/i.exec(n || '') || [])[1]) || '').toUpperCase();
	/* v2.9.72: a fájl alatt a dátum helyett a méret (fájlméret, képnél képméret is); ha még nem ismert, marad a dátum */
	const d3Size = b => b >= 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB';
	const d3Meta = f => [f.from, f.s ? d3Size(f.s) : '', f.w && f.h2 ? f.w + '×' + f.h2 : '', f.s || f.w ? '' : huDT(f.at)].filter(Boolean).join(' · ');
	function d3Files(list) {
		const seen = {}, out = [];
		/* v2.9.71: az ismétlődő fájl (ugyanaz a név ugyanattól a feladótól – jellemzően aláírás-kép) egy példányban látszik (×N),
		   a többi példány az elrejtettek közé kerül (a „Mutasd” alatt elérhető) */
		(list || []).forEach(f => { const k = f.h ? 'h:' + f.h : String(f.n || '').toLowerCase() + '|' + String(f.from || '').toLowerCase(); /* v2.9.71: ha ismert a kép tartalma, az dönt (más név, más feladó is) */ if (seen[k]) { seen[k].x++; out.push(Object.assign({}, f, { x: 1, emb: 1, dup: 1 })); return; } const g = Object.assign({}, f, { x: 1 }); seen[k] = g; out.push(g); });
		/* v2.9.20: a levélbe ágyazott képek (aláírás-logók, ikonok) a lista végére, alapból rejtve */
		return out.filter(f => !f.emb).concat(out.filter(f => f.emb));
	}
	const D3T = { on: true };
	function d3Thumb(f) {
		const e = d3Ext(f.n), cls = /^(PDF)$/.test(e) ? 'pdf' : /^(DOCX?|ODT|RTF|PAGES)$/.test(e) ? 'doc' : /^(XLSX?|CSV|ODS|NUMBERS)$/.test(e) ? 'xls' : /^(PPTX?|KEY|ODP)$/.test(e) ? 'ppt' : /^(ZIP|RAR|7Z|GZ)$/.test(e) ? 'zip' : D3IMG.test(f.n) ? 'img' : 'oth';
		const C0 = window.CPX || {};
		const img = D3T.on && D3IMG.test(f.n) && f.m && C0.ajax ? '<img loading="lazy" alt="" src="' + esc(C0.ajax + '?action=cp_mail_thumb&v=2&m=' + f.m + '&i=' + f.i + '&t=' + encodeURIComponent(C0.t || '')) + '">' : '';
		return '<span class="d3-tb t-' + cls + (img ? ' has' : '') + '">' + img + '<i>' + esc(e || 'FÁJL') + '</i></span>';
	}
	/* v2.9.176: Fájlok szűrő – PDF / Képek / Dokumentumok / Egyéb */
	const d3Kind = n => { const e = d3Ext(n); return e === 'PDF' ? 'pdf' : D3IMG.test(n || '') ? 'img' : /^(DOCX?|ODT|RTF|PAGES|TXT|XLSX?|CSV|ODS|NUMBERS|PPTX?|KEY|ODP)$/.test(e) ? 'doc' : 'oth'; };
	const D3K = [['pdf', 'PDF'], ['img', 'Képek'], ['doc', 'Dokumentumok'], ['oth', 'Egyéb']];
	function d3FiltBar(fl, sbf) {
		const c = { pdf: 0, img: (sbf || []).length, doc: 0, oth: 0 };
		fl.forEach(f => { if (!f.emb) c[d3Kind(f.n)]++; });
		const ks = D3K.filter(k => c[k[0]]);
		if (ks.length < 2) return '';
		const cur = ks.some(k => k[0] === D3T.ff) ? D3T.ff : '';
		return '<div class="d3-ff" role="group" aria-label="Fájlok szűrése"><button type="button" data-ff=""' + (cur ? '' : ' class="on" aria-pressed="true"') + '>Mind</button>' + ks.map(k => '<button type="button" data-ff="' + k[0] + '"' + (cur === k[0] ? ' class="on" aria-pressed="true"' : '') + '>' + k[1] + ' <small>' + c[k[0]] + '</small></button>').join('') + '</div>';
	}
	/* v2.9.184: a dealhez feltöltött fájlok (a panelben tárolva, a Salesmate-be nem kerülnek) */
	const dfUrl = (id, t, dl) => (window.CPX || {}).ajax + '?action=cp_crm_dfile&id=' + id + (t ? '&t=1' : '') + (dl ? '&dl=1' : '');
	function d3UpHtml(up) {
		if (!up.length) return '';
		return '<b class="d3-sbh">Feltöltött fájlok</b><ul class="d3-fg d3-upl">' + up.map(f => '<li data-fk="' + d3Kind(f.n) + '"><a class="d3-ft" href="' + esc(dfUrl(f.id)) + '" target="_blank" rel="noopener" title="' + esc(f.n) + '">' +
			(f.img ? '<span class="d3-tb t-img has"><img loading="lazy" alt="" src="' + esc(dfUrl(f.id, 1)) + '"><i>' + esc(d3Ext(f.n) || 'KÉP') + '</i></span>' : d3Thumb({ n: f.n })) +
			'<b>' + esc(f.n) + '</b><small>' + esc([f.by, f.s ? d3Size(f.s) : '', huDT(f.at)].filter(Boolean).join(' · ')) + '</small></a>' + (f.del ? '<button type="button" class="d3-dfx" data-dfdel="' + f.id + '" title="Fájl törlése" aria-label="' + esc(f.n) + ' törlése">×</button>' : '') + '</li>').join('') + '</ul>';
	}
	function d3UpBind(sh, dealId) {
		const re = () => { if (D3.ctx && D3.ctx.id === dealId) openDeal(dealId, D3.ctx.back, true); };
		const C0 = window.CPX || {};
		const send = async files => {
			const L = Array.from(files || []); if (!L.length) return;
			let ok = 0;
			for (const f of L) {
				if (f.size > 25 * 1048576) { toast(f.name + ': túl nagy (max. 25 MB).', true); continue; }
				toast('Feltöltés: ' + f.name + '…');
				try {
					const fd = new FormData(); fd.append('action', 'cp_crm_dfile_up'); fd.append('t', C0.t); fd.append('deal', dealId); fd.append('file', f, f.name);
					const r = await fetch(C0.ajax, { method: 'POST', body: fd, credentials: 'same-origin' }); let j = null; try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
					if (!j || !j.success) throw new Error((j && j.data && j.data.msg) || 'Hiba'); ok++;
				} catch (e) { toast(f.name + ': ' + e.message, true); }
			}
			if (ok) { toast(ok > 1 ? ok + ' fájl feltöltve.' : 'Fájl feltöltve.'); re(); }
		};
		const inp = sh.querySelector('[data-dfup]'); if (inp) inp.addEventListener('change', () => { send(inp.files); inp.value = ''; });
		const sec = sh.querySelector('[data-fsec]');
		if (sec && inp) {
			sec.addEventListener('dragover', e => { if (e.dataTransfer && Array.from(e.dataTransfer.types || []).includes('Files')) { e.preventDefault(); sec.classList.add('d3-drop'); } });
			sec.addEventListener('dragleave', e => { if (!sec.contains(e.relatedTarget)) sec.classList.remove('d3-drop'); });
			sec.addEventListener('drop', e => { if (!e.dataTransfer || !e.dataTransfer.files.length) return; e.preventDefault(); sec.classList.remove('d3-drop'); send(e.dataTransfer.files); });
		}
		sh.querySelectorAll('[data-dfdel]').forEach(b => b.addEventListener('click', async e => {
			e.preventDefault(); e.stopPropagation();
			const n = (b.closest('li').querySelector('b') || {}).textContent || 'a fájlt';
			if (!(await window.CPXConfirm('„' + n + '” törlése a dealről. Ez nem vonható vissza.', { title: 'Fájl törlése', ok: 'Törlés', danger: true }))) return;
			try { await post('cp_crm_dfile_del', { id: b.dataset.dfdel }); toast('Fájl törölve.'); re(); } catch (x) { toast(x.message, true); }
		}));
	}
	function d3FilesBind(sh, fl) {
		sh.querySelectorAll('[data-ff]').forEach(b => b.addEventListener('click', () => { const sec = b.closest('[data-fsec]'); D3T.ff = b.dataset.ff; if (b.dataset.ff) sec.dataset.ffv = b.dataset.ff; else delete sec.dataset.ffv; sec.querySelectorAll('[data-ff]').forEach(x => { const on = x === b; x.classList.toggle('on', on); if (on) x.setAttribute('aria-pressed', 'true'); else x.removeAttribute('aria-pressed'); }); }));
		{ const on = sh.querySelector('[data-ff].on'); if (on && on.dataset.ff) on.closest('[data-fsec]').dataset.ffv = on.dataset.ff; }
		if (window.CPZipScan) window.CPZipScan(sh); /* v2.9.28: zip-ek tartalomellenőrzése */
		const et = sh.querySelector('[data-embt]');
		if (et) et.addEventListener('click', () => { const sec = et.closest('[data-fsec]'), on = sec.classList.toggle('emb-on'); const l = et.querySelector('[data-embl]'); if (l) l.textContent = on ? 'Elrejtés' : 'Mutasd'; });
		/* v2.9.6: a PNG nélküli (régebben mentett) aktuális sablon a Sablonkészítőben nyílik meg, az ügyfél beállításaival */
		sh.querySelectorAll('a[data-sbopen]').forEach(a => a.addEventListener('click', e => { const b = sh.querySelector('[data-act="sablon"]'); if (b && !e.metaKey && !e.ctrlKey) { e.preventDefault(); /* v2.9.9: a Sablonkészítő elkészíti és a dealhez teszi a PNG-t */ try { localStorage.setItem('cpsb_png_' + a.dataset.sbopen, a.dataset.sbts || ''); } catch (x) { /* nincs tár */ } b.click(); } }));
		/* v2.9.66: a meg nem jeleníthető (üres / sérült) kép az elrejtett csoportba kerül – a szerver a következő megnyitáskor eleve oda teszi */
		sh.querySelectorAll('.d3-tb img').forEach(im => { const bad = () => { const p = im.parentNode; if (p) p.classList.remove('has'); im.remove(); const li = p && p.closest('.d3-fg > li'); if (li && !li.classList.contains('d3-emb') && !li.closest('.d3-sbf')) { li.classList.add('d3-emb'); const ul = li.parentNode; ul.appendChild(li); const sec = li.closest('[data-fsec]'), n = sec && sec.querySelector('[data-embn]'), bt = sec && sec.querySelector('[data-embt]'), h = sec && sec.querySelector('h4 small'); if (n) n.textContent = String(Number(n.textContent || 0) + 1); if (bt) bt.hidden = false; if (h) h.textContent = String(Math.max(0, Number(h.textContent || 0) - 1)); } }; im.addEventListener('error', bad); if (im.complete && !im.naturalWidth) bad(); });
		/* v2.9.71: tartalom szerinti ismétlődés a böngészőben – a betöltött bélyegképek 12×12-es, színben durvított képe az ujjlenyomat;
		   az azonos képből az első (legújabb) marad látható, a többi az elrejtettek közé kerül, és az elsőn nő a ×N */
		const FP = {};
		const dupHide = (li, first) => {
			if (li.classList.contains('d3-emb') || li === first) return;
			li.classList.add('d3-emb'); li.parentNode.appendChild(li);
			const sec = li.closest('[data-fsec]'), n = sec && sec.querySelector('[data-embn]'), bt = sec && sec.querySelector('[data-embt]'), h = sec && sec.querySelector('h4 small');
			if (n) n.textContent = String(Number(n.textContent || 0) + 1); if (bt) bt.hidden = false; if (h) h.textContent = String(Math.max(0, Number(h.textContent || 0) - 1));
			const sm = first.querySelector('.d3-ft small'); if (sm) { const x = Number(first.dataset.x || ((/×(\d+)/.exec(sm.textContent) || [])[1]) || 1) + 1; first.dataset.x = x; sm.textContent = sm.textContent.replace(/ · ×\d+$/, '') + ' · ×' + x; }
		};
		const fpOf = im => { try { const c = document.createElement('canvas'); c.width = c.height = 12; const x = c.getContext('2d', { willReadFrequently: true }); x.drawImage(im, 0, 0, 12, 12); const d = x.getImageData(0, 0, 12, 12).data; let s = ''; for (let i = 0; i < d.length; i += 4) s += ((d[i] >> 5) * 64 + (d[i + 1] >> 5) * 8 + (d[i + 2] >> 5)).toString(36) + ','; return s; } catch (e) { return ''; } };
		sh.querySelectorAll('.d3-fg > li:not(.d3-emb) .d3-tb img').forEach(im => {
			if (im.closest('.d3-sbf')) return;
			const go = () => { if (!im.naturalWidth) return; const li = im.closest('li'); if (!li || li.classList.contains('d3-emb')) return; const k = fpOf(im); if (!k) return; const first = FP[k]; if (!first || !first.isConnected || first.classList.contains('d3-emb')) { FP[k] = li; return; } const a = [first, li].sort((p, q) => (p.compareDocumentPosition(q) & Node.DOCUMENT_POSITION_FOLLOWING) ? -1 : 1); FP[k] = a[0]; dupHide(a[1], a[0]); };
			if (im.complete) go(); else im.addEventListener('load', go, { once: true });
		});
		const imgs = fl.map((f, ix) => ({ f, ix })).filter(x => D3IMG.test(x.f.n) && x.f.m && window.CPMailAtt);
		sh.querySelectorAll('a[data-fx]').forEach(a => a.addEventListener('click', e => {
			const ix = Number(a.dataset.fx), pos = imgs.findIndex(x => x.ix === ix);
			if (pos < 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
			e.preventDefault(); d3Lightbox(imgs.map(x => x.f), pos);
		}));
	}
	function d3Lightbox(list, pos) {
		const d = document.createElement('div'); d.className = 'd3-lb';
		const draw = () => {
			const f = list[pos], u = window.CPMailAtt(f.m, f.i, f.n), dl = u.replace('&view=1', '');
			d.innerHTML = '<div class="d3-lbt"><b>' + esc(f.n) + '</b><span>' + (list.length > 1 ? (pos + 1) + ' / ' + list.length : '') + '</span><a href="' + esc(dl) + '" class="d3-lbb">⬇ Letöltés</a><button type="button" class="d3-lbb" data-x>✕</button></div>' +
				'<div class="d3-lbi">' + (list.length > 1 ? '<button type="button" class="d3-lbn p" data-p aria-label="Előző">‹</button>' : '') + '<img src="' + esc(u) + '" alt="">' + (list.length > 1 ? '<button type="button" class="d3-lbn n" data-n aria-label="Következő">›</button>' : '') + '</div>';
			d.querySelector('[data-x]').onclick = close;
			const p = d.querySelector('[data-p]'), n = d.querySelector('[data-n]');
			if (p) p.onclick = () => { pos = (pos - 1 + list.length) % list.length; draw(); };
			if (n) n.onclick = () => { pos = (pos + 1) % list.length; draw(); };
		};
		const key = e => { if (e.key === 'Escape') close(); else if (e.key === 'ArrowLeft' && list.length > 1) { pos = (pos - 1 + list.length) % list.length; draw(); } else if (e.key === 'ArrowRight' && list.length > 1) { pos = (pos + 1) % list.length; draw(); } };
		function close() { d.remove(); document.removeEventListener('keydown', key, true); }
		d.addEventListener('click', e => { if (e.target === d || e.target.classList.contains('d3-lbi')) close(); });
		document.addEventListener('keydown', key, true);
		draw(); document.body.appendChild(d);
	}
	const uname = id => (USERS[String(id)] || {}).n || (id ? '#' + id : '');
	const safeUrl = u => /^https?:\/\//i.test(String(u || '').trim()) ? String(u).trim() : '';
	/* v1.84: kapacitás a rendezvény napján – „1 szabad · 9 ajánlat”; a csempén csak szűkös vagy kockázatos napnál */
	const capTip = k => 'Kapacitás ' + k.d.replace(/-/g, '.') + '.: ' + Math.max(0, k.fs) + ' szabad szelfigép a ' + k.cs + '-ből' + (k.sub ? ' (benne ' + k.sub + ' alvállalkozói)' : '') + ', ' + Math.max(0, k.fp) + ' szabad spinner a ' + k.cp + '-ből; függő ajánlat: ' + (k.ps + k.pp) + (k.risk ? '\nFigyelem: több a függő ajánlat, mint a szabad gép.' : '');
	const capHtml = (k, full) => !k || (!full && k.lvl === 'ok' && !k.risk) ? '' : '<span class="crm-cap ' + k.lvl + (k.risk ? ' risk' : '') + '" title="' + esc(capTip(k)) + '">' + (k.fs <= 0 ? 'betelt' : k.fs + ' szabad') + (k.ps + k.pp ? ' · ' + (k.ps + k.pp) + ' ajánlat' : '') + (k.risk ? ' !' : '') + '</span>';
	/* v1.82: ajánlat előtt az érték helyett becsült ár (~), a részletek rámutatva */
	/* v1.91.1: a becslés ÁFA-jelölése – ec: EventCrazy nettó (+ ÁFA); p / pb: magánszemély (ingyenes e-mail-cím), Crazyphoto-árak, bruttó */
	const estSrc = e => e.v === 'ec' ? 'az EventCrazy-árlista alapján (nettó, + ÁFA)' : (e.v === 'p' || e.v === 'pb') ? 'magánszemélynek (ingyenes e-mail-cím): Crazyphoto-árak, bruttó' : 'a kalkulátor alapján';
	const estVat = e => e.v === 'ec' ? '<small class="crm-vat">+ ÁFA</small>' : (e.v === 'p' || e.v === 'pb') ? '<small class="crm-vat br">bruttó</small>' : '';
	const valHtml = r => r.est ? '<span class="crm-est" title="' + esc('Becsült ár ' + estSrc(r.est) + (r.est.a ? ' (hiányos adatokból)' : '') + ':\n' + r.est.l.join('\n')) + '">~' + esc(money(r.est.p, 'HUF')) + (r.est.a ? '<sup>?</sup>' : '') + estVat(r.est) + '</span>' : '<span>' + esc(money(r.value, r.cur)) + '</span>';
	/* a cím végén álló egynapos dátum („| 2026-11-27”) elhagyható, mert a rendezvény dátuma külön is látszik; a többnaposat („2026-10-29/30”) meghagyjuk */
	/* v2.9.122: rohadás-jelvény: f = késés / a szakasz ideje (0 → halványsárga, 0,5 → narancs, 1+ → piros) */
	const rotCol = f => { f = Math.max(0, Math.min(1, f)); const h = Math.round(52 - 52 * f), l = Math.round(88 - 40 * f); return { bg: 'hsl(' + h + ',95%,' + l + '%)', fg: f > 0.55 ? '#fff' : 'hsl(' + h + ',80%,24%)', card: 'hsl(' + h + ',95%,' + Math.round(93 - 13 * f) + '%)', bd: 'hsl(' + h + ',85%,' + Math.round(62 - 14 * f) + '%)' }; };
	const rotOn = () => { try { return localStorage.getItem('cpx_crm_rot') !== '0'; } catch (e) { return true; } };
	const rotTxt = m => m < 60 ? m + ' perce' : m < 1440 ? Math.floor(m / 60) + ' órája' : Math.floor(m / 1440) + ' napja';
	const rotHtml = (r, m) => { const c = rotCol(m / Math.max(60, r.rw || 1440)); return '<span class="crm-rot v2" style="background:' + c.bg + ';color:' + c.fg + '" title="Rohad: ' + esc(rotTxt(m)) + ' lejárt (' + esc(r.stage) + ', határidő: ' + esc(r.rot) + ')"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>' + esc(m < 1440 ? Math.max(1, Math.floor(m / 60)) + ' ó' : Math.floor(m / 1440) + ' nap') + '</span>'; };
	const shortTitle = r => { const t = r.title || 'Deal #' + r.id; if (!r.ev) return t; const m = t.match(/\s*\|\s*(\d{4}-\d{2}-\d{2})\.?$/); return m && m[1] === r.ev && m.index > 0 ? t.slice(0, m.index) : t; };
	const today = () => { const d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };

	const COL_DEF_INIT = () => [{ k: '_contact' }, { k: '_event' }, { k: '_pkg' }, { k: '_stage' }, { k: 'deal.value' }, { k: 'deal.owner_id' }, { k: 'deal.modified_at' }];
	const SEL = new Map(); /* kijelölt deal-ek a listában: id → sor (lapozáskor megmarad, szűréskor törlődik) */
	const S = { bso: 'ev', dense: false, fold: [], cf: { m: 'all', g: [] }, cols: COL_DEF_INIT(), tw: 0, view: '', vdirty: false, meta: null, mode: 'list', q: '', st: 'Open', stage: '', own: '', ev: '', so: 'mod', dir: 'desc', p: 0, won: false, list: null, board: null, err: '', loading: false, main: null, sync: R.sync || 0, seq: 0 };
	try {
		const k = JSON.parse(localStorage.getItem('cpcrm') || '{}');
		['mode', 'st', 'stage', 'own', 'ev', 'so', 'dir', 'bso'].forEach(x => { if (typeof k[x] === 'string') S[x] = k[x]; });
		S.dense = !!k.dense;
		if (Array.isArray(k.cols)) { const c = k.cols.filter(x => x && typeof x.k === 'string' && /^(_[a-z]+|(deal|contact|company)\.[a-z0-9_]+)$/.test(x.k)).slice(0, 30).map(x => ({ k: x.k, w: Math.max(0, Math.min(800, Number(x.w) || 0)) })); if (c.length) S.cols = c; }
		S.tw = Math.max(0, Math.min(800, Number(k.tw) || 0));
		if (Array.isArray(k.fold)) S.fold = k.fold.filter(x => typeof x === 'string');
		if (k.cf && Array.isArray(k.cf.g)) { S.cf = { m: k.cf.m === 'any' ? 'any' : 'all', g: k.cf.g.filter(g => g && typeof g.t === 'string' && Array.isArray(g.c)) }; S.view = typeof k.view === 'string' ? k.view : ''; S.vdirty = !!k.vdirty; }
		else {
			/* v2.9.53: a régi legördülők (felelős, állapot, szakasz) átköltöznek az összetett szűrőbe */
			const c = [];
			if (k.own) c.push({ f: 'owner_id', o: 'in', v: [String(k.own)] });
			if (k.st === 'Won' || k.st === 'Lost') c.push({ f: 'status', o: 'in', v: [k.st] });
			if (k.st === '') c.push({ f: 'status', o: 'in', v: ['Open', 'Won', 'Lost'] });
			if (k.stage) c.push({ f: 'stage', o: 'in', v: [k.stage] });
			if (c.length) S.cf = { m: 'all', g: [{ t: 'deal', m: 'and', c }] };
		}
		if (S.mode === 'leads') S.mode = 'board';
	} catch (e) { /* privát mód */ }
	function save() { try { localStorage.setItem('cpcrm', JSON.stringify({ mode: S.mode, st: S.st, stage: S.stage, own: S.own, ev: S.ev, so: S.so, dir: S.dir, bso: S.bso, dense: S.dense, fold: S.fold, cf: S.cf, cols: S.cols, tw: S.tw, view: S.view, vdirty: S.vdirty })); } catch (e) { /* privát mód */ } }

	async function post(action, params) {
		const fd = new FormData();
		fd.append('action', action);
		fd.append('t', C.t);
		Object.keys(params || {}).forEach(k => fd.append(k, params[k]));
		const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
		let j = null;
		try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
		if (!j || !j.success) throw new Error((j && j.data && j.data.msg) === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : ((j && j.data && j.data.msg) || 'Hiba'));
		if (j.data && j.data.sync) S.sync = j.data.sync;
		return j.data;
	}

	const live = () => S.main && S.main.isConnected && S.main.querySelector('.crm');
	function setStatus() { const s = document.getElementById('cpx-status'); if (s && live()) status(s); }
	function status(s) {
		if (S.loading) { s.textContent = 'Betöltés…'; s.className = 'cpx-status'; return; }
		if (S.err) { s.textContent = S.err; s.className = 'cpx-status err'; return; }
		if (S.sync) { const d = new Date(S.sync * 1000); s.textContent = 'Szinkron: ' + pad(d.getHours()) + ':' + pad(d.getMinutes()); s.title = 'Utolsó szinkron a Salesmate-tel'; s.className = 'cpx-status'; }
		else { s.textContent = ''; s.className = 'cpx-status'; }
	}

	/* ------------------------------------------------------------ fej: nézetváltó, kereső, szűrők */
	function opts(list, cur) { return list.map(x => '<option value="' + esc(x[0]) + '"' + (x[0] === cur ? ' selected' : '') + '>' + esc(x[1]) + '</option>').join(''); }
	function ownerOpts() {
		const ids = Object.keys(USERS).sort((a, b) => (USERS[b].a - USERS[a].a) || USERS[a].n.localeCompare(USERS[b].n, 'hu'));
		return [['', 'Minden felelős']].concat(ids.map(i => [i, USERS[i].n + (USERS[i].a ? '' : ' (inaktív)')]));
	}
	/* v2.9.53: kompakt fej – 1. sor nézet + Új deal; 2. sor kereső, szűrő, gyorsszűrők, rendezés, sűrűség */
	const IC = {
		search: '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="8.5" cy="8.5" r="5.5"/><path d="m13 13 4 4"/></svg>',
		filter: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 5h14M6 10h8M8.5 15h3"/></svg>',
		sort: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M6 4v12M3 13l3 3 3-3M14 16V4M11 7l3-3 3 3"/></svg>',
		cards: '<svg viewBox="0 0 20 20" aria-hidden="true"><rect x="3" y="3.5" width="14" height="5.5" rx="1.5"/><rect x="3" y="11" width="14" height="5.5" rx="1.5"/></svg>',
		dense: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M3 4.5h14M3 8.2h14M3 11.9h14M3 15.6h14"/></svg>',
		info: '<svg viewBox="0 0 20 20" aria-hidden="true"><circle cx="10" cy="10" r="7.2"/><path d="M10 9v4.6M10 6.4v.1"/></svg>',
		x: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5.5 5.5 9 9M14.5 5.5l-9 9"/></svg>',
		pen: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M13.5 3.5l3 3L7 16H4v-3z"/></svg>',
		cols: '<svg viewBox="0 0 20 20" aria-hidden="true"><rect x="3" y="4" width="14" height="12" rx="2"/><path d="M8 4v12M12 4v12"/></svg>',
		grip: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M7.5 5h.01M12.5 5h.01M7.5 10h.01M12.5 10h.01M7.5 15h.01M12.5 15h.01"/></svg>',
		up: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 12 5-5 5 5"/></svg>',
		down: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="m5 8 5 5 5-5"/></svg>',
		trash: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 6h12M8 6V4.5h4V6M6 6l.8 10h6.4L14 6"/></svg>'
	};
	function head() {
		/* v2.9.111: telefonon a nézetváltó, a keresés, a szűrő és a rendezés egy sorban, piktogramokkal (a felirat rámutatva / felolvasva) */
		const MOBH = window.matchMedia && window.matchMedia('(max-width:760px)').matches;
		const MI = { list: '<svg viewBox="0 0 20 20" aria-hidden="true"><path d="M4 5.5h12M4 10h12M4 14.5h12"/></svg>', board: '<svg viewBox="0 0 20 20" aria-hidden="true"><rect x="3" y="4" width="4" height="12" rx="1"/><rect x="8" y="4" width="4" height="8" rx="1"/><rect x="13" y="4" width="4" height="10" rx="1"/></svg>' };
		const seg = '<div class="crm-seg' + (MOBH ? ' crm-mseg' : '') + '">' + [['list', 'Lista'], ['board', 'Pipeline']].map(x => '<button type="button" data-mode="' + x[0] + '" class="' + (S.mode === x[0] ? 'on' : '') + '" title="' + x[1] + '" aria-label="' + x[1] + '">' + (MOBH ? MI[x[0]] : x[1]) + '</button>').join('') + '</div>';
		const info = S.mode === 'leads' ? 'A weboldal ajánlatkérő űrlapjairól beérkező megkeresések – azonnal, a beküldés pillanatában.' : (W ? 'Minden módosítás a Salesmate-be is beíródik; a Salesmate-ben végzett változás 10 percen belül (vagy a Frissítés gombbal) itt is megjelenik.' : 'Csak olvasás – a deal-eket egyelőre a Salesmate-ben módosítsd.');
		const srch = ph => '<label class="crm-srch">' + IC.search + '<input type="search" data-f="q" placeholder="' + ph + '" value="' + esc(S.q) + '" aria-label="Keresés"></label>';
		let f = '';
		if (S.mode === 'leads') {
			f = srch('Keresés: név, e-mail, telefon') + '<select data-f="lst" class="crm-mini" aria-label="Szűrés">' + opts([['', 'Minden megkeresés'], ['spam', 'Spamnek jelöltek']], S.lst || '') + '</select>';
		} else {
			f = srch(MOBH ? 'Keresés…' : 'Keresés: név, e-mail, telefon, helyszín, #szám') + cfBtn();
			if (S.mode === 'list') {
				f += S.who ? '<span class="d3-chip">' + (S.who.k === 'co' ? '🏢 ' : '👤 ') + esc(S.who.n) + '<button type="button" data-whox aria-label="Szűrés törlése" title="Szűrés törlése">×</button></span>' : '<button type="button" class="crm-qfb" data-who>+ Cég / kontakt</button>';
			}
			f += '<span class="crm-bgap"></span>';
			const so = S.mode === 'list'
				? '<select data-f="sort" aria-label="Rendezés">' + opts([['mod:desc', 'Legutóbb módosított'], ['cr:desc', 'Legújabb'], ['ev:asc', 'Rendezvény ▲'], ['ev:desc', 'Rendezvény ▼'], ['val:desc', 'Legnagyobb érték'], ['title:asc', 'Név (A–Z)']], S.so + ':' + S.dir) + '</select>'
				: '<select data-f="bso" aria-label="Rendezés">' + opts([['ev', 'Rendezvény dátuma'], ['val', 'Érték'], ['title', 'Név (A–Z)'], ['mod', 'Legutóbb módosított'], ['rot', 'Legrégebben rohadó']], S.bso) + '</select>';
			f += '<label class="crm-sortc" title="Rendezés">' + IC.sort + so + '</label>';
			if (S.mode === 'list') f += '<button type="button" class="crm-cbtn" data-cols aria-haspopup="dialog" aria-expanded="false" title="Oszlopok kiválasztása és sorrendje">' + IC.cols + '<span>Oszlopok</span></button>';
			if (S.mode === 'board') f += '<button type="button" class="crm-rotsw' + (rotOn() ? ' on' : '') + '" data-rotsw aria-pressed="' + rotOn() + '" title="' + (rotOn() ? 'A rohadó csempék elszíneződése be van kapcsolva – kattintva kikapcsolod' : 'A rohadó csempék elszíneződése ki van kapcsolva – kattintva bekapcsolod') + '"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg><span>Rohadás</span></button>';
			if (S.mode === 'board') f += '<span class="crm-seg crm-dens" role="group" aria-label="Kártyák"><button type="button" data-dense="0" class="' + (S.dense ? '' : 'on') + '" aria-pressed="' + !S.dense + '" title="Részletes kártyák" aria-label="Részletes kártyák">' + IC.cards + '</button><button type="button" data-dense="1" class="' + (S.dense ? 'on' : '') + '" aria-pressed="' + S.dense + '" title="Kompakt kártyák" aria-label="Kompakt kártyák">' + IC.dense + '</button></span>';
		}
		const inf = '<span class="crm-info" tabindex="0" role="note" aria-label="' + esc(info) + '" data-tip="' + esc(info) + '">' + IC.info + '</span>';
		if (MOBH && S.mode !== 'leads') return '<div class="crm-bar crm-mbar">' + seg + f + (C.role === 'admin' ? '<button class="cpx-btn ghost crm-binb" type="button" data-bin title="Törölt deal-ek – 30 napig visszaállíthatók">🗑<span class="crm-binl"> Lomtár</span></button>' : '') + '</div>';
		return '<div class="crm-head"><div class="crm-hl">' + seg + (R.edit ? '<button class="cpx-btn" type="button" data-new>+ Új deal</button>' : '') + (C.role === 'admin' ? '<button class="cpx-btn ghost crm-binb" type="button" data-bin title="Törölt deal-ek – 30 napig visszaállíthatók">🗑<span class="crm-binl"> Lomtár</span></button>' : '') + '</div>' + inf + '</div><div class="crm-bar">' + f + '</div>';
	}

	/* ------------------------------------------------------------ v2.9.53: összetett szűrő (mint a Salesmate „View Settings → Filter”) */
	const CF_NOVAL = { set: 1, empty: 1, today: 1, me: 1, yes: 1, no: 1 };
	const CF_LIST = { in: 1, nin: 1, any: 1, all: 1, none: 1 };
	const CF_DAYS = { next: 1, past: 1, older: 1, later: 1 };
	const cfDone = c => {
		if (!c || !c.f || !c.o) return false;
		if (CF_NOVAL[c.o]) return true;
		if (CF_LIST[c.o]) return Array.isArray(c.v) && c.v.length > 0;
		if (c.o === 'btw') return String(c.v || '') !== '' || String(c.v2 || '') !== '';
		return String(c.v == null ? '' : c.v) !== '';
	};
	const cfCount = () => (S.cf.g || []).reduce((a, g) => a + (g.c || []).filter(cfDone).length, 0);
	/* a szervernek csak a kész feltételek mennek */
	const cfOut = () => { const g = (S.cf.g || []).map(x => ({ t: x.t, m: x.m, c: (x.c || []).filter(cfDone) })).filter(x => x.c.length); return g.length ? JSON.stringify({ m: S.cf.m, g }) : ''; };
	/* a szűrő engedi-e ezt az állapotot (a táblán megnyertre/elvesztettre állított deal ott maradhat) */
	const cfStatusShown = st => (S.cf.g || []).some(g => g.t === 'deal' && (g.c || []).some(c => c.f === 'status' && cfDone(c) && (c.o === 'set' || (c.o === 'in' && c.v.includes(st)) || (c.o === 'nin' && !c.v.includes(st)))));
	const cfView = () => S.view && S.meta ? (S.meta.views || []).find(v => v.id === S.view) : null;
	function cfBtn() {
		const n = cfCount(), v = cfView();
		const lab = v ? esc(v.n) + (S.vdirty ? ' <i class="crm-fdirty" title="Módosítva a mentett nézethez képest">•</i>' : '') : 'Szűrő';
		return '<span class="crm-fwrap"><button type="button" class="crm-fbtn' + (n ? ' on' : '') + '" data-cf title="Szűrő" aria-haspopup="dialog" aria-expanded="' + (!!document.querySelector('.crm-fp')) + '">' + IC.filter + '<span class="crm-flab">' + lab + '</span>' + (n ? '<b class="crm-fcnt">' + n + '</b>' : '') + '</button>' +
			(n ? '<button type="button" class="crm-fclr" data-cfclr title="Szűrő törlése" aria-label="Szűrő törlése">' + IC.x + '</button>' : '') + '</span>';
	}
	function cfRefreshBtn() { const w = S.main && S.main.querySelector('.crm-fwrap'); if (w) { w.outerHTML = cfBtn(); bindCfBtn(S.main); } }
	function bindCfBtn(main) {
		const b = main.querySelector('[data-cf]'); if (b) b.addEventListener('click', () => (document.querySelector('.crm-fp') ? cfClose() : cfOpen()));
		const x = main.querySelector('[data-cfclr]'); if (x) x.addEventListener('click', () => { S.cf = { m: 'all', g: [] }; S.view = ''; S.vdirty = false; cfChanged(true); });
	}
	let cfT = null;
	function cfChanged(redraw, slow) {
		save(); cfRefreshBtn();
		if (redraw) cfDraw();
		clearTimeout(cfT); cfT = setTimeout(() => { S.p = 0; SEL.clear(); load(); }, slow ? 500 : 250);
	}
	function cfTouch() { if (S.view) S.vdirty = true; }
	async function cfMeta() {
		if (S.meta) return S.meta;
		S.meta = await post('cp_cf_meta', {});
		return S.meta;
	}
	function cfFields(t) { const g = S.meta && S.meta.groups.find(x => x.k === t); return g ? g.f : []; }
	function cfField(t, k) { return cfFields(t).find(x => x.k === k) || null; }
	function cfOpts(fd) { if (fd.t === 'user') return Object.keys(USERS).sort((a, b) => (USERS[b].a - USERS[a].a) || USERS[a].n.localeCompare(USERS[b].n, 'hu')).map(i => [i, USERS[i].n + (USERS[i].a ? '' : ' (inaktív)')]); return fd.o || []; }
	async function cfOpen() {
		cfClose();
		const p = document.createElement('aside');
		p.className = 'crm-fp'; p.setAttribute('role', 'dialog'); p.setAttribute('aria-label', 'Szűrő');
		p.innerHTML = '<div class="cpx-empty">Betöltés…</div>';
		document.body.appendChild(p);
		requestAnimationFrame(() => p.classList.add('in'));
		document.addEventListener('keydown', cfKey, true);
		const b = S.main && S.main.querySelector('[data-cf]'); if (b) b.setAttribute('aria-expanded', 'true');
		try { await cfMeta(); } catch (e) { p.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
		cfDraw();
		const f = p.querySelector('select, button'); if (f) f.focus();
	}
	function cfKey(e) { if (e.key === 'Escape' && !e.target.closest('.crm-fp input')) { e.stopPropagation(); cfClose(); } }
	function cfClose() {
		const p = document.querySelector('.crm-fp'); if (!p) return;
		document.removeEventListener('keydown', cfKey, true);
		p.classList.remove('in'); setTimeout(() => p.remove(), 200);
		const b = S.main && S.main.querySelector('[data-cf]'); if (b) { b.setAttribute('aria-expanded', 'false'); b.focus(); }
	}
	function cfValHtml(gi, ci, c, fd) {
		const at = ' data-g="' + gi + '" data-c="' + ci + '"';
		if (!fd || !c.o || CF_NOVAL[c.o]) return '';
		const t = fd.t;
		if (CF_LIST[c.o]) {
			const o = cfOpts(fd), sel = Array.isArray(c.v) ? c.v : [];
			const nm = v => { const x = o.find(y => String(y[0]) === String(v)); return x ? x[1] : v; };
			const chips = sel.map((v, i) => '<span class="crm-fchip">' + esc(nm(v)) + '<button type="button" data-vdel="' + i + '"' + at + ' aria-label="' + esc(nm(v)) + ' eltávolítása">' + IC.x + '</button></span>').join('');
			const rest = o.filter(x => !sel.includes(String(x[0])));
			const add = o.length ? (rest.length ? '<select class="crm-fadd" data-vadd' + at + ' aria-label="Érték hozzáadása"><option value="">' + (sel.length ? '+ még egy…' : 'Válassz…') + '</option>' + rest.map(x => '<option value="' + esc(x[0]) + '">' + esc(x[1]) + '</option>').join('') + '</select>' : '')
				: '<input type="text" data-vtxt' + at + ' placeholder="Érték, majd Enter" aria-label="Érték">';
			return '<div class="crm-fval crm-fchips">' + chips + add + '</div>';
		}
		const inp = (k, type, v, ph) => '<input type="' + type + '" data-v="' + k + '"' + at + ' value="' + esc(v == null ? '' : v) + '"' + (ph ? ' placeholder="' + ph + '"' : '') + ' aria-label="' + (ph || 'Érték') + '">';
		if (CF_DAYS[c.o]) return '<div class="crm-fval crm-frow2">' + inp('v', 'number', c.v, 'nap') + '<span>nap</span></div>';
		if (t === 'date' || t === 'dt') return '<div class="crm-fval crm-frow2">' + inp('v', 'date', c.v, c.o === 'btw' ? 'ettől' : '') + (c.o === 'btw' ? '<span>–</span>' + inp('v2', 'date', c.v2, 'eddig') : '') + '</div>';
		if (t === 'num') return '<div class="crm-fval crm-frow2">' + inp('v', 'number', c.v, c.o === 'btw' ? 'ettől' : 'szám') + (c.o === 'btw' ? '<span>–</span>' + inp('v2', 'number', c.v2, 'eddig') : '') + '</div>';
		return '<div class="crm-fval">' + inp('v', 'text', c.v, 'szöveg') + '</div>';
	}
	function cfDraw() {
		const p = document.querySelector('.crm-fp'); if (!p || !S.meta) return;
		const M = S.meta, G = S.cf.g || [], v = cfView();
		const views = M.views || [];
		let h = '<div class="crm-fp-h"><h3>Szűrő</h3>' + (G.length ? '<button type="button" class="crm-flink" data-fall>Mind törlése</button>' : '') + '<button type="button" class="cpx-x" data-fclose aria-label="Bezárás">×</button></div><div class="crm-fp-b">';
		h += '<div class="crm-fviews"><label class="crm-flabel" for="crm-fvsel">Mentett nézet</label><div class="crm-frow2"><select id="crm-fvsel" data-fview><option value="">— nincs kiválasztva —</option>' +
			(views.filter(x => x.mine).length ? '<optgroup label="Saját">' + views.filter(x => x.mine).map(x => '<option value="' + esc(x.id) + '"' + (x.id === S.view ? ' selected' : '') + '>' + esc(x.n) + (x.sh ? ' (megosztva)' : '') + '</option>').join('') + '</optgroup>' : '') +
			(views.filter(x => !x.mine).length ? '<optgroup label="A csapattól">' + views.filter(x => !x.mine).map(x => '<option value="' + esc(x.id) + '"' + (x.id === S.view ? ' selected' : '') + '>' + esc(x.n) + '</option>').join('') + '</optgroup>' : '') +
			'</select>' + (v && v.mine ? '<button type="button" class="crm-ficon" data-vdelv title="Nézet törlése" aria-label="Nézet törlése">' + IC.trash + '</button>' : '') + '</div>' +
			(cfCount() ? '<div class="crm-fvact">' + (v && v.mine && S.vdirty ? '<button type="button" class="crm-flink" data-vsave="upd">Nézet frissítése</button>' : '') + '<button type="button" class="crm-flink" data-vsave="new">' + (v ? 'Mentés új nézetként' : 'Mentés nézetként') + '</button></div>' : '') +
			'<div class="crm-fvform" hidden><input type="text" maxlength="60" data-vname placeholder="A nézet neve, pl. Jövő heti rendezvények" aria-label="A nézet neve"><label class="crm-chk"><input type="checkbox" data-vsh> Megosztás a csapattal</label><div class="crm-frow2"><button type="button" class="cpx-btn" data-vok>Mentés</button><button type="button" class="crm-flink" data-vcancel>Mégse</button></div></div></div>';
		if (G.length > 1) h += '<label class="crm-flabel" for="crm-fm">A csoportok között</label><select id="crm-fm" class="crm-fmode" data-fm>' + opts([['all', 'Minden csoport teljesüljön (ÉS)'], ['any', 'Bármelyik csoport elég (VAGY)']], S.cf.m) + '</select>';
		G.forEach((g, gi) => {
			const gm = M.groups.find(x => x.k === g.t) || { n: g.t, f: [] };
			h += (gi ? '<div class="crm-fjoin">' + (S.cf.m === 'any' ? 'VAGY' : 'ÉS') + '</div>' : '') + '<section class="crm-fg" data-gi="' + gi + '"><div class="crm-fg-h"><b>' + esc(gm.n) + '</b>' +
				(g.c.length > 1 ? '<span class="crm-seg crm-fseg" role="group" aria-label="A feltételek között"><button type="button" data-gm="and" data-g="' + gi + '" class="' + (g.m !== 'or' ? 'on' : '') + '">ÉS</button><button type="button" data-gm="or" data-g="' + gi + '" class="' + (g.m === 'or' ? 'on' : '') + '">VAGY</button></span>' : '') +
				'<button type="button" class="crm-ficon" data-gdel="' + gi + '" title="Csoport törlése" aria-label="' + esc(gm.n) + ' csoport törlése">' + IC.trash + '</button></div>';
			g.c.forEach((c, ci) => {
				const fd = c.f ? cfField(g.t, c.f) : null;
				const ops = fd ? M.ops[fd.t] || {} : {};
				h += (ci ? '<div class="crm-fcj">' + (g.m === 'or' ? 'vagy' : 'és') + '</div>' : '') + '<div class="crm-fc' + (cfDone(c) ? '' : ' todo') + '"><div class="crm-frow2"><select data-ff data-g="' + gi + '" data-c="' + ci + '" aria-label="Mező"><option value="">Válassz mezőt…</option>' + gm.f.map(x => '<option value="' + esc(x.k) + '"' + (x.k === c.f ? ' selected' : '') + '>' + esc(x.n) + '</option>').join('') + '</select>' +
					'<button type="button" class="crm-ficon" data-cdel data-g="' + gi + '" data-c="' + ci + '" title="Feltétel törlése" aria-label="Feltétel törlése">' + IC.x + '</button></div>' +
					(fd ? '<select data-fo data-g="' + gi + '" data-c="' + ci + '" aria-label="Művelet">' + Object.keys(ops).map(k => '<option value="' + k + '"' + (k === c.o ? ' selected' : '') + '>' + esc(ops[k]) + '</option>').join('') + '</select>' + cfValHtml(gi, ci, c, fd) : '') + '</div>';
			});
			h += '<button type="button" class="crm-flink crm-fplus" data-cadd="' + gi + '">+ ' + esc(gm.n) + '-feltétel</button></section>';
		});
		h += '<select class="crm-fgadd" data-gadd aria-label="Csoport hozzáadása"><option value="">+ Feltételcsoport: Deal, Kontakt, Cég, Teendő…</option>' + M.groups.map(x => '<option value="' + x.k + '">' + esc(x.n) + '</option>').join('') + '</select>';
		h += '<p class="crm-fnote">Állapot-feltétel nélkül csak a nyitott dealek látszanak – a megnyertekhez vagy elvesztettekhez vegyél fel egy Deal → Állapot feltételt.</p></div>';
		const sc = p.querySelector('.crm-fp-b'), top = sc ? sc.scrollTop : 0;
		p.innerHTML = h;
		const nb = p.querySelector('.crm-fp-b'); if (nb) nb.scrollTop = top;
		cfBind(p);
	}
	function cfBind(p) {
		const C0 = el => S.cf.g[+el.dataset.g].c[+el.dataset.c];
		const q = (s, fn, ev) => p.querySelectorAll(s).forEach(el => el.addEventListener(ev || 'click', e => fn(el, e)));
		q('[data-fclose]', cfClose);
		q('[data-fall]', () => { S.cf = { m: 'all', g: [] }; S.view = ''; S.vdirty = false; cfChanged(true); });
		q('[data-fm]', el => { S.cf.m = el.value; cfTouch(); cfChanged(true); }, 'change');
		q('[data-gm]', el => { S.cf.g[+el.dataset.g].m = el.dataset.gm; cfTouch(); cfChanged(true); });
		q('[data-gdel]', el => { S.cf.g.splice(+el.dataset.gdel, 1); cfTouch(); cfChanged(true); });
		q('[data-gadd]', el => { if (!el.value) return; S.cf.g.push({ t: el.value, m: 'and', c: [{ f: '', o: '', v: '' }] }); cfDraw(); const s = p.querySelectorAll('[data-ff]'); if (s.length) s[s.length - 1].focus(); }, 'change');
		q('[data-cadd]', el => { const gi = +el.dataset.cadd; S.cf.g[gi].c.push({ f: '', o: '', v: '' }); cfDraw(); const s = p.querySelectorAll('[data-ff][data-g="' + gi + '"]'); if (s.length) s[s.length - 1].focus(); });
		q('[data-cdel]', el => { const g = S.cf.g[+el.dataset.g]; g.c.splice(+el.dataset.c, 1); if (!g.c.length) S.cf.g.splice(+el.dataset.g, 1); cfTouch(); cfChanged(true); });
		q('[data-ff]', el => { const g = S.cf.g[+el.dataset.g], c = C0(el); c.f = el.value; const fd = cfField(g.t, c.f); c.o = fd ? Object.keys(S.meta.ops[fd.t] || {})[0] || '' : ''; c.v = fd && CF_LIST[c.o] ? [] : ''; c.v2 = ''; cfTouch(); cfChanged(true); }, 'change');
		q('[data-fo]', el => { const c = C0(el), was = !!CF_LIST[c.o]; c.o = el.value; if (!!CF_LIST[c.o] !== was || CF_DAYS[c.o]) { c.v = CF_LIST[c.o] ? [] : ''; c.v2 = ''; } cfTouch(); cfChanged(true); }, 'change');
		q('[data-vadd]', el => { if (!el.value) return; const c = C0(el); c.v = (Array.isArray(c.v) ? c.v : []).concat([el.value]); cfTouch(); cfChanged(true); }, 'change');
		q('[data-vdel]', el => { const c = C0(el); c.v.splice(+el.dataset.vdel, 1); cfTouch(); cfChanged(true); });
		q('[data-vtxt]', (el, e) => { if (e.key !== 'Enter' || !el.value.trim()) return; e.preventDefault(); const c = C0(el); c.v = (Array.isArray(c.v) ? c.v : []).concat([el.value.trim()]); cfTouch(); cfChanged(true); }, 'keydown');
		q('[data-v]', el => { const c = C0(el); c[el.dataset.v] = el.value.trim(); cfTouch(); el.closest('.crm-fc').classList.toggle('todo', !cfDone(c)); cfChanged(false, true); }, 'input');
		q('[data-fview]', el => { const v = (S.meta.views || []).find(x => x.id === el.value); S.view = v ? v.id : ''; S.vdirty = false; if (v) { S.cf = JSON.parse(JSON.stringify(v.f)); if (Array.isArray(v.cols) && v.cols.length) S.cols = v.cols.map(c => ({ k: c.k, w: Number(c.w) || 0 })); if (v.so && v.so.indexOf(':') > 0 && S.mode === 'list') { const x = v.so.split(':'); S.so = x[0]; S.dir = x[1] === 'asc' ? 'asc' : 'desc'; } } cfChanged(true); }, 'change');
		const form = p.querySelector('.crm-fvform');
		let mode = 'new';
		q('[data-vsave]', el => { mode = el.dataset.vsave; const v = cfView(); form.hidden = false; const n = form.querySelector('[data-vname]'); n.value = mode === 'upd' && v ? v.n : ''; form.querySelector('[data-vsh]').checked = mode === 'upd' && v ? v.sh : false; n.focus(); });
		q('[data-vcancel]', () => { form.hidden = true; });
		q('[data-vname]', (el, e) => { if (e.key === 'Enter') { e.preventDefault(); form.querySelector('[data-vok]').click(); } }, 'keydown');
		q('[data-vok]', async el => {
			const n = form.querySelector('[data-vname]').value.trim();
			if (!n) { form.querySelector('[data-vname]').focus(); return; }
			el.disabled = true;
			try {
				const d = await post('cp_cf_view_save', { id: mode === 'upd' ? S.view : '', n, sh: form.querySelector('[data-vsh]').checked ? 1 : '', f: cfOut(), cols: JSON.stringify(S.cols), so: S.mode === 'list' ? S.so + ':' + S.dir : '' });
				S.meta.views = d.views; S.view = d.id; S.vdirty = false; save(); cfRefreshBtn(); cfDraw();
			} catch (e) { el.disabled = false; alert(e.message); }
		});
		q('[data-vdelv]', async () => {
			const v = cfView(); if (!v || !confirm('Törlöd a(z) „' + v.n + '” nézetet? A szűrő itt megmarad.')) return;
			try { const d = await post('cp_cf_view_del', { id: v.id }); S.meta.views = d.views; S.view = ''; S.vdirty = false; save(); cfRefreshBtn(); cfDraw(); } catch (e) { alert(e.message); }
		});
	}

	function view(main) {
		autoStart();
		S.main = main;
		main.innerHTML = '<div class="crm">' + head() + '<div class="crm-body" id="crm-body"><div class="cpx-empty">Betöltés…</div></div></div>';
		bindHead(main);
		load();
		/* a gomb a mentett nézet nevét mutatja – a nézetek a háttérben töltődnek */
		if (S.view && !S.meta) cfMeta().then(cfRefreshBtn).catch(() => {});
		if (document.querySelector('.crm-fp') && S.mode !== 'leads') cfDraw(); else cfClose();
	}
	function bindHead(main) {
		bindCfBtn(main);
		const rs = main.querySelector('[data-rotsw]'); if (rs) rs.addEventListener('click', () => { try { localStorage.setItem('cpx_crm_rot', rotOn() ? '0' : '1'); } catch (e) { /* privát mód */ } view(S.main); });
		main.querySelectorAll('[data-dense]').forEach(b => b.addEventListener('click', () => { S.dense = b.dataset.dense === '1'; main.querySelectorAll('[data-dense]').forEach(x => { x.classList.toggle('on', x === b); x.setAttribute('aria-pressed', x === b); }); save(); redrawBoard(); }));
		const nb = main.querySelector('[data-new]');
		if (nb) nb.addEventListener('click', newDeal);
		const bnb = main.querySelector('[data-bin]'); if (bnb) bnb.addEventListener('click', openBin);
		const cb = main.querySelector('[data-cols]');
		if (cb) cb.addEventListener('click', e => { e.stopPropagation(); colPick(cb); });
		const wb = main.querySelector('[data-who]');
		if (wb) wb.addEventListener('click', e => { e.stopPropagation(); if (!wb.nextElementSibling || !wb.nextElementSibling.classList.contains('d3-wpop')) whoPicker(wb); });
		const wx = main.querySelector('[data-whox]');
		if (wx) wx.addEventListener('click', () => { S.who = null; S.p = 0; SEL.clear(); view(main); });
		main.querySelectorAll('[data-mode]').forEach(b => b.addEventListener('click', () => { if (S.mode === b.dataset.mode) return; S.mode = b.dataset.mode; save(); view(main); }));
		let tq = null;
		main.querySelectorAll('[data-f]').forEach(inp => {
			const k = inp.dataset.f;
			if (k === 'q') { inp.addEventListener('input', () => { clearTimeout(tq); tq = setTimeout(() => { S.q = inp.value.trim(); S.p = 0; SEL.clear(); if (S.mode === 'board' && S.board) redrawBoard(); else load(); }, S.mode === 'board' ? 150 : 300); }); return; }
			if (k === 'lst') { inp.addEventListener('change', () => { S.lst = inp.value; load(); }); return; }
			inp.addEventListener('change', () => { if (k === 'sort') { const x = inp.value.split(':'); S.so = x[0]; S.dir = x[1]; } else { S[k] = inp.value; SEL.clear(); } S.p = 0; save(); if (k === 'bso' && S.board) { redrawBoard(); return; } load(); });
		});
	}

	async function load() {
		const my = ++S.seq;
		S.loading = true; S.err = ''; setStatus();
		const body = () => S.main && S.main.querySelector('#crm-body');
		try {
			if (S.mode === 'leads') {
				const d = await post('cp_lead_list', { q: S.q, st: S.lst || '' });
				if (my !== S.seq) return;
				S.leads = d; S.loading = false;
				if (body()) drawLeads(body());
			} else if (S.mode === 'list') {
				const [d] = await Promise.all([post('cp_crm_list', Object.assign({ q: S.q, cf: cfOut(), so: S.so, dir: S.dir, p: S.p, cols: JSON.stringify(colKeys()) }, S.who ? { [S.who.k]: S.who.id } : {})), cfMeta().catch(() => null)]);
				if (my !== S.seq) return;
				S.list = d;
				S.loading = false;
				if (body()) drawList(body());
			} else {
				const [d, lp] = await Promise.all([post('cp_crm_board', { cf: cfOut() }), post('cp_lead_list', { pending: 1 }).catch(() => null)]);
				if (my !== S.seq) return;
				S.board = d;
				S.pend = lp || { rows: [], fresh: {} };
				S.loading = false;
				if (body()) drawBoard(body());
			}
		} catch (e) {
			if (my !== S.seq) return;
			S.loading = false; S.err = e.message;
			if (body()) { body().innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '<br><button class="cpx-btn" type="button" id="crm-retry">Újra</button></div>'; body().querySelector('#crm-retry').onclick = load; }
		}
		setStatus();
	}

	/* ------------------------------------------------------------ v1.42: megkeresések (a weboldal űrlapjairól, párhuzamosan a Salesmate-tel) */
	const agoLead = s => { const d = (Date.now() - new Date(String(s).replace(' ', 'T')).getTime()) / 60000; return d < 2 ? 'most' : d < 60 ? Math.round(d) + ' perce' : d < 1440 ? Math.round(d / 60) + ' órája' : huDT(s); };
	/* a pipeline-on várakozó megkeresés adatlapja (amíg a Salesmate-deal át nem jön) */
	function openLead(id) {
		const x = ((S.pend && S.pend.rows) || []).find(r => r.id === id);
		if (!x) return;
		const L = S.pend.labels || {}, COLS = S.pend.cols || {};
		const lab = k => L[k] || (COLS[k] && LBL[COLS[k]]) || k.replace(/^(Deal|Contact|Company)\./, '');
		const sh = sheet(sheetHead(esc(x.n || x.e || 'Új megkeresés')) + '<p class="crm-warn" style="background:#FFF7E0;color:#7F5F01">A weboldalról érkezett ' + esc(agoLead(x.at)) + ' (' + esc(x.form || 'űrlap') + '). A Salesmate-ben létrejött deal pár percen belül átjön, és ez a kártya a helyére kerül.</p>' +
			'<dl class="crm-dl">' + (x.e ? '<dt>E-mail</dt><dd><a href="mailto:' + esc(x.e) + '">' + esc(x.e) + '</a></dd>' : '') + (x.p ? '<dt>Telefon</dt><dd><a href="tel:' + esc(String(x.p).replace(/[^\d+]/g, '')) + '">' + esc(x.p) + '</a></dd>' : '') +
			Object.keys(x.f).filter(k => x.f[k] !== '' && !/^Contact\.(firstName|lastName|name|email|mobile|phone)$/.test(k)).map(k => '<dt>' + esc(lab(k)) + '</dt><dd>' + esc(x.f[k]) + '</dd>').join('') + '</dl>');
		return sh;
	}
	function drawLeads(box) {
		const x = S.leads || { rows: [] }, L = x.labels || {}, COLS = x.cols || {};
		const lab = k => L[k] || (COLS[k] && LBL[COLS[k]]) || k.replace(/^(Deal|Contact|Company)\./, '');
		const agoS = s => { const d = (Date.now() - new Date(String(s).replace(' ', 'T')).getTime()) / 60000; return d < 2 ? 'most' : d < 60 ? Math.round(d) + ' perce' : d < 1440 ? Math.round(d / 60) + ' órája' : huDT(s); };
		if (!x.rows.length) { box.innerHTML = '<div class="cpx-empty"><b>Még nincs megkeresés</b><span>Az első beküldött ajánlatkérés pár másodpercen belül itt jelenik meg. A korábbiak a Salesmate-ből a Lista nézetben vannak.</span></div>'; return; }
		box.innerHTML = '<div class="crm-leads">' + x.rows.map(r => {
			const f = Object.keys(r.f).filter(k => r.f[k] !== '' && !/^(Contact\.(firstName|lastName|name|email|mobile|phone))$/.test(k));
			return '<article class="crm-lead' + (r.st === 'new' ? ' new' : '') + '" data-lead="' + r.id + '"><div class="crm-lh"><div><b>' + esc(r.n || r.e || 'Névtelen') + '</b>' + (r.st === 'new' ? ' <span class="crm-lnew">új</span>' : '') +
				'<small>' + esc([r.e, r.p].filter(Boolean).join(' · ')) + '</small></div><div class="crm-lt"><small>' + esc(agoS(r.at)) + '</small><small class="cpx-muted">' + esc(r.form || 'űrlap') + '</small></div></div>' +
				(f.length ? '<dl class="crm-dl crm-ldl">' + f.map(k => '<dt>' + esc(lab(k)) + '</dt><dd>' + esc(r.f[k]) + '</dd>').join('') + '</dl>' : '') +
				'<div class="crm-la">' + (r.deal ? '<button type="button" class="cpx-btn ghost" data-ldeal="' + r.deal.id + '">Deal: ' + esc(r.deal.t) + ' <small>(' + esc(r.deal.stage) + ')</small></button>' : '<span class="cpx-muted crm-lwait">A Salesmate-deal még nem jött át – a szinkron pár percen belül hozza, utána itt összekapcsolódik.</span>') +
				'<span class="crm-bgap"></span>' + (r.st === 'new' ? '<button type="button" class="cpx-btn ghost" data-lst="seen">Láttam</button>' : '') +
				(r.st === 'spam' ? '<button type="button" class="cpx-btn ghost" data-lst="seen">Nem spam</button>' : '<button type="button" class="cpx-btn ghost bad" data-lst="spam">Spam</button>') + '</div></article>';
		}).join('') + '</div>';
		box.querySelectorAll('[data-ldeal]').forEach(b => b.addEventListener('click', () => openDeal(Number(b.dataset.ldeal))));
		box.querySelectorAll('[data-lst]').forEach(b => b.addEventListener('click', async () => {
			const id = Number(b.closest('[data-lead]').dataset.lead);
			try { await post('cp_lead_set', { id: id, st: b.dataset.lst }); load(); } catch (e) { alert(e.message); }
		}));
	}

	/* ------------------------------------------------------------ lista */
	function stBadge(r) { return '<span class="crm-st ' + esc(String(r.status).toLowerCase()) + '">' + esc(ST_HU[r.status] || r.status) + '</span>'; }
	/* v2.9.71: többnapos rendezvény – az első és az utolsó nap („2026. dec. 3. – 12.”); lezajlott, ha az utolsó nap is elmúlt */
	const evEnd = r => r.eve && r.eve > r.ev ? r.eve : '';
	const endTxt = (a, b) => (a.slice(0, 4) !== b.slice(0, 4) ? b.slice(0, 4) + '. ' : '') + (a.slice(0, 7) !== b.slice(0, 7) ? HU_MS[+b.slice(5, 7) - 1] + ' ' : '') + (+b.slice(8, 10)) + '.';
	const evShort = r => shortD(r.ev) + (evEnd(r) ? '–' + endTxt(r.ev, r.eve) : '');
	function evCell(r) {
		if (!r.ev) return '<span class="cpx-muted">–</span>';
		const past = (evEnd(r) || r.ev) < today();
		return '<span class="' + (past ? 'cpx-muted' : '') + '"' + (evEnd(r) ? ' title="Többnapos rendezvény – az első és az utolsó nap"' : '') + '>' + esc(huD(r.ev)) + (evEnd(r) ? ' – ' + esc(endTxt(r.ev, r.eve)) : '') + '</span>';
	}
	function contactCell(r) {
		const a = [];
		if (r.cn) a.push('<b>' + esc(r.cn) + '</b>');
		if (r.co) a.push('<small>' + esc(r.co) + '</small>');
		if (r.ce) a.push('<small>' + esc(r.ce) + '</small>');
		if (r.cp) a.push('<small>' + esc(r.cp) + '</small>');
		return a.join('') || '<span class="cpx-muted">–</span>';
	}
	/* ------------------------------------------------------------ v2.9.54: lista – választható oszlopok, szélesség, rendezés, gyors szerkesztés */
	const COL_SPEC = {
		_contact: { n: 'Kapcsolat', so: 'contact.name', w: 230, cell: r => contactCell(r) },
		_event: { n: 'Rendezvény', so: 'ev', w: 180, cell: r => evCell(r) + (r.venue ? '<small>' + esc(r.venue) + '</small>' : '') },
		_pkg: { n: 'Csomag', so: 'deal.package', w: 170, cell: r => (r.pkg ? esc(r.pkg) : '<span class="cpx-muted">–</span>') + (r.svc ? '<small>' + esc(r.svc) + '</small>' : '') },
		_stage: { n: 'Szakasz', so: 'deal.stage', w: 180, cell: r => esc(r.stage) + '<br>' + stBadge(r) }
	};
	const COL_W = { text: 200, num: 120, date: 150, dt: 150, enum: 170, user: 150, multi: 230, bool: 110 };
	const colFd = k => { if (COL_SPEC[k]) return null; const [g, f] = k.split('.'); return cfField(g, f); };
	const colName = k => { if (COL_SPEC[k]) return COL_SPEC[k].n; const fd = colFd(k); if (!fd) return k; const g = k.split('.')[0]; return (g === 'deal' ? '' : (g === 'contact' ? 'Kontakt: ' : 'Cég: ')) + fd.n; };
	const colW = c => c.w || (COL_SPEC[c.k] ? COL_SPEC[c.k].w : COL_W[(colFd(c.k) || {}).t] || 170);
	const colKeys = () => S.cols.filter(c => !COL_SPEC[c.k]).map(c => c.k);
	function colVal(k, r) {
		const fd = colFd(k), v = r.x ? r.x[k] : undefined;
		const none = '<span class="cpx-muted">–</span>';
		if (!fd) return none;
		if (v == null || v === '') return fd.t === 'bool' ? none : none;
		switch (fd.t) {
			case 'num': return k === 'deal.value' ? esc(money(Number(v), r.cur)) : esc(Number(v) % 1 ? String(Number(v)).replace('.', ',') : num(Number(v)));
			case 'date': return esc(huD(v));
			case 'dt': return esc(huDT(v));
			case 'user': return esc(uname(v));
			case 'bool': return Number(v) ? 'Igen' : none;
			case 'multi': return '<span class="crm-mchips">' + String(v).split(' | ').filter(Boolean).map(x => '<i>' + esc(x) + '</i>').join('') + '</span>';
			default: return '<span class="crm-clamp" title="' + esc(v) + '">' + esc(v) + '</span>';
		}
	}
	function rowHtml(r, i) {
		const tds = S.cols.map(c => {
			const fd = colFd(c.k), ed = W && fd && fd.e && !(c.k.startsWith('contact.') && !r.cn && !r.ce);
			const val = COL_SPEC[c.k] ? COL_SPEC[c.k].cell(r) : colVal(c.k, r);
			const num_ = fd && fd.t === 'num' ? ' crm-num' : '';
			return '<td data-l="' + esc(colName(c.k)) + '" class="crm-xc' + num_ + (ed ? ' crm-ed' : '') + '" data-k="' + esc(c.k) + '">' + val + (ed ? '<button type="button" class="crm-ied" data-ied="' + esc(c.k) + '" aria-label="' + esc(colName(c.k)) + ' szerkesztése" title="Szerkesztés">' + IC.pen + '</button>' : '') + '</td>';
		}).join('');
		return '<tr class="crm-row' + (i % 2 ? ' g1' : '') + (SEL.has(r.id) ? ' sel' : '') + '" data-id="' + r.id + '" tabindex="0">' +
			'<td class="crm-cbc crm-stk0"><input type="checkbox" class="crm-cb" data-cb="' + r.id + '"' + (SEL.has(r.id) ? ' checked' : '') + ' aria-label="Kijelölés"></td>' +
			'<td data-l="Deal" class="crm-stk1"><b>' + (r.ret && r.ret.won ? '<span class="crm-ret" title="' + esc('Visszatérő ügyfél: ' + r.ret.won + ' megnyert rendezvény' + (r.ret.last_ev ? ', utoljára ' + huD(r.ret.last_ev) : '')) + '">🔁</span> ' : '') + esc(shortTitle(r)) + '</b><small class="cpx-muted">#' + r.id + '</small></td>' + tds + '</tr>';
	}
	function thHtml(key, label, so, i) {
		const on = so && S.so === so;
		const sortable = !!so;
		return '<th' + (i != null ? ' data-ci="' + i + '"' : '') + (on ? ' aria-sort="' + (S.dir === 'asc' ? 'ascending' : 'descending') + '"' : '') + '>' + (sortable ? '<button type="button" class="crm-sort' + (on ? ' on' : '') + '" data-so="' + esc(so) + '" title="Rendezés: ' + esc(label) + '">' + esc(label) + (on ? (S.dir === 'asc' ? ' ▲' : ' ▼') : '') + '</button>' : esc(label)) +
			(i != null ? '<span class="crm-rsz" data-rsz="' + i + '" aria-hidden="true"></span>' : '') + '</th>';
	}
	function drawList(box) {
		const d = S.list;
		const by = d.by || {};
		const sum = Object.keys(by).map(k => (ST_HU[k] || k) + ': ' + num(by[k].n) + (by[k].v ? ' · ' + money(by[k].v) : '')).join(' · ');
		let h = '<div class="crm-sum"><b>' + num(d.total) + ' deal</b>' + (sum ? '<span class="cpx-muted"> – ' + esc(sum) + '</span>' : '') + '</div>';
		/* v2.9.181: mobilon a lista nézetben is van „+ Új deal” gomb (eddig csak a pipeline-on) */
		const listFab = () => { const f = box.querySelector('[data-fab]'); if (f) f.addEventListener('click', () => newDeal()); };
		if (!d.rows.length) { box.innerHTML = h + '<div class="cpx-empty">Nincs a szűrésnek megfelelő deal.</div>' + (R.edit ? '<button type="button" class="crm-fab" data-fab aria-label="Új deal" title="Új deal">+</button>' : ''); listFab(); return; }
		h += '<div class="crm-bulk" id="crm-bulk" hidden></div>';
		const ws = [44, S.tw || 300].concat(S.cols.map(colW));
		h += '<div class="cpx-trk-wrap crm-wrap"><table class="cpx-trk crm-tbl crm-xt" style="width:' + ws.reduce((a, b) => a + b, 0) + 'px"><colgroup>' + ws.map(w => '<col style="width:' + w + 'px">').join('') + '</colgroup><thead><tr><th class="crm-cbh crm-stk0"><input type="checkbox" class="crm-cb" data-all aria-label="Az oldal összes deal-jének kijelölése"></th>' +
			thHtml('title', 'Deal', 'title', -1).replace('<th', '<th class="crm-stk1"') + S.cols.map((c, i) => thHtml(c.k, colName(c.k), COL_SPEC[c.k] ? COL_SPEC[c.k].so : c.k, i)).join('') + '</tr></thead><tbody>';
		d.rows.forEach((r, i) => { h += rowHtml(r, i); });
		h += '</tbody></table></div>';
		const pages = Math.ceil(d.total / d.n);
		if (pages > 1) {
			h += '<div class="crm-pager"><button class="cpx-btn ghost" type="button" data-pg="-1"' + (d.p <= 0 ? ' disabled' : '') + '>‹ Előző</button><span>' + (d.p + 1) + ' / ' + pages + ' oldal</span><button class="cpx-btn ghost" type="button" data-pg="1"' + (d.p + 1 >= pages ? ' disabled' : '') + '>Következő ›</button></div>';
		}
		box.innerHTML = h + (R.edit ? '<button type="button" class="crm-fab" data-fab aria-label="Új deal" title="Új deal">+</button>' : '');
		listFab();
		box.querySelectorAll('[data-so]').forEach(b => b.addEventListener('click', () => {
			const k = b.dataset.so;
			if (S.so === k) S.dir = S.dir === 'asc' ? 'desc' : 'asc';
			else { S.so = k; const fd = colFd(k); S.dir = k === 'title' || k === 'ev' || (fd && (fd.t === 'text' || fd.t === 'enum' || fd.t === 'date')) || k === 'contact.name' || k === 'deal.stage' || k === 'deal.package' ? 'asc' : 'desc'; }
			S.p = 0; save(); syncSortSel(); load();
		}));
		box.querySelectorAll('[data-pg]').forEach(b => b.addEventListener('click', () => { S.p = Math.max(0, S.p + Number(b.dataset.pg)); load(); box.scrollIntoView({ block: 'start' }); }));
		bindResize(box);
		bindEdit(box);
		bindOpen(box);
		bindSel(box);
	}
	/* a sáv rendező legördülője: ha a fejlécből egy mezőre rendeztél, az is ott látszik */
	function syncSortSel() {
		const s = S.main && S.main.querySelector('select[data-f="sort"]');
		if (!s) return;
		const v = S.so + ':' + S.dir;
		if (![...s.options].some(o => o.value === v)) { const o = document.createElement('option'); o.value = v; o.textContent = (S.so === 'title' ? 'Deal' : S.so === 'ev' ? 'Rendezvény' : colName(S.so)) + (S.dir === 'asc' ? ' ▲' : ' ▼'); s.appendChild(o); }
		s.value = v;
	}
	/* oszlopszélesség: a fejléc jobb szélét húzva (a Deal-oszlopé is) */
	function bindResize(box) {
		box.querySelectorAll('[data-rsz]').forEach(hd => hd.addEventListener('pointerdown', e => {
			e.preventDefault(); e.stopPropagation();
			const i = Number(hd.dataset.rsz), th = hd.closest('th'), tbl = th.closest('table');
			const cols = tbl.querySelectorAll('col'), col = cols[i + 2];
			const x0 = e.clientX, w0 = th.getBoundingClientRect().width, t0 = tbl.getBoundingClientRect().width;
			hd.setPointerCapture(e.pointerId); th.classList.add('rsz');
			const mv = ev => { const w = Math.max(80, Math.min(800, Math.round(w0 + ev.clientX - x0))); col.style.width = w + 'px'; tbl.style.width = (t0 + w - w0) + 'px'; };
			const up = ev => {
				hd.removeEventListener('pointermove', mv); hd.removeEventListener('pointerup', up); th.classList.remove('rsz');
				const w = Math.max(80, Math.min(800, Math.round(w0 + ev.clientX - x0)));
				if (i < 0) S.tw = w; else S.cols[i].w = w;
				cfTouch(); save();
			};
			hd.addEventListener('pointermove', mv); hd.addEventListener('pointerup', up);
		}));
		box.querySelectorAll('[data-rsz]').forEach(hd => hd.addEventListener('click', e => e.stopPropagation()));
	}
	/* gyors szerkesztés: ceruza → a mező típusához illő szerkesztő; Enter ment, Esc elvet */
	function bindEdit(box) {
		box.querySelectorAll('[data-ied]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); editCell(b.closest('td')); }));
		box.querySelectorAll('td.crm-ed').forEach(td => td.addEventListener('dblclick', e => { e.stopPropagation(); editCell(td); }));
	}
	function editCell(td) {
		if (!td || td.querySelector('.crm-ie')) return;
		const tr = td.closest('tr'), id = Number(tr.dataset.id), k = td.dataset.k, fd = colFd(k);
		const r = ((S.list && S.list.rows) || []).find(x => x.id === id);
		if (!r || !fd || !fd.e) return;
		const cur = r.x ? (r.x[k] == null ? '' : String(r.x[k])) : '';
		const et = fd.e;
		const opts = fd.o || [];
		let inp = '';
		if (et === 'user') inp = '<select data-iv>' + '<option value="">— nincs —</option>' + cfOpts(fd).map(o => '<option value="' + esc(o[0]) + '"' + (String(o[0]) === cur ? ' selected' : '') + '>' + esc(o[1]) + '</option>').join('') + '</select>';
		else if (et === 'multi') { const sel = cur.split(' | ').filter(Boolean); const all = Array.from(new Set(opts.map(o => String(o[0])).concat(sel))); inp = '<div class="crm-iem" role="group" aria-label="' + esc(fd.n) + '">' + all.map(v => '<label><input type="checkbox" value="' + esc(v) + '"' + (sel.includes(v) ? ' checked' : '') + '> ' + esc(v) + '</label>').join('') + '</div>'; }
		else if (et === 'date') inp = '<input type="date" data-iv value="' + esc(cur.slice(0, 10)) + '">';
		else if (et === 'time') inp = '<input type="time" data-iv value="' + esc(cur.slice(11, 16)) + '">';
		else if (et === 'int' || et === 'dec') inp = '<input type="number" step="' + (et === 'int' ? '1' : 'any') + '" data-iv value="' + esc(cur === '' ? '' : String(Number(cur))) + '">';
		else if (fd.t === 'text' && (k === 'deal.note' || k === 'deal.description')) inp = '<textarea data-iv rows="4">' + esc(cur) + '</textarea>';
		else { const dl = opts.length ? 'crm-dl-' + id + '-' + k.replace(/\W/g, '') : ''; inp = '<input type="' + (k === 'contact.email' ? 'email' : 'text') + '" data-iv value="' + esc(cur) + '"' + (dl ? ' list="' + dl + '"' : '') + '>' + (dl ? '<datalist id="' + dl + '">' + opts.slice(0, 80).map(o => '<option value="' + esc(o[0]) + '">').join('') + '</datalist>' : ''); }
		const ed = document.createElement('div');
		ed.className = 'crm-ie' + (et === 'multi' || fd.t === 'text' && k === 'deal.note' ? ' wide' : '');
		ed.innerHTML = inp + '<div class="crm-iebar"><button type="button" class="cpx-btn" data-iok>Mentés</button><button type="button" class="crm-flink" data-ix>Mégse</button><small>' + (et === 'multi' ? '' : (fd.t === 'text' && k === 'deal.note' ? 'Ctrl+Enter: mentés · ' : 'Enter: mentés · ') + 'Esc: mégse') + '</small></div>';
		td.appendChild(ed); td.classList.add('editing');
		const f = ed.querySelector('[data-iv], input'); if (f) { f.focus(); if (f.select && f.type === 'text') f.select(); }
		const val = () => et === 'multi' ? [...ed.querySelectorAll('input:checked')].map(x => x.value).join(',') : (ed.querySelector('[data-iv]').value || '').trim();
		const close = () => { ed.remove(); td.classList.remove('editing'); document.removeEventListener('pointerdown', out, true); };
		const out = e => { if (!ed.contains(e.target)) close(); };
		setTimeout(() => document.addEventListener('pointerdown', out, true), 0);
		ed.addEventListener('click', e => e.stopPropagation());
		ed.addEventListener('keydown', e => {
			e.stopPropagation();
			if (e.key === 'Escape') { e.preventDefault(); close(); td.focus && tr.focus(); }
			if (e.key === 'Enter' && (e.target.tagName !== 'TEXTAREA' || e.ctrlKey || e.metaKey) && e.target.type !== 'checkbox') { e.preventDefault(); ed.querySelector('[data-iok]').click(); }
		});
		ed.querySelector('[data-ix]').addEventListener('click', close);
		ed.querySelector('[data-iok]').addEventListener('click', async ev => {
			const v = val(), was = et === 'multi' ? cur.split(' | ').filter(Boolean).join(',') : (et === 'time' ? cur.slice(11, 16) : et === 'date' ? cur.slice(0, 10) : (et === 'int' || et === 'dec') && cur !== '' ? String(Number(cur)) : cur);
			if (v === was) { close(); return; }
			const [g, fk] = k.split('.');
			if (fk === 'title' && !v) { toast('A deal neve nem lehet üres.', true); return; }
			const b = ev.currentTarget; b.disabled = true; b.textContent = 'Mentés…';
			try {
				const res = await post('cp_crm_save', { id, f: JSON.stringify(g === 'deal' ? { [fk]: v } : {}), c: JSON.stringify(g === 'contact' ? { [fk]: v } : {}), cols: JSON.stringify(colKeys()) });
				const i = S.list.rows.findIndex(x => x.id === id);
				if (res.row && i >= 0) { S.list.rows[i] = Object.assign({}, S.list.rows[i], res.row); }
				close();
				const tmp = document.createElement('tbody'); tmp.innerHTML = rowHtml(S.list.rows[i], i);
				const nr = tmp.firstElementChild; tr.replaceWith(nr);
				bindEdit(nr); bindOpenRow(nr); bindSelRow(nr);
				nr.querySelector('td[data-k="' + CSS.escape(k) + '"]').classList.add('crm-flash');
				toast(res.warn ? 'Elmentve, de: ' + res.warn : 'Elmentve – a Salesmate-be is.', !!res.warn);
			} catch (e) { b.disabled = false; b.textContent = 'Mentés'; toast(e.message, true); }
		});
	}
	function bindOpenRow(r) {
		r.addEventListener('click', e => { if (e.target.closest('a, .crm-cbc, .crm-cb, .crm-qnb, .crm-qn, .crm-ied, .crm-ie, .crm-rsz')) return; openDeal(Number(r.dataset.id)); });
		r.addEventListener('keydown', e => { if (e.key === 'Enter' && !e.target.closest('.crm-qn, .crm-ie')) openDeal(Number(r.dataset.id)); });
	}
	function bindSelRow(tr) {
		const c = tr.querySelector('[data-cb]'); if (!c) return;
		c.addEventListener('change', () => { const id = Number(c.dataset.cb); const r = S.list.rows.find(x => x.id === id); if (c.checked && r) SEL.set(id, r); else SEL.delete(id); tr.classList.toggle('sel', c.checked); const box = S.main.querySelector('#crm-body'); if (box) drawBulk(box); });
	}

	/* ------------------------------------------------------------ oszlopválasztó (a lista fejlécében) */
	function colPick(btn) {
		const old = document.querySelector('.crm-cpop'); if (old) { old.remove(); btn.setAttribute('aria-expanded', 'false'); return; }
		const pop = document.createElement('div');
		pop.className = 'crm-cpop'; pop.setAttribute('role', 'dialog'); pop.setAttribute('aria-label', 'Oszlopok');
		btn.parentNode.appendChild(pop); btn.setAttribute('aria-expanded', 'true');
		let q = '';
		let tl = null;
		const apply = (reload) => { save(); cfTouch(); cfRefreshBtn(); clearTimeout(tl); if (reload) tl = setTimeout(load, 350); else if (S.list) drawList(S.main.querySelector('#crm-body')); };
		const draw = () => {
			if (!S.meta) { pop.innerHTML = '<div class="cpx-empty">Betöltés…</div>'; return; }
			const on = new Set(S.cols.map(c => c.k));
			const qq = q.toLowerCase();
			const groups = S.meta.groups.filter(g => g.k !== 'activity');
			let h = '<div class="crm-cp-h"><b>Oszlopok</b><button type="button" class="crm-flink" data-cdef>Alapértelmezés</button><button type="button" class="cpx-x" data-cclose aria-label="Bezárás">×</button></div>' +
				'<p class="crm-cp-s">Látható (húzd a sorrendhez)</p><ol class="crm-cp-on">' + S.cols.map((c, i) => '<li draggable="true" data-ci="' + i + '"><span class="crm-cp-grip" aria-hidden="true">' + IC.grip + '</span><span class="crm-cp-n">' + esc(colName(c.k)) + '</span>' +
					'<button type="button" class="crm-ficon" data-cup="' + i + '"' + (i ? '' : ' disabled') + ' aria-label="' + esc(colName(c.k)) + ' feljebb">' + IC.up + '</button><button type="button" class="crm-ficon" data-cdn="' + i + '"' + (i < S.cols.length - 1 ? '' : ' disabled') + ' aria-label="' + esc(colName(c.k)) + ' lejjebb">' + IC.down + '</button><button type="button" class="crm-ficon" data-crm="' + i + '" aria-label="' + esc(colName(c.k)) + ' elrejtése">' + IC.x + '</button></li>').join('') + '</ol>' +
				'<input type="search" class="crm-cp-q" data-cq placeholder="Mező keresése…" aria-label="Mező keresése" value="' + esc(q) + '"><div class="crm-cp-all">';
			const specs = Object.keys(COL_SPEC).filter(k => !on.has(k) && (!qq || COL_SPEC[k].n.toLowerCase().includes(qq)));
			if (specs.length) h += '<p class="crm-cp-s">Összevont</p>' + specs.map(k => '<button type="button" class="crm-cp-add" data-cadd="' + k + '">+ ' + esc(COL_SPEC[k].n) + '</button>').join('');
			groups.forEach(g => {
				const fs = g.f.filter(f => !on.has(g.k + '.' + f.k) && !(g.k === 'deal' && f.k === 'title') && (!qq || f.n.toLowerCase().includes(qq)));
				if (fs.length) h += '<p class="crm-cp-s">' + esc(g.n) + '</p>' + fs.map(f => '<button type="button" class="crm-cp-add" data-cadd="' + g.k + '.' + f.k + '">+ ' + esc(f.n) + (f.e && W ? ' <i title="Szerkeszthető a listában">' + IC.pen + '</i>' : '') + '</button>').join('');
			});
			h += '</div>';
			pop.innerHTML = h;
			const qi = pop.querySelector('[data-cq]'); qi.addEventListener('input', () => { q = qi.value; const p = qi.selectionStart; draw(); const n = pop.querySelector('[data-cq]'); n.focus(); n.setSelectionRange(p, p); });
			pop.querySelector('[data-cclose]').addEventListener('click', () => { pop.remove(); btn.setAttribute('aria-expanded', 'false'); btn.focus(); });
			pop.querySelector('[data-cdef]').addEventListener('click', () => { S.cols = COL_DEF_INIT(); S.tw = 0; draw(); apply(true); });
			pop.querySelectorAll('[data-cadd]').forEach(b => b.addEventListener('click', () => { if (S.cols.length >= 30) { toast('Legfeljebb 30 oszlop lehet.', true); return; } S.cols.push({ k: b.dataset.cadd }); draw(); apply(!COL_SPEC[b.dataset.cadd]); }));
			pop.querySelectorAll('[data-crm]').forEach(b => b.addEventListener('click', () => { S.cols.splice(Number(b.dataset.crm), 1); draw(); apply(false); }));
			const mv = (i, j) => { if (j < 0 || j >= S.cols.length) return; const [x] = S.cols.splice(i, 1); S.cols.splice(j, 0, x); draw(); apply(false); const f = pop.querySelector('[data-ci="' + j + '"] [data-' + (j > i ? 'cdn' : 'cup') + ']'); if (f && !f.disabled) f.focus(); };
			pop.querySelectorAll('[data-cup]').forEach(b => b.addEventListener('click', () => mv(Number(b.dataset.cup), Number(b.dataset.cup) - 1)));
			pop.querySelectorAll('[data-cdn]').forEach(b => b.addEventListener('click', () => mv(Number(b.dataset.cdn), Number(b.dataset.cdn) + 1)));
			let dragI = -1;
			pop.querySelectorAll('.crm-cp-on li').forEach(li => {
				li.addEventListener('dragstart', e => { dragI = Number(li.dataset.ci); li.classList.add('drag'); e.dataTransfer.effectAllowed = 'move'; try { e.dataTransfer.setData('text/plain', String(dragI)); } catch (x) { /* régi böngésző */ } });
				li.addEventListener('dragend', () => li.classList.remove('drag'));
				li.addEventListener('dragover', e => { e.preventDefault(); li.classList.add('over'); });
				li.addEventListener('dragleave', () => li.classList.remove('over'));
				li.addEventListener('drop', e => { e.preventDefault(); li.classList.remove('over'); const j = Number(li.dataset.ci); if (dragI >= 0 && dragI !== j) mv(dragI, j); });
			});
		};
		draw();
		if (!S.meta) cfMeta().then(draw).catch(e => { pop.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; });
		const outside = e => { if (!pop.isConnected) { document.removeEventListener('pointerdown', outside, true); return; } if (!pop.contains(e.target) && e.target !== btn && !btn.contains(e.target)) { pop.remove(); btn.setAttribute('aria-expanded', 'false'); document.removeEventListener('pointerdown', outside, true); } };
		setTimeout(() => document.addEventListener('pointerdown', outside, true), 0);
		pop.addEventListener('keydown', e => { if (e.key === 'Escape') { e.stopPropagation(); pop.remove(); btn.setAttribute('aria-expanded', 'false'); btn.focus(); } });
	}
	function bindOpen(box) { box.querySelectorAll('[data-id]').forEach(bindOpenRow); }

	/* ------------------------------------------------------------ kijelölés és tömeges műveletek (lista) */
	function bindSel(box) {
		const rows = (S.list && S.list.rows) || [];
		const all = box.querySelector('[data-all]');
		const sync = () => {
			const on = rows.filter(r => SEL.has(r.id)).length;
			if (all) { all.checked = on > 0 && on === rows.length; all.indeterminate = on > 0 && on < rows.length; }
			box.querySelectorAll('[data-cb]').forEach(c => { const id = Number(c.dataset.cb); c.checked = SEL.has(id); c.closest('tr').classList.toggle('sel', SEL.has(id)); });
			drawBulk(box);
		};
		box.querySelectorAll('.crm-cbc').forEach(td => td.addEventListener('click', e => { if (e.target === td) { const c = td.querySelector('.crm-cb'); c.click(); } }));
		box.querySelectorAll('[data-cb]').forEach(c => c.addEventListener('change', () => { const id = Number(c.dataset.cb); const r = rows.find(x => x.id === id); if (c.checked && r) SEL.set(id, r); else SEL.delete(id); sync(); }));
		if (all) all.addEventListener('change', () => { rows.forEach(r => { if (all.checked) SEL.set(r.id, r); else SEL.delete(r.id); }); sync(); });
		sync();
	}
	function drawBulk(box) {
		const bar = box.querySelector('#crm-bulk');
		if (!bar) return;
		const n = SEL.size;
		bar.hidden = !n;
		if (!n) { bar.innerHTML = ''; return; }
		const onPage = ((S.list && S.list.rows) || []).filter(r => SEL.has(r.id)).length;
		bar.innerHTML = '<button type="button" class="crm-bx" data-b="clear" aria-label="Kijelölés törlése">×</button><b>' + n + ' kijelölve</b>' + (onPage < n ? '<small class="cpx-muted">(' + (n - onPage) + ' másik oldalon)</small>' : '') +
			'<span class="crm-bgap"></span>' +
			'<button type="button" class="cpx-btn ghost" data-b="mail">E-mail</button>' +
			(W ? '<button type="button" class="cpx-btn ghost" data-b="stage">Szakasz</button><button type="button" class="cpx-btn ghost" data-b="set">Módosítás</button><button type="button" class="cpx-btn ghost" data-b="won">Megnyert</button><button type="button" class="cpx-btn ghost bad" data-b="lost">Elvesztett</button>' : '') +
			'<button type="button" class="cpx-btn ghost" data-b="csv">Export</button>' +
			(W ? '<button type="button" class="cpx-btn crm-del" data-b="del">Törlés</button>' : '');
		bar.querySelectorAll('[data-b]').forEach(b => b.addEventListener('click', () => bulk(b.dataset.b)));
	}
	const selRows = () => Array.from(SEL.values());
	async function bulk(act) {
		const rows = selRows();
		if (act === 'clear') { SEL.clear(); if (S.list) drawList(S.main.querySelector('#crm-body')); return; }
		if (act === 'csv') return exportCsv(rows);
		if (act === 'mail') {
			const em = Array.from(new Set(rows.map(r => r.ce).filter(Boolean)));
			if (!em.length) { toast('A kijelölt deal-ek kontaktjainak nincs e-mail-címe.', true); return; }
			if (em.length < rows.length) toast((rows.length - em.length) + ' kijelölt deal-nél nincs e-mail-cím – kimaradnak.');
			location.href = 'mailto:?bcc=' + em.map(encodeURIComponent).join(',');
			return;
		}
		if (act === 'stage') return dialog('Szakasz – ' + rows.length + ' deal', '<select data-v>' + opts(STAGES.map(x => [x, x]), '') + '</select>', v => runBulk(rows, 'a(z) „' + v + '” szakaszba helyezés', r => post('cp_crm_move', { id: r.id, to: v })));
		if (act === 'won') return confirmRun(rows, 'megnyertnek jelölés', r => post('cp_crm_move', { id: r.id, st: 'Won' }));
		if (act === 'lost') {
			/* v2.9.172: az elvesztés oka választólistából (kötelező) */
			return lostWhy(rows.length + ' deal elvesztettnek jelölése').then(w => { if (w === null) return; return runBulk(rows, 'elvesztettnek jelölés', r => post('cp_crm_move', { id: r.id, st: 'Lost', why: w })); });
		}
		if (act === 'set') {
			const F = R.bulk || {};
			const keys = Object.keys(F);
			if (!keys.length) { toast('Nincs tömegesen írható mező.', true); return; }
			return dialog('Módosítás – ' + rows.length + ' deal', '<label>Mező<select data-f>' + opts(keys.map(k => [k, F[k][0]]), keys[0]) + '</select></label><label>Új érték<span data-vbox></span></label>', (v, f) => runBulk(rows, '„' + F[f][0] + '” → „' + (F[f][1] === 'user' ? uname(v) : v) + '”', r => post('cp_crm_set', { id: r.id, f: f, v: v })), async (sh) => {
				const fs = sh.querySelector('[data-f]'), vb = sh.querySelector('[data-vbox]');
				const fill = async () => {
					const f = fs.value;
					if (F[f][1] === 'user') { vb.innerHTML = '<select data-v>' + opts(Object.keys(USERS).filter(i => USERS[i].a).map(i => [i, USERS[i].n]), '') + '</select>'; return; }
					vb.innerHTML = '<input data-v list="crm-vals" placeholder="írd be, vagy válassz"><datalist id="crm-vals"></datalist>';
					try { const x = await post('cp_crm_values', { f: f }); vb.querySelector('datalist').innerHTML = (x.vals || []).map(v => '<option value="' + esc(v) + '">').join(''); } catch (e) { /* nem kötelező */ }
				};
				fs.addEventListener('change', fill); await fill();
			});
		}
		if (act === 'del') {
			return window.CPXAsk({ title: rows.length + ' deal törlése', text: 'A deal-ek a Salesmate-ből is törlődnek, velük az ügyfélportáljuk (fájlokkal) és a naptárbejegyzésük; a levélszálaik a Kukába kerülnek.\n30 napig visszaállíthatók a Lomtárból (a Salesmate-ben új dealként).', type: 'TÖRLÉS', ok: 'Törlés', danger: true })
				.then(ok => ok ? runBulk(rows, 'törlés', r => post('cp_crm_delete', { id: r.id, ok: 'TÖRLÉS' })) : undefined);
		}
	}
	function confirmRun(rows, what, fn) {
		return window.CPXConfirm(rows.length + ' deal: ' + what + '?\n\nA Salesmate-ben is átírom, és a Salesmate automatizmusai mindegyiknél lefutnak (pl. e-mail az ügyfélnek).', { title: 'Tömeges művelet', ok: 'Mehet' }).then(ok => ok ? runBulk(rows, what, fn) : undefined);
	}
	/* kis párbeszédablak a műveletsáv alatt; ok(v, f) a kiválasztott értékkel */
	function dialog(title, body, ok, init) {
		const box = S.main.querySelector('#crm-bulk');
		let dl = S.main.querySelector('.crm-dlg');
		if (dl) dl.remove();
		dl = document.createElement('div'); dl.className = 'crm-dlg';
		dl.innerHTML = '<b>' + esc(title) + '</b>' + body + '<span class="crm-dlg-b"><button type="button" class="cpx-btn" data-ok>Alkalmaz</button><button type="button" class="cpx-btn ghost" data-no>Mégse</button></span>';
		box.after(dl);
		if (init) init(dl);
		dl.querySelector('[data-no]').onclick = () => dl.remove();
		dl.querySelector('[data-ok]').onclick = async () => {
			const v = (dl.querySelector('[data-v]') || {}).value, f = (dl.querySelector('[data-f]') || {}).value;
			if (v == null || String(v).trim() === '') { toast('Adj meg egy értéket.', true); return; }
			const fa = (R.bulk || {})[f];
			if (f && !(await window.CPXConfirm(SEL.size + ' deal: ' + (fa ? '„' + fa[0] + '” mező' : 'módosítás') + ' → „' + (fa && fa[1] === 'user' ? uname(v) : v) + '”?\n\nA Salesmate-be is beírom (a Salesmate automatizmusai lefuthatnak).', { title: 'Tömeges módosítás', ok: 'Mehet' }))) return;
			if (!f && !(await window.CPXConfirm(SEL.size + ' deal áthelyezése: „' + v + '”?\n\nA Salesmate-ben is átírom, és a Salesmate automatizmusai mindegyiknél lefutnak (pl. e-mail az ügyfélnek).', { title: 'Tömeges áthelyezés', ok: 'Mehet' }))) return;
			dl.remove(); ok(String(v).trim(), f);
		};
	}
	/* deal-enként egymás után; haladás a műveletsávban, a végén összegzés */
	async function runBulk(rows, what, fn) {
		const bar = S.main.querySelector('#crm-bulk');
		let ok = 0; const bad = [];
		for (let i = 0; i < rows.length; i++) {
			if (bar) bar.innerHTML = '<b>' + esc(what) + ': ' + (i + 1) + ' / ' + rows.length + '…</b><span class="crm-prog"><i style="width:' + Math.round(i / rows.length * 100) + '%"></i></span>';
			try { await fn(rows[i]); ok++; SEL.delete(rows[i].id); }
			catch (e) { bad.push(shortTitle(rows[i]) + ': ' + e.message); }
		}
		toast(bad.length ? ok + ' sikerült, ' + bad.length + ' nem – az utóbbiak kijelölve maradtak. ' + bad[0] : 'Kész: ' + ok + ' deal (' + what + ').', !!bad.length);
		load();
	}
	function exportCsv(rows) {
		const H = ['Azonosító', 'Deal', 'Kapcsolat', 'E-mail', 'Telefon', 'Cég', 'Rendezvény dátuma', 'Helyszín', 'Csomag', 'Szolgáltatás', 'Szakasz', 'Állapot', 'Érték', 'Pénznem', 'Felelős', 'Létrehozva', 'Módosítva'];
		const q = v => { const t = v == null ? '' : String(v); return /[";\n]/.test(t) ? '"' + t.replace(/"/g, '""') + '"' : t; };
		const L = rows.map(r => [r.id, r.title, r.cn, r.ce, r.cp, r.co, r.ev, r.venue, r.pkg, r.svc, r.stage, ST_HU[r.status] || r.status, r.value == null ? '' : String(r.value).replace('.', ','), r.cur, uname(r.own), r.cr, r.mod].map(q).join(';'));
		const blob = new Blob(['\ufeff' + [H.join(';')].concat(L).join('\r\n')], { type: 'text/csv;charset=utf-8' });
		const a = document.createElement('a');
		a.href = URL.createObjectURL(blob); a.download = 'dealek-' + today() + '.csv';
		document.body.appendChild(a); a.click(); setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 500);
		toast(rows.length + ' deal exportálva.');
	}

	/* ------------------------------------------------------------ pipeline-tábla */
	function drawBoard(box) {
		const all = S.board.rows || [];
		const now = (S.board && S.board.now) || '';
		const rows = all.filter(r => !S.q || hit(r, S.q));
		const col = {};
		STAGES.forEach(s => { col[s] = []; });
		rows.forEach(r => { (col[r.stage] = col[r.stage] || []).push(r); });
		const SORT = {
			ev: (a, b) => (a.ev || '9999') .localeCompare(b.ev || '9999') || b.id - a.id,
			val: (a, b) => (b.value || 0) - (a.value || 0) || b.id - a.id,
			title: (a, b) => shortTitle(a).replace(/^\[[^\]]*\]\s*/, '').localeCompare(shortTitle(b).replace(/^\[[^\]]*\]\s*/, ''), 'hu'),
			mod: (a, b) => (b.mod || '').localeCompare(a.mod || ''),
			rot: (a, b) => (a.rot || '9999').localeCompare(b.rot || '9999')
		};
		Object.keys(col).forEach(k => col[k].sort(SORT[S.bso] || SORT.ev));
		const names = STAGES.concat(Object.keys(col).filter(s => STAGES.indexOf(s) < 0));
		const t = today();
		/* v1.66: mobilon a Salesmate mintájára – oszloponként lapozható, nincs összecsukás, részletes kártya */
		const MOB = window.matchMedia && window.matchMedia('(max-width:760px)').matches;
		const prog = i => '<span class="crm-prog" aria-hidden="true">' + names.map((x, j) => '<i class="' + (j === i ? 'on' : '') + '"></i>').join('') + '</span>';
		let h = '<div class="crm-sumrow"><div class="crm-sum"><b>' + num(rows.length) + ' deal</b>' + (S.q ? '<span class="cpx-muted"> (szűrve, összesen ' + num(all.length) + ')</span>' : '') + (S.board.more ? '<span class="crm-more" title="A táblán legfeljebb 2000 deal fér el – szűkítsd a szűrőt (pl. rendezvény dátuma)">csak az első 2000</span>' : '') + '<span class="cpx-muted"> – ' + esc(money(rows.reduce((a, r) => a + (r.value || 0), 0))) + '</span></div>' +
			'<div class="crm-arr"><button type="button" data-arr="-1" aria-label="Balra">‹</button><button type="button" data-arr="1" aria-label="Jobbra">›</button></div></div><div class="crm-board' + (S.dense ? ' dense' : '') + '">';
		names.forEach(s => {
			const l = col[s] || [];
			const v = l.reduce((a, r) => a + (r.value || 0), 0);
			/* v1.82.2: az ajánlat előtti dealek becsült ára külön összegezve (~), a tényleges értékekhez nem adódik hozzá */
			const ve = l.reduce((a, r) => a + (!(r.value > 0) && r.est ? r.est.p : 0), 0), nve = l.filter(r => !(r.value > 0) && r.est).length;
			const veH = ve ? '<span class="crm-est" title="' + esc('Becsült összeg ' + nve + ' ajánlat előtti dealre (a kalkulátor alapján) – a tényleges értékekhez nem adódik hozzá') + '">~' + esc(money(ve, 'HUF')) + '</span>' : '';
			if (S.fold.includes(s) && !MOB) {
				h += '<section class="crm-col folded" data-stage="' + esc(s) + '"><button type="button" class="crm-unfold" data-fold="' + esc(s) + '" title="Oszlop kinyitása"><span>' + esc(s) + '</span><b>' + l.length + '</b></button></section>';
				return;
			}
			const pend = s === STAGES[0] && S.pend ? S.pend.rows.filter(x => !S.q || hit({ title: x.n, cn: x.n, ce: x.e, cp: x.p }, S.q)) : [];
			h += '<section class="crm-col" data-stage="' + esc(s) + '"><header><div class="crm-chh"><span class="crm-chip">' + esc(s) + '</span><span class="crm-chb">' + (R.edit ? '<button type="button" class="crm-add" data-add="' + esc(s) + '" title="Új deal ebbe a szakaszba" aria-label="Új deal: ' + esc(s) + '">+</button>' : '') + '<button type="button" class="crm-add crm-fold" data-fold="' + esc(s) + '" title="Oszlop összecsukása" aria-label="Összecsukás: ' + esc(s) + '">‹</button></span></div>' +
				'<small><span># ' + l.length + '</span>' + (v ? '<span>' + esc(money(v)) + '</span>' : '') + veH + '</small>' +
				'<div class="crm-mh"><b>' + esc(s) + '</b><small>' + (v || !ve ? esc(money(v)) : '') + (ve ? (v ? ' + ' : '') + veH : '') + ' • ' + l.length + ' deal</small>' + prog(names.indexOf(s)) + '</div></header><div class="crm-cards">' +
				pend.map(x => { const ev = x.f['Deal.dateCustomField1'] || '', sv = x.f['Deal.multiSelectCustomField3'] || ''; return '<article class="crm-card crm-pend" data-lead="' + x.id + '" tabindex="0"><div class="crm-ct"><span class="crm-lnew">új</span> ' + esc(x.n || x.e || 'Névtelen') + '</div><div class="crm-cl">' + (ev ? '<span>' + esc(shortD(ev)) + '</span><i>•</i>' : '') + '<span class="cpx-muted">' + esc(sv || x.form || 'weboldal') + '</span></div><div class="crm-pw">⏳ Salesmate-szinkronra vár · ' + esc(agoLead(x.at)) + '<button type="button" class="crm-px" data-pdel="' + x.id + '" title="Elvetés (spam / teszt)">×</button></div></article>'; }).join('');
			l.forEach(r => {
				const soon = r.ev && (evEnd(r) || r.ev) >= t && r.ev <= addDays(t, 14), past = r.ev && (evEnd(r) || r.ev) < t; /* v2.9.71: többnapos: az utolsó napig */
				/* v2.9.122: rohadás – halványsárgától narancson át pirosig, aszerint, hogy a szakasz idejéhez képest mennyit késik */
				const rotM = r.rot ? Math.round((Date.now() - new Date(r.rot.replace(' ', 'T')).getTime()) / 60000) : -1;
				const rot = rotM > 0 ? rotM : 0;
				const act = r.na ? (r.na < now ? '<span class="crm-na late" title="Lejárt teendő: ' + esc(huDT(r.na)) + '">⚑ lejárt</span>' : '<span class="crm-na" title="Következő teendő: ' + esc(huDT(r.na)) + '">⚑ ' + esc(shortD(r.na.slice(0, 10))) + '</span>') : '<span class="crm-na none" title="Nincs ütemezett teendő">⚑ nincs teendő</span>';
				const rotB = rot ? rotHtml(r, rot) : '';
				const fresh = S.pend && S.pend.fresh && S.pend.fresh[r.id];
				/* v2.9.122: a rohadó csempe egésze elszíneződik (halványsárga → narancs → piros); a felső sávban kikapcsolható */
				/* v2.9.125: ha a késés eléri a szakasz idejét (a skála piros vége): lüktető piros fénygyűrű + időnkénti remegés (a csempék nem egyszerre) */
				const rotF = rot / Math.max(60, r.rw || 1440), rotMax = rot && rotOn() && rotF >= 1;
				const rotSt = rot && rotOn() ? (c => ' style="background:' + c.card + ';border-color:' + c.bd + ';box-shadow:inset 4px 0 0 ' + c.bd + (rotMax ? ';--rd:-' + ((r.id * 7919) % 5000) + 'ms' : '') + '"')(rotCol(rotF)) : '';
				const open = '<article class="crm-card' + (fresh ? ' fresh' : '') + (r.status === 'Won' ? ' won' : '') + (r._busy ? ' busy' : '') + (rotSt ? ' rotting' : '') + (rotMax ? ' rot-max' : '') + '" data-id="' + r.id + '" tabindex="0"' + (W ? ' draggable="true"' : '') + rotSt + '><div class="crm-ct" title="' + esc(r.title) + '">' + (fresh ? '<span class="crm-lnew">új</span> ' : '') + (r.ret && r.ret.won ? '<span class="crm-ret" title="' + esc('Visszatérő ügyfél: ' + r.ret.won + ' megnyert rendezvény' + (r.ret.last_ev ? ', utoljára ' + huD(r.ret.last_ev) : '')) + '">🔁</span> ' : '') + esc(shortTitle(r)) + '</div>';
				if (S.dense && !MOB) {
					h += open + '<div class="crm-cl">' + (r.ev ? '<span class="' + (past ? 'crm-past' : soon ? 'crm-soon' : '') + '">' + esc(evShort(r)) + '</span>' : '<span class="cpx-muted">nincs dátum</span>') + '<i>•</i>' + valHtml(r) + (r.na && r.na < now ? '<span class="crm-na late" title="Lejárt teendő">⚑</span>' : '') + rotB + '</div></article>';
					return;
				}
				h += open +
					'<div class="crm-cl">' + (r.cn || r.co ? esc([r.cn, r.co].filter(Boolean).join(' • ')) : '<span class="cpx-muted">nincs kontakt</span>') + '</div>' +
					(r.venue ? '<div class="crm-cl crm-cv" title="' + esc(r.venue) + '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-7-6.2-7-11.5A7 7 0 0 1 19 9.5C19 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg><span>' + esc(r.venue) + '</span></div>' : '') +
					'<div class="crm-cl">' + valHtml(r) + '<i>•</i>' + (r.ev ? '<span class="' + (past ? 'crm-past' : soon ? 'crm-soon' : '') + '">' + esc(evShort(r)) + '</span>' : '<span class="cpx-muted">nincs dátum</span>') + '</div>' +
					(capHtml(r.cap, false) ? '<div class="crm-cl">' + capHtml(r.cap, false) + '</div>' : '') +
					'<div class="crm-cfoot"><span class="crm-av" style="background:' + avColor(r.own) + '" title="' + esc(uname(r.own)) + '">' + esc(initials(uname(r.own))) + '</span>' + (W ? '<button type="button" class="crm-qnb" data-qnote="' + r.id + '" title="Jegyzet hozzáadása" aria-label="Jegyzet hozzáadása"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4h14v10l-6 6H5z"/><path d="M13 20v-6h6"/></svg></button>' : '') + (r.status === 'Won' ? stBadge(r) : '') + /* v2.9.159: a teendő nem kerül a csempe aljára (a felhasználó kérésére) */ '<span class="crm-cfr">' + rotB + lcH(r) + '</span></div></article>'; /* v2.9.158: jegyzet balra (mint a Salesmate-ben), jobbra rohadás + utolsó kommunikáció, egy sorban */
			});
			if (!l.length) h += '<div class="crm-none">Nincs deal</div>';
			h += '</div></section>';
		});
		h += '</div>';
		if (R.edit) h += '<button type="button" class="crm-fab" data-fab aria-label="Új deal" title="Új deal ebbe a szakaszba">+</button>';
		if (W) h += '<div class="crm-drop" aria-hidden="true"><div data-st="Won">Megnyert</div><div data-st="Lost">Elvesztett</div></div>';
		box.innerHTML = h;
		bindOpen(box);
		bindDrag(box);
		box.querySelectorAll('[data-qnote]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); quickNote(b); }));
		box.querySelectorAll('[data-fold]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); const s = b.dataset.fold; S.fold = S.fold.includes(s) ? S.fold.filter(x => x !== s) : S.fold.concat([s]); save(); redrawBoard(); }));
		box.querySelectorAll('[data-add]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); newDeal(b.dataset.add); }));
		const fab = box.querySelector('[data-fab]');
		/* v2.9.82: a közös „+ Új deal” mindig az első szakaszba (Érdeklődő) tesz – eddig a látható oszlopét vette, görgetett táblán az „Ajánlat kiküldve”-t; az oszlopok saját „+” gombja továbbra is a saját oszlopába */
		if (fab) fab.addEventListener('click', () => newDeal());
		box.querySelectorAll('[data-lead]').forEach(c => {
			c.addEventListener('click', e => { if (e.target.closest('[data-pdel]')) return; openLead(Number(c.dataset.lead)); });
			c.addEventListener('keydown', e => { if (e.key === 'Enter') openLead(Number(c.dataset.lead)); });
		});
		box.querySelectorAll('[data-pdel]').forEach(b => b.addEventListener('click', async e => {
			e.stopPropagation();
			if (!(await window.CPXConfirm('Elveted ezt a megkeresést a pipeline-ról (spam vagy teszt)? A Salesmate-et nem érinti.'))) return;
			try { await post('cp_lead_set', { id: b.dataset.pdel, st: 'spam' }); S.pend.rows = S.pend.rows.filter(x => x.id !== Number(b.dataset.pdel)); redrawBoard(); } catch (er) { alert(er.message); }
		}));
		const bd = box.querySelector('.crm-board');
		const arr = () => { const a = box.querySelectorAll('[data-arr]'); a[0].disabled = bd.scrollLeft <= 2; a[1].disabled = bd.scrollLeft + bd.clientWidth >= bd.scrollWidth - 2; };
		box.querySelectorAll('[data-arr]').forEach(b => b.addEventListener('click', () => bd.scrollBy({ left: Number(b.dataset.arr) * 250, behavior: 'smooth' })));
		bd.addEventListener('scroll', arr, { passive: true });
		fitBoard(); arr();
		if (MOB) mobFit(bd);
	}
	/* húzás a szakaszok között, illetve a Megnyert / Elvesztett mezőre */
	function bindDrag(box) {
		if (!W) return;
		const bd = box.querySelector('.crm-board');
		let dragId = 0;
		box.querySelectorAll('.crm-card[draggable]').forEach(c => {
			c.addEventListener('dragstart', e => { dragId = Number(c.dataset.id); e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(dragId)); c.classList.add('drag'); document.body.classList.add('crm-dragging'); });
			c.addEventListener('dragend', () => { dragId = 0; c.classList.remove('drag'); document.body.classList.remove('crm-dragging'); box.querySelectorAll('.over').forEach(x => x.classList.remove('over')); });
		});
		box.querySelectorAll('.crm-col[data-stage], .crm-drop [data-st]').forEach(z => {
			z.addEventListener('dragover', e => { if (!dragId) return; e.preventDefault(); e.dataTransfer.dropEffect = 'move'; z.classList.add('over'); });
			z.addEventListener('dragleave', e => { if (!z.contains(e.relatedTarget)) z.classList.remove('over'); });
			z.addEventListener('drop', e => {
				e.preventDefault(); z.classList.remove('over');
				const id = dragId; dragId = 0;
				document.body.classList.remove('crm-dragging');
				if (id) doMove(id, z.dataset.stage ? { to: z.dataset.stage } : { st: z.dataset.st });
			});
		});
		/* a tábla szélénél vízszintesen görget húzás közben */
		bd.addEventListener('dragover', e => { if (!dragId) return; const r = bd.getBoundingClientRect(); if (e.clientX < r.left + 70) bd.scrollLeft -= 20; else if (e.clientX > r.right - 70) bd.scrollLeft += 20; });
	}
	/* v1.83: gyors jegyzet a csempéről (a Salesmate-be is beíródik, mint az adatlapon) */
	function quickNote(btn) {
		const card = btn.closest('.crm-card'), id = Number(btn.dataset.qnote);
		const open = card.querySelector('.crm-qn'); if (open) { open.remove(); return; }
		document.querySelectorAll('.crm-qn').forEach(x => x.remove());
		const f = document.createElement('div'); f.className = 'crm-qn';
		f.innerHTML = '<textarea rows="3" placeholder="Jegyzet a dealhez… (Ctrl+Enter: mentés, Esc: mégse)"></textarea><div class="crm-qnf"><small>A Salesmate-be is beíródik.</small><button type="button" class="cpx-btn ghost" data-qx>Mégse</button><button type="button" class="cpx-btn" data-qs>Mentés</button></div>';
		card.appendChild(f); card.setAttribute('draggable', 'false');
		const ta = f.querySelector('textarea'), close = () => { f.remove(); if (W) card.setAttribute('draggable', 'true'); };
		f.addEventListener('click', e => e.stopPropagation()); f.addEventListener('mousedown', e => e.stopPropagation());
		const save = async () => {
			const v = ta.value.trim(); if (!v) { ta.focus(); return; }
			const b = f.querySelector('[data-qs]'); b.disabled = true; b.textContent = 'Mentés…';
			try { await post('cp_crm_note_add', { deal: id, body: v }); close(); toast('Jegyzet mentve – a Salesmate-be is.'); } catch (er) { b.disabled = false; b.textContent = 'Mentés'; toast(er.message, true); }
		};
		f.querySelector('[data-qs]').addEventListener('click', save);
		f.querySelector('[data-qx]').addEventListener('click', close);
		ta.addEventListener('keydown', e => { e.stopPropagation(); if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); save(); } if (e.key === 'Escape') { e.preventDefault(); close(); } });
		ta.focus();
	}
	function redrawBoard() {
		const box = S.main && S.main.querySelector('#crm-body');
		if (!box || S.mode !== 'board' || !S.board) return;
		const bd = box.querySelector('.crm-board');
		const keep = { x: bd ? bd.scrollLeft : 0, y: window.scrollY, cols: {} };
		box.querySelectorAll('.crm-col').forEach(c => { const k = c.querySelector('.crm-cards'); if (k) keep.cols[c.dataset.stage] = k.scrollTop; });
		drawBoard(box);
		const nb = box.querySelector('.crm-board');
		if (nb) nb.scrollLeft = keep.x;
		box.querySelectorAll('.crm-col').forEach(c => { const k = c.querySelector('.crm-cards'); if (k && keep.cols[c.dataset.stage]) k.scrollTop = keep.cols[c.dataset.stage]; });
		window.scrollTo(0, keep.y);
	}
	function toast(t, bad) {
		let d = document.getElementById('cpx-toast');
		if (!d) { d = document.createElement('div'); d.id = 'cpx-toast'; d.className = 'cpx-toast'; d.setAttribute('role', 'status'); document.body.appendChild(d); }
		d.textContent = t; d.classList.toggle('bad', !!bad); d.classList.add('on');
		clearTimeout(d._t); d._t = setTimeout(() => d.classList.remove('on'), bad ? 6000 : 3200);
	}
	/* v2.9.172: az elvesztés oka – választólista (kötelező), „Egyéb”-nél szabad szöveg */
	const lostWhy = name => window.CPXLostWhy(name); /* v2.9.179: a lista a panel.js-ben (a Levelezés is ezt használja) */
	/* v2.9.188: elveszett / törölt deal aktív beosztásai – rákérdez, lemondja-e (a kezelő „Elmarad a rendezvény” levelet kap) */
	const SLST = { invited: 'meghívva', accepted: 'ráér', final: 'véglegesítve' };
	async function slotStorno(dealId, name) {
		let rows = [];
		try { rows = (await post('cp_slot_deal_active', { deal: dealId })).rows || []; } catch (e) { return; }
		if (!rows.length) return;
		const ok = await window.CPXConfirm((name ? name + '\n\n' : '') + 'Ehhez a rendezvényhez beosztás tartozik:\n' + rows.map(r => '• ' + r.n + ' – ' + huD(r.day) + ' (' + (SLST[r.st] || r.st) + ')' + (r.mail ? '' : ' – nincs e-mail-címe')).join('\n') + '\n\nLemondod? Lemondáskor „Elmarad a rendezvény” levelet kapnak, a naptárukból törlődik a bejegyzés, és a beosztás felszabadul.', { title: 'Beosztás lemondása', ok: 'Lemondom (levéllel)', cancel: 'Meghagyom' });
		if (!ok) return;
		try { const r = await post('cp_slot_deal_storno', { deal: dealId }); toast('Beosztás lemondva: ' + r.done + (r.mailed ? ' · ' + r.mailed + ' levél elküldve' : '') + (r.err && r.err.length ? ' · hiba: ' + r.err.join('; ') : ''), !!(r.err && r.err.length)); }
		catch (e) { toast('A beosztás lemondása nem sikerült: ' + e.message, true); }
	}
	window.CPXSlotStorno = slotStorno;
	/* áthelyezés: p = { to: szakasz } vagy { st: 'Won' | 'Lost' | 'Open' }; a Salesmate-be is ír */
	async function doMove(id, p, title) {
		const rows = S.board ? S.board.rows : [];
		const r = rows.find(x => x.id === id);
		const name = title || (r ? shortTitle(r) : 'Deal #' + id);
		if (p.to && r && r.stage === p.to) return false;
		const what = p.to ? 'a(z) „' + p.to + '” szakaszba' : (p.st === 'Won' ? 'a megnyertek közé' : p.st === 'Lost' ? 'az elvesztettek közé' : 'újra nyitottnak');
		/* v2.9.172: megerősítés helyett 5 mp-ig visszavonható (a Salesmate-be csak utána írunk, így visszavonáskor semmi nem fut le);
		   elvesztésnél kötelező ok választólistából */
		if (p.st === 'Lost') { const w = await lostWhy(name); if (w === null) return false; p.why = w; }
		const old = r ? Object.assign({}, r) : null;
		if (r) { if (p.to) r.stage = p.to; if (p.st) r.status = p.st; r._busy = 1; redrawBoard(); }
		if (!(await window.CPXUndo('Áthelyezve: ' + name + ' → ' + what, 5000))) {
			if (r && old) { Object.assign(r, old); delete r._busy; } redrawBoard(); toast('Visszavonva – nem változott semmi.'); return false;
		}
		let ok = false;
		try {
			const x = await post('cp_crm_move', Object.assign({ id: id }, p));
			if (x.row && S.board) { const i = S.board.rows.findIndex(y => y.id === id); if (i >= 0) S.board.rows[i] = x.row; }
			toast(x.warn ? x.warn : (x.changed ? 'Kész – a Salesmate-ben is átírva.' : 'Nem kellett változtatni, már ott volt.'), !!x.warn);
			ok = true;
			if (p.st === 'Lost') slotStorno(id, name);
		} catch (e) {
			if (r && old) Object.assign(r, old);
			toast('Nem sikerült: ' + e.message, true);
		}
		if (S.board) {
			S.board.rows.forEach(y => { delete y._busy; });
			S.board.rows = S.board.rows.filter(y => y.status === 'Open' || cfStatusShown(y.status));
			redrawBoard();
		}
		return ok;
	}
	/* az oszlopok a képernyő aljáig érjenek (a saját görgetésük így mindig elérhető) */
	/* v1.83.4: mobilon a tábla magassága mindig a látható szakaszé – a rövid szakasz alatt nem lehet tovább görgetni
	   (a szomszédos, hosszabb oszlop nem nyújtja meg az oldalt); oldalra húzáskor és a tartalom változásakor igazodik */
	function mobFit(bd) {
		const cols = Array.from(bd.querySelectorAll('.crm-col'));
		if (!cols.length) return;
		let t = 0;
		const fit = () => {
			if (!bd.isConnected) return;
			const mid = bd.getBoundingClientRect().left + bd.clientWidth / 2;
			const c = cols.reduce((best, x) => { const r = x.getBoundingClientRect(), d = Math.abs(r.left + r.width / 2 - mid); return d < best.d ? { x: x, d: d } : best; }, { x: cols[0], d: Infinity }).x;
			bd.style.height = (c.offsetHeight + 96) + 'px';
		};
		bd.style.overflowY = 'hidden';
		/* v1.88.3: az aktuális szakasz fejléce a tábla fölött, CSS-sel rögzítve (position:sticky) – görgetéskor a böngésző mozgatja, így iPhone-on sem szaggat;
		   JS csak oldalra lapozáskor cseréli a tartalmát (az oszlopokon belüli fejléc mobilon rejtve) */
		document.querySelectorAll('.crm-stk').forEach(x => x.remove());
		const stk = document.createElement('div'); stk.className = 'crm-stk'; bd.parentNode.insertBefore(stk, bd);
		stk.addEventListener('click', () => { const y = bd.getBoundingClientRect().top + window.scrollY - stk.offsetHeight - 60; if (window.scrollY > y) window.scrollTo({ top: Math.max(0, y), behavior: 'smooth' }); });
		let cur = null, raf = 0;
		const head = () => {
			raf = 0;
			if (!bd.isConnected) return;
			const mid = bd.getBoundingClientRect().left + bd.clientWidth / 2;
			const c = cols.reduce((best, x) => { const r = x.getBoundingClientRect(), d = Math.abs(r.left + r.width / 2 - mid); return d < best.d ? { x: x, d: d } : best; }, { x: cols[0], d: Infinity }).x;
			const mh = c.querySelector('.crm-mh');
			if (c !== cur && mh) { stk.innerHTML = mh.innerHTML; cur = c; }
		};
		bd.addEventListener('scroll', () => { if (!raf) raf = requestAnimationFrame(head); }, { passive: true });
		head();
		bd.addEventListener('scroll', () => { clearTimeout(t); t = setTimeout(fit, 90); }, { passive: true });
		if (window.ResizeObserver) { const ro = new ResizeObserver(() => { clearTimeout(t); t = setTimeout(fit, 30); }); cols.forEach(c => ro.observe(c)); }
		fit();
	}
	function fitBoard() {
		const bd = S.main && S.main.querySelector('.crm-board');
		if (!bd) return;
		const top = bd.getBoundingClientRect().top + window.scrollY;
		const mn = document.body.classList.contains('mn-on') && window.innerWidth <= 760 ? 70 : 0; /* mobilon az alsó menüsor */
		bd.style.setProperty('--crm-h', Math.max(360, window.innerHeight - Math.max(0, top - window.scrollY) - 26 - mn) + 'px');
	}
	window.addEventListener('resize', () => { if (live() && S.mode === 'board') fitBoard(); });
	/* keresés a táblán (ékezet- és kisbetű-független; telefonszámnál csak a számjegyek) */
	const fold = s => String(s || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
	function hit(r, q) {
		const t = fold(q).trim();
		if (!t) return true;
		if (/^#?\d{3,7}$/.test(t)) return String(r.id) === t.replace('#', '') || String(r.cp || '').replace(/\D/g, '').includes(t.replace('#', ''));
		const dq = t.replace(/[^\d]/g, '');
		if (dq.length >= 6 && String(r.cp || '').replace(/\D/g, '').includes(dq.slice(-8))) return true;
		const hay = fold([r.title, r.cn, r.ce, r.co, r.venue, r.pkg, r.svc, uname(r.own)].join(' '));
		return t.split(/\s+/).every(w => hay.includes(w));
	}
	const shortD = s => { const p = s.split('-').map(Number); return p[0] + '. ' + HU_MS[p[1] - 1] + ' ' + p[2] + '.'; };
	const dayDiff = (a, b) => { const x = a.split('-').map(Number), y = b.split('-').map(Number); return Math.round((Date.UTC(y[0], y[1] - 1, y[2]) - Date.UTC(x[0], x[1] - 1, x[2])) / 864e5); };
	const initials = n => String(n || '').replace(/^#.*/, '').split(/\s+/).filter(Boolean).slice(0, 2).map(w => w[0]).join('').toUpperCase() || '?';
	const AVC = ['#F6B48F', '#93D5B4', '#A9C1F6', '#E3B5E6', '#F3D27F', '#9FD8E5', '#F2A5A5'];
	const avColor = id => AVC[Math.abs(Number(id) || 0) % AVC.length];
	function addDays(s, n) { const p = s.split('-').map(Number); const d = new Date(p[0], p[1] - 1, p[2] + n); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }

	/* ------------------------------------------------------------ deal-adatlap */
	const LBL = {
		title: 'Név', stage: 'Szakasz', status: 'Állapot', owner_id: 'Felelős', value: 'Érték', currency: 'Pénznem', contact_id: 'Kontakt', company_id: 'Cég',
		source: 'Forrás', tags: 'Címkék', lost_reason: 'Elvesztés oka', est_close_d: 'Várható lezárás', closed_at: 'Lezárva', closed_stage: 'Lezárási szakasz',
		last_note: 'Utolsó jegyzet', event_d: 'Rendezvény dátuma', event_end_d: 'Rendezvény vége', start_at: 'Kezdés', venue: 'Helyszín', pkg_old: 'Választott csomag (régi)',
		note: 'Megjegyzés', pay_method: 'Fizetési mód', background: 'Fotófal / háttér', reminder: 'Menjen emlékeztető', paid: 'Bejött az utalás', operator: 'Gépkezelő asszisztens',
		album: 'Fotóalbum', template_st: 'Template', ctype: 'Szerződés (EV/KFT)', cal_check: 'Naptár', service: 'Szolgáltatás', spinner_pkg: 'Spinner csomag',
		template_paid: 'Fizetős template', melange_pkg: 'Melange csomag', pkg_v2: 'Választott csomag', pkg_fix: 'Választott csomag 2', template_link: 'Sablon kép',
		portal_link: 'Ügyfélportál', guests: 'Tervezett létszám', km_fee: 'Kiszállási díj', hours: 'Rendelkezésre állás (óra)', extension: 'Hosszabbítás',
		coupon: 'Kuponkód', services_more: 'További szolgáltatások', extras: 'Választott extra', services_ec: 'EventCrazy szolgáltatás(ok)', services_cp: 'Választott szolgáltatás',
		package: 'Csomag', event_days: 'Rendezvénynapok' /* v2.9.70 */
	};
	function fmtVal(k, v, d) {
		if (v == null || v === '') return '';
		if (k === 'event_d' || k === 'event_end_d' || k === 'est_close_d') return esc(huD(v));
		if (k === 'start_at' || k === 'closed_at') return esc(huDT(v));
		if (k === 'value') return esc(money(v, d && d.currency));
		if (k === 'km_fee') return esc(money(v));
		if (k === 'status') return esc(ST_HU[v] || v);
		if (k === 'owner_id') return esc(uname(v));
		if (k === 'template_link' || k === 'portal_link') { const u = safeUrl(v); return u ? '<a href="' + esc(u) + '" target="_blank" rel="noopener">Megnyitás ↗</a>' : esc(v); }
		if (/^(services_|extras|tags)/.test(k)) return String(v).split(' | ').map(x => '<span class="crm-tag">' + esc(x) + '</span>').join(' ');
		return esc(v).replace(/\n/g, '<br>');
	}
	const ALWAYS_K = ['km_fee'];
	function dl(d, keys, inl) {
		/* v2.9.13: a kiszállási díj üresen is látszik, hogy rögtön kézzel beírható legyen */
		const rows = keys.map(k => { let v = fmtVal(k, d[k], d); if (!v && inl && ALWAYS_K.includes(k) && ikA(k)) v = '<span class="cpx-muted">– kattints a megadáshoz</span>'; return v ? '<dt>' + esc(LBL[k] || k) + '</dt><dd' + (inl ? ikA(k) : '') + '>' + v + '</dd>' : ''; }).join('');
		return rows ? '<dl class="crm-dl">' + rows + '</dl>' : '';
	}
	function sheet(html) {
		const m = document.getElementById('cpx-modal');
		if (!m) return null;
		if (window.cpxSheetMo) window.cpxSheetMo.watch();
		m.innerHTML = '<div class="cpx-sheet wide crm-sheet" role="dialog" aria-modal="true">' + html + '</div>';
		m.hidden = false;
		return m.querySelector('.crm-sheet');
	}
	const sheetHead = t => '<div class="cpx-sheet-h"><h3>' + t + '</h3><button class="cpx-x" type="button" data-close aria-label="Bezárás">×</button></div>';

	/* ------------------------------------------------------------ v1.48: Deal 360° – minden egy helyen (a Salesmate deal-oldalához hasonlóan) */
	const PEOPLE = (C.mail && C.mail.people) || (C.kb && C.kb.people) || [];
	const pn = id => (PEOPLE.find(p => p.id === Number(id)) || {}).n || '';
	const HU_MF = ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'];
	const daysAgo = s => { if (!s) return null; return Math.floor((Date.now() - new Date(String(s).replace(' ', 'T')).getTime()) / 864e5); };
	const agoTxt = s => { const n = daysAgo(s); if (n == null) return ''; return n <= 0 ? 'ma' : n === 1 ? 'tegnap' : n + ' napja'; };
	/* v2.9.81: a jegyzetben lévő webcím kattintható (új lapon nyílik) */
	const linkify = h => h.replace(/\bhttps?:\/\/[^\s<]+/g, u => { const m = /[.,;:!?)\]]+$/.exec(u), t = m ? m[0] : '', v = t ? u.slice(0, -t.length) : u; return '<a href="' + v + '" target="_blank" rel="noopener noreferrer" class="note-link">' + v + '</a>' + t; });
	const nl = s => linkify(esc(s)).replace(/\n/g, '<br>');
	/* v2.9.68: az idővonal szűrője (Mind · Levelek · Jegyzetek · Portál · Változások) felhasználónként megjegyezve */
	const TL_KEY = 'cpd3tl' + (C.user ? ':' + C.user : '');
	const TL_TABS = [['all', 'Mind'], ['mail', 'Levelek'], ['note', 'Jegyzetek'], ['portal', 'Portál'], ['hist', 'Változások']];
	const D3 = { mon: (() => { try { return localStorage.getItem('cp_d3_mon') !== '0'; } catch (e) { return true; } })(), tab: (() => { try { const v = localStorage.getItem(TL_KEY); return TL_TABS.some(t => t[0] === v) ? v : 'all'; } catch (e) { return 'all'; } })() };
	const I = {
		mail_in: '<svg viewBox="0 0 24 24"><path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/></svg>', mail_out: '<svg viewBox="0 0 24 24"><path d="M4 6h16v12H4z"/><path d="m4 7 8 6 8-6"/><path d="M15 3l3 3-3 3" /></svg>',
		note: '<svg viewBox="0 0 24 24"><path d="M5 4h14v12l-4 4H5z"/><path d="M15 20v-4h4"/></svg>', act: '<svg viewBox="0 0 24 24"><rect x="4" y="5" width="16" height="15" rx="2"/><path d="M4 10h16M9 3v4M15 3v4"/></svg>',
		hist: '<svg viewBox="0 0 24 24"><path d="M12 7v5l3 2"/><circle cx="12" cy="12" r="8"/></svg>', card: '<svg viewBox="0 0 24 24"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="m8 12 3 3 5-6"/></svg>',
		lead: '<svg viewBox="0 0 24 24"><path d="M12 3l2.6 5.6 6 .6-4.5 4 1.3 6L12 16l-5.4 3.2 1.3-6-4.5-4 6-.6z"/></svg>', flow: '<svg viewBox="0 0 24 24"><path d="M4 6h6v4H4zM14 14h6v4h-6zM7 10v4a2 2 0 0 0 2 2h5"/></svg>',
		plus: '<svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>', portal: '<svg viewBox="0 0 24 24"><rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/></svg>', file: '<svg viewBox="0 0 24 24"><path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4"/></svg>'
	};
	/* v1.75: a deal-, kontakt- és cégnézet önálló oldal a fő tartalomban (nem felugró ablak): saját címe van (#deals:<sorszám>),
	   az Esc nem zárja be, a böngésző Vissza gombja és a ← / × gomb igen; a lista alatta megmarad */
	function hideModal() { const m = document.getElementById('cpx-modal'); if (m && !m.hidden) { if (window.cpxSheetMo) window.cpxSheetMo.close(m); else { m.hidden = true; m.innerHTML = ''; } } }
	function closePage(nav) {
		const main = document.getElementById('cpx-main');
		if (main) { main.classList.remove('crm-pg-on'); const p = main.querySelector(':scope > .crm-page'); if (p) p.remove(); }
		if (nav !== false && /^#deals:\d+/.test(location.hash)) history.replaceState(null, '', '#' + (D3.tab0 || 'deals'));
		if (nav !== false && /^#kapcsolatok:(ct|co):\d+/.test(location.hash)) history.replaceState(null, '', '#' + (document.querySelector('.cpx-tabs [data-tab].on') ? document.querySelector('.cpx-tabs [data-tab].on').dataset.tab : 'kapcsolatok'));
	}
	function sheet360(html) {
		hideModal();
		const main = document.getElementById('cpx-main');
		if (!main) return null;
		let pg = main.querySelector(':scope > .crm-page');
		if (!pg) {
			pg = document.createElement('div'); pg.className = 'crm-page';
			main.prepend(pg);
			pg.addEventListener('click', e => { const x = e.target.closest('[data-close]'); if (x && x.closest('.d3-h')) { e.preventDefault(); closePage(); } });
		}
		main.classList.add('crm-pg-on');
		pg.innerHTML = '<div class="crm-360 crm-pg" role="region" aria-label="Adatlap">' + html + '</div>';
		window.scrollTo(0, 0);
		return pg.firstChild;
	}
	/* a deal saját címe: #deals:<sorszám> – a böngészőben visszaléphető, könyvjelzőzhető, elküldhető */
	function dealUrl(id) { return location.origin + location.pathname + location.search + '#deals:' + id; }
	function pushDeal(id) {
		const cur = location.hash.slice(1).split(':')[0];
		if (!/^#deals:\d+$/.test(location.hash)) D3.tab0 = cur || 'deals';
		if (location.hash !== '#deals:' + id) history.pushState({ cpDeal: id }, '', '#deals:' + id);
	}
	window.addEventListener('popstate', () => {
		/* v2.9.64: a visszalépés csak a levélszál gyorsnézetét zárta – a deal / kontakt / cég marad, nem rajzoljuk újra */
		if (window.CPMail && window.CPMail.peekPop && window.CPMail.peekPop()) return;
		const pm = /^#kapcsolatok:(ct|co):(\d+)$/.exec(location.hash);
		if (pm) { if (pm[1] === 'ct') openContact(Number(pm[2]), null, true); else openCompany(Number(pm[2]), null, true); return; }
		const m = /^#deals:(\d+)$/.exec(location.hash);
		if (m) {
			const id = Number(m[1]);
			if (document.querySelector('#cpx-main > .crm-page')) openDeal(id, null, true);
			else if (history.state && history.state.cpDeal) {
				/* visszalépés egy másik fülről (pl. a dealről megnyitott levélből): vissza a Deal-ek fülre, rajta a deal */
				if (window.CPXGo && !document.querySelector('.cpx-tabs [data-tab="deals"].on')) { window.CPXGo('deals'); history.replaceState({ cpDeal: id }, '', '#deals:' + id); }
				openDeal(id, null, true);
			}
		}
		else closePage(false);
	});
	const AIs = p => '<svg viewBox="0 0 24 24" aria-hidden="true">' + p + '</svg>';
	const AI = { mail: AIs('<rect x="3.5" y="5.5" width="17" height="13" rx="2"/><path d="M4 7l8 6 8-6"/>'), ok: AIs('<path d="M5 12.5l4.5 4.5L19 7.5"/>'), no: AIs('<path d="M6.5 6.5l11 11M17.5 6.5l-11 11"/>'), undo: AIs('<path d="M9 8H4V3"/><path d="M4.5 8A8 8 0 1 1 4 12"/>'), edit: AIs('<path d="M4 20h4L19 9l-4-4L4 16z"/><path d="M14 6l4 4"/>'), todo: AIs('<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M8 12l3 3 5-6"/>'), ext: AIs('<path d="M14 4h6v6"/><path d="M20 4l-9 9"/><path d="M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>') };
	const ab = (cls, attr, ico, txt) => '<button type="button" class="d3-ab' + (cls ? ' ' + cls : '') + '" ' + attr + '>' + ico + '<span>' + txt + '</span></button>';
	const head360 = (t, back, did, pp) => '<div class="cpx-sheet-h d3-h">' + (back ? '<button type="button" class="d3-back" data-back>← ' + esc(back.label) + '</button>' : '<button type="button" class="d3-back" data-close>← Vissza</button>') + '<h3>' + t + '</h3>' +
		(did ? '<span class="d3-no" title="A deal egyedi sorszáma (a Salesmate-ben is ez)">#' + Number(did) + '</span><button type="button" class="cpx-btn ghost d3-link" data-dlink="' + Number(did) + '" title="A deal saját linkje – elküldhető, könyvjelzőzhető">🔗 <span>Link</span></button>' : '') +
		(pp ? '<button type="button" class="cpx-btn ghost d3-link" data-pplink="' + esc(pp) + '" title="A ' + (pp.indexOf('co:') === 0 ? 'cég' : 'kontakt') + ' saját linkje – elküldhető, könyvjelzőzhető">🔗 <span>Link</span></button>' : '') +
		'<button class="cpx-x" type="button" data-close aria-label="Bezárás">×</button></div>';
	/* v2.9.104: kontakt / cég saját címe: #kapcsolatok:ct:<id>, #kapcsolatok:co:<id> */
	const ppUrl = pp => location.origin + location.pathname + location.search + '#kapcsolatok:' + pp;
	function pushPP(pp) { if (location.hash !== '#kapcsolatok:' + pp) history.pushState({ cpPP: pp }, '', '#kapcsolatok:' + pp); }
	function bindBack(sh, back) {
		const b = sh.querySelector('[data-back]'); if (b && back) b.addEventListener('click', back.fn);
		const pl = sh.querySelector('[data-pplink]');
		if (pl && !pl.dataset.b) { pl.dataset.b = '1'; pl.addEventListener('click', async () => { const u = ppUrl(pl.dataset.pplink); try { await navigator.clipboard.writeText(u); toast('A link kimásolva.'); } catch (e) { window.prompt('A link:', u); } }); }
		const l = sh.querySelector('[data-dlink]');
		if (l && !l.dataset.b) { l.dataset.b = '1'; l.addEventListener('click', async () => { const u = dealUrl(l.dataset.dlink); try { await navigator.clipboard.writeText(u); toast('A deal linkje kimásolva: #' + l.dataset.dlink); } catch (e) { window.prompt('A deal linkje:', u); } }); }
	}
	/* v1.78.1: a dealről megnyitott levélből vissza lehet lépni a dealre (← gomb vagy a böngésző Vissza gombja) */
	const goMail = id => {
		const m = /^#deals:(\d+)$/.exec(location.hash), did = m ? Number(m[1]) : 0;
		hideModal(); closePage(!did);
		if (did) history.pushState({ cpFromDeal: did }, '', '#mail');
		if (window.CPMail && window.CPMail.open) window.CPMail.open(id, { deal: did });
	};
	/* v2.9.64: a levélszál a deal / kontakt / cég fölött felugró ablakban nyílik (fülváltás nélkül); az ablak
	   „Megnyitás a Levelezésben” gombja a régi utat (goMail) hívja. Gyorsnézet nélkül (régi mail.js) marad a goMail. */
	const openTh = (id, el) => { if (window.CPMail && window.CPMail.peek) window.CPMail.peek(id, { from: el, subj: el && el.dataset.ths ? el.dataset.ths : '', mid: el && el.dataset.mid ? Number(el.dataset.mid) : 0, full: goMail }); /* v2.9.210: a kattintott levélre ugrik */ else goMail(id); };
	function dealRows(list, curId, back) {
		if (!list.length) return '<p class="cpx-muted">Nincs.</p>';
		const li = o => '<li class="' + (o.id === curId ? 'cur' : '') + '"><button type="button" data-open="' + o.id + '"><b>' + esc(o.title || 'Deal #' + o.id) + '</b><small>' +
			esc([o.event_d ? huD(o.event_d) : '', o.stage].filter(Boolean).join(' · ')) + '</small></button><span>' + stBadge(o) + '<em>' + esc(o.value != null ? money(o.value, o.currency) : '') + '</em></span></li>';
		/* v2.9.172: hosszú listánál a legutóbbi 10 látszik, a többi lenyitható */
		const N = 10;
		return '<ul class="d3-deals">' + list.slice(0, N).map(li).join('') + '</ul>' +
			(list.length > N ? '<details class="d3-more"><summary>További ' + (list.length - N) + ' deal</summary><ul class="d3-deals">' + list.slice(N).map(li).join('') + '</ul></details>' : '');
	}
	function statLine(s) {
		if (!s || !s.n) return '';
		return '<div class="d3-stat"><span><b>' + s.n + '</b> deal</span><span><b>' + s.won + '</b> megnyert</span><span><b>' + esc(money(s.won_v)) + '</b> bevétel</span>' + (s.open ? '<span><b>' + s.open + '</b> nyitott · ' + esc(money(s.open_v)) + '</span>' : '') + '</div>' +
			(s.first ? '<p class="d3-sub">Első megkeresés: ' + esc(huD(s.first.slice(0, 10))) + (s.last_ev ? ' · legutóbbi rendezvény: ' + esc(huD(s.last_ev)) : '') + '</p>' : '');
	}
	/* v2.9.69: hibrid szálcsoport (idővonal, kontakt- és céglap): egy szál = egy csoport a legújabb levelénél – fejléc (tárgy, levélszám,
	   időszak, mellékletek, állapot), alatta a 2 legújabb levél a régi levélelem-stílusban, „+N korábbi levél” helyben kinyitható
	   (lustán, a csak olvasó cp_crm_thread_msgs végpontról – nem jelöli olvasottnak). Az összesítőt és az lm-et a szerver adja (cp_d360_mail). */
	const CLIP_S = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 11.5l-8.2 8.2a5 5 0 0 1-7.1-7.1l8.6-8.6a3.4 3.4 0 0 1 4.8 4.8l-8.6 8.6a1.7 1.7 0 0 1-2.4-2.4l7.9-7.9"/></svg>';
	const tcD = (s, yr) => { const p = s.slice(0, 10).split('-').map(Number); return (yr || p[0] !== new Date().getFullYear() ? p[0] + '. ' : '') + HU_MS[p[1] - 1] + ' ' + p[2] + '.'; };
	const tcSpan = (f, l) => { if (!l) return ''; if (!f || f.slice(0, 10) === l.slice(0, 10)) return tcD(l); const yr = f.slice(0, 4) !== l.slice(0, 4); return tcD(f, yr) + ' – ' + tcD(l, yr); };
	const TG = { open: new Set(), cache: new Map(), notes: new Map() }; /* kinyitott csoportok és a betöltött korábbi levelek (szűrőváltás / újrarajzolás után is) */
	const tmItem = (t, g) => '<div class="d3-ti d3-tm ' + (g.dir === 'out' ? 'out' : 'in') + '" data-th="' + t.id + '"' + (g.id ? ' data-mid="' + Number(g.id) + '"' : '') + ' data-ths="' + esc(t.subj || '') + '" role="button" tabindex="0"><i>' + (g.dir === 'out' ? I.mail_out : I.mail_in) + '</i><div><div class="d3-tt"><b>' + (g.dir === 'out' ? 'Levél tőlünk' : 'Levél érkezett') + '</b> <span class="d3-tmf">– ' + esc(g.from || '?') + '</span>' +
		(g.q ? ' <span class="d3-q" title="A levél nincs meg az Elküldött mappában – az ügyfél válaszában idézett szövegből állította vissza a panel">visszaállított</span>' : '') + (g.att ? ' <span class="d3-q d3-tca" title="' + Number(g.att) + ' melléklet">' + CLIP_S + Number(g.att) + '</span>' : '') + '<small>' + esc(huDT(g.at)) + '</small></div>' +
		'<div class="d3-tx d3-tmx">' + (g.sn ? esc(g.sn) : '<em class="d3-tce">(nincs szöveg)</em>') + '</div></div></div>';
	/* v2.9.185: a levélszálra írt jegyzet a szálon belül, a levelek között (időrendben) */
	/* v2.9.214: a levélszál saját jegyzete itt is szerkeszthető (✎) */
	const TN_MAP = new Map();
	const tnEd = n => { if (!n.mine) return ''; TN_MAP.set(String(n.id), n); return '<button type="button" class="d3-x d3-ned" data-tnedit="' + n.id + '" title="Jegyzet szerkesztése">✎</button>'; };
	const tnItem = n => '<div class="d3-ti note d3-tnote"><i>' + I.note + '</i><div><div class="d3-tt"><b>Jegyzet</b> <span class="d3-tmf">– ' + esc(n.by || '') + '</span><small>' + esc(huDT(n.at)) + '</small>' + tnEd(n) + '</div><div class="d3-tx" data-tnbody="' + n.id + '">' + nl(n.b) + '</div></div></div>';
	document.addEventListener('click', e => {
		const b = e.target.closest && e.target.closest('[data-tnedit]'); if (!b) return;
		e.preventDefault(); e.stopPropagation();
		const id = b.dataset.tnedit, n = TN_MAP.get(id), tx = b.closest('.d3-ti') && b.closest('.d3-ti').querySelector('[data-tnbody]');
		if (!n || !tx || tx.querySelector('textarea')) return;
		const back = () => { tx.innerHTML = nl(n.b); };
		tx.innerHTML = '<textarea class="d3-nta" rows="3"></textarea><div class="d3-nbar"><button type="button" class="cpx-btn sm" data-nok>Mentés</button><button type="button" class="cpx-btn ghost sm" data-nx>Mégse</button></div>';
		const ta = tx.querySelector('textarea'); ta.value = n.full != null ? n.full : n.b; ta.style.height = Math.min(400, ta.scrollHeight + 4) + 'px'; ta.focus();
		['click', 'keydown'].forEach(ev => tx.addEventListener(ev, z => z.stopPropagation()));
		const save = async () => {
			const v = ta.value.trim(); if (!v) { toast('Üres jegyzet – ha törölni szeretnéd, a Levelezésben teheted.', true); return; }
			const ok = tx.querySelector('[data-nok]'); ok.disabled = true;
			try { await post('cp_mail_note_edit', { nid: id, body: v }); n.full = v; n.b = v.slice(0, 1200); document.querySelectorAll('[data-tnbody="' + id + '"]').forEach(x => { x.innerHTML = nl(n.b); }); toast('Jegyzet mentve.'); }
			catch (er) { ok.disabled = false; toast(er.message, true); }
		};
		tx.querySelector('[data-nok]').addEventListener('click', save);
		tx.querySelector('[data-nx]').addEventListener('click', back);
		ta.addEventListener('keydown', z => { if (z.key === 'Enter' && (z.ctrlKey || z.metaKey)) { z.preventDefault(); save(); } if (z.key === 'Escape') { z.preventDefault(); back(); } });
	}, true);
	const tgMoreTxt = n => '+' + n + ' korábbi levél';
	/* v2.9.157: hány napja volt az utolsó kommunikáció (a csempe jobb alsó sarkában, a Salesmate mintájára) */
	function lcH(r) {
		if (!r.lc) return '';
		const d = Math.max(0, Math.floor((Date.now() - new Date(r.lc.replace(' ', 'T')).getTime()) / 86400000));
		return '<span class="crm-lc' + (d >= 14 ? ' old' : '') + '" title="Utolsó kommunikáció: ' + esc(r.lc) + '"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 7v5l3 2"/><path d="M21 12a9 9 0 1 1-3-6.7" stroke-dasharray="2.2 2.6"/><path d="M21 4v4h-4"/></svg>' + (d ? d + ' n' : 'ma') + '</span>';
	}
	function threadGroup(t, inDeal) { /* v2.9.155: inDeal = a deal idővonalán (csatolás / leválasztás gombokkal) */
		const n = t.n != null ? t.n : t.cnt, lm = t.lm || [], rest = Math.max(0, n - lm.length), dir = t.ld === 'out' ? 'out' : t.ld === 'in' ? 'in' : '', closed = t.st === 'closed';
		const key = t.id + '|' + (t.la || t.at) + '|' + (t._notes || []).length, cached = TG.cache.get(t.id), open = rest > 0 && TG.open.has(t.id) && cached && cached.key === key;
		const chips = (inDeal && !t.deal ? '<span class="d3-q d3-tcau" title="Nincs a dealhez csatolva – a kontakt e-mail-címe alapján látszik itt. A lánc ikonnal csatolhatod.">kontakt alapján</span> ' : '') + '<span class="d3-q">' + n + ' levél</span>' + (t.na ? ' <span class="d3-q d3-tca" title="' + Number(t.na) + ' melléklet a szálban">' + CLIP_S + Number(t.na) + '</span>' : '') +
			(dir === 'out' ? ' <span class="d3-q d3-tcwo">Ügyfél válaszára vár</span>' : dir === 'in' ? ' <span class="d3-q' + (closed ? '' : ' warn') + '">Válaszra vár</span>' : '') + (closed ? ' <span class="d3-q d3-tccl">lezárva</span>' : '');
		return '<section class="d3-tg' + (closed ? ' closed' : '') + '" data-tg="' + t.id + '" data-tgk="' + esc(key) + '">' +
			'<div class="d3-tgr">' + '<button type="button" class="d3-tgh" data-th="' + t.id + '" data-ths="' + esc(t.subj || '') + '" title="A szál megnyitása"><b class="d3-tgs">' + esc(t.subj || '(nincs tárgy)') + '</b> <span class="d3-tgm">' + chips + ' <small>' + esc(tcSpan(t.f, t.la || t.at)) + '</small></span></button>' +
			(inDeal && R.edit && !t.deal ? '<button type="button" class="d3-tgdx d3-tgat" data-tgatt="' + t.id + '" aria-label="Levélszál csatolása ehhez a dealhez"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg></button>' : '') +
			(inDeal && R.edit ? '<button type="button" class="d3-tgdx" data-tgdet="' + t.id + '" data-auto="' + (t.deal ? '' : '1') + '" aria-label="Levélszál leválasztása erről a dealről"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9.5 14.5l-1.8 1.8a3.2 3.2 0 0 1-4.5-4.5l2.6-2.6a3.2 3.2 0 0 1 4 .4"/><path d="M14.5 9.5l1.8-1.8a3.2 3.2 0 0 1 4.5 4.5l-2.6 2.6a3.2 3.2 0 0 1-4-.4"/><path d="M8 3.5v2.2M3.5 8h2.2M16 20.5v-2.2M20.5 16h-2.2"/></svg></button>' : '') + '</div>' + /* v2.9.152 */
			'<div class="d3-tgl">' + (() => { const ns = (t._notes || []).slice().sort((a, b) => (a.at < b.at ? 1 : -1)); if (!ns.length) return lm.map(g => tmItem(t, g)).join(''); const oldest = lm.length ? lm[lm.length - 1].at || '' : ''; const mix = lm.map(g => ({ at: g.at || '', h: tmItem(t, g) })).concat(ns.filter(n => n.at >= oldest).map(n => ({ at: n.at, h: tnItem(n) }))).sort((a, b) => (a.at < b.at ? 1 : -1)); const older = ns.filter(n => n.at < oldest);
				/* v2.9.187: a látható levelek előtti jegyzetek a „korábbi levelek” közé kerülnek (kinyitáskor időrendben); ha nincs több levél, a végén */
				if (rest && older.length) { TG.notes.set(t.id, older); return mix.map(x => x.h).join(''); } TG.notes.delete(t.id); return mix.map(x => x.h).join('') + older.map(tnItem).join(''); })() +
			(rest ? '<button type="button" class="d3-tgx" data-tgx="' + rest + '" data-skip="' + lm.length + '" aria-expanded="' + !!open + '">' + (open ? 'Korábbi levelek elrejtése' : tgMoreTxt(rest)) + '</button><div class="d3-tgo" data-tgo' + (open ? '>' + cached.html : ' hidden>') + '</div>' : '') +
			'</div></section>';
	}
	const tcSort = m => (m.threads || []).slice().sort((a, b) => ((a.la || a.at || '') < (b.la || b.at || '') ? 1 : (a.la || a.at || '') > (b.la || b.at || '') ? -1 : 0));
	function threadCards(m) {
		if (m.off) return '<p class="cpx-muted">A Levelezés füled nincs bekapcsolva.</p>';
		if (!(m.threads || []).length) return '<p class="cpx-muted d3-tl0">Nincs levélszál.</p>';
		return '<div class="d3-tgs-l">' + tcSort(m).map(threadGroup).join('') + '</div>';
	}
	const bindTm = el => { const go = () => openTh(Number(el.dataset.th), el); el.addEventListener('click', go); el.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } }); };
	async function tgToggle(b) {
		const g = b.closest('.d3-tg'), id = Number(g.dataset.tg), box = g.querySelector('[data-tgo]'), rest = Number(b.dataset.tgx);
		const show = on => { box.hidden = !on; b.setAttribute('aria-expanded', String(on)); b.textContent = on ? 'Korábbi levelek elrejtése' : tgMoreTxt(rest); if (on) TG.open.add(id); else TG.open.delete(id); };
		if (!box.hidden) return show(false);
		const c = TG.cache.get(id);
		if (!(c && c.key === g.dataset.tgk)) {
			b.disabled = true; b.textContent = 'Korábbi levelek betöltése…';
			try {
				const r = await post('cp_crm_thread_msgs', { th: id, skip: b.dataset.skip });
				const t = { id: id, subj: g.querySelector('.d3-tgh').dataset.ths };
				const ons = TG.notes.get(id) || [], rm = r.msgs || [], low = r.more && rm.length ? rm[rm.length - 1].at || '' : '';
				const html = rm.map(m => ({ at: m.at || '', h: tmItem(t, m) })).concat(ons.filter(n => !low || n.at >= low).map(n => ({ at: n.at, h: tnItem(n) }))).sort((a, b) => (a.at < b.at ? 1 : -1)).map(z => z.h).join('') + (r.more ? '<p class="d3-tgn">További ' + Number(r.more) + ' levél nem fért ide – a teljes szál a gyorsnézetben (a tárgyra kattintva).</p>' : '') + (!(r.msgs || []).length ? '<p class="d3-tgn">Nincs több megjeleníthető levél.</p>' : '') +
					/* hosszú listánál alul is van elrejtés (a fókusz a felső gombra ugrik, így a csoport eleje látszik) */
					((r.msgs || []).length > 4 ? '<button type="button" class="d3-tgx" data-tgc>Korábbi levelek elrejtése</button>' : '');
				TG.cache.set(id, { key: g.dataset.tgk, html: html });
			} catch (e) { b.disabled = false; b.textContent = tgMoreTxt(rest); toast(e.message, true); return; }
			b.disabled = false;
		}
		box.innerHTML = TG.cache.get(id).html;
		box.querySelectorAll('.d3-tm').forEach(bindTm);
		box.querySelectorAll('[data-tgc]').forEach(c => c.addEventListener('click', () => { tgToggle(b); b.focus(); }));
		show(true);
	}
	function bindTc(root) {
		root.querySelectorAll('.d3-tgh[data-th]').forEach(b => b.addEventListener('click', () => openTh(Number(b.dataset.th), b)));
		root.querySelectorAll('.d3-tg .d3-tm').forEach(bindTm);
		root.querySelectorAll('[data-tgx]').forEach(b => b.addEventListener('click', () => tgToggle(b)));
		/* v2.9.152: levélszál leválasztása a dealről (a levél a Levelezésben megmarad, csak a dealhez nem tartozik többé) */
		root.querySelectorAll('[data-tgatt]').forEach(b => b.addEventListener('click', async e => {
			e.stopPropagation();
			const did = D3.ctx && D3.ctx.id; if (!did) return;
			b.disabled = true;
			try { await post('cp_mail_thread_set', { id: b.dataset.tgatt, thread: b.dataset.tgatt, f: 'deal', v: String(did) }); toast('Levélszál csatolva a dealhez.'); openDeal(D3.ctx.id, D3.ctx.back); }
			catch (er) { b.disabled = false; toast(er.message, true); }
		}));
		root.querySelectorAll('[data-tgdet]').forEach(b => b.addEventListener('click', async e => {
			e.stopPropagation();
			const g = b.closest('.d3-tg'), subj = (g.querySelector('.d3-tgs') || {}).textContent || '';
			if (!(await window.CPXConfirm((b.dataset.auto ? 'Ez a levélszál nincs a dealhez csatolva, csak a kontakt címe miatt látszik itt. Jelölöd úgy, hogy nem ehhez a dealhez tartozik?' : 'Leválasztod ezt a levélszálat a dealről?') + '\n\n„' + subj + '”\n\nA levél a Levelezésben megmarad, a deal adatlapján nem jelenik meg többé.'))) return;
			b.disabled = true;
			try { await post('cp_mail_thread_set', { id: b.dataset.tgdet, thread: b.dataset.tgdet, f: 'deal', v: 'none' }); g.remove(); toast('Levélszál leválasztva a dealről.'); }
			catch (er) { b.disabled = false; toast(er.message, true); }
		}));
		root.querySelectorAll('.d3-tg [data-tgc]').forEach(c => c.addEventListener('click', () => { const b = c.closest('.d3-tg').querySelector('[data-tgx]'); tgToggle(b); b.focus(); }));
	}
	function bindCrew(sh, x, dealId, back) {
		const crew = x.crew || [];
		sh.querySelectorAll('[data-crgo]').forEach(b => b.addEventListener('click', () => { if (window.CPXSlotDay) window.CPXSlotDay(b.dataset.crgo); }));
		sh.querySelectorAll('[data-crsw]').forEach(sel => {
			const c = crew[Number(sel.dataset.crsw)];
			let loaded = false;
			const load = async () => {
				if (loaded) return; loaded = true;
				try {
					const r = await post('cp_slot_day', { day: c.d });
					const slot = (r.slots || []).find(s => s.deal === dealId && s.skey === c.sk);
					const need = slot ? slot.need : '';
					const L = (r.cands || []).filter(k => k.id !== c.kz);
					const ok = k => !(need === 'kezelo' && k.kind !== 'kezelo') && !(slot && slot.skill && k.sk.length && !k.sk.includes(slot.skill)) && !k.busy.length;
					sel.innerHTML = '<option value="' + c.kz + '" selected>' + esc(c.n) + '</option>' +
						'<optgroup label="Javasolt">' + L.filter(ok).map(k => '<option value="' + k.id + '">' + esc(k.n) + (k.kind === 'kezelo' ? ' (Kezelő)' : ' (Host)') + '</option>').join('') + '</optgroup>' +
						'<optgroup label="Nem javasolt (foglalt / nem kezeli / Host vidékre)">' + L.filter(k => !ok(k)).map(k => '<option value="' + k.id + '">' + esc(k.n) + (k.busy.length ? ' – aznap foglalt' : '') + '</option>').join('') + '</optgroup>';
				} catch (e) { loaded = false; sel.innerHTML = '<option value="' + c.kz + '" selected>' + esc(c.n) + '</option>'; toast(e.message, true); }
			};
			sel.addEventListener('focus', load); sel.addEventListener('pointerdown', load);
			sel.addEventListener('change', async () => {
				const nk = Number(sel.value); if (!nk || nk === c.kz) return;
				const nn = sel.options[sel.selectedIndex].textContent;
				if (!(await window.CPXConfirm('Lecseréled: ' + c.n + ' → ' + nn + '?\n\n' + (c.st === 'final' ? 'Az új ember véglegesített beosztást és megerősítő levelet kap.' : 'Az új ember meghívást kap (a levelet a beosztásnál küldheted ki).')))) { sel.value = String(c.kz); return; }
				sel.disabled = true;
				try { await post('cp_slot_act', { op: 'swap', day: c.d, id: c.id, kz: nk }); toast('Beosztás módosítva.'); openDeal(dealId, back, true); }
				catch (e) { toast(e.message, true); sel.disabled = false; sel.value = String(c.kz); }
			});
		});
	}
	function bindCommon(sh, back) {
		sh.querySelectorAll('[data-open]').forEach(b => b.addEventListener('click', () => openDeal(Number(b.dataset.open), back)));
		/* az idővonal elemeit (.d3-ti) a drawTimeline köti be – itt nem, különben kétszer nyílna meg a levél */
		/* v2.9.69: a szálcsoport fejlécét (.d3-tgh) a bindTc köti be */
		sh.querySelectorAll('[data-th]:not(.d3-ti):not(.d3-tgh):not(.d3-thl)').forEach(b => b.addEventListener('click', () => openTh(Number(b.dataset.th), b)));
		sh.querySelectorAll('[data-ct]').forEach(b => { if (!(Number(b.dataset.ct) > 0)) return; b.addEventListener('click', e => { e.preventDefault(); openContact(Number(b.dataset.ct), back); }); });
		sh.querySelectorAll('[data-co]').forEach(b => { if (!(Number(b.dataset.co) > 0)) return; b.addEventListener('click', e => { e.preventDefault(); openCompany(Number(b.dataset.co), back); }); });
	}
	/* a deal teljes idővonala: levelek, jegyzetek, tevékenységek, feladatok, megkeresések, automatizmusok, változások */
	function timeline(x) {
		const d = x.deal, m = x.mail || { msgs: [], notes: [] }, it = [];
		/* v2.9.68: levelenkénti elemek helyett szálanként egy csoport, a szál legutóbbi levelének idejénél (új levéllel a szál felülre kerül); v2.9.69: hibrid csoport */
		(m.off ? [] : m.threads || []).forEach(t => { t._notes = (m.notes || []).filter(n => n.th === t.id); const ln = t._notes.reduce((a, n) => (n.at > a ? n.at : a), ''); it.push({ k: 'mail', at: ln > (t.la || t.at || '') ? ln : (t.la || t.at), h: threadGroup(t, true) }); });
		(x.dnotes || []).forEach(n => it.push({ k: 'note', at: n.at, h: '<div class="d3-ti note dnote"><i>' + I.note + '</i><div><div class="d3-tt"><b>Jegyzet a dealen</b> – ' + esc(n.author || '') + (n.src === 'sm' || n.src === 'smi' ? ' <span class="d3-q" title="' + (n.src === 'smi' ? 'A Salesmate egyik levélszálán írták' : 'A Salesmate-ben írták') + '">Salesmate</span>' : '') + '<small>' + esc(huDT(n.at)) + '</small>' + (W && (n.mine || (C.au && C.au.on)) ? '<button type="button" class="d3-x d3-ned" data-nedit="' + n.id + '" title="Jegyzet szerkesztése (a Salesmate-ben is)">✎</button><button type="button" class="d3-x" data-ndel="' + n.id + '" title="Jegyzet törlése (a Salesmate-ből is)">×</button>' : '') + '</div><div class="d3-tx" data-nbody="' + n.id + '">' + nl(n.body) + '</div></div></div>' }));
		/* v2.9.184: a levélszálra írt jegyzet jelölve (melyik szálé), kattintásra nem nyit meg semmit; a szálat a „Levélszál ›” link nyitja */
		const thS = {}; (m.threads || []).forEach(t => { thS[t.id] = t.subj || ''; });
		(m.notes || []).forEach(n => it.push({ k: 'note', tn: 1, at: n.at, h: '<div class="d3-ti note thnote"><i>' + I.note + '</i><div><div class="d3-tt"><b>Jegyzet a levélszálon</b> – ' + esc(n.by) + '<small>' + esc(huDT(n.at)) + '</small>' + tnEd(n) + '</div><div class="d3-tx" data-tnbody="' + n.id + '">' + nl(n.b) + '</div><div class="d3-tnth">✉ <span>' + esc(thS[n.th] || 'levélszál') + '</span> <button type="button" class="d3-thl" data-th="' + n.th + '" data-ths="' + esc(thS[n.th] || '') + '">Levélszál ›</button></div></div></div>' }));
		(x.acts || []).filter(a => Number(a.completed)).forEach(a => it.push({ k: 'act', at: a.completed_at || a.due_at, h: '<div class="d3-ti act"><i>' + I.act + '</i><div><div class="d3-tt"><b>' + esc(a.title || a.type) + '</b> <span class="d3-q">kész</span><small>' + esc(huDT(a.completed_at || a.due_at)) + '</small>' + (W && Number(a.related_id) === d.id ? '<button type="button" class="d3-ab" data-aundo="' + a.id + '" title="Visszanyitás">↺</button>' : '') + '</div><div class="d3-ts">' + esc([a.type, a.owner].filter(Boolean).join(' · ')) + '</div>' + (a.description ? '<div class="d3-tx">' + nl(a.description) + '</div>' : '') + '</div></div>' }));
		(x.cards || []).forEach(c => it.push({ k: 'card', at: c.at, h: '<div class="d3-ti card" data-card="' + c.id + '" role="button" tabindex="0"><i>' + I.card + '</i><div><div class="d3-tt"><b>Feladat</b> – ' + esc(c.by) + '<small>' + esc(huDT(c.at)) + '</small></div><div class="d3-ts">' + (c.done ? '✓ ' : '') + esc(c.title) + '</div><div class="d3-tx">' + esc([c.list, c.as.join(', '), c.due ? 'határidő: ' + huD(c.due) : ''].filter(Boolean).join(' · ')) + '</div></div></div>' }));
		/* v2.9.109: az ügyfél megnyitotta a panelből küldött levelet (első valódi megnyitás) */
		(x.opens || []).forEach(o => it.push({ k: 'mail', at: o.at, h: '<div class="d3-ti open" data-th="' + o.th + '" role="button" tabindex="0"><i>👁</i><div><div class="d3-tt"><b>Az ügyfél megnyitotta a levelet</b><small>' + esc(huDT(o.at)) + '</small></div><div class="d3-tx">„' + esc(o.s) + '”' + (o.n > 1 ? ' · ' + o.n + '× megnyitva, utoljára ' + esc(huDT(o.l)) : '') + '</div></div></div>' }));
		(x.leads || []).forEach(l => it.push({ k: 'lead', at: l.at, h: '<div class="d3-ti lead"><i>' + I.lead + '</i><div><div class="d3-tt"><b>Webes megkeresés</b> – ' + esc(l.form) + '<small>' + esc(huDT(l.at)) + '</small></div><dl class="d3-kv">' + l.kv.map(p => '<dt>' + esc(p[0]) + '</dt><dd>' + nl(p[1]) + '</dd>').join('') + '</dl></div></div>' }));
		(x.flows || []).forEach(f => it.push({ k: 'hist', at: f.at, h: '<div class="d3-ti flow"><i>' + I.flow + '</i><div><div class="d3-tt"><b>Automatizmus</b> – ' + esc(f.n) + (f.mode === 'shadow' ? ' <span class="d3-q">árnyék</span>' : '') + '<small>' + esc(huDT(f.at)) + '</small></div><div class="d3-tx">' + nl(f.detail) + '</div></div></div>' }));
		/* a változások: az egyszerre (ugyanakkor, ugyanattól) történtek egy bejegyzésben */
		const hs = [];
		(x.hist || []).forEach(r => { const l = hs[hs.length - 1]; if (l && l.at === r.at && l.by === r.by_user) l.rows.push(r); else hs.push({ at: r.at, by: r.by_user, rows: [r] }); });
		hs.forEach(g => it.push({ k: 'hist', at: g.at, h: '<div class="d3-ti hist"><i>' + I.hist + '</i><div><div class="d3-tt"><b>' + (g.rows.length === 1 ? esc(LBL[g.rows[0].field] || g.rows[0].field) + ' módosítva' : g.rows.length + ' mező módosítva') + '</b>' + (g.by ? ' – ' + esc(g.by) : '') + '<small>' + esc(huDT(g.at)) + '</small></div>' +
			g.rows.map(r => '<div class="d3-hv">' + (g.rows.length > 1 ? '<span>' + esc(LBL[r.field] || r.field) + ':</span> ' : '') + (r.old_val ? '<s>' + esc(histVal(r.field, r.old_val)) + '</s> → ' : '') + '<b>' + esc(histVal(r.field, r.new_val) || '–') + '</b></div>').join('') + '</div></div>' }));
		(x.portal_ev || []).forEach(p => it.push({ k: 'portal', at: p.at, h: '<div class="d3-ti portal' + (p.ok ? '' : ' late') + '"><i>' + I.portal + '</i><div><div class="d3-tt"><b>' + esc(p.text) + '</b>' + (p.ok ? '' : ' <span class="d3-q">hiba</span>') + '<small>' + esc(huDT(p.at)) + '</small></div></div></div>' }));
		if (d.created_at) it.push({ k: 'hist', at: d.created_at, h: '<div class="d3-ti hist"><i>' + I.plus + '</i><div><div class="d3-tt"><b>Deal létrehozva</b>' + (d.created_by_n ? ' – ' + esc(d.created_by_n) : '') + '<small>' + esc(huDT(d.created_at)) + '</small></div>' + (d.source ? '<div class="d3-tx">Forrás: ' + esc(d.source) + '</div>' : '') + '</div></div>' });
		return it.filter(i => i.at).sort((a, b) => (a.at < b.at ? 1 : a.at > b.at ? -1 : 0));
	}
	/* v2.9.68: szűrő-csoportok – a tevékenység, feladat, webes megkeresés, automatizmus és a deal létrehozása a „Változások” alá kerül
	   (így minden bejegyzés pontosan egy szűrőben van, és a darabszámok összege a „Mind”) */
	const TL_GRP = { mail: 'mail', note: 'note', portal: 'portal', hist: 'hist', act: 'hist', card: 'hist', lead: 'hist' };
	const TL_EMPTY = { all: 'Ezen a dealen még nincs bejegyzés.', mail: 'Ehhez a dealhez még nincs levélszál.', note: 'Még nincs jegyzet.', portal: 'Nincs portál-esemény (még nem készült portál, vagy nem történt rajta semmi).', hist: 'Nincs változás, tevékenység vagy feladat.' };
	function drawTimeline(box, x) {
		const all = timeline(x);
		const cnt = k => k === 'all' ? all.filter(i => !i.tn).length : all.filter(i => TL_GRP[i.k] === k).length; /* v2.9.185: a szálas jegyzet a Mind-ban a szálon belül van */
		const up = (x.acts || []).filter(a => !Number(a.completed)).sort((a, b) => (a.due_at || '') < (b.due_at || '') ? -1 : 1);
		const cardsOpen = (x.cards || []).filter(c => !c.done && c.due);
		const list = all.filter(i => (D3.tab === 'all' && !i.tn) || TL_GRP[i.k] === D3.tab);
		let h = '<div class="d3-tabs" role="group" aria-label="Idővonal szűrése">' + TL_TABS.map(t => '<button type="button" data-t3="' + t[0] + '" aria-pressed="' + (D3.tab === t[0]) + '" class="' + (D3.tab === t[0] ? 'on' : '') + '">' + t[1] + ' <small>' + cnt(t[0]) + '</small></button>').join('') + '<button type="button" class="d3-monb" data-mon aria-pressed="' + D3.mon + '" title="Hónap szerinti bontás be/ki">📅 Hónapok</button></div>'; /* v2.9.209: a hónap-fejlécek kikapcsolhatók */
		if ((up.length || cardsOpen.length) && (D3.tab === 'all' || D3.tab === 'hist')) {
			h += '<h5 class="d3-mh">Közelgő (' + (up.length + cardsOpen.length) + ')</h5>' + up.map(a => { const late = a.due_at && a.due_at < today(); const mine = W && Number(a.related_id) === x.deal.id; return '<div class="d3-ti act up' + (late ? ' late' : '') + '"><i>' + I.act + '</i><div><div class="d3-tt"><b>' + esc(a.title || a.type) + '</b>' + (a.owner ? ' – ' + esc(a.owner) : '') + '<small>' + esc(huDT(a.due_at)) + (late ? ' · lejárt' : '') + '</small>' + (mine ? '<button type="button" class="d3-ab ok" data-adone="' + a.id + '" title="Késznek jelölés (a Salesmate-ben is)">✓ Kész</button><button type="button" class="d3-ab" data-aedit="' + a.id + '" title="Szerkesztés">✎</button>' : '') + '</div><div class="d3-ts">' + esc(a.type || '') + '</div>' + (a.description ? '<div class="d3-tx">' + nl(a.description) + '</div>' : '') + '</div></div>'; }).join('') +
				cardsOpen.map(c => '<div class="d3-ti card up" data-card="' + c.id + '" role="button" tabindex="0"><i>' + I.card + '</i><div><div class="d3-tt"><b>' + esc(c.title) + '</b>' + (c.as.length ? ' – ' + esc(c.as.join(', ')) : '') + '<small>határidő: ' + esc(huD(c.due)) + '</small></div><div class="d3-ts">' + esc(c.list) + '</div></div></div>').join('');
		}
		let mon = '';
		h += list.map(i => { const k = i.at.slice(0, 7); const mh = D3.mon && k !== mon ? '<h5 class="d3-mh">' + k.slice(0, 4) + '. ' + HU_MF[Number(k.slice(5, 7)) - 1] + '</h5>' : ''; mon = k; return mh + i.h; }).join('') || '<p class="cpx-muted d3-tl0">' + (D3.tab === 'mail' && (x.mail || {}).off ? 'A Levelezés füled nincs bekapcsolva.' : TL_EMPTY[D3.tab]) + '</p>';
		box.innerHTML = h;
		box.querySelector('[data-mon]').addEventListener('click', () => {
			D3.mon = !D3.mon; try { localStorage.setItem('cp_d3_mon', D3.mon ? '1' : '0'); } catch (e) { /* privát mód */ }
			drawTimeline(box, x); const f = box.querySelector('[data-mon]'); if (f) f.focus();
		});
		box.querySelectorAll('[data-t3]').forEach(b => b.addEventListener('click', () => {
			D3.tab = b.dataset.t3; try { localStorage.setItem(TL_KEY, D3.tab); } catch (e) { /* privát mód */ }
			drawTimeline(box, x); const f = box.querySelector('[data-t3="' + D3.tab + '"]'); if (f) f.focus();
		}));
		const re = () => D3.ctx && openDeal(D3.ctx.id, D3.ctx.back);
		const run = async (btn, action, p, msg) => { btn.disabled = true; try { await post(action, p); toast(msg); re(); } catch (e) { btn.disabled = false; toast(e.message, true); } };
		box.querySelectorAll('[data-adone]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); run(b, 'cp_crm_act_done', { id: b.dataset.adone, done: 1 }, 'Kész – a Salesmate-ben is.'); }));
		box.querySelectorAll('[data-aundo]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); run(b, 'cp_crm_act_done', { id: b.dataset.aundo, done: '' }, 'Visszanyitva – a Salesmate-ben is.'); }));
		box.querySelectorAll('[data-aedit]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); const a = (x.acts || []).find(z => String(z.id) === b.dataset.aedit); const c = document.querySelector('.crm-360 [data-comp]'); if (a && c) compose(c, x, 'act', a); }));
		box.querySelectorAll('[data-ndel]').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); window.CPXConfirm('A Salesmate-ből is törlődik.', { title: 'Törlöd a jegyzetet?', ok: 'Törlés', danger: true }).then(ok => { if (ok) run(b, 'cp_crm_note_del', { id: b.dataset.ndel }, 'Jegyzet törölve.'); }); }));
		/* v2.9.170: jegyzet szerkesztése helyben (a Salesmate-ben is módosul) */
		box.querySelectorAll('[data-nedit]').forEach(b => b.addEventListener('click', e => {
			e.stopPropagation();
			const id = b.dataset.nedit, tx = box.querySelector('[data-nbody="' + id + '"]'), n = (x.dnotes || []).find(z => String(z.id) === id); if (!tx || !n || tx.querySelector('textarea')) return;
			tx.innerHTML = '<textarea class="d3-nta" rows="3"></textarea><div class="d3-nbar"><button type="button" class="cpx-btn sm" data-nok>Mentés</button><button type="button" class="cpx-btn ghost sm" data-nx>Mégse</button></div>';
			const ta = tx.querySelector('textarea'); ta.value = n.body || ''; ta.style.height = Math.min(400, ta.scrollHeight + 4) + 'px'; ta.focus();
			['click', 'keydown'].forEach(ev => tx.addEventListener(ev, z => z.stopPropagation()));
			const save = async () => { const ok = tx.querySelector('[data-nok]'); ok.disabled = true; try { await post('cp_crm_note_edit', { id: id, body: ta.value }); toast('Jegyzet mentve – a Salesmate-ben is.'); re(); } catch (er) { ok.disabled = false; toast(er.message, true); } };
			tx.querySelector('[data-nok]').addEventListener('click', save);
			tx.querySelector('[data-nx]').addEventListener('click', () => { tx.innerHTML = nl(n.body); });
			ta.addEventListener('keydown', z => { if (z.key === 'Enter' && (z.ctrlKey || z.metaKey)) { z.preventDefault(); save(); } if (z.key === 'Escape') { z.preventDefault(); tx.innerHTML = nl(n.body); } });
		}));
		box.querySelectorAll('.d3-thl').forEach(b => b.addEventListener('click', e => { e.stopPropagation(); openTh(Number(b.dataset.th), b); }));
		box.querySelectorAll('.d3-ti[data-th]:not(.d3-tm)').forEach(b => { const go = () => openTh(Number(b.dataset.th), b); b.addEventListener('click', e => { if (e.target.closest('a')) return; go(); }); /* v2.9.81: a jegyzetben lévő linkre kattintva a link nyílik, nem a szál */ b.addEventListener('keydown', e => { if (e.key === 'Enter') go(); }); });
		bindTc(box);
		box.querySelectorAll('.d3-ti[data-card]').forEach(b => { const go = () => { hideModal(); closePage(); if (window.CPNotify && window.CPNotify.go) window.CPNotify.go('feladatok:' + b.dataset.card); }; b.addEventListener('click', go); b.addEventListener('keydown', e => { if (e.key === 'Enter') go(); }); });
	}
	/* v1.59: az ügyfélportál kártyája a deal-adatlapon */
	const PST = { hianyos: 'Hiányos', kesz: 'Kész', modositva: 'Módosítva' };
	function portalCard(x) {
		const P = C.portal || {}, s = x.portal;
		if (!P.on || !s) return '';
		if (s.none) {
			return '<section class="d3-c d3-portal"><h4>Ügyfélportál</h4>' + (s.mode !== 'on' ? '<p class="cpx-muted">Az ügyfélportál ki van kapcsolva.</p>' :
				'<p class="d3-sub">Még nincs portál. Kiküldéskor létrejön, az ügyfél meghívó levelet kap a belépőlinkkel' + (P.sentStage ? ', és a deal az „' + esc(P.sentStage) + '” szakaszba kerül' : '') + '.</p>' +
				'<div class="d3-pact"><select data-ptype aria-label="Megrendelő"><option value="">az ügyfél választja</option><option value="magan">magánszemély</option><option value="ceg">cég</option></select><button type="button" class="cpx-btn" data-psend>Ügyfélportál kiküldése</button></div>') + '</section>';
		}
		const pct = s.total ? Math.round(s.done / s.total * 100) : 0;
		return '<section class="d3-c d3-portal"><h4>Ügyfélportál <span class="d3-pst ' + esc(s.st) + '">' + esc(PST[s.st] || s.st) + '</span></h4>' +
			'<div class="d3-pbar"><i style="width:' + pct + '%"></i></div><p class="d3-sub"><b>' + s.done + '/' + s.total + '</b> kötelező kérdés · szerződéshez: ' + s.cd + '/' + s.ct + (s.deadline ? ' · határidő: <b>' + esc(s.deadline) + '</b>' + (s.passed ? ' (lezárva)' : '') : '') + '</p>' +
			(s.flags && s.flags.length ? '<div class="d3-pflags">' + s.flags.map(f => '<span class="d3-q ' + esc(f.tone) + '">' + esc(f.t) + '</span>').join('') + '</div>' : '') +
			(s.missing && s.missing.length ? '<p class="d3-sub">Hiányzik: ' + esc(s.missing.join(', ')) + '</p>' : '') +
			/* v1.92: eltérés a portál és a deal között – egy kattintással átvehető */
			(s.diff && s.diff.length ? '<div class="d3-pdiff"><b>Az ügyfél a portálon mást adott meg:</b>' + s.diff.map((f, i) => '<div class="d3-pdr"><span>' + esc(f.l) + ': <b>' + esc(f.k === 'event_d' ? huD(f.p) : f.p) + '</b> <small>(a dealen: ' + esc(f.d === '' ? 'üres' : f.k === 'event_d' ? huD(f.d) : f.d) + ')</small></span><button type="button" class="cpx-btn ghost sm" data-pdiff="' + i + '">Átveszem</button></div>').join('') + '</div>' : '') +
			'<p class="d3-sub">' + (s.last_open ? 'Az ügyfél utoljára megnyitotta: ' + esc(huDT(s.last_open)) : 'Az ügyfél még nem nyitotta meg.') + '</p>' +
			'<div class="d3-pact"><button type="button" class="cpx-btn" data-popen>Adatlap megnyitása</button><button type="button" class="cpx-btn ghost" data-plink>Link másolása</button><button type="button" class="cpx-btn ghost" data-pmail>Új link e-mailben</button></div></section>';
	}
	function bindPortal(sh, x, reopen) {
		const id = x.deal.id, title = x.deal.title || 'Deal #' + id;
		const call = async (action, p) => post(action, Object.assign({ id: id }, p || {}));
		const ps = sh.querySelector('[data-psend]');
		if (ps) ps.addEventListener('click', async () => {
			if (!(await window.CPXConfirm('Kiküldöd az ügyfélportált?\n\nAz ügyfél meghívó levelet kap a belépőlinkkel' + ((C.portal || {}).sentStage ? ', és a deal a Salesmate-ben az „' + C.portal.sentStage + '” szakaszba kerül.' : '.')))) return;
			ps.disabled = true;
			try { const r = await call('cp_portal_p_send', { type: sh.querySelector('[data-ptype]').value }); toast(r.msg || 'Kiküldve.'); reopen(); } catch (e) { ps.disabled = false; toast(e.message, true); }
		});
		const po = sh.querySelector('[data-popen]');
		if (po) po.addEventListener('click', () => { if (window.CPPortal && window.CPPortal.open) window.CPPortal.open(id, title); else toast('Az ügyfélportál felülete nem töltődött be.', true); });
		const pl = sh.querySelector('[data-plink]');
		if (pl) pl.addEventListener('click', async () => {
			pl.disabled = true;
			try { const r = await call('cp_portal_p_link', { op: 'new' }); try { await navigator.clipboard.writeText(r.url); toast('Új személyes link kimásolva – csak az ügyfélnek küldd el.'); } catch (e) { window.prompt('A személyes link (másold ki):', r.url); } }
			catch (e) { toast(e.message, true); }
			pl.disabled = false;
		});
		/* v1.92: portál-eltérés átvétele a dealbe (a Salesmate-be is) */
		sh.querySelectorAll('[data-pdiff]').forEach(b => b.addEventListener('click', async () => {
			const f = ((x.portal || {}).diff || [])[Number(b.dataset.pdiff)]; if (!f) return;
			b.disabled = true;
			/* v2.9.78: többnapos rendezvény napi eltérése: a nap felülírásába (csak a panelben) */
			if (f.f === 'mday') { try { await post('cp_crm_day_set', { id: id, day: f.day, fld: f.fld, v: String(f.v) }); toast(f.l + ' átvéve a nap adatai közé (csak a panelben).'); reopen(); } catch (e) { b.disabled = false; toast(e.message, true); } return; }
			try { const r = await post('cp_crm_save', { id: id, f: JSON.stringify({ [f.f]: String(f.v) }), c: '{}' }); toast(r.warn ? 'Átvéve, de: ' + r.warn : f.l + ' átvéve a dealbe – a Salesmate-ben is.', !!r.warn); reopen(); }
			catch (e) { b.disabled = false; toast(e.message, true); }
		}));
		const pm = sh.querySelector('[data-pmail]');
		if (pm) pm.addEventListener('click', async () => { if (!(await window.CPXConfirm('Új belépőlinket küldök e-mailben az ügyfélnek. Mehet?'))) return; pm.disabled = true; try { const r = await call('cp_portal_p_link', { op: 'send' }); toast(r.msg || 'Elküldve.'); reopen(); } catch (e) { pm.disabled = false; toast(e.message, true); } });
	}
	/* jegyzet vagy tevékenység felvétele a dealre (a Salesmate-be is) */
	function compose(box, x, mode, a) {
		const d = x.deal, types = x.act_types || ['Task', 'Call'];
		const me = R.me_sm && USERS[String(R.me_sm)] ? String(R.me_sm) : String(d.owner_id || '');
		const due = a && a.due_at ? a.due_at.slice(0, 10) : '', tm = a && a.due_at && a.due_at.slice(11, 16) !== '01:00' && a.due_at.slice(11, 16) !== '00:00' ? a.due_at.slice(11, 16) : '';
		const owners = Object.keys(USERS).filter(i => USERS[i].a || String(i) === String(a ? a.owner_id : me)).map(i => [i, USERS[i].n]);
		box.innerHTML = '<div class="d3-ctabs"><button type="button" data-cm="note" class="' + (mode === 'note' ? 'on' : '') + '">📝 Jegyzet</button><button type="button" data-cm="act" class="' + (mode === 'act' ? 'on' : '') + '">📅 ' + (a ? 'Tevékenység szerkesztése' : 'Tevékenység') + '</button></div>' +
			(mode === 'note' ? '<textarea data-nb rows="2" placeholder="Jegyzet a dealhez – @Név: megemlítés. (Ctrl+Enter: mentés)"></textarea><div class="d3-cbar"><small>A Salesmate-be is beíródik.</small><button type="button" class="cpx-btn" data-save>Jegyzet mentése</button></div>' :
			'<div class="d3-af"><input data-at type="text" placeholder="Mi a teendő? (pl. Visszahívni az árajánlat miatt)" value="' + esc(a ? a.title : '') + '">' +
				'<label>Típus <select data-ty>' + types.concat(a && a.type && !types.includes(a.type) ? [a.type] : []).map(t => '<option' + ((a ? a.type : types[0]) === t ? ' selected' : '') + '>' + esc(t) + '</option>').join('') + '</select></label>' +
				'<label>Határidő <input data-ad type="date" value="' + esc(due || today()) + '"></label><label>Időpont <input data-am type="time" value="' + esc(tm) + '"></label>' +
				'<label>Hossz <select data-du>' + [[15, '15 perc'], [30, '30 perc'], [60, '1 óra'], [120, '2 óra'], [240, '4 óra'], [480, 'egész nap']].map(o => '<option value="' + o[0] + '"' + (Number(a ? a.duration || 30 : 30) === o[0] ? ' selected' : '') + '>' + o[1] + '</option>').join('') + '</select></label>' +
				'<label>Felelős <select data-ow>' + opts(owners, String(a ? a.owner_id : me)) + '</select></label>' +
				'<textarea data-ds rows="2" placeholder="Leírás (nem kötelező)">' + esc(a ? a.description || '' : '') + '</textarea></div>' +
				'<div class="d3-cbar"><small>A Salesmate-be is beíródik' + (a ? '' : ', a naptárszinkron ugyanúgy látja') + '. Időpont nélkül egész napos.</small>' + (a ? '<button type="button" class="cpx-btn ghost" data-cancel>Mégse</button>' : '') + '<button type="button" class="cpx-btn" data-save>' + (a ? 'Mentés' : 'Tevékenység felvétele') + '</button></div>');
		box.querySelectorAll('[data-cm]').forEach(b => b.addEventListener('click', () => compose(box, x, b.dataset.cm)));
		const cancel = box.querySelector('[data-cancel]');
		if (cancel) cancel.addEventListener('click', () => compose(box, x, 'act'));
		const btn = box.querySelector('[data-save]');
		const done = msg => { toast(msg); if (D3.ctx) openDeal(D3.ctx.id, D3.ctx.back); };
		const save = async () => {
			let p, action;
			if (mode === 'note') {
				const v = box.querySelector('[data-nb]').value.trim();
				if (!v) { box.querySelector('[data-nb]').focus(); return; }
				action = 'cp_crm_note_add'; p = { deal: d.id, body: v };
			} else {
				const t = box.querySelector('[data-at]').value.trim();
				if (!t) { box.querySelector('[data-at]').focus(); return; }
				action = 'cp_crm_act_save';
				p = { deal: d.id, id: a ? a.id : '', title: t, type: box.querySelector('[data-ty]').value, due: box.querySelector('[data-ad]').value, time: box.querySelector('[data-am]').value, dur: box.querySelector('[data-du]').value, owner: box.querySelector('[data-ow]').value, descr: box.querySelector('[data-ds]').value };
			}
			btn.disabled = true; btn.textContent = 'Mentés…';
			try { await post(action, p); done(mode === 'note' ? 'Jegyzet mentve – a Salesmate-ben is.' : (a ? 'Tevékenység módosítva – a Salesmate-ben is.' : 'Tevékenység felvéve – a Salesmate-ben is.')); }
			catch (e) { btn.disabled = false; btn.textContent = mode === 'note' ? 'Jegyzet mentése' : (a ? 'Mentés' : 'Tevékenység felvétele'); toast(e.message, true); }
		};
		btn.addEventListener('click', save);
		const nb = box.querySelector('[data-nb]');
		if (nb) nb.addEventListener('keydown', e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); save(); } });
		if (a) box.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
	}
	/* a deal mezői csoportosítva; ami egyik csoportba sem került és van értéke, az „Egyéb” alá */
	const GROUPS = [
		['Rendezvény', ['event_d', 'event_end_d', 'start_at', 'venue', 'guests', 'hours', 'service', 'package', 'services_cp', 'services_ec', 'services_more', 'extras', 'background', 'km_fee', 'extension', 'operator', 'album', 'coupon']],
		['Sablon és portál', ['template_st', 'template_paid', 'template_link', 'portal_link']],
		['Pénzügy és szerződés', ['value', 'pay_method', 'paid', 'ctype']],
		['Állapot', ['stage', 'status', 'owner_id', 'source', 'tags', 'lost_reason', 'est_close_d', 'closed_at', 'reminder', 'cal_check']],
		['Megjegyzés', ['note', 'last_note']]
	];
	const NOSHOW = ['id', 'title', 'currency', 'contact_id', 'company_id', 'raw', 'via', 'synced_at', 'deleted_at', 'created_at', 'modified_at', 'created_by', 'modified_by', 'created_by_n', 'modified_by_n', 'owner', 'last_comm_at', 'last_comm_mode', 'last_note_at', 'rotten_d', 'win_prob', 'description', 'closed_stage', 'pkg_old', 'pkg_v2', 'pkg_fix', 'spinner_pkg', 'melange_pkg'];
	function fieldGroups(d, pinfo) {
		const used = new Set(NOSHOW);
		/* v2.9.165: az ügyfél-adatlap (portál) hasznos válaszai a Rendezvény csoport után – csak olvasható */
		const pi = (pinfo || []).length ? '<details class="d3-g d3-pinfo" open><summary>Az ügyfél adatlapjáról</summary><dl class="crm-dl">' + pinfo.map(r => '<dt>' + esc(r.l) + '</dt><dd>' + esc(r.t) + '</dd>').join('') + '</dl></details>' : '';
		let h = GROUPS.map(g => { g[1].forEach(k => used.add(k)); const b = dl(d, g[1], !d.deleted_at); return (b ? '<details class="d3-g" open><summary>' + esc(g[0]) + '</summary>' + b + '</details>' : '') + (g[0] === 'Rendezvény' ? pi : ''); }).join('');
		const rest = Object.keys(d).filter(k => !used.has(k) && d[k] != null && d[k] !== '' && LBL[k]);
		const pk = ['pkg_v2', 'pkg_fix', 'spinner_pkg', 'melange_pkg', 'pkg_old'].filter(k => d[k]);
		if (rest.length || pk.length) h += '<details class="d3-g"><summary>Egyéb</summary>' + dl(d, rest.concat(pk)) + '</details>';
		if (d.description) h += '<details class="d3-g"><summary>Leírás</summary><div class="crm-desc">' + nl(d.description) + '</div></details>';
		return h;
	}
	function stageBar(d) {
		if (!STAGES.length) return '';
		const i = STAGES.indexOf(d.stage);
		return '<div class="d3-stages' + (d.status === 'Won' ? ' won' : d.status === 'Lost' ? ' lost' : '') + '">' + STAGES.map((s, k) => '<button type="button" data-stg="' + esc(s) + '" class="' + (k < i ? 'done' : k === i ? 'cur' : '') + '"' + (W && d.status === 'Open' && !d.deleted_at && k !== i ? '' : ' disabled') + ' title="' + esc(s) + (W && d.status === 'Open' && k !== i ? ' – kattints az áthelyezéshez' : '') + '"><span>' + esc(s) + '</span></button>').join('') + '</div>';
	}
	async function coSuggest(box, did, reload) {
		let r;
		try { r = await post('cp_crm_co_suggest', { id: did }); } catch (e) { return; }
		if (!box.isConnected || !r || r.why !== 'ok') return;
		const rows = r.rows || [];
		const form = (n, w) => {
			box.innerHTML = '<div class="d3-cosf"><label>Cégnév<input type="text" data-cn maxlength="190" value="' + esc(n || '') + '"></label><label>Weboldal<input type="text" data-cw maxlength="255" value="' + esc(w || '') + '"></label>' +
				'<div class="d3-cosb"><button type="button" class="cpx-btn" data-cok>Létrehozás és hozzárendelés</button><button type="button" class="cpx-btn ghost" data-cno>Mégse</button></div><small>Új cég jön létre a Salesmate-ben (ha már van ilyen nevű, azt kapja meg), és a kontakthoz és a deal-hez is hozzárendelem.</small></div>';
			const i = box.querySelector('[data-cn]'); i.focus(); i.setSelectionRange(i.value.length, i.value.length);
			box.querySelector('[data-cno]').addEventListener('click', draw);
			box.querySelector('[data-cok]').addEventListener('click', () => apply({ name: i.value.trim(), website: box.querySelector('[data-cw]').value.trim() }, box.querySelector('[data-cok]')));
		};
		const apply = async (p, btn) => {
			if (p.name !== undefined && p.name.length < 2) { toast('Add meg a cég nevét.', true); return; }
			if (btn) { btn.disabled = true; btn.textContent = 'Mentés…'; }
			try { const o = await post('cp_crm_co_apply', Object.assign({ id: did }, p)); toast(o.warn ? 'Hozzárendelve, de: ' + o.warn : 'A cég hozzárendelve a kontakthoz és a deal-hez.', !!o.warn); reload(); }
			catch (e) { toast(e.message, true); if (btn) { btn.disabled = false; btn.textContent = 'Újra'; } }
		};
		function draw() {
			box.innerHTML = '<div class="d3-cosh">✨ Javasolt cég <small>@' + esc(r.dom) + '</small></div>' +
				(rows.length ? rows.map((x, k) => '<div class="d3-cosr"><span><b>' + esc(x.n) + '</b><small>' + esc(x.src) + '</small></span><button type="button" class="cpx-btn" data-cosk="' + k + '" title="' + (x.id ? 'Hozzárendelés ehhez a meglévő céghez' : 'Átnézed a nevet, majd létrehozod') + '">' + (x.id ? '✓ Hozzárendelés' : '✓ Jóváhagyás…') + '</button></div>').join('') : '<p class="cpx-muted">Az aláírásból és a weboldalból nem találtam cégnevet.</p>') +
				'<button type="button" class="d3-cosm" data-cosm>' + (rows.length ? 'Más név megadása' : '+ Cég megadása') + '</button>';
			box.querySelectorAll('[data-cosk]').forEach(b => b.addEventListener('click', () => {
				const x = rows[Number(b.dataset.cosk)];
				if (x.id) apply({ co: x.id }, b); else form(x.n, x.w);
			}));
			box.querySelector('[data-cosm]').addEventListener('click', () => form('', 'https://' + r.dom));
		}
		draw();
	}
	/* v2.5: visszatérő ügyfél – a kontakt és a cég korábbi deal-jeiből */
	function retBadge(list, c) {
		const seen = new Set(), L = list.filter(o => !seen.has(o.id) && seen.add(o.id));
		if (!L.length) return '';
		const won = L.filter(o => o.status === 'Won'), v = won.reduce((a, o) => a + Number(o.value || 0), 0);
		const last = won.map(o => o.event_d).filter(Boolean).sort().pop();
		if (won.length) return '<div class="d3-ret" title="A kontakt és a cége korábbi deal-jei – lent, a „Korábbi munkák” alatt">🔁 <b>Visszatérő ügyfél</b> · ' + won.length + ' megnyert rendezvény' + (v ? ' (' + esc(money(v)) + ')' : '') + (last ? ' · utoljára ' + esc(huD(last)) : '') + '</div>';
		return '<div class="d3-ret lo">↺ Korábban már érdeklődött (' + L.length + ' deal, még nem rendelt)</div>';
	}
	async function trvCard(box, d, reload) {
		let r;
		try { r = await post('cp_crm_trv', { id: d.id }); } catch (e) { box.innerHTML = '<small class="cpx-muted">A kiszállási díjat most nem tudom kiszámolni.</small>'; return; }
		if (!box.isConnected) return;
		const cur = Number(r.cur || 0);
		/* v2.9.11: kézi összeg is megadható (pl. egyedi megállapodás, vidéki helyszín, ahol a számítás nem pontos) */
		const save = async (v, btn) => {
			if (btn) { btn.disabled = true; btn.textContent = 'Mentés…'; }
			try { await post('cp_crm_save', { id: d.id, f: JSON.stringify({ km_fee: String(v) }), c: '{}' }); toast('A kiszállási díj (' + (v ? money(v) : '0 Ft') + ') beírva a dealbe – a Salesmate-ben is.'); reload(); }
			catch (e) { if (btn) { btn.disabled = false; btn.textContent = 'Újra'; } toast(e.message, true); }
		};
		const man = () => W ? '<form class="d3-trvm" data-trvm novalidate><label>Kézi összeg<input type="number" min="0" step="1" inputmode="numeric" value="' + (cur ? cur : '') + '" placeholder="pl. 15000"> Ft</label><button type="submit" class="cpx-btn sm ghost">Mentés</button></form>' : '';
		const wire = () => {
			const f = box.querySelector('[data-trvm]');
			if (f) f.addEventListener('submit', e => { e.preventDefault(); const i = f.querySelector('input'), v = Math.round(Number(i.value)); if (i.value === '' || !(v >= 0)) { toast('Adj meg egy összeget (Ft).', true); i.focus(); return; } if (v === cur) { toast('A dealen is ennyi szerepel.'); return; } save(v, f.querySelector('button')); });
		};
		if (!r.ok) { box.innerHTML = '<div class="d3-trvh">🚐 Kiszállás' + (cur ? ': <b>' + esc(money(cur)) + '</b> <small>(a dealen)</small>' : '') + '</div><small class="cpx-muted">' + esc(r.msg || '') + '</small>' + man(); wire(); return; }
		const fee = r.fee == null ? null : Number(r.fee), same = fee != null && fee === cur;
		box.innerHTML = '<div class="d3-trvh">🚐 Kiszállási díj' + (fee != null ? ': <b>' + (fee ? esc(money(fee)) : 'ingyenes') + '</b>' + (r.vat ? ' <small>(' + esc(r.vat) + ')</small>' : '') : '') + '</div><small class="cpx-muted">' + esc(r.how) + '</small>' +
			(fee != null && W && !same ? '<div class="d3-trvb">' + (cur ? '<small>A dealen most: ' + esc(money(cur)) + '</small>' : '') + '<button type="button" class="cpx-btn sm" data-trvs>' + (cur ? 'Átírom erre' : 'Beírom a dealbe') + '</button></div>' : same && cur ? '<small class="d3-trvok">✓ A dealen is ennyi szerepel.</small>' : (fee == null && cur ? '<small>A dealen most: ' + esc(money(cur)) + '</small>' : '')) + man();
		const b = box.querySelector('[data-trvs]');
		if (b) b.addEventListener('click', () => save(fee, b));
		wire();
	}

	/* ------------------------------------------------------------ v2.9.70: többnapos rendezvény – napok a Deal-adatlapon
	 * A napok listája csak a panelé (a Salesmate-be nem ír). Az 1. nap a deal „Rendezvény dátuma” – itt nem mozgatható,
	 * nem törölhető. A napi mezők üresen a deal értékét öröklik (a mezőben halványan látszik). Mentés: cp_crm_days_save. */
	const MD_MAX = 62;
	const ymdDiff = (a, b) => Math.round((Date.UTC(+b.slice(0, 4), +b.slice(5, 7) - 1, +b.slice(8, 10)) - Date.UTC(+a.slice(0, 4), +a.slice(5, 7) - 1, +a.slice(8, 10))) / 864e5);
	/* a PHP cp_mday_label() párja: „dec. 3., 5., 12. (3 nap)”, „dec. 14–16. (3 nap)”, „dec. 30. – jan. 1. (3 nap)” */
	function mdayLabel(list) {
		const days = Array.from(new Set((list || []).filter(x => /^\d{4}-\d{2}-\d{2}$/.test(x)))).sort();
		if (!days.length) return '';
		const multiY = days[0].slice(0, 4) !== days[days.length - 1].slice(0, 4);
		const runs = []; let s = days[0], p = days[0];
		for (let i = 1; i <= days.length; i++) { const d = days[i]; if (d && ymdDiff(p, d) === 1) { p = d; continue; } runs.push([s, p]); if (d) { s = d; p = d; } }
		let lastY = '', lastM = '';
		const fmt = (d, forceM) => { const y = d.slice(0, 4), m = d.slice(0, 7); let t = ''; if (multiY && y !== lastY) t += y + '. '; if (forceM || m !== lastM) t += HU_MS[+d.slice(5, 7) - 1] + ' '; lastY = y; lastM = m; return t + (+d.slice(8, 10)); };
		const out = runs.map(r => {
			if (r[0] === r[1]) return fmt(r[0], false) + '.';
			const a = fmt(r[0], false);
			if (r[0].slice(0, 7) === r[1].slice(0, 7)) { lastY = r[1].slice(0, 4); return a + '–' + (+r[1].slice(8, 10)) + '.'; }
			return a + '. – ' + fmt(r[1], true) + '.';
		});
		return out.join(', ') + ' (' + days.length + ' nap)';
	}
	const MD_F = [['start_t', 'Kezdés', 'time'], ['hours', 'Üzemóra', 'num'], ['guests', 'Létszám', 'int'], ['venue', 'Helyszín', 'text'], ['bg', 'Háttér', 'text'], ['note', 'Megjegyzés', 'text']];
	function daysCard(box, d, reload) {
		const base = { start_t: d.start_at ? String(d.start_at).slice(11, 16) : '', hours: d.hours != null ? String(d.hours) : '', guests: d.guests != null ? String(d.guests) : '', venue: d.venue || '', bg: d.background || '', note: d.note || '' };
		const blank = day => ({ id: 0, day: day, start_t: '', hours: '', guests: '', venue: '', bg: '', note: '' });
		const norm0 = x => Object.assign(blank(x.day), x, { hours: x.hours == null ? '' : String(x.hours), guests: x.guests == null ? '' : String(x.guests) });
		const orig = (d.days || []).map(norm0);
		let L = orig.map(x => Object.assign({}, x));
		let open = new Set(), adding = false, busy = false, msg = '';
		const dirty = () => JSON.stringify(L) !== JSON.stringify(orig);
		const shortDay = s => { const p = s.split('-').map(Number), x = new Date(p[0], p[1] - 1, p[2]); return HU_MS[p[1] - 1] + ' ' + p[2] + '. (' + DOW[x.getDay()] + ')' + (p[0] !== new Date().getFullYear() ? ' ' + p[0] : ''); };
		const ovr = x => MD_F.filter(f => String(x[f[0]] || '') !== '');
		const sumLine = x => MD_F.map(f => { const v = String(x[f[0]] || ''), b = base[f[0]]; const t = v !== '' ? v : b; if (!t) return ''; const show = f[0] === 'hours' ? t + ' óra' : f[0] === 'guests' ? t + ' fő' : t; return '<span class="' + (v !== '' ? 'ov' : 'inh') + '"' + (v !== '' ? ' title="Erre a napra megadva"' : ' title="A deal értéke"') + '>' + esc(show) + '</span>'; }).filter(Boolean).join('<i>·</i>');
		const rowHead = (x, i, n) => { const ov = ovr(x).length; return '<b>' + esc(shortDay(x.day)) + '</b><small>' + (i + 1) + '/' + n + '. nap' + (i === 0 ? ' · a deal dátuma' : '') + (ov ? ' · ' + ov + ' saját adat' : '') + '</small><span class="d3-days-s">' + sumLine(x) + '</span>'; };
		const foot = () => (dirty() ? '<div class="d3-dayb d3-daysave"><button type="button" class="cpx-btn sm" data-dsave' + (busy ? ' disabled' : '') + '>' + (busy ? 'Mentés…' : 'Napok mentése') + '</button><button type="button" class="cpx-btn sm ghost" data-drev' + (busy ? ' disabled' : '') + '>Elvetés</button><small>Csak a panelben – a Salesmate-be nem kerül.</small></div>' : '') + (msg ? '<p class="d3-daymsg err" role="alert">' + esc(msg) + '</p>' : '');
		function draw() {
			const n = L.length;
			let h = '';
			if (n < 2) {
				h = '<div class="d3-dayh"><span>Egynapos rendezvény</span>' + (adding ? '' : '<button type="button" class="d3-dayadd" data-dadd>+ nap</button>') + '</div>';
				if (n === 1) h += '<p class="d3-daymsg">Egy nap maradt – mentéskor a deal újra egynapos lesz (a deal mezői érvényesek).</p>';
			} else {
				h = '<div class="d3-dayh"><span><b>' + esc(mdayLabel(L.map(x => x.day)).replace(/ \(\d+ nap\)$/, '')) + '</b> (' + n + ' nap)</span>' + (adding || n >= MD_MAX ? '' : '<button type="button" class="d3-dayadd" data-dadd>+ nap</button>') + '</div><ol class="d3-dayl">' +
					L.map((x, i) => {
						const o = open.has(i), first = i === 0, ov = ovr(x).length;
						return '<li class="d3-day' + (o ? ' on' : '') + '" data-di="' + i + '"><div class="d3-dayr"><button type="button" class="d3-dayt" data-dtog aria-expanded="' + (o ? 'true' : 'false') + '">' + rowHead(x, i, n) + '</button>' +
							(first ? '' : '<button type="button" class="d3-daydel" data-ddel aria-label="' + esc(shortDay(x.day)) + ' törlése" title="Nap törlése">×</button>') + '</div>' +
							(o ? '<div class="d3-dayf">' + (first ? '' : '<label class="w">Dátum<input type="date" data-dk="day" value="' + esc(x.day) + '" min="' + esc(d.event_d) + '"></label>') +
								MD_F.map(f => '<label' + (f[0] === 'venue' || f[0] === 'note' ? ' class="w"' : '') + '>' + f[1] + '<input data-dk="' + f[0] + '" ' + (f[2] === 'time' ? 'type="time"' : f[2] === 'text' ? 'type="text" maxlength="' + (f[0] === 'bg' ? 80 : f[0] === 'venue' ? 255 : 1000) + '"' : 'type="number" inputmode="' + (f[2] === 'num' ? 'decimal' : 'numeric') + '" min="' + (f[2] === 'num' ? '0.5' : '1') + '" step="' + (f[2] === 'num' ? '0.5' : '1') + '"') + ' value="' + esc(x[f[0]]) + '" placeholder="' + esc(base[f[0]] ? String(base[f[0]]).slice(0, 60) : '–') + '"></label>').join('') + '<small class="d3-dayhint">Üres mező = a deal értéke (halványan látszik).</small></div>' : '') + '</li>';
					}).join('') + '</ol>';
			}
			if (adding) h += '<form class="d3-dayform" data-dform novalidate><label>Nap<input type="date" data-a1 required min="' + esc(d.event_d) + '"></label><label><span>Utolsó nap <small>(tartományhoz)</small></span><input type="date" data-a2 min="' + esc(d.event_d) + '"></label><div class="d3-dayb"><button type="submit" class="cpx-btn sm">Hozzáadás</button><button type="button" class="cpx-btn sm ghost" data-dcan>Mégse</button></div></form>';
			box.innerHTML = h + '<div data-dfoot>' + foot() + '</div>';
			const a1 = box.querySelector('[data-a1]');
			if (a1) a1.focus();
		}
		function addDays(a, b) {
			msg = '';
			if (!/^\d{4}-\d{2}-\d{2}$/.test(a)) { msg = 'Válassz napot.'; return false; }
			if (b && !/^\d{4}-\d{2}-\d{2}$/.test(b)) b = '';
			if (b && b < a) { const t = a; a = b; b = t; }
			if (a < d.event_d) { msg = 'Az 1. nap a deal „Rendezvény dátuma” (' + huD(d.event_d) + '). Korábbi napot ott állíts be.'; return false; }
			if (!L.length) L = [blank(d.event_d)];
			const have = new Set(L.map(x => x.day)), add = [];
			for (let x = a, k = 0; x <= (b || a) && k < 400; x = addDays1(x), k++) if (!have.has(x)) add.push(x);
			if (!add.length) { msg = 'Ez a nap már szerepel.'; return false; }
			if (L.length + add.length > MD_MAX) { msg = 'Legfeljebb ' + MD_MAX + ' nap adható meg (most ' + (L.length + add.length) + ' lenne).'; return false; }
			L = L.concat(add.map(blank)).sort((x, y) => x.day < y.day ? -1 : 1);
			open = new Set();
			return true;
		}
		const addDays1 = x => { const p = x.split('-').map(Number), t = new Date(Date.UTC(p[0], p[1] - 1, p[2] + 1)); return t.getUTCFullYear() + '-' + pad(t.getUTCMonth() + 1) + '-' + pad(t.getUTCDate()); };
		box.addEventListener('click', async ev => {
			const t = ev.target;
			if (t.closest('[data-dadd]')) { adding = true; msg = ''; draw(); return; }
			if (t.closest('[data-dcan]')) { adding = false; msg = ''; draw(); return; }
			if (t.closest('[data-drev]')) { L = orig.map(x => Object.assign({}, x)); adding = false; open = new Set(); msg = ''; draw(); return; }
			const li = t.closest('[data-di]');
			if (li && t.closest('[data-dtog]')) { const i = +li.dataset.di; if (open.has(i)) open.delete(i); else open.add(i); draw(); const f = box.querySelector('[data-di="' + i + '"] [data-dk]'); if (f && open.has(i)) f.focus(); return; }
			if (li && t.closest('[data-ddel]')) { const i = +li.dataset.di; if (i === 0) return; L.splice(i, 1); open = new Set(); msg = ''; draw(); return; }
			if (t.closest('[data-dsave]')) {
				busy = true; msg = ''; draw();
				try {
					const payload = L.length >= 2 ? L.map(x => ({ id: x.id || 0, day: x.day, start_t: x.start_t, hours: x.hours, guests: x.guests, venue: x.venue, bg: x.bg, note: x.note })) : [];
					const r = await post('cp_crm_days_save', { id: d.id, days: JSON.stringify(payload) });
					toast(r.label ? 'Napok mentve: ' + r.label : 'A deal újra egynapos.');
					reload();
				} catch (e) {
					/* v2.9.70: elszámolással rendelkező nap nem törölhető / nem kap új dátumot – a sor visszakerül a helyére */
					const huDi = x => { const m = +x.slice(5, 7), n = +x.slice(8, 10); return HU_MS[m - 1] + ' ' + n + (n % 10 === 1 && n !== 11 ? '-jei' : '-i'); };
					orig.slice(1).forEach(o => {
						if (L.some(x => x.day === o.day) || e.message.indexOf(huDi(o.day) + ' nap') < 0) return;
						const cur = o.id ? L.find(x => x.id === o.id) : null;
						if (cur) cur.day = o.day; else L.push(Object.assign({}, o));
					});
					L.sort((x, y) => x.day < y.day ? -1 : 1);
					busy = false; msg = e.message; open = new Set(); draw();
				}
			}
		});
		box.addEventListener('submit', ev => { const f = ev.target.closest('[data-dform]'); if (!f) return; ev.preventDefault(); if (addDays(f.querySelector('[data-a1]').value, f.querySelector('[data-a2]').value)) adding = false; draw(); });
		box.addEventListener('change', ev => {
			const inp = ev.target.closest('[data-dk]'), li = ev.target.closest('[data-di]');
			if (!inp || !li) return;
			const i = +li.dataset.di, k = inp.dataset.dk;
			let v = inp.value.trim();
			if (k === 'day') {
				if (!/^\d{4}-\d{2}-\d{2}$/.test(v) || v <= d.event_d) { msg = 'A ' + (i + 1) + '. nap a deal dátuma (' + huD(d.event_d) + ') utáni nap lehet.'; draw(); return; }
				if (L.some((x, j) => j !== i && x.day === v)) { msg = 'Ez a nap már szerepel.'; draw(); return; }
				L[i].day = v; L.sort((x, y) => x.day < y.day ? -1 : 1); open = new Set([L.findIndex(x => x.day === v)]); msg = ''; draw(); return;
			}
			/* mezőváltozásnál nincs teljes újrarajzolás (a fókusz a következő mezőn marad): csak a sor feje és a mentés-sáv */
			L[i][k] = v; msg = '';
			const hd = li.querySelector('[data-dtog]'); if (hd) hd.innerHTML = rowHead(L[i], i, L.length);
			const ft = box.querySelector('[data-dfoot]'); if (ft) ft.innerHTML = foot();
		});
		draw();
	}
	async function openDeal(id, back, fromHist) {
		if (!fromHist) pushDeal(id);
		if (S.pend && S.pend.fresh && S.pend.fresh[id]) { const lid = S.pend.fresh[id]; delete S.pend.fresh[id]; post('cp_lead_set', { id: lid, st: 'seen' }).catch(() => {}); if (S.mode === 'board' && S.board) setTimeout(redrawBoard, 0); }
		const sh = sheet360(head360('Deal #' + id, back, id) + '<div class="cpx-empty">Betöltés…</div>');
		if (!sh) return;
		bindBack(sh, back);
		let x;
		/* v2.9.60 (P3): a Feladatok / Számlázás / Szerződés modul igény szerint töltődik – a deal adataival párhuzamosan (hibánál nélkülük rajzol) */
		try { [x] = await Promise.all([post('cp_crm_deal', { id: id }), window.CPXNeed ? window.CPXNeed('kanban', 'bill', 'contract').catch(() => {}) : null]); }
		catch (e) { if (sh.isConnected) { sh.innerHTML = head360('Deal #' + id, back, id) + '<div class="cpx-empty err">' + esc(e.message) + '</div>'; bindBack(sh, back); } return; }
		if (!sh.isConnected) return;
		const d = x.deal, c = x.contact, co = x.company, m = x.mail || { threads: [], msgs: [], notes: [], files: [] };
		/* v2.8.8: bélyegkép csak a nyitott, illetve a jövőbeli rendezvényű dealeknél (visszamenőleg nem készül) */
		D3T.on = !d.deleted_at && (d.status === 'Open' || (d.event_d && d.event_d >= today()));
		const smUrl = C.sm ? C.sm + d.id : '';
		const self = { label: shortTitle({ id: d.id, title: d.title, ev: d.event_d }).slice(0, 40), fn: () => openDeal(id, back) };
		/* összkép */
		const lastIn = (m.msgs || []).find(g => g.dir === 'in'), lastOut = (m.msgs || []).find(g => g.dir === 'out');
		const toEv = d.event_d ? dayDiff(today(), d.event_d) : null;
		/* v2.9.88: ugyanaz az ember egyszer szerepel – a név-sorrend („Andrea Gyarmati” / „Gyarmati Andrea”), kis- és nagybetű
		   meg ékezet nem számít; a szerepei egy sorba kerülnek (Felelős · Levelezés · Feladat) */
		const tKey = n => String(n).normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/[^a-z0-9 ]+/g, ' ').trim().split(/\s+/).sort().join(' ');
		const teamM = new Map();
		const tAdd = (n, role) => { n = String(n || '').trim(); if (!n) return; const k = tKey(n); const e = teamM.get(k); if (!e) teamM.set(k, [n, [role]]); else if (!e[1].includes(role)) e[1].push(role); };
		if (d.owner) tAdd(d.owner, 'Felelős');
		(m.threads || []).forEach(t => { if (t.as && pn(t.as)) tAdd(pn(t.as), 'Levelezés'); });
		(x.cards || []).forEach(k => k.as.forEach(n => tAdd(n, 'Feladat')));
		const team = new Map(Array.from(teamM.values()).map(e => [e[0], e[1].join(' · ')]));
		const otherCt = (x.ct_deals || []).filter(o => o.id !== d.id);
		const otherCo = (x.co_deals || []).filter(o => o.id !== d.id && !otherCt.some(z => z.id === o.id));
		const ph = c ? (c.mobile || c.phone) : '';
		let h = head360(esc(d.title || 'Deal #' + d.id), back, d.id) +
			'<div class="d3-top">' + stageBar(d) + '<div class="d3-acts' + (d.status === 'Open' ? ' open' : '') + '">' + stBadge(d) +
			/* v1.78.8: egységes gombsor – azonos méret és stílus, vonalas ikonok */
			(W && !d.deleted_at ? (d.status === 'Open' ? ab('d3-won', 'data-mvst="Won"', AI.ok, 'Megnyert') + ab('bad', 'data-mvst="Lost"', AI.no, 'Elvesztett') : ab('', 'data-mvst="Open"', AI.undo, 'Újranyitás')) : '') +
			(R.edit && !d.deleted_at ? ab('pri', 'data-edit', AI.edit, 'Szerkesztés') : '') +
			(window.CPMail && window.CPMail.canSend && window.CPMail.canSend() ? ab('', 'data-d3mail title="Új levél a deal kontaktjának (a levél a dealhez kötve)"', AI.mail, 'E-mail') : '') +
			(window.CPKanban ? ab('', 'data-todo title="Teendő felvétele a Feladatokba"', AI.todo, 'Teendő') : '') +
			(smUrl ? '<a class="d3-ab" href="' + esc(smUrl) + '" target="_blank" rel="noopener" title="Megnyitás a Salesmate-ben">' + AI.ext + '<span>Salesmate</span></a>' : '') +
			/* v2.9.18: deal törlése az adatlapról (csak admin) */
			(W && C.role === 'admin' && !d.deleted_at ? ab('bad', 'data-d3del title="Végleges törlés (a Salesmate-ből is) – csak admin"', AI.no, 'Törlés') : '') + '</div></div>' +
			(d.deleted_at ? '<p class="crm-warn">Ez a deal a Salesmate-ben már nem található (törölték).</p>' : '') +
			'<div class="d3">' +
			/* bal: összefoglaló, kontakt, cég, mezők */
			'<aside class="d3-l"><section class="d3-c d3-sum">' + retBadge(otherCt.concat(otherCo), c) + '<div class="d3-val"' + ikA('value', d) + '>' + (d.est && !(d.value > 0) ? '<span class="crm-est d3-est" title="' + esc('Becsült ár ' + estSrc(d.est) + (d.est.a ? ' (hiányos adatokból)' : '') + ':\n' + d.est.l.join('\n')) + '">~' + esc(money(d.est.p, 'HUF')) + (d.est.a ? '<sup>?</sup>' : '') + '</span><small class="d3-estl">becsült ár' + (d.est.v === 'ec' ? ' (nettó, + ÁFA)' : (d.est.v === 'p' || d.est.v === 'pb') ? ' (bruttó – magánszemély, Crazyphoto-árak)' : '') + ' – még nincs ajánlat</small>' : esc(money(d.value, d.currency))) + '</div>' + (d.cap ? '<div class="d3-cap">' + capHtml(d.cap, true) + '<small>' + esc('a rendezvény napján (' + d.cap.cs + ' szelfigép, ' + d.cap.cp + ' spinner)') + '</small></div>' : '') + (d.est && W && c && c.email && window.CPMail && window.CPMail.compose ? /* v2.9.77: beírt árnál is */ '<button type="button" class="cpx-btn d3-offer" data-offer' + (window.CPMail.canSend && !window.CPMail.canSend() ? ' disabled title="A levélküldés a panelből neked nincs bekapcsolva (Beállítások → Levelezés)"' : '') + '>Ajánlat küldése ezzel az árral</button>' : '') + '<dl class="crm-dl">' +
				(d.event_d ? '<dt>Rendezvény</dt><dd' + ikA('event_d', d) + '>' + (d.days && d.days.length > 1 ? esc(d.days_label || mdayLabel(d.days.map(x => x.day))) /* v2.9.70 */ : esc(huD(d.event_d)) + (d.event_end_d ? ' – ' + esc(huD(d.event_end_d)) : '')) + '</dd>' : '') + (d.venue ? '<dt>Helyszín</dt><dd' + ikA('venue', d) + '>' + esc(d.venue) + '</dd>' : '') +
				'<dt>Felelős</dt><dd' + ikA('owner_id', d) + '>' + esc(d.owner || '–') + '</dd><dt>Szakasz</dt><dd>' + esc(d.stage) + '</dd>' + (d.source ? '<dt>Forrás</dt><dd' + ikA('source', d) + '>' + esc(d.source) + '</dd>' : '') + (d.service ? '<dt>Szolgáltatás</dt><dd' + ikA('service', d) + '>' + esc(d.service) + '</dd>' : '') + '</dl>' + (d.event_d && !d.deleted_at ? '<div class="d3-days" data-days></div>' : '') + (d.venue && !d.deleted_at ? '<div class="d3-trv" data-trv><small class="cpx-muted">Kiszállási díj számolása…</small></div>' : '') + '</section>' +
			'<section class="d3-c"><h4>Kontakt</h4>' + (c ? '<a href="#" class="d3-who" data-ct="' + c.id + '"><span class="d3-av" style="background:' + avColor(c.id) + '">' + esc(initials(c.name)) + '</span><span><b>' + esc(c.name || c.email) + '</b><small>' + (otherCt.length ? (otherCt.length + 1) + ' deal · ' + (x.ct_stats ? x.ct_stats.won : 0) + ' megnyert' : 'első deal') + '</small></span></a><dl class="crm-dl">' +
				(c.email ? '<dt>E-mail</dt><dd><a href="mailto:' + esc(c.email) + '">' + esc(c.email) + '</a></dd>' : '') + (ph ? '<dt>Telefon</dt><dd><a href="tel:' + esc(String(ph).replace(/[^\d+]/g, '')) + '">' + esc(ph) + '</a></dd>' : '') +
				(c.city ? '<dt>Város</dt><dd>' + esc(c.city) + '</dd>' : '') + (c.utm_source || c.utm_campaign ? '<dt>Honnan jött</dt><dd>' + esc([c.utm_source, c.utm_medium, c.utm_campaign].filter(Boolean).join(' / ')) + '</dd>' : '') + '</dl>' : '<p class="cpx-muted">Nincs kontakt rendelve a deal-hez.</p>') + '</section>' +
			'<section class="d3-c"><h4>Cég</h4>' + (co ? '<a href="#" class="d3-who" data-co="' + co.id + '"><span class="d3-av sq" style="background:' + avColor(co.id + 3) + '">' + esc(initials(co.name)) + '</span><span><b>' + esc(co.name) + '</b><small>' + ((x.co_deals || []).length) + ' deal · ' + ((x.co_contacts || []).length) + ' kontakt</small></span></a>' + (safeUrl(co.website) ? '<p class="d3-sub"><a href="' + esc(safeUrl(co.website)) + '" target="_blank" rel="noopener">' + esc(co.website) + ' ↗</a></p>' : '') : '<p class="cpx-muted">Nincs cég rendelve.</p>' + (W && c && c.email && !d.deleted_at ? '<div class="d3-cos" data-cos></div>' : '')) + '</section>' +
			'<section class="d3-c"><h4 class="d3-adh">Adatok' + (x.gcal_on && R.edit && d.event_d && !d.deleted_at ? '<button type="button" class="d3-gsync" data-gsync title="A cégnaptár bejegyzésének frissítése a deal és az ügyfél-adatlap mostani adataival (ha nincs, létrehozza)">📅 Naptárbejegyzés frissítése</button>' : '') + '</h4>' + fieldGroups(d, x.pinfo) +
				'<p class="crm-meta">Létrehozva: ' + esc(huDT(d.created_at)) + (d.created_by_n ? ' (' + esc(d.created_by_n) + ')' : '') + '<br>Utolsó módosítás: ' + esc(huDT(d.modified_at)) + (d.modified_by_n ? ' (' + esc(d.modified_by_n) + ')' : '') + '</p></section></aside>' +
			/* közép: teendők és idővonal */
			'<main class="d3-m">' + (W && !d.deleted_at ? '<div class="d3-comp" data-comp></div>' : '') + '<div data-kbq hidden class="crm-kbq"></div><div data-kbl class="crm-kbl"></div><div data-tl></div></main>' +
			/* jobb: összkép, korábbi munkák, csapat, fájlok */
			'<aside class="d3-r">' + /* v2.9.156: az Összkép legfelül, kompakt */ '<section class="d3-c d3-insc"><h4>Összkép</h4><dl class="crm-dl d3-ins">' +
				(toEv != null ? '<dt>Rendezvényig</dt><dd>' + (toEv > 0 ? '<b>' + toEv + ' nap</b>' : toEv === 0 ? '<b>ma</b>' : 'lezajlott (' + (-toEv) + ' napja)') + '</dd>' : '') +
				(x.stage_since ? '<dt>Ebben a szakaszban</dt><dd>' + esc(agoTxt(x.stage_since)) + ' óta</dd>' : '') +
				(d.last_comm_at ? '<dt>Utolsó kommunikáció</dt><dd>' + esc(huDT(d.last_comm_at)) + (d.last_comm_mode ? ' · ' + esc(d.last_comm_mode) : '') + '</dd>' : '') +
				(lastIn ? '<dt>Ügyfél utoljára írt</dt><dd>' + esc(agoTxt(lastIn.at)) + '</dd>' : '') + (lastOut ? '<dt>Mi utoljára írtunk</dt><dd>' + esc(agoTxt(lastOut.at)) + '</dd>' : '') +
				(() => { const m = d.rot ? Math.round((Date.now() - new Date(d.rot.replace(' ', 'T')).getTime()) / 60000) : 0; return m > 0 ? '<dt>Rohad</dt><dd>' + rotHtml(d, m) + ' <small>' + esc(rotTxt(m)) + ' lejárt a határidő (' + esc(d.rot) + ')</small></dd>' : (d.rot ? '<dt>Rohad</dt><dd class="cpx-muted">' + esc(d.rot) + '-tól, ha addig nem lép tovább</dd>' : ''); })() + '</dl></section>' +
			portalCard(x) + (window.CPBill ? window.CPBill.slot() : '') + (window.CPContract ? window.CPContract.slot() : '') +
			'<section class="d3-c"><h4>Korábbi munkák – kontakt</h4>' + statLine(x.ct_stats) + (otherCt.length ? dealRows(otherCt, d.id) : '<p class="cpx-muted">' + (c ? 'Ez az első deal ezzel a kontakttal.' : 'Nincs kontakt.') + '</p>') + '</section>' +
			(co ? '<section class="d3-c"><h4>Korábbi munkák – ' + esc(co.name) + '</h4>' + statLine(x.co_stats) + (otherCo.length ? dealRows(otherCo, d.id) : '<p class="cpx-muted">A cégnek nincs más (más kontakthoz tartozó) deal-je.</p>') + '</section>' : '') +
			/* v2.9.117: beosztás (Kapacitás → Beosztás) */
			/* v2.9.126: a beosztás sorai: lenyíló listából cserélhető ember, és átvisz a nap beosztásához (Kapacitás → Beosztás) */
			((x.crew || []).length ? '<section class="d3-c"><h4>Beosztás</h4><ul class="d3-team d3-crew">' + x.crew.map((c, ci) => '<li><span class="d3-av" style="background:' + avColor(String(c.n).length) + '">' + esc(initials(c.n)) + '</span><span class="d3-cw"><select data-crsw="' + ci + '" aria-label="Beosztott ember: ' + esc(c.n) + '" title="Csere: válassz másik embert"><option value="' + c.kz + '" selected>' + esc(c.n) + '</option><option value="" disabled>– a lista betöltése… –</option></select>' +
				'<button type="button" class="d3-crgo" data-crgo="' + esc(c.d) + '" title="A nap beosztásának megnyitása (Kapacitás → Beosztás)">' + esc(huD(c.d) + ' · ' + c.g + ' · ' + c.sl) + ' ↗</button></span></li>').join('') + '</ul></section>' : '') +
			'<section class="d3-c"><h4>Csapat</h4>' + (team.size ? '<ul class="d3-team">' + Array.from(team).map(p => '<li><span class="d3-av" style="background:' + avColor(p[0].length) + '">' + esc(initials(p[0])) + '</span>' + esc(p[0]) + '<small>' + esc(p[1]) + '</small></li>').join('') + '</ul>' : '<p class="cpx-muted">Nincs.</p>') + '</section>' +
			/* v2.9.68: a „Levélszálak” blokk megszűnt – a szálak kártyaként az idővonalon vannak (Levelek szűrő) */
			/* v2.8.8: Fájlok – bélyegképes rács, az ismétlődő fájl egyszer (×N), képeknél nagyító előnézet */
			(() => { const fl = d3Files(m.files || []), sbf = x.sbf || [], embN = fl.filter(f => f.emb).length; const up = x.dfiles || []; return '<section class="d3-c" data-fsec><h4>Fájlok <small>' + (fl.length - embN + sbf.length + up.length) + '</small>' + (R.edit ? '<label class="d3-upb" title="Fájl feltöltése a dealhez (PDF, kép, Office, zip – max. 25 MB) – vagy húzd ide"><input type="file" multiple hidden data-dfup>＋ Feltöltés</label>' : '') + '</h4>' + d3FiltBar(fl.concat(up.map(f => ({ n: f.n }))), sbf) + d3UpHtml(up) +
				/* v2.9.5: az ügyfél online fotósablonjai – lyukasztott PNG letöltése */
				(sbf.length ? '<div class="d3-sbf"><b class="d3-sbh">Online fotósablon</b><ul class="d3-fg">' + sbf.map(t => { const fn = 'fotosablon-' + d.id + '-' + String(t.at || '').slice(0, 10) + '.png'; return '<li><a class="d3-ft" href="' + esc(t.png ? t.png + '&dl=' + encodeURIComponent(fn) : t.prev) + '" target="_blank" rel="noopener"' + (!t.png && t.cur ? ' data-sbopen="' + esc(d.id) + '" data-sbts="' + esc(t.ts || '') + '"' : '') + ' title="' + esc(t.png ? 'Lyukasztott PNG letöltése (4×6")' : t.cur ? 'Kattints: a Sablonkészítő az ügyfél beállításaiból elkészíti a lyukasztott PNG-t, és ide teszi' : 'Előnézet (ehhez a régebbi változathoz nem készült PNG)') + '"><span class="d3-tb t-img has"><img loading="lazy" alt="" src="' + esc(t.prev) + '"><i>' + (t.png ? 'PNG' : 'JPG') + '</i></span><b>' + (t.cur ? 'Aktuális' : 'Korábbi') + (t.name ? ' – ' + esc(t.name) : '') + '</b><small>' + esc(huDT(t.at)) + (t.png ? ' · ⬇ lyukasztott PNG' : t.cur ? ' · PNG készítése ›' : ' · csak előnézet') + '</small></a></li>'; }).join('') + '</ul></div>' : '') +
				(fl.length ? '<ul class="d3-fg">' + fl.map((f, ix) => '<li data-fk="' + d3Kind(f.n) + '"' + (f.emb ? ' class="d3-emb"' : '') + '>' + (f.m && window.CPMailAtt ? '<a class="d3-ft" href="' + esc(window.CPMailAtt(f.m, f.i, f.n)) + '" target="_blank" rel="noopener" data-fx="' + ix + '"' + ((window.CPAttRisk && window.CPAttRisk(f.n)) ? ' data-risk="' + window.CPAttRisk(f.n).lvl + '" data-rn="' + esc(f.n) + '" title="' + esc((window.CPAttRisk(f.n).lvl === 'high' ? 'Veszélyes: ' : 'Kockázatos: ') + window.CPAttRisk(f.n).why) + '"' : ' title="' + esc(f.n) + '"') + '>' : '<button type="button" class="d3-ft" data-th="' + f.th + '" title="' + esc(f.n) + '">') + d3Thumb(f) + '<b>' + ((window.CPAttRisk && window.CPAttRisk(f.n)) ? '<span class="ml-ariskb ' + window.CPAttRisk(f.n).lvl + '" aria-label="Kockázatos fájl">⚠</span> ' : '') + esc(f.n) + '</b><small>' + esc(d3Meta(f)) + (f.x > 1 ? ' · ×' + f.x : '') + '</small>' + (f.m && window.CPMailAtt ? '</a>' : '</button>') + '</li>').join('') + '</ul>' + '<button type="button" class="d3-embt" data-embt' + (embN ? '' : ' hidden') + '><span data-embn>' + embN + '</span> elrejtett fájl (aláírás, apró, üres vagy ismétlődő) · <span data-embl>Mutasd</span></button>' + (fl.length > embN ? '<p class="d3-sub">Kép: kattintásra nagyítva · PDF és más fájl: megnyitás / letöltés (a postafiókból).</p>' : (sbf.length ? '' : '<p class="cpx-muted">A levelekben nem volt melléklet.</p>')) : (sbf.length ? '' : '<p class="cpx-muted">A levelekben nem volt melléklet.</p>')) + '</section>'; })() +
			'</aside></div>';
		sh.innerHTML = h;
		d3FilesBind(sh, d3Files(m.files || []));
		d3UpBind(sh, d.id);
		D3.ctx = { id: id, back: back };
		const cmp = sh.querySelector('[data-comp]');
		if (cmp) compose(cmp, x, 'note');
		drawTimeline(sh.querySelector('[data-tl]'), x);
		bindPortal(sh, x, () => openDeal(id, back));
		if (window.CPBill) window.CPBill.mount(sh, x);
		if (window.CPContract) window.CPContract.mount(sh, x);
		bindCommon(sh, self);
		bindCrew(sh, x, d.id, back);
		bindBack(sh, back);
		{ const gs = sh.querySelector('[data-gsync]'); if (gs) gs.addEventListener('click', async () => { gs.disabled = true; const t0 = gs.textContent; gs.textContent = 'Frissítés…'; try { const r = await post('cp_gcal_deal_sync', { id: x.deal.id }); toast(r.msg); } catch (e) { toast(e.message, true); } gs.disabled = false; gs.textContent = t0; }); } /* v2.9.165 */
		bindInline(sh, x, back);
		/* v1.84: „Ajánlat küldése” – előtöltött levél a becsült árral; küldés után a deal „Ajánlat kiküldve” lesz, értéke a becsült ár */
		const ob = sh.querySelector('[data-offer]');
		if (ob) ob.addEventListener('click', () => {
			const first = (c.first_name || (c.name || '').trim().split(/\s+/).slice(-1)[0] || '').trim();
			/* csak az árat adó sorok, a belső megjegyzések nélkül */
			const man = d.value > 0 && Math.round(d.value) !== Math.round(d.est.p), fin = d.value > 0 ? d.value : d.est.p; /* v2.9.77: beírt ár: az a végösszeg; ha eltér a becsülttől, a tételek ár nélkül */
			const lines = (d.est.l || []).filter(l => /:\s*[−-]?[\d\s]+ Ft$|ingyenes$/.test(l)).map(l => l.replace(/ – nincs megadva csomag[^:]*/, '').replace(/ \(felismerve: [^)]*\)/, '').replace(' (a dealben megadva)', '').replace(/ – a „[^”]*” árával számolva/, '').replace(/ – nincs megadva óraszám[^:]*/, '').replace(/ × 130 Ft\)/, ')'));
			const body = '<p>Kedves ' + esc(first || 'Ügyfelünk') + '!</p><p>Köszönjük megkeresésedet! ' + (d.event_d ? 'A ' + esc(huD(d.event_d)) + ' rendezvényedre' : 'Rendezvényedre') + ' az alábbi ajánlatot adjuk' + (d.venue ? ' (helyszín: ' + esc(d.venue) + ')' : '') + ':</p>' +
				'<ul>' + lines.map(l => '<li>' + esc(man ? l.replace(/:\s*[−-]?[\d\s]+ Ft$|:\s*ingyenes$/, '') : l) + '</li>').join('') + '</ul><p><b>Végösszeg: ' + esc(money(fin, 'HUF')) + (d.est.v === 'ec' ? ' + ÁFA' : '') + '</b></p>' +
				(d.est.v === 'ec' ? '<p>Az árak nettó árak, az ÁFA-t nem tartalmazzák. Hosszabbítás a helyszínen is kérhető.</p>' : d.est.v === 'pb' ? '<p>A feltüntetett árak a teljes, fizetendő (bruttó) árak. Hosszabbítás a helyszínen is kérhető.</p>' : '<p>Lakossági megrendelés esetén az árak alanyi adómentesek, ÁFA-t nem tartalmaznak; céges megrendelésnél egyedi ajánlatot adunk. Hosszabbítás a helyszínen is kérhető.</p>') +
				'<p>Ha megfelel, válaszolj erre a levélre, és küldjük a szerződést.</p>';
			const nextSt = STAGES[1] || 'Ajánlat kiküldve', val = fin;
			hideModal(); closePage();
			window.CPMail.compose({ to: [c.email], subject: 'Ajánlat – ' + (d.title || ''), body: body, deal: d.id, dealTitle: d.title, onSent: async () => {
				try { await post('cp_crm_save', { id: d.id, f: JSON.stringify({ value: String(val) }), c: '{}' }); await post('cp_crm_move', { id: d.id, to: nextSt }); toast('A deal „' + nextSt + '” szakaszba került, értéke ' + money(val, 'HUF') + '.'); }
				catch (e) { toast('A levél elment, de a deal frissítése nem sikerült: ' + e.message + ' – állítsd át kézzel.', true); }
			} });
		});
		/* v2.5: kiszállási díj a helyszínből (Crazyphoto-árkalkulátor szabályai) */
		const trv = sh.querySelector('[data-trv]');
		if (trv) trvCard(trv, d, () => openDeal(id, back));
		/* v2.9.70: többnapos rendezvény – napok */
		const dys = sh.querySelector('[data-days]');
		if (dys) daysCard(dys, d, () => openDeal(id, back));
		/* v1.96: cégjavaslat a domainből / aláírásból / weboldalból – csak jóváhagyás után kerül a Salesmate-be */
		const cos = sh.querySelector('[data-cos]');
		if (cos) coSuggest(cos, d.id, () => openDeal(id, back));
		const eb = sh.querySelector('[data-edit]');
		if (eb) eb.addEventListener('click', () => editDeal(sh, x));
		const kbl = sh.querySelector('[data-kbl]');
		if (window.CPKanban) window.CPKanban.linked(kbl, { deal: d.id });
		/* v2.9.1: e-mail küldése a dealből – a levélíró a kontakt címével, a dealhez kötve */
		const d3m = sh.querySelector('[data-d3mail]');
		if (d3m) d3m.addEventListener('click', () => { const to = c && c.email ? [c.email] : []; window.CPMail.compose({ to: to, subject: '', body: '', deal: d.id, dealTitle: d.title }); });
		const d3d = sh.querySelector('[data-d3del]');
		if (d3d) d3d.addEventListener('click', async () => {
			/* v2.9.19: előbb megmutatja, mi törlődik még a deallel együtt */
			let inf = null; d3d.disabled = true;
			try { inf = await post('cp_crm_del_info', { id: d.id }); } catch (e) { inf = null; }
			d3d.disabled = false;
			const also = inf ? [inf.portal ? '• az ügyfélportál' + (inf.files ? ' és ' + inf.files + ' fájlja (sablon, logó)' : '') + ', a link sem működik tovább' : '', inf.cal ? '• ' + inf.cal + ' naptárbejegyzés a Google Naptárból' : (inf.cal_err ? '• (a naptárat most nem érem el – onnan kézzel töröld)' : ''), inf.threads ? '• ' + inf.threads + ' levélszál a Kukába kerül' : ''].filter(Boolean) : [];
			if (!(await window.CPXAsk({ title: 'Deal törlése', text: '„' + (d.title || '#' + d.id) + '” – a Salesmate-ből is törlődik.' + (also.length ? '\nVele együtt:\n' + also.join('\n') : '') + '\n\n30 napig visszaállítható a Lomtárból (a Salesmate-ben új dealként; az ügyfélportál és a Salesmate-beli jegyzetek, tevékenységek nem jönnek vissza).', type: 'TÖRLÉS', ok: 'Törlés', danger: true }))) return;
			await slotStorno(d.id, d.title || ''); /* v2.9.188: a törlés előtt (utána a beosztás adatai már nem érhetők el) */
			d3d.disabled = true;
			try { const r = await post('cp_crm_delete', { id: d.id, ok: 'TÖRLÉS' }); toast('A deal törölve: ' + (d.title || '#' + d.id) + (r && r.clean && r.clean.length ? ' · vele együtt: ' + r.clean.join(', ') : '')); closePage(); hideModal(); load(); }
			catch (e) { d3d.disabled = false; toast(e.message, true); }
		});
		const todo = sh.querySelector('[data-todo]');
		if (todo) todo.addEventListener('click', () => window.CPKanban.quickForm(sh.querySelector('[data-kbq]'), { title: d.title || 'Deal #' + d.id, deal: d.id, onDone: () => window.CPKanban.linked(kbl, { deal: d.id }) }));
		/* v2.9.200: a deal csak akkor nyílik újra, ha közben az adatlapján maradtál (eddig a visszavonási idő után máshonnan is visszarántott) */
		const after = async ok => { if (!ok) return; if (S.mode === 'list') load(); if (sh.isConnected) openDeal(id, back); };
		/* v2.9.200: a szakasz-sáv azonnal az új szakaszt mutatja (a mentés a visszavonási idő után fut); visszavonáskor / hibánál visszaáll */
		const stgNow = to => {
			const bs = Array.from(sh.querySelectorAll('[data-stg]')), k = bs.findIndex(x => x.dataset.stg === to);
			const prev = bs.map(x => [x.className, x.disabled]);
			if (k >= 0) bs.forEach((x, j) => { x.className = j < k ? 'done' : j === k ? 'cur' : ''; x.disabled = true; });
			return () => { if (sh.isConnected) bs.forEach((x, j) => { x.className = prev[j][0]; x.disabled = prev[j][1]; }); };
		};
		sh.querySelectorAll('[data-stg]').forEach(b => b.addEventListener('click', async () => { const undo = stgNow(b.dataset.stg); const ok = await doMove(id, { to: b.dataset.stg }, d.title); if (!ok) undo(); after(ok); }));
		sh.querySelectorAll('[data-mvst]').forEach(b => b.addEventListener('click', async () => after(await doMove(id, { st: b.dataset.mvst }, d.title))));
	}

	/* ------------------------------------------------------------ kontakt- és cégnézet: minden munkájuk, levelezésük */
	function filterTo(k, id, n) {
		S.who = { k: k, id: id, n: n }; S.mode = 'list'; S.st = ''; S.p = 0; SEL.clear(); save();
		hideModal(); closePage();
		if (window.CPXGo) window.CPXGo('deals');
		setTimeout(() => { if (S.main && S.main.isConnected) view(S.main); }, 30);
	}
	function entityView(sh, title, back, info, x, k, id, name, extra) {
		const s = x.stats || {};
		sh.innerHTML = head360(title, back, null, k + ':' + id) + '<div class="d3 d3-2"><aside class="d3-l"><section class="d3-c">' + info + '</section>' + (extra || '') + '</aside><main class="d3-m">' +
			'<div class="d3-tiles"><div><b>' + (s.n || 0) + '</b><span>deal</span></div><div><b>' + (s.won || 0) + '</b><span>megnyert</span></div><div><b>' + esc(money(s.won_v || 0)) + '</b><span>megnyert érték</span></div><div><b>' + esc(money(s.open_v || 0)) + '</b><span>nyitott érték (' + (s.open || 0) + ')</span></div></div>' +
			(s.first ? '<p class="d3-sub">Első deal: ' + esc(huD(s.first.slice(0, 10))) + (s.last_ev ? ' · legutóbbi rendezvény: ' + esc(huD(s.last_ev)) : '') + '</p>' : '') +
			'<div class="d3-mrow"><h4>Deal-ek</h4><button type="button" class="cpx-btn ghost" data-filt>Deal-lista szűrése erre ' + (k === 'co' ? 'a cégre' : 'a kontaktra') + '</button></div>' + dealRows(x.deals || [], 0) +
			/* v2.9.68: ugyanazok a szálkártyák, mint a deal idővonalán */
			'<h4>Levélszálak <small class="cpx-muted">' + ((x.mail || {}).threads || []).length + '</small></h4>' + threadCards(x.mail || { threads: [] }) + '</main></div>';
		bindCommon(sh, { label: String(name || '').slice(0, 40), fn: () => (k === 'co' ? openCompany : openContact)(id, back) });
		bindTc(sh);
		bindBack(sh, back);
		sh.querySelector('[data-filt]').addEventListener('click', () => filterTo(k, id, name));
	}
	/* v1.90: kontakt / cég szerkesztése (a Salesmate-be is beíródik) */
	const EDB = '<button type="button" class="cpx-btn ghost sm d3-eedit" data-eedit>✎ Szerkesztés</button>';
	function bindEntityEdit(sh, fn) { const b = sh.querySelector('[data-eedit]'); if (b) b.addEventListener('click', fn); }
	function editEntity(sh, k, id, back, cur, coName) {
		const ct = k === 'ct';
		const F = ct ? [['name', 'Név', 'text'], ['email', 'E-mail', 'email'], ['mobile', 'Telefonszám', 'tel'], ['city', 'Város', 'text'], ['company_id', 'Cég', 'co'], ['description', 'Leírás / megjegyzés', 'area']]
			: [['name', 'Cégnév', 'text'], ['website', 'Weboldal', 'text'], ['phone', 'Telefon', 'tel'], ['description', 'Leírás / megjegyzés', 'area']];
		const field = f => {
			const nm = ' data-k="' + f[0] + '"', v = cur[f[0]] || '';
			let inp;
			if (f[2] === 'area') inp = '<textarea rows="4"' + nm + '>' + esc(v) + '</textarea>';
			else if (f[2] === 'co') inp = '<span class="crm-copk"><input type="hidden"' + nm + ' value="' + esc(v) + '"><input type="search" data-cos placeholder="Cég keresése (min. 2 betű)…" value="' + esc(coName) + '" autocomplete="off"><button type="button" class="cpx-btn ghost sm" data-cox' + (v ? '' : ' hidden') + '>× Nincs cég</button><span class="crm-copl" hidden></span></span>';
			else inp = '<input type="' + ({ email: 'email', tel: 'tel' }[f[2]] || 'text') + '"' + nm + ' value="' + esc(v) + '"' + (f[0] === 'name' ? ' required' : '') + '>';
			return '<label class="crm-ef"><span>' + esc(f[1]) + (f[0] === 'name' ? ' *' : '') + '</span>' + inp + '</label>';
		};
		sh.innerHTML = head360((ct ? 'Kontakt' : 'Cég') + ' szerkesztése: ' + esc(cur.name || ''), back) + '<form class="crm-edit d3-eform" novalidate><div class="crm-eg">' + F.map(field).join('') + '</div>' +
			'<p class="crm-ro">Mentéskor csak a megváltozott mezők íródnak be, a Salesmate-be is.' + (ct ? ' A cég csak meglévő cégre állítható.' : '') + '</p>' +
			'<div class="crm-ebar"><button class="cpx-btn" type="submit">Mentés</button><button class="cpx-btn ghost" type="button" data-cancel>Mégse</button><span class="crm-emsg" aria-live="polite"></span></div></form>';
		bindBack(sh, back);
		sh.scrollTop = 0;
		const reopen = () => (ct ? openContact : openCompany)(id, back);
		const form = sh.querySelector('form');
		sh.querySelector('[data-cancel]').onclick = reopen;
		/* cégválasztó: keresés a meglévő cégek között */
		const cos = form.querySelector('[data-cos]');
		if (cos) {
			const hid = form.querySelector('[data-k="company_id"]'), pl = form.querySelector('.crm-copl'), cx = form.querySelector('[data-cox]');
			let qt = 0, seq = 0;
			cos.addEventListener('input', () => {
				clearTimeout(qt);
				const q = cos.value.trim();
				if (q.length < 2) { pl.hidden = true; return; }
				qt = setTimeout(async () => {
					const my = ++seq; let r;
					try { r = await post('cp_crm_who', { q: q }); } catch (e) { return; }
					if (my !== seq) return;
					const rows = (r.rows || []).filter(x => x.k === 'co').slice(0, 8);
					pl.innerHTML = rows.length ? rows.map(x => '<button type="button" data-coid="' + x.id + '">' + esc(x.n) + (x.c ? ' <small>' + x.c + ' deal</small>' : '') + '</button>').join('') : '<small class="cpx-muted">Nincs ilyen cég.</small>';
					pl.hidden = false;
					pl.querySelectorAll('[data-coid]').forEach(b => b.addEventListener('click', () => { hid.value = b.dataset.coid; cos.value = b.firstChild.textContent; pl.hidden = true; cx.hidden = false; }));
				}, 250);
			});
			cx.addEventListener('click', () => { hid.value = ''; cos.value = ''; cx.hidden = true; pl.hidden = true; });
		}
		form.addEventListener('submit', async e => {
			e.preventDefault();
			const ch = {}, msg = sh.querySelector('.crm-emsg');
			form.querySelectorAll('[data-k]').forEach(el => { const v = el.value.trim(); if (v !== String(cur[el.dataset.k] || '')) ch[el.dataset.k] = v; });
			if (!Object.keys(ch).length) { msg.textContent = 'Nem változott semmi.'; return; }
			if (ch.name !== undefined && !ch.name) { msg.textContent = 'A név nem lehet üres.'; return; }
			const btn = form.querySelector('[type=submit]'); btn.disabled = true; msg.textContent = 'Mentés…';
			try {
				const r = await post('cp_crm_entity_save', { k: k, id: id, f: JSON.stringify(ch) });
				toast(r.warn ? 'Mentve, de: ' + r.warn : 'Mentve – a Salesmate-ben is.', !!r.warn);
				if (window.CPContacts) window.CPContacts.refresh();
				reopen();
			} catch (err) { btn.disabled = false; msg.textContent = err.message; msg.className = 'crm-emsg err'; }
		});
	}
	async function openContact(id, back, fromHist) {
		if (!fromHist) pushPP('ct:' + id);
		const sh = sheet360(head360('Kontakt', back) + '<div class="cpx-empty">Betöltés…</div>');
		if (!sh) return;
		bindBack(sh, back);
		let x;
		try { x = await post('cp_crm_contact', { id: id }); } catch (e) { sh.innerHTML = head360('Kontakt', back) + '<div class="cpx-empty err">' + esc(e.message) + '</div>'; bindBack(sh, back); return; }
		const c = x.contact, co = x.company, ph = c.mobile || c.phone;
		const info = '<div class="d3-who big"><span class="d3-av" style="background:' + avColor(c.id) + '">' + esc(initials(c.name)) + '</span><span><b>' + esc(c.name || c.email) + '</b><small>Kontakt' + (c.type ? ' · ' + esc(c.type) : '') + '</small></span></div><dl class="crm-dl">' +
			(c.email ? '<dt>E-mail</dt><dd><a href="mailto:' + esc(c.email) + '">' + esc(c.email) + '</a></dd>' : '') + (ph ? '<dt>Telefon</dt><dd><a href="tel:' + esc(String(ph).replace(/[^\d+]/g, '')) + '">' + esc(ph) + '</a>' + (c.mobile && c.phone && c.phone !== c.mobile ? ' · ' + esc(c.phone) : '') + '</dd>' : '') +
			(co ? '<dt>Cég</dt><dd><a href="#" data-co="' + co.id + '">' + esc(co.name) + '</a></dd>' : '') + (c.city ? '<dt>Város</dt><dd>' + esc(c.city) + '</dd>' : '') + (c.tags ? '<dt>Címkék</dt><dd>' + esc(c.tags) + '</dd>' : '') +
			(c.utm_source || c.utm_campaign ? '<dt>Honnan jött</dt><dd>' + esc([c.utm_source, c.utm_medium, c.utm_campaign].filter(Boolean).join(' / ')) + '</dd>' : '') + '<dt>Felvéve</dt><dd>' + esc(huDT(c.created_at)) + '</dd></dl>' +
			(c.description ? '<div class="crm-desc">' + nl(c.description) + '</div>' : '') + EDB;
		entityView(sh, esc(c.name || c.email), back, info, x, 'ct', id, c.name || c.email, '');
		ceList(sh, id); /* v2.9.73: a kontakt további e-mail-címei */
		bindEntityEdit(sh, () => editEntity(sh, 'ct', id, back, { name: c.name || '', email: c.email || '', mobile: c.mobile || c.phone || '', city: c.city || '', company_id: co ? String(co.id) : '', description: c.description || '' }, co ? co.name : ''));
	}
	/* v2.9.73: további e-mail-címek (a levelezés ajánlatából felvett, csak a panelé) – az E-mail sor után, törlés gombbal */
	async function ceList(sh, cid) {
		const dl = sh.querySelector('.crm-dl'); if (!dl) return;
		let r; try { r = await post('cp_ce_list', { cid: cid }); } catch (e) { return; }
		if (!dl.isConnected) return;
		dl.querySelectorAll('[data-ce]').forEach(x => x.remove());
		const rows = (r && r.rows) || [];
		if (!rows.length) return;
		const dt = document.createElement('dt'); dt.dataset.ce = '1'; dt.textContent = 'További címek';
		const dd = document.createElement('dd'); dd.dataset.ce = '1'; dd.className = 'crm-ce';
		dd.innerHTML = rows.map(a => '<span class="crm-ce-i"><a href="mailto:' + esc(a.e) + '">' + esc(a.e) + '</a><button type="button" data-cedel="' + a.id + '" aria-label="' + esc(a.e) + ' eltávolítása" title="Eltávolítás">×</button></span>').join('');
		const after = [...dl.querySelectorAll('dt')].find(x => x.textContent === 'E-mail');
		if (after && after.nextElementSibling) after.nextElementSibling.after(dt, dd); else dl.prepend(dt, dd);
		dd.querySelectorAll('[data-cedel]').forEach(b => b.addEventListener('click', async () => {
			const a = rows.find(x => String(x.id) === b.dataset.cedel);
			if (!a || !(await window.CPXConfirm('Eltávolítod a(z) ' + a.e + ' címet ettől a kontakttól? Az innen érkező levelek ezután nem kerülnek automatikusan a dealjéhez.'))) return;
			b.disabled = true;
			try { await post('cp_ce_del', { id: a.id, cid: cid }); toast('Cím eltávolítva.'); ceList(sh, cid); } catch (e) { b.disabled = false; toast(e.message, true); }
		}));
	}
	async function openCompany(id, back, fromHist) {
		if (!fromHist) pushPP('co:' + id);
		const sh = sheet360(head360('Cég', back) + '<div class="cpx-empty">Betöltés…</div>');
		if (!sh) return;
		bindBack(sh, back);
		let x;
		try { x = await post('cp_crm_company', { id: id }); } catch (e) { sh.innerHTML = head360('Cég', back) + '<div class="cpx-empty err">' + esc(e.message) + '</div>'; bindBack(sh, back); return; }
		const co = x.company;
		const info = '<div class="d3-who big"><span class="d3-av sq" style="background:' + avColor(co.id + 3) + '">' + esc(initials(co.name)) + '</span><span><b>' + esc(co.name) + '</b><small>Cég' + (co.type ? ' · ' + esc(co.type) : '') + '</small></span></div><dl class="crm-dl">' +
			(safeUrl(co.website) ? '<dt>Weboldal</dt><dd><a href="' + esc(safeUrl(co.website)) + '" target="_blank" rel="noopener">' + esc(co.website) + ' ↗</a></dd>' : '') + (co.phone ? '<dt>Telefon</dt><dd>' + esc(co.phone) + '</dd>' : '') + '</dl>' + (co.description ? '<div class="crm-desc">' + nl(co.description) + '</div>' : '') + EDB;
		const extra = '<section class="d3-c"><h4>Kontaktok <small>' + x.contacts.length + '</small></h4>' + (x.contacts.length ? '<ul class="d3-team">' + x.contacts.map(c => '<li><a href="#" data-ct="' + c.id + '"><span class="d3-av" style="background:' + avColor(c.id) + '">' + esc(initials(c.name)) + '</span>' + esc(c.name || c.email) + '</a><small>' + esc(c.email || '') + '</small></li>').join('') + '</ul>' : '<p class="cpx-muted">Nincs.</p>') + '</section>';
		entityView(sh, esc(co.name), back, info, x, 'co', id, co.name, extra);
		bindEntityEdit(sh, () => editEntity(sh, 'co', id, back, { name: co.name || '', website: co.website || '', phone: co.phone || '', description: co.description || '' }, ''));
	}
	/* a deal-listában: cég / kontakt szerinti szűrés kereséssel */
	function whoPicker(btn) {
		const pop = document.createElement('div');
		pop.className = 'd3-wpop';
		pop.innerHTML = '<input type="search" placeholder="Cég vagy kontakt neve, e-mail…" aria-label="Cég vagy kontakt"><div class="d3-wl"><p class="cpx-muted">Írj be legalább 2 betűt.</p></div>';
		btn.after(pop);
		const inp = pop.querySelector('input'), l = pop.querySelector('.d3-wl');
		inp.focus();
		let t = null;
		inp.addEventListener('input', () => { clearTimeout(t); t = setTimeout(async () => {
			const q = inp.value.trim(); if (q.length < 2) { l.innerHTML = '<p class="cpx-muted">Írj be legalább 2 betűt.</p>'; return; }
			let r; try { r = await post('cp_crm_who', { q: q }); } catch (e) { l.innerHTML = '<p class="cpx-muted">' + esc(e.message) + '</p>'; return; }
			l.innerHTML = r.rows.length ? r.rows.map(w => '<button type="button" data-wk="' + w.k + '" data-wid="' + w.id + '" data-wn="' + esc(w.n) + '"><span>' + (w.k === 'co' ? '🏢 ' : '👤 ') + esc(w.n) + (w.e && w.e !== w.n ? ' <small>' + esc(w.e) + '</small>' : '') + '</span><small>' + w.c + ' deal</small></button>').join('') : '<p class="cpx-muted">Nincs találat.</p>';
			l.querySelectorAll('[data-wk]').forEach(b => b.addEventListener('click', () => { pop.remove(); S.who = { k: b.dataset.wk, id: Number(b.dataset.wid), n: b.dataset.wn }; S.st = ''; S.p = 0; SEL.clear(); save(); view(S.main); }));
		}, 250); });
		setTimeout(() => document.addEventListener('click', function off(e) { if (!pop.isConnected) { document.removeEventListener('click', off); return; } if (!pop.contains(e.target) && e.target !== btn) { pop.remove(); document.removeEventListener('click', off); } }), 0);
	}
	/* ------------------------------------------------------------ deal szerkesztése (az adatlapon) */
	const EDIT = [
		['Deal', [['title', 'Név', 'text'], ['value', 'Érték (Ft)', 'num'], ['owner_id', 'Felelős', 'user'], ['source', 'Forrás', 'text']]],
		['Rendezvény', [['event_d', 'Rendezvény dátuma', 'date'], ['start_at', 'Kezdés (óra:perc)', 'time'], ['venue', 'Helyszín', 'text'], ['guests', 'Tervezett létszám', 'int'], ['hours', 'Rendelkezésre állás (óra)', 'int'],
			['service', 'Szolgáltatás', 'text'], ['pkg_fix', 'Választott csomag', 'text'], ['services_ec', 'EventCrazy szolgáltatás(ok)', 'multi'], ['services_cp', 'Választott szolgáltatás', 'multi'], ['services_more', 'További szolgáltatások', 'multi'], ['extras', 'Választott extra', 'multi'],
			['background', 'Fotófal / háttér', 'text'], ['km_fee', 'Kiszállási díj (Ft)', 'int'], ['extension', 'Hosszabbítás (óra)', 'num'], ['operator', 'Gépkezelő asszisztens', 'text'], ['album', 'Fotóalbum', 'text'], ['template_st', 'Template', 'text'], ['coupon', 'Kuponkód', 'area']]],
		['Pénzügy és szerződés', [['pay_method', 'Fizetési mód', 'text'], ['paid', 'Bejött az utalás?', 'text'], ['ctype', 'Szerződés EV/KFT', 'text']]],
		['Megjegyzés', [['note', 'Megjegyzés', 'area']]]
	];
	const CEDIT = [['name', 'Név', 'text'], ['email', 'E-mail', 'email'], ['mobile', 'Telefonszám', 'tel']]; /* v2.9.215: egy telefonszám-mező (a Salesmate „Mobile” mezője; ha üres, a régi „Phone” értéke látszik) */
	const timeOf = v => (v && /\d{2}:\d{2}/.test(v) ? v.match(/\d{2}:\d{2}/)[0] : '');
	/* v1.84: helyben szerkesztés – az adatlapon az értékre kattintva (egy mező, ugyanaz a mentés, mint a Szerkesztés ablaké) */
	const EDIT_T = {}; EDIT.forEach(sec => sec[1].forEach(f => { EDIT_T[f[0]] = f; }));
	function ikA(k, d) { const f = EDIT_T[k]; return R.edit && f && !(d && d.deleted_at) ? ' data-ik="' + k + '" title="Kattints a szerkesztéshez"' : ''; }
	/* v2.9.127: választólisták az inline szerkesztőhöz (egyszer töltődik) */
	let IVALS = null, IVP = null;
	const ivVals = () => IVALS ? Promise.resolve(IVALS) : (IVP || (IVP = post('cp_crm_opts', {}).then(r => (IVALS = r.vals || {}), () => { IVP = null; return {}; })));
	const IV_OTHER = '\u0000egyedi';
	/* fix értékkészletű mezők (a Salesmate választólistái szerint) – a ritkán használt értékek is mindig a listában vannak */
	const IV_FIX = { ctype: ['EV', 'KFT'], paid: ['Igen', 'Nem'], album: ['Kell', 'Nem kell'], reminder: ['Igen', 'Nem'], template_paid: ['Igen', 'Nem'] };
	function bindInline(sh, x, back) {
		const d = x.deal;
		ivVals();
		sh.addEventListener('click', async e => {
			const el = e.target.closest('[data-ik]');
			if (!el || el.classList.contains('ik-on') || e.target.closest('a, button, input, select, textarea')) return; /* v2.9.76: a becsült ár buborékjára kattintva is szerkeszthető az ár */
			/* v2.9.128: a választólisták megvárása (első kattintásnál) */
			if (!IVALS && EDIT_T[el.dataset.ik] && ['text', 'multi'].includes(EDIT_T[el.dataset.ik][2])) { el.classList.add('ik-load'); await ivVals(); el.classList.remove('ik-load'); if (el.classList.contains('ik-on')) return; }
			const k = el.dataset.ik, f = EDIT_T[k], type = f[2], cur = editVal(d, k), old = el.innerHTML;
			let inp;
			if (type === 'user') inp = '<select data-iv>' + opts([['', '–']].concat(Object.keys(USERS).filter(i => USERS[i].a || String(i) === String(cur)).map(i => [i, USERS[i].n])), String(cur || '')) + '</select>';
			else if (type === 'area') inp = '<textarea data-iv rows="3">' + esc(cur) + '</textarea>';
			else if (type === 'multi') {
				/* v2.9.130: többválasztós mező – pipálható lista + új érték hozzáadása */
				const on = cur.split(' | ').map(v => v.trim()).filter(Boolean), all = Array.from(new Set(((IVALS && IVALS[k]) || []).concat(on)));
				inp = '<span class="crm-multi ik-multi" data-iv data-multi>' + all.map(v => '<label><input type="checkbox" value="' + esc(v) + '"' + (on.includes(v) ? ' checked' : '') + '> ' + esc(v) + '</label>').join('') + '<input type="text" class="ik-mnew" placeholder="+ Új érték (Enter)"></span>';
			}
			else if (type === 'text' && ((IVALS && (IVALS[k] || []).length) || IV_FIX[k])) {
				/* lenyíló lista az ismert értékekkel + „Egyedi érték…” (kézzel beírható) */
				const L = Array.from(new Set((IV_FIX[k] || []).concat((IVALS && IVALS[k]) || []))); if (cur && !L.includes(cur)) L.unshift(cur);
				inp = '<select data-iv><option value="">– nincs –</option>' + L.map(v => '<option' + (v === cur ? ' selected' : '') + '>' + esc(v) + '</option>').join('') + '<option value="' + IV_OTHER + '">✎ Egyedi érték…</option></select>';
			}
			else inp = '<input data-iv type="' + ({ date: 'date', time: 'time', int: 'number', num: 'number', email: 'email', tel: 'tel' }[type] || 'text') + '"' + (type === 'num' ? ' step="0.5"' : '') + ' value="' + esc(cur) + '">';
			el.classList.add('ik-on');
			el.innerHTML = '<span class="ik-ed">' + inp + '<button type="button" class="ik-ok" title="Mentés (Enter)">✓</button><button type="button" class="ik-x" title="Mégse (Esc)">✕</button></span>';
			let iv = el.querySelector('[data-iv]'); if (type !== 'multi') { iv.focus(); if (iv.select && type !== 'date' && type !== 'time') iv.select(); }
			const mval = () => Array.from(iv.querySelectorAll('input[type=checkbox]:checked')).map(c => c.value).join(' | ');
			if (type === 'multi') {
				const nw = iv.querySelector('.ik-mnew');
				nw.addEventListener('keydown', ev => {
					if (ev.key === 'Escape') { ev.preventDefault(); cancel(); return; }
					if (ev.key !== 'Enter') return; ev.preventDefault();
					const v = nw.value.trim().replace(/\s*\|\s*/g, ' ');
					if (!v) { save(); return; }
					const ex = Array.from(iv.querySelectorAll('input[type=checkbox]')).find(c => c.value.toLowerCase() === v.toLowerCase());
					if (ex) ex.checked = true; else { const l = document.createElement('label'); l.innerHTML = '<input type="checkbox" checked> '; l.firstChild.value = v; l.appendChild(document.createTextNode(v)); nw.before(l); }
					nw.value = '';
				});
			}
			/* „Egyedi érték…”: szövegmezőre vált */
			if (iv.tagName === 'SELECT' && type === 'text') iv.addEventListener('change', () => { if (iv.value !== IV_OTHER) return; const t = document.createElement('input'); t.type = 'text'; t.setAttribute('data-iv', ''); t.value = cur; t.placeholder = 'Írd be az értéket'; iv.replaceWith(t); iv = t; t.focus(); t.select(); bindKeys(t); });
			const cancel = () => { el.classList.remove('ik-on'); el.innerHTML = old; };
			const save = async () => {
				if (type === 'multi') { const nw = iv.querySelector('.ik-mnew'); if (nw && nw.value.trim()) nw.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter' })); }
				const v = type === 'multi' ? mval() : iv.value === IV_OTHER ? cur : iv.value.trim();
				if (v === cur) { cancel(); return; }
				if (k === 'title' && !v) { toast('A név nem lehet üres.', true); return; }
				el.querySelectorAll('button').forEach(b => { b.disabled = true; });
				try { await post('cp_crm_save', { id: d.id, f: JSON.stringify({ [k]: v }), c: '{}' }); toast((f[1] || k) + ' mentve – a Salesmate-be is.'); openDeal(d.id, back, true); }
				catch (er) { el.querySelectorAll('button').forEach(b => { b.disabled = false; }); toast(er.message, true); }
			};
			el.querySelector('.ik-ok').addEventListener('click', ev => { ev.stopPropagation(); save(); });
			el.querySelector('.ik-x').addEventListener('click', ev => { ev.stopPropagation(); cancel(); });
			const bindKeys = t => t.addEventListener('keydown', ev => { if (ev.key === 'Escape') { ev.preventDefault(); cancel(); } if (ev.key === 'Enter' && (type !== 'area' || ev.ctrlKey || ev.metaKey)) { ev.preventDefault(); save(); } });
			if (type !== 'multi') bindKeys(iv);
		});
	}
	function editVal(d, k) { const v = d[k]; if (v == null) return ''; if (k === 'start_at') return timeOf(v); if (k === 'value' || k === 'extension') return String(Number(v)); return String(v); }
	async function editDeal(sh, x) {
		const d = x.deal, c = x.contact || {};
		let vals = {};
		try { vals = (await post('cp_crm_opts', {})).vals || {}; } catch (e) { /* választólista nélkül is szerkeszthető */ }
		const field = (k, label, type, cur) => {
			const nm = ' data-k="' + k + '"';
			let inp;
			if (type === 'user') inp = '<select' + nm + '>' + opts([['', '–']].concat(Object.keys(USERS).filter(i => USERS[i].a || String(i) === String(cur)).map(i => [i, USERS[i].n])), String(cur || '')) + '</select>';
			else if (type === 'area') inp = '<textarea rows="3"' + nm + '>' + esc(cur) + '</textarea>';
			else if (type === 'multi') {
				const on = String(cur || '').split(' | ').filter(Boolean);
				const all = Array.from(new Set(((vals[k] || [])).concat(on)));
				inp = '<span class="crm-multi"' + nm + ' data-multi>' + all.map(v => '<label><input type="checkbox" value="' + esc(v) + '"' + (on.includes(v) ? ' checked' : '') + '> ' + esc(v) + '</label>').join('') + (all.length ? '' : '<small class="cpx-muted">Nincs választható érték.</small>') + '</span>';
			} else {
				const t = { date: 'date', time: 'time', int: 'number', num: 'number', email: 'email', tel: 'tel' }[type] || 'text';
				inp = '<input type="' + t + '"' + (type === 'num' ? ' step="0.5"' : '') + nm + ' value="' + esc(cur) + '"' + (vals[k] && type === 'text' ? ' list="crm-l-' + k + '"' : '') + '>' + (vals[k] && type === 'text' ? '<datalist id="crm-l-' + k + '">' + vals[k].map(v => '<option value="' + esc(v) + '">').join('') + '</datalist>' : '');
			}
			return '<label class="crm-ef"><span>' + esc(label) + '</span>' + inp + '</label>';
		};
		let h = sheetHead('Szerkesztés: ' + esc(d.title || 'Deal #' + d.id)) + '<form class="crm-edit" novalidate>';
		EDIT.forEach(sec => { h += '<h4>' + esc(sec[0]) + '</h4><div class="crm-eg">' + sec[1].map(f => field(f[0], f[1], f[2], editVal(d, f[0]))).join('') + '</div>'; });
		if (x.contact) h += '<h4>Kapcsolat</h4><div class="crm-eg" data-contact>' + CEDIT.map(f => field('c_' + f[0], f[1], f[2], (f[0] === 'mobile' ? c.mobile || c.phone : c[f[0]]) || '')).join('') + '</div>';
		h += '<p class="crm-ro">Mentéskor csak a megváltozott mezők íródnak be, a Salesmate-be is (a Salesmate automatizmusai lefuthatnak). A változás a deal előzményeiben a neveddel jelenik meg.</p>';
		h += '<div class="crm-ebar"><button class="cpx-btn" type="submit">Mentés</button><button class="cpx-btn ghost" type="button" data-cancel>Mégse</button><span class="crm-emsg" aria-live="polite"></span></div></form>';
		sh.innerHTML = h;
		sh.scrollTop = 0;
		const form = sh.querySelector('form');
		const read = el => el.dataset.multi !== undefined ? Array.from(el.querySelectorAll('input:checked')).map(i => i.value).join(' | ') : el.value.trim();
		const orig = {};
		form.querySelectorAll('[data-k]').forEach(el => { orig[el.dataset.k] = read(el); });
		sh.querySelector('[data-cancel]').onclick = () => openDeal(d.id);
		form.addEventListener('submit', async e => {
			e.preventDefault();
			const ch = {}, cc = {};
			form.querySelectorAll('[data-k]').forEach(el => { const k = el.dataset.k, v = read(el); if (v !== orig[k]) { if (k.indexOf('c_') === 0) cc[k.slice(2)] = v; else ch[k] = v; } });
			const msg = sh.querySelector('.crm-emsg');
			if (!Object.keys(ch).length && !Object.keys(cc).length) { msg.textContent = 'Nem változott semmi.'; return; }
			if (ch.title !== undefined && !ch.title) { msg.textContent = 'A név nem lehet üres.'; return; }
			const btn = form.querySelector('[type=submit]'); btn.disabled = true; msg.textContent = 'Mentés…'; msg.className = 'crm-emsg';
			try {
				const r = await post('cp_crm_save', { id: d.id, f: JSON.stringify(ch), c: JSON.stringify(cc) });
				toast(r.warn ? 'Mentve, de: ' + r.warn : 'Mentve – a Salesmate-ben is.', !!r.warn);
				if (S.mode === 'list') load(); else if (S.board) { const i = S.board.rows.findIndex(y => y.id === d.id); if (i >= 0 && r.row) { S.board.rows[i] = r.row; redrawBoard(); } }
				openDeal(d.id);
			} catch (err) { btn.disabled = false; msg.textContent = err.message; msg.className = 'crm-emsg err'; }
		});
	}

	/* ------------------------------------------------------------ új deal */
	/* pre: szakasz neve, vagy (v1.69, levélből) { thread, msg, onCreated(id, row, moveThread) } */
	async function newDeal(pre) {
		const X = pre && typeof pre === 'object' ? pre : null;
		pre = !X && typeof pre === 'string' && STAGES.includes(pre) ? pre : STAGES[0];
		let vals = {};
		const sh0 = sheet(sheetHead('Új deal') + '<div class="cpx-empty">Betöltés…</div>');
		if (!sh0) return;
		try { vals = (await post('cp_crm_opts', {})).vals || {}; } catch (e) { /* választólista nélkül is */ }
		if (!sh0.isConnected) return;
		const me = R.me_sm && USERS[String(R.me_sm)] ? String(R.me_sm) : '';
		const inp = (k, label, type, extra) => '<label class="crm-ef"><span>' + esc(label) + '</span>' + (type === 'area' ? '<textarea rows="3" data-k="' + k + '"></textarea>' : '<input type="' + type + '" data-k="' + k + '"' + (extra || '') + '>') + '</label>';
		const multi = k => '<span class="crm-multi" data-k="' + k + '" data-multi>' + (vals[k] || []).map(v => '<label><input type="checkbox" value="' + esc(v) + '"> ' + esc(v) + '</label>').join('') + '</span>';
		let h = sheetHead('Új deal') + '<form class="crm-edit" novalidate>' +
			'<h4>Kapcsolat</h4><div class="crm-eg">' + inp('c_name', 'Név *', 'text', ' required') + inp('c_email', 'E-mail', 'email') + inp('c_mobile', 'Telefonszám', 'tel') + '<input type="hidden" data-k="c_id"></div><div class="crm-dupc" data-dupc hidden></div>' +
			'<p class="crm-ro" style="margin-top:6px">Ha az e-mail-cím már szerepel egy kontaktnál, a deal ahhoz kerül. Ha hasonló kontaktot találok, itt jelzem.</p>' +
			'<h4>Rendezvény</h4><div class="crm-eg">' + inp('event_d', 'Rendezvény dátuma', 'date') + inp('start_at', 'Kezdés (óra:perc)', 'time') + inp('venue', 'Helyszín', 'text') + inp('guests', 'Tervezett létszám', 'number') + inp('hours', 'Rendelkezésre állás (óra)', 'number') +
			'<label class="crm-ef"><span>Szolgáltatás</span><input type="text" data-k="service" list="crm-n-svc"><datalist id="crm-n-svc">' + (vals.service || []).map(v => '<option value="' + esc(v) + '">').join('') + '</datalist></label>' +
			'<label class="crm-ef"><span>EventCrazy szolgáltatás(ok)</span>' + multi('services_ec') + '</label></div>' +
			'<h4>Deal</h4><div class="crm-eg">' + inp('title', 'Deal neve *', 'text', ' required') +
			'<label class="crm-ef"><span>Szakasz</span><select data-k="stage">' + opts(STAGES.map(x => [x, x]), pre) + '</select></label>' +
			'<label class="crm-ef"><span>Felelős</span><select data-k="owner_id">' + opts([['', '–']].concat(Object.keys(USERS).filter(i => USERS[i].a).map(i => [i, USERS[i].n])), me) + '</select></label>' +
			inp('value', 'Érték (Ft)', 'number') + inp('note', 'Megjegyzés', 'area') + '</div>' +
			'<p class="crm-ro">A deal a Salesmate-ben is létrejön; az átnevezést és a Pumble-értesítést a panel végzi.</p>' +
			'<div class="crm-ebar"><button class="cpx-btn" type="submit">Deal létrehozása</button><button class="cpx-btn ghost" type="button" data-close>Mégse</button><span class="crm-emsg" aria-live="polite"></span></div></form>';
		sh0.innerHTML = h;
		const form = sh0.querySelector('form');
		const q = k => form.querySelector('[data-k="' + k + '"]');
		let titleTouched = false;
		q('title').addEventListener('input', () => { titleTouched = true; });
		/* a deal neve magától: „[Crazyphoto] Név | dátum” (amíg kézzel át nem írod) */
		let brand = '[Crazyphoto]', yr = '';
		const auto = () => { if (titleTouched) return; const n = q('c_name').value.trim(), dt = q('event_d').value; q('title').value = n ? brand + ' ' + n + (dt ? ' | ' + dt : (yr ? ' | ' + yr : '')) : ''; };
		q('c_name').addEventListener('input', auto); q('event_d').addEventListener('input', auto); q('event_d').addEventListener('change', auto);
		/* v2.5: duplikátum-figyelés – ha már van ilyen kontakt, ahhoz lehet rendelni a dealt */
		const dupc = form.querySelector('[data-dupc]');
		let dupT = null, dupSeq = 0, dupNo = new Set();
		const dupDraw = rows => {
			const pick = Number(q('c_id').value || 0), P = rows.find(x => x.id === pick);
			if (P) { dupc.hidden = false; dupc.innerHTML = '<div class="crm-dupok">✓ A deal a meglévő kontakthoz kerül: <b>' + esc(P.name || P.email) + '</b> (' + P.n + ' deal)<button type="button" class="ml-link" data-dupu>mégse, új kontakt</button></div>'; dupc.querySelector('[data-dupu]').addEventListener('click', () => { dupNo.add(pick); q('c_id').value = ''; dupDraw(rows); }); return; }
			const L = rows.filter(x => !dupNo.has(x.id));
			dupc.hidden = !L.length;
			dupc.innerHTML = L.length ? '<div class="crm-duph">⚠ Már van hasonló kontakt – ha ő az, rendeld hozzá, így nem lesz duplikátum:</div>' + L.map(x => '<div class="crm-dupr"><span><b>' + esc(x.name || x.email || '#' + x.id) + '</b><small>' + esc([x.email, x.mobile || x.phone, x.co, x.n + ' deal' + (x.last_ev ? ', utolsó rendezvény: ' + huD(x.last_ev) : '')].filter(Boolean).join(' · ')) + '</small><small class="crm-dupw">' + esc(x.why.join(', ')) + '</small></span><span class="crm-dupb"><button type="button" class="cpx-btn sm" data-dupp="' + x.id + '">Ehhez rendelem</button><button type="button" class="ml-link" data-dupx="' + x.id + '">nem ő</button></span></div>').join('') : '';
			dupc.querySelectorAll('[data-dupp]').forEach(b => b.addEventListener('click', () => {
				const x = rows.find(z => z.id === Number(b.dataset.dupp));
				q('c_id').value = String(x.id);
				if (!q('c_email').value && x.email) q('c_email').value = x.email;
				if (!q('c_mobile').value && (x.mobile || x.phone)) q('c_mobile').value = x.mobile || x.phone;
				dupDraw(rows);
			}));
			dupc.querySelectorAll('[data-dupx]').forEach(b => b.addEventListener('click', () => { dupNo.add(Number(b.dataset.dupx)); dupDraw(rows); }));
		};
		const dupCheck = () => {
			clearTimeout(dupT);
			dupT = setTimeout(async () => {
				const my = ++dupSeq, n = q('c_name').value.trim(), e = q('c_email').value.trim(), ph = q('c_mobile').value.trim();
				if (n.length < 5 && e.length < 5 && ph.replace(/\D/g, '').length < 8) { if (!q('c_id').value) { dupc.hidden = true; } return; }
				try { const r = await post('cp_crm_dup_check', { name: n, email: e, phone: ph }); if (my === dupSeq && form.isConnected) dupDraw(r.rows || []); } catch (er) { /* a figyelő nélkül is létrehozható */ }
			}, 500);
		};
		['c_name', 'c_email', 'c_mobile'].forEach(k => q(k).addEventListener('input', dupCheck));
		/* v1.69: deal egy levélből – ismert adatok azonnal, az AI-kiolvasás utána (csak az üres mezőkbe) */
		if (X) {
			form.querySelector('.crm-ebar').insertAdjacentHTML('beforebegin', '<p class="crm-aist" data-aist>Az adatok betöltése a levélből…</p><label class="crm-chk crm-mv"><input type="checkbox" data-mv checked> <span>A levélszál az új dealhez kerüljön</span></label>');
			const touched = new Set();
			form.querySelectorAll('[data-k]').forEach(el => el.addEventListener('input', () => touched.add(el.dataset.k)));
			const set = (k, v) => { const el = q(k); if (!el || v === '' || v == null || v === 0 || touched.has(k)) return; if (el.dataset.multi !== undefined) { const L = [].concat(v); el.querySelectorAll('input').forEach(i => { if (L.includes(i.value)) i.checked = true; }); } else if (!el.value) el.value = String(v); };
			const st = form.querySelector('[data-aist]');
			const load1 = async ai => {
				const r = await post('cp_ai_deal', { thread: X.thread, msg: X.msg || 0, all: X.all ? 1 : '', ai: ai ? 1 : '', svc: (vals.services_ec || []).join('|') });
				const k = r.known;
				if (!ai) {
					if (k.brand) brand = k.brand;
					['c_name', 'c_email', 'c_mobile'].forEach(f => set(f, k[f]));
					dupCheck();
					if (k.owner_id && q('owner_id').querySelector('option[value="' + k.owner_id + '"]')) q('owner_id').value = k.owner_id;
					const mv = form.querySelector('.crm-mv span');
					if (k.old_deal) mv.textContent = 'A levélszál az új dealhez kerüljön (most: ' + k.old_title + ')';
					auto();
				}
				return r;
			};
			try {
				await load1(false);
				st.textContent = '✨ Az AI kiolvassa a levélből a rendezvény adatait…';
				const r = await load1(true);
				const a = r.ai;
				if (a) {
					['event_d', 'start_at', 'venue', 'guests', 'hours', 'service', 'services_ec'].forEach(f => set(f, a[f]));
					if (!a.event_d && a.year) { yr = a.year + (a.month ? '-' + String(a.month).padStart(2, '0') : ''); }
					const when = a.event_d ? '' : (a.year ? 'Időpont: ' + a.year + (a.month ? '. ' + ['január', 'február', 'március', 'április', 'május', 'június', 'július', 'augusztus', 'szeptember', 'október', 'november', 'december'][a.month - 1] : '') + ' (pontos dátum még nincs)' : 'Időpont: még nincs megadva');
					set('note', [a.event_type ? 'Rendezvény: ' + a.event_type : '', when, a.summary, 'Forrás: levél – „' + r.known.subject + '”'].filter(Boolean).join('\n'));
					auto();
					st.textContent = '✨ Az AI kitöltötte, amit a levélből ki tudott olvasni – kérlek, ellenőrizd mentés előtt.';
				} else st.textContent = r.aierr ? 'Az AI-kiolvasás nem sikerült (' + r.aierr + ') – a többit töltsd ki kézzel.' : (r.ai_on ? '' : 'Az AI nincs bekapcsolva – a levél adatait töltsd ki kézzel.');
			} catch (er) { st.textContent = er.message; }
		}
		form.addEventListener('submit', async e => {
			e.preventDefault();
			const msg = sh0.querySelector('.crm-emsg');
			const d = {};
			form.querySelectorAll('[data-k]').forEach(el => { d[el.dataset.k] = el.dataset.multi !== undefined ? Array.from(el.querySelectorAll('input:checked')).map(i => i.value).join(' | ') : el.value.trim(); });
			if (!d.c_name) { msg.textContent = 'Add meg a kontakt nevét.'; msg.className = 'crm-emsg err'; return; }
			if (!d.title) { msg.textContent = 'Add meg a deal nevét.'; msg.className = 'crm-emsg err'; return; }
			if (!(await window.CPXConfirm('Létrehozod a deal-t: ' + d.title + '?\n\nA Salesmate-ben is létrejön, és az automatizmusok lefutnak.'))) return;
			const btn = form.querySelector('[type=submit]'); btn.disabled = true; msg.textContent = 'Létrehozás…'; msg.className = 'crm-emsg';
			try {
				const r = await post('cp_crm_create', { d: JSON.stringify(d) });
				toast('A deal létrejött (#' + r.id + ') – a Salesmate-ben is.');
				if (X && X.onCreated) { const mv = form.querySelector('[data-mv]'); try { await X.onCreated(r.id, r.row, !!(mv && mv.checked)); } catch (er) { toast(er.message, true); } }
				load();
				openDeal(r.id);
			} catch (err) { btn.disabled = false; msg.textContent = err.message; msg.className = 'crm-emsg err'; }
		});
		q('c_name').focus();
	}

	function histVal(k, v) {
		if (v == null || v === '') return '';
		if (k === 'owner_id') return uname(v);
		if (k === 'status') return ST_HU[v] || v;
		if (k === 'event_d') return huD(v);
		if (k === 'value') return money(Number(v));
		return String(v).length > 300 ? String(v).slice(0, 300) + '…' : String(v);
	}

	/* ------------------------------------------------------------ Frissítés gomb: szinkron a Salesmate-tel, majd újratöltés */
	async function refresh() {
		S.loading = true; S.err = ''; setStatus();
		try { await post('cp_crm_sync', {}); } catch (e) { S.err = e.message; }
		S.loading = false;
		if (live()) load(); else setStatus();
	}

	/* v1.94: automatikus frissítés – percenként (és amikor visszatérsz a fülre) megnézi, jött-e új deal / megkeresés, vagy módosult-e valami;
	   ha igen, csendben újratölti a listát / táblát (görgetés megmarad). Nem frissít, ha épp nyitva van egy deal, ablak vagy szerkesztés. */
	const AUTO = { t: 0, k: '', n: 0, ln: 0, busy: false };
	const autoBlocked = () => !!(document.hidden || !live() || S.loading || document.querySelector('#cpx-main > .crm-page') || (document.getElementById('cpx-modal') && !document.getElementById('cpx-modal').hidden)
		|| (document.activeElement && document.activeElement.closest && document.activeElement.closest('#crm-body textarea, #crm-body input:not([type=search]), #crm-body select')) || (S.main && S.main.querySelector('.crm-qn')));
	/* v2.9.59 (P1): a változásjelzőt a csengő percenkénti könnyített kérése (cp_ping) hozza „cp-ping” eseményként ($pre);
	   saját cp_crm_stamp kérés csak akkor megy, ha 150 mp óta nem jött jelzés (pl. az értesítések ki vannak kapcsolva) */
	const pingFresh = () => !!(window.CPXPing && Date.now() - window.CPXPing.at < 150000);
	async function autoTick(pre) {
		if (AUTO.busy || autoBlocked()) return;
		AUTO.busy = true;
		try {
			const r = pre && pre.k ? pre : await post('cp_crm_stamp', {});
			if (AUTO.k && r.k !== AUTO.k && !autoBlocked()) {
				const dn = r.n - AUTO.n, dl = r.ln - AUTO.ln;
				const y = window.scrollY, bd = S.main && S.main.querySelector('.crm-board'), x = bd ? bd.scrollLeft : 0;
				await load();
				window.scrollTo(0, y); const bd2 = S.main && S.main.querySelector('.crm-board'); if (bd2) bd2.scrollLeft = x;
				if (dn > 0 || dl > 0) toast(dn > 0 ? dn + ' új deal érkezett – a nézet frissült.' : dl + ' új megkeresés érkezett – a nézet frissült.');
			}
			AUTO.k = r.k; AUTO.n = r.n; AUTO.ln = r.ln;
		} catch (e) { /* csendben – a következő körben újra */ }
		AUTO.busy = false;
	}
	function autoStart() {
		if (AUTO.t) return;
		document.addEventListener('cp-ping', e => { if (e.detail && e.detail.crm) autoTick(e.detail.crm); });
		AUTO.t = setInterval(() => { if (!pingFresh()) autoTick(); }, 60000);
		document.addEventListener('visibilitychange', () => { if (!document.hidden && !pingFresh()) autoTick(); });
		setTimeout(() => { if (!AUTO.k) autoTick(window.CPXPing && window.CPXPing.d && window.CPXPing.d.crm); }, 4000);
	}

	/* mélylink: #deals:leads = a Megkeresések nézet */
	function deep(arg) { const pm = /^(ct|co):(\d+)$/.exec(String(arg || '')); if (pm) { if (pm[1] === 'ct') openContact(Number(pm[2])); else openCompany(Number(pm[2])); return; } if (arg === 'leads') { S.mode = 'board'; save(); if (S.main && S.main.isConnected) view(S.main); } else if (Number(arg) > 0) openDeal(Number(arg)); }
	/* v2.9.182: Lomtár – a törölt deal-ek 30 napig visszaállíthatók (csak admin) */
	async function openBin() {
		const w = document.createElement('div'); w.className = 'cpx-ask crm-bin'; w.setAttribute('role', 'dialog'); w.setAttribute('aria-modal', 'true'); w.setAttribute('aria-label', 'Lomtár');
		w.innerHTML = '<div><div class="crm-binh"><h3>🗑 Lomtár</h3><button type="button" class="crm-binx" data-x aria-label="Bezárás">×</button></div><p class="cpx-muted">A törölt deal-ek 30 napig visszaállíthatók. Visszaállításkor a Salesmate-ben új deal jön létre a régi adataival; a panelben a levelek, a beosztás, az elszámolás és a jegyzetek visszakerülnek hozzá. Az ügyfélportál és a Salesmate-beli jegyzetek, tevékenységek nem jönnek vissza.</p><div data-l><div class="cpx-empty">Betöltés…</div></div></div>';
		document.body.appendChild(w);
		const close = () => { document.removeEventListener('keydown', kd, true); w.remove(); };
		const kd = e => { if (e.key === 'Escape' && !document.querySelector('.cpx-ask:not(.crm-bin)')) { e.preventDefault(); close(); } };
		document.addEventListener('keydown', kd, true);
		w.querySelector('[data-x]').addEventListener('click', close);
		w.addEventListener('mousedown', e => { if (e.target === w) close(); });
		const L = w.querySelector('[data-l]');
		const draw = async () => {
			let r; try { r = await post('cp_crm_bin_list', {}); } catch (e) { L.innerHTML = '<div class="cpx-empty err">' + esc(e.message) + '</div>'; return; }
			if (!r.rows.length) { L.innerHTML = '<div class="cpx-empty">A Lomtár üres.</div>'; return; }
			L.innerHTML = '<ul class="crm-binl">' + r.rows.map(x => '<li><div><b>' + esc(x.title || 'Deal #' + x.id) + '</b><small>' + esc([x.cn, x.event_d ? huD(x.event_d) : '', x.value ? money(x.value) : '', x.status === 'Won' ? 'Megnyert' : x.status === 'Lost' ? 'Elvesztett' : x.stage].filter(Boolean).join(' · ')) + '</small><small>Törölve: ' + esc(x.at.replace(/-/g, '.')) + (x.by ? ' · ' + esc(x.by) : '') + ' · még ' + x.left + ' napig</small></div><button type="button" class="cpx-btn ghost" data-rs="' + x.id + '">Visszaállítás</button></li>').join('') + '</ul>';
			L.querySelectorAll('[data-rs]').forEach(b => b.addEventListener('click', async () => {
				const x = r.rows.find(y => y.id === Number(b.dataset.rs));
				if (!(await window.CPXConfirm('„' + (x.title || '#' + x.id) + '” visszaállítása.\n\nA Salesmate-ben új deal jön létre a régi adataival (új azonosítóval), és a Salesmate automatizmusai lefuthatnak. A panelben a levelek' + (x.threads ? ' (' + x.threads + ' szál a Kukából)' : '') + ', a beosztás, az elszámolás és a jegyzetek visszakerülnek hozzá.' + (x.portal ? '\nAz ügyfélportál nem jön vissza – szükség esetén újat kell kiküldeni.' : ''), { title: 'Visszaállítás', ok: 'Visszaállítás' }))) return;
				b.disabled = true; b.textContent = 'Visszaállítás…';
				try {
					const z = await post('cp_crm_bin_restore', { id: x.id });
					toast('Visszaállítva: ' + (x.title || '#' + x.id) + (z.partial ? ' – a Salesmate csak az alapadatokat fogadta el, a többit nézd át.' : ''), !!z.partial);
					close(); load(); openDeal(z.id);
				} catch (e) { toast(e.message, true); b.disabled = false; b.textContent = 'Visszaállítás'; }
			}));
		};
		draw();
	}
	window.CPCrm = { view, status, refresh, newDeal, open: id => openDeal(id), contact: id => openContact(id), company: id => openCompany(id), deep };
})();
