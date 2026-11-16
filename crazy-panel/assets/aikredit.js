/* Crazy panel – AI-kredit (v2.9.287): az AI-képek (karikatúra és társai) OpenRouter-egyenlege, havi felhasználás,
 * egy kattintásos feltöltés (az OpenRouter oldalán, a fizetés ott történik). Adat: cp_ai_credit (a galéria /ai-setup-ja, 5 perc gyorsítótár). */
(function () {
	'use strict';
	const C = window.CPX || {};
	if (!C.aic) return;
	const TOPUP = 'https://openrouter.ai/settings/credits', ACT = 'https://openrouter.ai/activity';
	const PRICE = { 'google/gemini-nano-banana-2.1': 0.055, 'google/gemini-3.1-flash-image': 0.10, 'openai/gpt-image-2.6-flare': 0.05 };
	const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	const usd = n => Number(n).toLocaleString('hu-HU', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' $';
	const S = { main: null, d: null, err: '', busy: false, seq: 0 };
	async function post(action, params) {
		const fd = new FormData(); fd.append('action', action); fd.append('t', C.t);
		Object.keys(params || {}).forEach(k => fd.append(k, params[k]));
		const r = await fetch(C.ajax, { method: 'POST', body: fd, credentials: 'same-origin' });
		let j = null; try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
		if (!j || !j.success) throw new Error((j && j.data && j.data.msg) === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : ((j && j.data && j.data.msg) || 'Hiba'));
		return j.data;
	}
	const CSS = '.ai-w{max-width:640px}.ai-w h2{margin:0 0 4px;font:700 22px Inter,sans-serif}.ai-sub{margin:0 0 18px;color:var(--mu,#6B6B6B);font-size:14px;line-height:1.45}' +
		'.ai-card{background:#fff;border:1px solid var(--li,#E6E2DA);border-radius:16px;padding:20px}.ai-lbl{font:600 12px Inter,sans-serif;text-transform:uppercase;letter-spacing:.05em;color:var(--mu,#6B6B6B)}' +
		'.ai-bal{font:800 40px/1.1 Inter,sans-serif;margin:6px 0 4px;font-variant-numeric:tabular-nums;color:#141C33}.ai-bal.low{color:#B3261E}.ai-bal.mid{color:#9A5B00}.ai-est{font-size:14px;color:var(--mu,#6B6B6B)}' +
		'.ai-warn{margin:12px 0 0;padding:10px 12px;border-radius:10px;font-size:14px;background:#FBEBEB;color:#8C1D18}.ai-warn.mid{background:#F9EEDA;color:#6B4200}' +
		'.ai-act{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px}.ai-act a,.ai-act button{min-height:40px;display:inline-flex;align-items:center;text-decoration:none}' +
		'.ai-m{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:14px}.ai-m div{background:#fff;border:1px solid var(--li,#E6E2DA);border-radius:12px;padding:12px}.ai-m b{display:block;font:700 20px Inter,sans-serif;font-variant-numeric:tabular-nums}.ai-m span{font-size:12.5px;color:var(--mu,#6B6B6B)}' +
		'.ai-how{margin-top:18px;font-size:14px;line-height:1.55;color:#333}.ai-how ol{padding-left:20px;margin:6px 0 0}.ai-t{font-size:12.5px;color:var(--mu,#6B6B6B);margin-top:10px}' +
		'@media (max-width:520px){.ai-bal{font-size:34px}.ai-m{grid-template-columns:1fr 1fr}.ai-m div:last-child{grid-column:1/-1}.ai-act a,.ai-act button{flex:1 1 auto;justify-content:center}}';
	function css() { if (document.getElementById('ai-css')) return; const st = document.createElement('style'); st.id = 'ai-css'; st.textContent = CSS; document.head.appendChild(st); }
	function draw() {
		const m = S.main; if (!m || !m.isConnected) return;
		const d = S.d, top = '<a class="cpx-btn" href="' + TOPUP + '" target="_blank" rel="noopener">Kredit feltöltése ↗</a>';
		let h = '<div class="ai-w"><h2>AI-kredit</h2><p class="ai-sub">Az AI-képek (karikatúra, magazinborító és társai) az OpenRouter-fiók kreditjéből készülnek. A feltöltés és a fizetés az OpenRouter oldalán történik.</p>';
		if (S.err && !d) h += '<div class="cpx-empty err">' + esc(S.err) + '</div>';
		else if (!d) h += '<div class="cpx-empty">Betöltés…</div>';
		else if (!d.has_key) h += '<div class="ai-card"><b>Nincs beállítva AI-kulcs</b> – az AI-képek most nem készülnek.<p class="ai-est">A kulcsot a WordPress adminban, a „Crazy Booth gépek” oldalon lehet megadni.</p><div class="ai-act">' + (d.admin ? '<a class="cpx-btn ghost" href="' + esc(d.admin) + '" target="_blank" rel="noopener">Beállítás ↗</a>' : '') + '</div></div>';
		else {
			const c = d.credits || {}, b = c.bal, pr = PRICE[d.model] || 0.06, mo = d.month || {};
			const lvl = b == null ? '' : b < 5 ? 'low' : b < 15 ? 'mid' : '';
			h += '<div class="ai-card"><div class="ai-lbl">Egyenleg</div>' +
				(b == null ? '<div class="ai-bal">–</div><div class="ai-est">Az egyenleg ezzel a kulccsal nem kérdezhető le – az OpenRouteren látszik.</div>'
					: '<div class="ai-bal ' + lvl + '">' + usd(b) + '</div><div class="ai-est">kb. ' + Math.max(0, Math.floor(b / pr)).toLocaleString('hu-HU') + ' AI-kép a jelenlegi modellel (~' + pr.toLocaleString('hu-HU', { maximumFractionDigits: 3 }) + ' $ / kép)' + (c.src === 'key' ? ' · a kulcs saját kerete' : '') + '</div>') +
				(lvl === 'low' ? '<p class="ai-warn">Hamarosan elfogy – töltsd fel a rendezvény előtt, különben a gépen az AI-kép nem készül el.</p>' : lvl === 'mid' ? '<p class="ai-warn mid">Kevés a kredit egy nagyobb rendezvényhez – érdemes feltölteni.</p>' : '') +
				'<div class="ai-act">' + top + '<a class="cpx-btn ghost" href="' + ACT + '" target="_blank" rel="noopener">Használat részletei ↗</a><button type="button" class="cpx-btn ghost" data-aif' + (S.busy ? ' disabled' : '') + '>' + (S.busy ? 'Frissítés…' : 'Frissítés') + '</button></div>' +
				(c.at ? '<div class="ai-t">Lekérdezve: ' + new Date(c.at * 1000).toLocaleTimeString('hu-HU', { hour: '2-digit', minute: '2-digit' }) + '</div>' : '') + '</div>' +
				'<div class="ai-m"><div><b>' + Number(mo.n || 0).toLocaleString('hu-HU') + '</b><span>AI-kép ebben a hónapban</span></div><div><b>' + usd(mo.cost || 0) + '</b><span>költség ebben a hónapban</span></div><div><b>' + Number(mo.err || 0).toLocaleString('hu-HU') + '</b><span>sikertelen kérés</span></div></div>' +
				'<div class="ai-how"><b>Feltöltés lépésről lépésre</b><ol><li>Kattints a „Kredit feltöltése” gombra – az OpenRouter oldala nyílik meg.</li><li>Lépj be az OpenRouter-fiókba (a cég fiókja).</li><li>„Add Credits” → összeg → fizetés kártyával.</li><li>Gyere vissza, és nyomd meg a „Frissítés” gombot – az új egyenleg pár másodperc múlva itt látszik.</li></ol></div>';
		}
		m.innerHTML = h + '</div>';
		const f = m.querySelector('[data-aif]'); if (f) f.addEventListener('click', () => load(true));
	}
	async function load(fresh) {
		const my = ++S.seq; S.busy = !!fresh; draw();
		try { const d = await post('cp_ai_credit', fresh ? { fresh: 1 } : {}); if (my !== S.seq) return; S.d = d; S.err = ''; } catch (e) { if (my !== S.seq) return; S.err = e.message; }
		S.busy = false; draw();
	}
	window.CPAi = { view(main) { css(); S.main = main; draw(); load(false); }, refresh() { return load(true); }, status() {} };
})();
