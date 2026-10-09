/* Crazy panel – Sablonkészítő
 * A crazyphoto.hu „Választható fotósablonok” élő előnézetének panelbeli változata:
 *   - lyukasztott PNG (átlátszó fotóablakok) a fotós szoftvernek, egységes 4×6" méretben
 *     (1200×1800 álló / 1800×1200 fekvő, 300 dpi)
 *   - a csíkos sablon duplán: 2 csík egymás mellett egy lapon
 *   - felirat mérete + eltolása (csúszka, nyilak, húzás a képen)
 *   - logó feltöltése és elhelyezése a céges / party sablonoknál
 *   - dealből betöltés: az ügyfél crazyphoto.hu-s választása (megjegyzés mező) előtölti
 *   - egyedi keret AI-val (OpenAI, csak a panelben): álló vázlat + maszk → díszítő keret, a fotóablakokat
 *     a panel lyukasztja ki, a feliratot / logót a panel teszi rá; mentés az „Egyedi” kategóriába
 * A panel.js hívja: window.CPSablon.view(main, ctx)
 * Ügyfél-mód (CPX.sb.client, az ügyfélportál /rendezvenyem/sablon oldala – assets/portal-sablon.js): nincs dealválasztó,
 *   AI, saját keret, PNG/JPG-letöltés; „Sablon mentése” → ctx.save({snap, logo, preview}); ctx.deal / ctx.saved / ctx.logoUrl
 *   ctx: { take(), load(m), events(), who(e), huDate(ymd), tp(id), markTp(id), toast(t) }
 * FIGYELEM: a sablonlista (D) a crazyphoto.hu/valaszthato-foto-templatek/ oldal kódjának másolata –
 * új sablonnál / koordináta-módosításnál mindkét helyen frissíteni kell.
 */
(function () {
'use strict';
const C = window.CPX || {};
const SB = C.sb || {};
const CLIENT = !!SB.client;   /* ügyfélportál: csak választás + felirat + logó, mentés a portálra */
const IMG = f => (SB.img || ((C.ajax || '/wp-admin/admin-ajax.php') + '?action=cp_sb_img&f=')) + encodeURIComponent(f);
const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const pad = n => (n < 10 ? '0' : '') + n;
const ymdToday = () => { const d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); };
const dotDate = ymd => /^\d{4}-\d{2}-\d{2}$/.test(ymd || '') ? ymd.replace(/-/g, '.') + '.' : '';
const OUT_P = [1200, 1800], OUT_L = [1800, 1200]; /* 4×6" 300 dpi */
const DPI = 300;

/* ------------------------------------------------------------ sablonok (a crazyphoto.hu oldal másolata) */
function cat(a, b) { return a.concat(b); }
const SERIF = '"Cormorant Garamond",Georgia,serif', SCRIPT = '"Parisienne",cursive', SANS = 'Montserrat,Arial,sans-serif', DSERIF = '"Cormorant Infant","Cormorant Garamond",Georgia,serif', CINZEL = 'Cinzel,Georgia,serif', SPOOK = 'Creepster,Impact,sans-serif';
const PHOTOS = ['minta-foto-2.webp', 'minta-foto-8.webp', 'minta-foto-4.webp'];
const PH_SET = { wed: ['eskuvo-1', 'eskuvo-2'], ny: ['party-1', 'party-2', 'party-3'], xmas: ['party-3', 'party-1', 'party-2'], corp: ['party-2', 'party-3', 'party-1'], bday: ['szulinap-1', 'szulinap-2'], hallo: ['halloween-1', 'halloween-2'] };
const SETS = {
	wed: { l: ['Első név', 'Második név', 'Dátum'], v: ['Anna', 'Péter', '2026.06.14.'] },
	xmas: { l: ['Felirat', 'Második sor', 'Dátum'], v: ['Boldog Karácsonyt!', 'Crazy Kft. évzáró', '2026.12.18.'] },
	bday: { l: ['Ünnepelt neve', 'Felirat', 'Dátum'], v: ['Lili', '30. szülinap', '2026.05.09.'] },
	hallo: { l: ['Felirat', 'Második sor', 'Dátum'], v: ['Halloween Party', 'Crazy Bár', '2026.10.31.'] },
	corp: { l: ['Cégnév', 'Második sor', 'Dátum'], v: ['Crazy Kft.', 'Évzáró parti', '2026.12.18.'] },
	ny: { l: ['Felirat', 'Második sor', 'Dátum'], v: ['Boldog Új Évet!', 'Szilveszter', '2026.12.31.'] }
};
const CATS = [['all', 'Összes'], ['wed', 'Esküvő'], ['party', 'Céges & party'], ['own', 'Egyedi']].filter(c => !(CLIENT && c[0] === 'own'));
function stack(cx, y1, yA, y2, yD, size, ampSize, dateSize, maxW, col, dcol) { return [{ t: 'a', x: cx, y: y1, s: size, f: SERIF, w: '400', c: col, m: maxW, up: 1, g: 'n' }, { t: 'amp', x: cx, y: yA, s: ampSize, f: SERIF, w: 'italic 400', c: col, g: 'n' }, { t: 'b', x: cx, y: y2, s: size, f: SERIF, w: '400', c: col, m: maxW, up: 1, g: 'n' }, { t: 'c', x: cx, y: yD, s: dateSize, f: DSERIF, w: '500', c: dcol || col, m: maxW }]; }
function andStack(cx, y1, size, col, acol, dcol, maxW) { const r = size / 100; return [{ t: 'a', x: cx, y: y1, s: size, f: SERIF, w: '400', c: col, m: maxW, up: 1, g: 'n' }, { t: 'and', x: cx, y: Math.round(y1 + 60 * r), s: Math.round(60 * r), f: SCRIPT, w: '400', c: acol, g: 'n' }, { t: 'b', x: cx, y: Math.round(y1 + 140 * r), s: size, f: SERIF, w: '400', c: col, m: maxW, up: 1, g: 'n' }, { t: 'c', x: cx, y: Math.round(y1 + 205 * r), s: Math.round(40 * r), f: DSERIF, w: '500', c: dcol, m: maxW }]; }
function pairLine(cx, y, size, col, maxW, dy, dsize, dcol, opt) { const p = { t: 'pair', x: cx, y: y, s: size, f: SERIF, w: '400', c: col, m: maxW, as: 0.72 }; for (const k in opt) p[k] = opt[k]; return [p, { t: 'c', x: cx, y: y + dy, s: dsize, f: DSERIF, w: '500', c: dcol, m: maxW }]; }
function scr(cx, y, s, m, dy, ds) { return [{ t: 'line', x: cx, y: y, s: s, f: SCRIPT, w: '400', c: '#7A5C3E', m: m }, { t: 'c', x: cx, y: y + dy, s: ds, f: DSERIF, w: '500', c: '#8B7355', m: m, ls: 3 }]; }
function corpT(x, y, s, m, by, bs) { return [{ t: 'a', x: x, y: y, s: s, f: SANS, w: '700', c: '#111111', up: 1, m: m, nl: 1 }, { t: 'bc', x: x, y: by, s: bs, f: SANS, w: '600', c: '#555555', up: 1, m: m }]; }
const NAT = { amp: 'and', af: SCRIPT, aw: '400', as: 0.62, ac: '#A08C74', gp: 0.28 };
const D = [
	{ name: 'Zöld levél', set: 'wed', thumb: 'sablon-thumb-v2-zold-level.webp', vars: [
		{ label: '1 kép', ov: 'sablon-leaves-1x.webp', text: stack(596, 1500, 1558, 1628, 1716, 92, 54, 38, 520, '#9A8462') },
		{ label: '2 kép', ov: 'sablon-leaves-2x.webp', text: stack(596, 1498, 1554, 1624, 1712, 92, 54, 38, 520, '#9A8462') },
		{ label: '3 képes csík', ov: 'sablon-leaves-csik-3x.webp', order: 'col', half: 1, text: cat(stack(294, 1290, 1340, 1414, 1498, 74, 44, 32, 440, '#9A8462'), stack(890, 1290, 1340, 1414, 1498, 74, 44, 32, 440, '#9A8462')) },
		{ label: 'Fekvő', ov: 'sablon-leaves-fekvo.webp', text: pairLine(900, 1040, 84, '#9A8462', 1100, 72, 38, '#9A8462', { as: 0.75 }) }] },
	{ name: 'Eukaliptusz', set: 'wed', thumb: 'sablon-thumb-v2-eukaliptusz.webp', vars: [
		{ label: '1 kép', ov: 'sablon-eukaliptusz-1x.webp', text: [{ t: 'a', x: 600, y: 1252, s: 100, f: SERIF, w: '400', c: '#9B7E5C', m: 640, up: 1, g: 'n' }, { t: 'and', x: 600, y: 1312, s: 56, f: SCRIPT, w: '400', c: '#B49C7C', g: 'n' }, { t: 'b', x: 600, y: 1392, s: 100, f: SERIF, w: '400', c: '#9B7E5C', m: 640, up: 1, g: 'n' }, { t: 'c', x: 600, y: 1448, s: 36, f: DSERIF, w: '500', c: '#9B7E5C', m: 500 }] },
		{ label: '3 képes csík', ov: 'sablon-eukaliptusz-csik-3x.webp', order: 'col', half: 1, text: cat(andStack(300, 1300, 76, '#9B7E5C', '#B49C7C', '#9B7E5C', 470), andStack(902, 1300, 76, '#9B7E5C', '#B49C7C', '#9B7E5C', 470)) }] },
	{ name: 'Natúr', set: 'wed', thumb: 'sablon-thumb-v2-natur.webp', vars: [
		{ label: '1 kép', ov: 'sablon-natur-1x.webp', text: pairLine(600, 1396, 84, '#6E655C', 1000, 70, 40, '#8A8075', NAT) },
		{ label: '2 kép', ov: 'sablon-natur-2x.webp', text: pairLine(600, 1436, 80, '#6E655C', 1000, 66, 38, '#8A8075', NAT) },
		{ label: '3 képes csík', ov: 'sablon-natur-csik-3x.webp', order: 'col', half: 1, text: cat(andStack(300, 1290, 70, '#6E655C', '#A08C74', '#8A8075', 470), andStack(902, 1290, 70, '#6E655C', '#A08C74', '#8A8075', 470)) }] },
	{ name: 'Minimál', set: 'wed', thumb: 'sablon-thumb-v2-minimal.webp', vars: [
		{ label: '1 kép', ov: 'sablon-minimal-1x.webp', text: pairLine(600, 1612, 84, '#5E6E68', 1000, 76, 42, '#7C8A84'), text2: scr(600, 1630, 108, 1000, 70, 40) },
		{ label: '2 kép', ov: 'sablon-minimal-2x.webp', text: pairLine(600, 1612, 84, '#5E6E68', 1000, 76, 42, '#7C8A84'), text2: scr(600, 1630, 108, 1000, 70, 40) },
		{ label: '3 képes csík', ov: 'sablon-minimal-csik-3x.webp', order: 'col', half: 1, text: cat(stack(300, 1574, 1618, 1680, 1736, 66, 40, 32, 460, '#5E6E68'), stack(903, 1574, 1618, 1680, 1736, 66, 40, 32, 460, '#5E6E68')), text2: [{ t: 'a', x: 300, y: 1590, s: 76, f: SCRIPT, w: '400', c: '#7A5C3E', m: 460, g: 'n' }, { t: 'amp', x: 300, y: 1642, s: 46, f: SCRIPT, w: '400', c: '#7A5C3E', g: 'n' }, { t: 'b', x: 300, y: 1706, s: 76, f: SCRIPT, w: '400', c: '#7A5C3E', m: 460, g: 'n' }, { t: 'c', x: 300, y: 1752, s: 30, f: DSERIF, w: '500', c: '#8B7355', m: 460, ls: 3 }] },
		{ label: 'Fekvő, 3 kép', ov: 'sablon-esku-fekvo-3x.webp', text: pairLine(1342, 968, 78, '#5E6E68', 700, 72, 38, '#7C8A84'), text2: scr(1342, 980, 92, 700, 66, 36) }] },
	{ name: 'Geometria', set: 'wed', thumb: 'sablon-thumb-v2-geometria.webp', vars: [
		{ label: '1 kép', ov: 'sablon-geometrikus-1x.webp', text: stack(592, 1290, 1342, 1410, 1472, 82, 50, 34, 460, '#9A8462') },
		{ label: 'Fekvő, 3 kép', ov: 'sablon-geometrikus-3x.webp', text: stack(492, 838, 882, 946, 1002, 74, 44, 30, 400, '#9A8462') }] },
	{ name: 'Pampa', set: 'wed', thumb: 'sablon-thumb-v2-pampa.webp', vars: [
		{ label: 'Fekvő', ov: 'sablon-pampa-1x.webp', text: [{ t: 'line', x: 690, y: 1088, s: 124, f: SCRIPT, w: '400', c: '#4A4237', m: 900 }, { t: 'c', x: 690, y: 1156, s: 38, f: SANS, w: '400', c: '#6E6A66', m: 500 }] }] },
	{ name: 'Levélke', set: 'wed', thumb: 'sablon-thumb-v2-levelke.webp', vars: [
		{ label: '2 kép', ov: 'sablon-tini-2x.webp', text: [{ t: 'line', x: 503, y: 1398, s: 80, f: SCRIPT, w: '400', c: '#4A4A4A', m: 520 }, { t: 'c', x: 503, y: 1462, s: 38, f: DSERIF, w: '500', c: '#5A5A5A', m: 400 }] },
		{ label: 'Fekvő', ov: 'sablon-tini-1x.webp', text: [{ t: 'line', x: 750, y: 922, s: 78, f: SCRIPT, w: '400', c: '#4A4A4A', m: 760 }, { t: 'c', x: 750, y: 978, s: 32, f: DSERIF, w: '500', c: '#5A5A5A', m: 400 }] }] },
	{ name: 'Akvarell', set: 'wed', thumb: 'sablon-thumb-v2-akvarell.webp', vars: [
		{ label: 'Fekvő', ov: 'sablon-zold-kor.webp', text: [{ t: 'a', x: 132, y: 888, s: 36, f: SERIF, w: '400', c: '#A8884F', m: 170, up: 1, g: 'n' }, { t: 'amp', x: 132, y: 918, s: 26, f: SERIF, w: 'italic 400', c: '#A8884F', g: 'n' }, { t: 'b', x: 132, y: 952, s: 36, f: SERIF, w: '400', c: '#A8884F', m: 170, up: 1, g: 'n' }, { t: 'c', x: 132, y: 984, s: 20, f: DSERIF, w: '500', c: '#A8884F', m: 170 }] }] },
	{ name: 'Fekete-fehér', set: 'wed', thumb: 'sablon-thumb-v2-fekete-feher.webp', vars: [
		{ label: '1 kép', ov: 'sablon-fekete-feher.webp', bw: 1, text: [{ t: 'line', x: 500, y: 1140, s: 120, f: SCRIPT, w: '400', c: '#151515', m: 840 }, { t: 'c', x: 502, y: 1328, s: 40, f: DSERIF, w: '500', c: '#222222', m: 600, ls: 9 }] }] },
	{ name: 'Karácsony', set: 'xmas', thumb: 'sablon-thumb-v2-karacsony.webp', vars: [
		{ label: 'Piros, fekvő', ov: 'sablon-karacsony-1x.webp', text: [{ t: 'a', x: 900, y: 1092, s: 72, f: CINZEL, w: '700', c: '#F1D29A', up: 1, m: 1050 }, { t: 'bc', x: 900, y: 1150, s: 34, f: SANS, w: '600', c: '#FBEBD3', up: 1, m: 1000 }] },
		{ label: 'Piros, 2 kép', ov: 'sablon-karacsony-2x.webp', text: [{ t: 'a', x: 600, y: 1528, s: 64, f: CINZEL, w: '700', c: '#F1D29A', up: 1, m: 960 }, { t: 'bc', x: 600, y: 1582, s: 30, f: SANS, w: '600', c: '#FBEBD3', up: 1, m: 960 }] },
		{ label: 'Fa hatású', ov: 'sablon-karacsony-fa-1x.webp', text: [{ t: 'a', x: 871, y: 1117, s: 58, f: CINZEL, w: '700', c: '#5E3410', up: 1, m: 1040 }, { t: 'bc', x: 871, y: 1012, s: 30, f: SANS, w: '600', c: '#FFF3DF', up: 1, m: 1100 }] }] },
	{ name: 'Szilveszter', set: 'ny', thumb: 'sablon-thumb-v2-szilveszter.webp', vars: [
		{ label: 'Fekvő', ov: 'sablon-szilveszter-1x.webp', text: [{ t: 'a', x: 900, y: 1098, s: 76, f: CINZEL, w: '700', c: '#EBC77F', up: 1, m: 1200 }, { t: 'bc', x: 900, y: 1156, s: 32, f: SANS, w: '600', c: '#F4EEE2', up: 1, m: 1100 }] }] },
	{ name: 'Szülinap', set: 'bday', thumb: 'sablon-thumb-v2-szulinap.webp', vars: [
		{ label: 'Arany, 3 kép', ov: 'sablon-szulinap-3x.webp', text: [{ t: 'b', x: 1302, y: 790, s: 46, f: SANS, w: '600', c: '#A9832F', up: 1, m: 560 }, { t: 'a', x: 1302, y: 930, s: 150, f: SCRIPT, w: '400', c: '#232842', m: 560 }, { t: 'c', x: 1302, y: 1000, s: 38, f: SANS, w: '400', c: '#5B5F70', m: 560 }] },
		{ label: 'Fehér, 2 kép', ov: 'sablon-szulinap-2x.webp', text: [{ t: 'a', x: 490, y: 1290, s: 118, f: SCRIPT, w: '400', c: '#1E1E1E', m: 560 }, { t: 'b', x: 490, y: 1372, s: 72, f: SCRIPT, w: '400', c: '#1E1E1E', m: 560 }, { t: 'c', x: 490, y: 1432, s: 28, f: SANS, w: '600', c: '#A88A5C', m: 500, ls: 6 }] },
		{ label: 'Mintás, fekvő', ov: 'sablon-szulinap-1x.webp', text: [{ t: 'a', x: 1205, y: 812, s: 72, f: SANS, w: '700', c: '#D3A02B', up: 1, m: 400 }, { t: 'b', x: 1205, y: 868, s: 38, f: SANS, w: '700', c: '#D3A02B', up: 1, m: 400 }, { t: 'c', x: 1205, y: 912, s: 24, f: SANS, w: '600', c: '#8C8C8C', m: 400, ls: 5 }] }] },
	{ name: 'Halloween', set: 'hallo', thumb: 'sablon-thumb-v2-halloween.webp', vars: [
		{ label: 'Fekvő', ov: 'sablon-halloween-1x.webp', text: [{ t: 'a', x: 900, y: 1088, s: 124, f: SPOOK, w: '400', c: '#1C1414', m: 1150 }, { t: 'bc', x: 900, y: 1152, s: 40, f: SANS, w: '600', c: '#FFF4E6', up: 1, m: 1100 }] }] },
	{ name: 'Céges logós', set: 'corp', thumb: 'sablon-thumb-v2-ceges.webp', vars: [
		{ label: 'Fekvő, 1 kép', ov: 'sablon-ceges-fekvo-1x.webp', logo: [400, 960, 1000, 150], text: corpT(900, 1080, 110, 1100, 1150, 32) },
		{ label: 'Fekvő, 2 kép', ov: 'sablon-ceges-fekvo-2x.webp', logo: [550, 790, 700, 240], text: corpT(900, 960, 100, 1000, 1090, 30) },
		{ label: 'Fekvő, 3 kép', ov: 'sablon-ceges-fekvo-3x.webp', logo: [967, 690, 670, 250], text: corpT(1302, 860, 90, 680, 1010, 28) },
		{ label: 'Csík', ov: 'sablon-ceges-csik.webp', strip: 1, logo: [70, 1330, 460, 280], text: corpT(300, 1500, 70, 480, 1680, 26) },
		{ label: 'Álló, 2 kép', ov: 'sablon-ceges-allo-2x.webp', logo: [180, 1560, 840, 150], text: corpT(600, 1665, 96, 950, 1755, 28) },
		{ label: 'Álló, 1 kép', ov: 'sablon-ceges-allo-1x.webp', logo: [150, 1200, 900, 380], text: corpT(600, 1420, 120, 950, 1660, 34) }] }
];

/* ------------------------------------------------------------ egyedi (AI-val készült) keretek
 * Álló 1200×1800-as vagy fekvő 1800×1200-as lap (or: 'p' / 'l'), rögzített fotóablakokkal (slots) és felirat-zónával (tz).
 * Az AI csak a díszítő hátteret festi; a felirat, a logó és a lyukasztás a panelé, mint a többi sablonnál. */
const OWN_LAY = {
	'1x': { label: '1 kép', slots: [[90, 90, 1020, 1230]], tz: { cx: 600, y0: 1360, y1: 1740, m: 980, k: 1 } },
	'2x': { label: '2 kép', slots: [[90, 90, 1020, 600], [90, 720, 1020, 600]], tz: { cx: 600, y0: 1360, y1: 1740, m: 980, k: 1 } },
	'4x': { label: '4 kép', slots: [[90, 90, 495, 600], [615, 90, 495, 600], [90, 720, 495, 600], [615, 720, 495, 600]], tz: { cx: 600, y0: 1360, y1: 1740, m: 980, k: 1 } },
	'csik3': { label: '3 képes csík', half: 1, order: 'col', slots: [[60, 70, 480, 380], [60, 480, 480, 380], [60, 890, 480, 380], [660, 70, 480, 380], [660, 480, 480, 380], [660, 890, 480, 380]], tz: { cx: 300, y0: 1310, y1: 1740, m: 470, k: 0.68 } },
	'f1x': { label: 'Fekvő, 1 kép', or: 'l', slots: [[80, 80, 1140, 1040]], tz: { cx: 1500, y0: 280, y1: 920, m: 500, k: 0.8 } },
	'f2x': { label: 'Fekvő, 2 kép', or: 'l', slots: [[80, 80, 800, 640], [920, 80, 800, 640]], tz: { cx: 900, y0: 780, y1: 1150, m: 1300, k: 0.9 } },
	'f3x': { label: 'Fekvő, 3 kép', or: 'l', slots: [[80, 80, 520, 680], [640, 80, 520, 680], [1200, 80, 520, 680]], tz: { cx: 900, y0: 820, y1: 1150, m: 1300, k: 0.85 } },
	'f4x': { label: 'Fekvő, 4 kép', or: 'l', slots: [[80, 80, 540, 500], [660, 80, 540, 500], [80, 620, 540, 500], [660, 620, 540, 500]], tz: { cx: 1500, y0: 250, y1: 950, m: 500, k: 0.75 } }
};
const layOr = lay => (OWN_LAY[lay] && OWN_LAY[lay].or) || 'p';
const sheet = or => (or === 'l' ? [1800, 1200] : [1200, 1800]);
/* ha a feltöltött kép tájolása nem egyezik a választott elrendezésével: a megfelelő párja */
const LAY_PAIR = { '1x': 'f1x', '2x': 'f2x', '4x': 'f4x', 'csik3': 'f3x', 'f1x': '1x', 'f2x': '2x', 'f3x': 'csik3', 'f4x': '4x' };
const OWN_SETS = [['wed', 'Esküvő'], ['corp', 'Céges / party'], ['bday', 'Szülinap']];
/* melyik fülön jelenjen meg a mentett saját sablon */
const OWN_CATS = [['own', 'Egyedi'], ['wed', 'Esküvő'], ['party', 'Céges & party']];
const okCat = c => (c === 'wed' || c === 'party' ? c : 'own');
const catName = c => OWN_CATS.find(x => x[0] === okCat(c))[1];
const OWN_FONTS = [['serif', 'Klasszikus'], ['script', 'Kézírásos'], ['sans', 'Modern']];
/* fotóablak-keret: a panel rajzolja pontosan az ablakok szélére (az AI-tól függetlenül, mindig a helyén) */
const OWN_WF = [['none', 'Nincs'], ['gold', 'Arany vonal'], ['goldmat', 'Arany + passzpartu'], ['line', 'Vonal'], ['double', 'Dupla vonal'], ['mat', 'Passzpartu']];
/* sorok: [elem, alapvonal a blokk tetejétől] (k = 1 méretben), h = a blokk magassága */
function ownLines(set, font, col) {
	const L = (o, b) => [Object.assign({ c: col }, o), b];
	if (set === 'wed') {
		if (font === 'script') return { h: 200, l: [L({ t: 'line', s: 110, f: SCRIPT, w: '400' }, 110), L({ t: 'c', s: 40, f: DSERIF, w: '500', ls: 3 }, 190)] };
		if (font === 'sans') return { h: 150, l: [L({ t: 'line', s: 70, f: SANS, w: '600', up: 1 }, 72), L({ t: 'c', s: 34, f: SANS, w: '400', ls: 4 }, 140)] };
		return { h: 316, l: [L({ t: 'a', s: 92, f: SERIF, w: '400', up: 1, g: 'n' }, 92), L({ t: 'amp', s: 54, f: SERIF, w: 'italic 400', g: 'n' }, 148), L({ t: 'b', s: 92, f: SERIF, w: '400', up: 1, g: 'n' }, 218), L({ t: 'c', s: 38, f: DSERIF, w: '500' }, 304)] };
	}
	const nl = set === 'corp' ? 1 : 0;
	if (font === 'script') return { h: 190, l: [L({ t: 'a', s: 120, f: SCRIPT, w: '400', nl }, 112), L({ t: 'bc', s: 34, f: SANS, w: '600', up: 1 }, 180)] };
	if (font === 'sans') return { h: 160, l: [L({ t: 'a', s: 96, f: SANS, w: '700', up: 1, nl }, 92), L({ t: 'bc', s: 32, f: SANS, w: '600', up: 1 }, 152)] };
	return { h: 170, l: [L({ t: 'a', s: 90, f: CINZEL, w: '700', up: 1, nl }, 90), L({ t: 'bc', s: 34, f: SANS, w: '600', up: 1 }, 156)] };
}
/* egy egyedi sablon (D-elem) a mentett / vázlat adataiból: { name, set, layout, font, color, ov, thumb|thumbUrl, own } */
/* feltöltött saját keret, a saját átlátszó ablakaival: a felirat-zóna a legalsó ablak alatt */
const okSlots = a => Array.isArray(a) && a.length > 0 && a.length <= 12 && a.every(x => Array.isArray(x) && x.length === 4 && x.every(n => isFinite(n)));
function layFor(e) {
	if (e.layout !== 'custom' || !okSlots(e.slots)) return OWN_LAY[e.layout] || OWN_LAY['1x'];
	const bottom = Math.max.apply(null, e.slots.map(s => s[1] + s[3])), right = Math.max.apply(null, e.slots.map(s => s[0] + s[2]));
	if (e.or === 'l') {
		let tz;
		if (1200 - bottom >= 220) tz = { cx: 900, y0: bottom + 30, y1: 1160, m: 1300, k: 0.9 };
		else if (1800 - right >= 380) { const w = 1800 - right; tz = { cx: right + w / 2, y0: 150, y1: 1050, m: w - 60, k: Math.min(1, w / 600) }; }
		else tz = { cx: 900, y0: 990, y1: 1170, m: 1300, k: 0.7 };   /* nincs elég hely: alulra kerül, onnan húzható */
		return { label: 'Saját ablakok (fekvő)', or: 'l', slots: e.slots, tz };
	}
	let y0 = Math.min(1700, bottom + 40);
	if (1760 - y0 < 170) y0 = 1590;   /* nincs elég hely: alulra kerül, onnan húzható */
	return { label: 'Saját ablakok', slots: e.slots, tz: { cx: 600, y0, y1: 1760, m: 980, k: 1 } };
}
function ownDesign(e) {
	const lay = layFor(e), z = lay.tz, k = z.k, set = e.set || 'wed';
	const B = ownLines(set, e.font, e.color || '#4A4237');
	let top = z.y0, logo = null;
	if (set !== 'wed') { const lw = z.m * 0.62, lh = 130 * k; logo = [z.cx - lw / 2, z.y0 + 10 * k, lw, lh]; top = z.y0 + lh + 30 * k; }
	top += Math.max(0, (z.y1 - top) - B.h * k) / 2;
	const text = B.l.map(r => Object.assign({}, r[0], { x: z.cx, y: Math.round(top + r[1] * k), s: Math.round(r[0].s * k), m: r[0].t === 'amp' ? 0 : z.m }));
	const V = { label: lay.label, ov: e.ov, slots: lay.slots.map(x => x.slice()), text };
	if (e.wf && e.wf !== 'none') { V.wf = e.wf; V.wc = e.wc || '#3A3A3A'; V.wk = lay.half ? 0.75 : 1; }
	if (lay.half) { V.half = 1; V.order = lay.order; }
	if (logo) V.logo = logo;
	return { name: e.name, set, own: e.own, ocat: e.own === 'draft' ? 'own' : okCat(e.cat), thumb: e.thumb || '', thumbUrl: e.thumbUrl || '', meta: e, vars: [V] };
}
(SB.own || []).forEach(e => { if (e && e.file && (OWN_LAY[e.layout] || (e.layout === 'custom' && okSlots(e.slots)))) D.push(ownDesign(Object.assign({}, e, { ov: e.file, own: e.id }))); });

/* ------------------------------------------------------------ állapot (fülváltáskor megmarad) */
const st = {
	di: 0, vi: 0, cat: 'all',
	vals: {}, j: '&', fs: 0,
	ts: 100, tdx: 0, tdy: 0,                         /* felirat: méret %, eltolás (sablon-px) */
	logo: null, logoProc: null, logoDeal: '', textsFrom: '', ls: 100, ldx: 0, ldy: 0, lwhite: false, keepName: false,
	own: null, mode: 'punch',                        /* előnézet: lyukasztva | mintafotóval */
	deal: null,
	up: { lay: '1x' },
	ai: { lay: '1x', cat: 'own', set: 'wed', font: 'serif', color: '#4A4237', wf: 'gold', wc: '#3A3A3A', prompt: '', busy: false, draft: null, dirty: false, n: 0, left: -1 }
};
Object.keys(SETS).forEach(k => { st.vals[k] = SETS[k].v.slice(); });
const cur = () => D[st.di].vars[st.vi];
const vals = () => st.vals[D[st.di].set];
const conn = () => st.j || '&';
const logoOn = () => D[st.di].set !== 'wed';
const logoActive = () => logoOn() && !!st.logo;

/* ------------------------------------------------------------ képek, betűk */
const cache = {}, holeCache = {};
function img(f) {
	if (cache[f]) return cache[f];
	cache[f] = new Promise(res => { const i = new Image(); i.addEventListener('load', () => res(i)); i.addEventListener('error', () => { delete cache[f]; res(null); }); i.src = IMG(f); });
	return cache[f];
}
function photos() { const l = PH_SET[D[st.di].set]; return l ? l.map(n => 'minta-' + n + '.webp') : PHOTOS; }

let fontsP = null;
function fontsReady() {
	if (fontsP) return fontsP;
	if (!document.getElementById('cpsb-font')) {
		const l = document.createElement('link'); l.id = 'cpsb-font'; l.rel = 'stylesheet';
		l.href = 'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;1,400&family=Cormorant+Infant:wght@500&family=Parisienne&family=Montserrat:wght@400;600;700&family=Cinzel:wght@700&family=Creepster&display=swap';
		document.head.appendChild(l);
	}
	if (!document.fonts || !document.fonts.load) { fontsP = Promise.resolve(); return fontsP; }
	fontsP = new Promise(res => {
		setTimeout(res, 3500);
		let n = 0;
		(function chk() {
			Promise.all([document.fonts.load('400 40px "Cormorant Garamond"', 'ÁÉŐŰ'), document.fonts.load('italic 400 40px "Cormorant Garamond"', '&'), document.fonts.load('500 40px "Cormorant Infant"', '2026'), document.fonts.load('40px "Parisienne"', 'and'), document.fonts.load('400 40px Montserrat', '2026')])
				.then(f => { if ((f[0].length && f[3].length) || ++n > 12) res(); else setTimeout(chk, 250); }).catch(res);
		})();
	});
	return fontsP;
}
function lazyFonts() {
	if (!document.fonts || !document.fonts.load) return;
	Promise.all([document.fonts.load('700 40px Cinzel', 'ÁÉŐŰ'), document.fonts.load('40px Creepster', 'Halloween'), document.fonts.load('600 40px Montserrat', '2026'), document.fonts.load('700 40px Montserrat', '2026')]).then(() => schedule()).catch(() => {});
}

/* ------------------------------------------------------------ fotóablakok felismerése (átlátszó foltok a sablonképen) */
function holes(im, order) {
	const key = im.src + '|' + (order || '');
	if (holeCache[key]) return holeCache[key];
	const W = im.naturalWidth, H = im.naturalHeight, k = Math.max(1, Math.ceil(Math.max(W, H) / 700)), w = Math.ceil(W / k), h = Math.ceil(H / k);
	const c = document.createElement('canvas'); c.width = w; c.height = h;
	const x = c.getContext('2d'); x.drawImage(im, 0, 0, w, h);
	const d = x.getImageData(0, 0, w, h).data, lab = new Uint8Array(w * h), stk = [], bl = k * 2 + 4;
	let out = [];
	const push = r => { if (!lab[r] && d[r * 4 + 3] <= 200) { lab[r] = 1; stk.push(r); } };
	for (let p = 0; p < w * h; p++) {
		if (lab[p] || d[p * 4 + 3] > 200) continue;
		let a = w, b = h, e = 0, f = 0, n = 0, edge = false;
		lab[p] = 1; stk.push(p);
		while (stk.length) {
			const q = stk.pop(), qx = q % w, qy = (q - qx) / w; n++;
			if (qx < a) a = qx; if (qx > e) e = qx; if (qy < b) b = qy; if (qy > f) f = qy;
			if (qx === 0 || qy === 0 || qx === w - 1 || qy === h - 1) edge = true;
			if (qx > 0) push(q - 1); if (qx < w - 1) push(q + 1); if (qy > 0) push(q - w); if (qy < h - 1) push(q + w);
		}
		if (!edge && n > w * h * 0.008) out.push([a * k - bl, b * k - bl, (e - a + 1) * k + bl * 2, (f - b + 1) * k + bl * 2]);
	}
	out = out.filter(s => !out.some(o => o !== s && o[0] <= s[0] + 2 && o[1] <= s[1] + 2 && o[0] + o[2] >= s[0] + s[2] - 2 && o[1] + o[3] >= s[1] + s[3] - 2));
	const tol = H * 0.05, tolx = W * 0.05;
	out.sort(order === 'col' ? (p1, p2) => (Math.abs(p1[0] - p2[0]) > tolx ? p1[0] - p2[0] : p1[1] - p2[1]) : (p1, p2) => (Math.abs(p1[1] - p2[1]) > tol ? p1[1] - p2[1] : p1[0] - p2[0]));
	holeCache[key] = out;
	return out;
}

/* elrendezés: natív (sablon-px) méretek, csík-duplázás, kimeneti méret */
function layout(V, ov) {
	const W = ov.naturalWidth, H = ov.naturalHeight;
	const single = !V.half && (!!V.strip || W / H < 0.45);     /* egyetlen csík a képen → megduplázzuk */
	const NW = single ? W * 2 : W, NH = H;
	const UW = single ? W : V.half ? W / 2 : W;                 /* egy csík szélessége */
	const copies = single || V.half ? [0, UW] : [0];
	const O = NW <= NH ? OUT_P : OUT_L;
	let hs = V.slots || holes(ov, V.order);
	if (single) hs = hs.concat(hs.map(s => [s[0] + W, s[1], s[2], s[3]]));
	return { W, H, NW, NH, UW, copies, single, strip: copies.length > 1, OW: O[0], OH: O[1], sx: O[0] / NW, sy: O[1] / NH, holes: hs };
}

/* ------------------------------------------------------------ feliratok */
function txtOf(o) {
	const v = vals();
	if (o.t === 'a') return v[0]; if (o.t === 'b') return v[1]; if (o.t === 'c') return v[2];
	if (o.t === 'amp' || o.t === 'and') return conn();
	if (o.t === 'line') return (v[0] && v[1]) ? v[0] + ' ' + conn() + ' ' + v[1] : (v[0] || v[1]);
	if (o.t === 'bc') return [v[1], v[2]].filter(x => x && x.trim()).join(' • ');
	return '';
}
function prep(o) { let t = (txtOf(o) || '').trim(); if (o.up) t = t.toLocaleUpperCase('hu'); return t; }
function pairParts() { const v = vals(); return [(v[0] || '').trim().toLocaleUpperCase('hu'), (v[1] || '').trim().toLocaleUpperCase('hu')]; }
function pairDraw(g, o, s, draw) {
	const p = pairParts(), A = conn(), as = Math.round(s * (o.as || 0.7)), gap = s * (o.gp || 0.35), af = (o.aw || 'italic 400') + ' ' + as + 'px ' + (o.af || o.f), nf = o.w + ' ' + s + 'px ' + o.f;
	g.font = nf; const wa = g.measureText(p[0]).width, wb = g.measureText(p[1]).width;
	g.font = af; const wm = (p[0] && p[1]) ? g.measureText(A).width : 0;
	const tot = wa + wb + (wm ? wm + gap * 2 : 0);
	if (!draw) return tot;
	let x = o.x - tot / 2;
	g.textAlign = 'left'; g.textBaseline = 'alphabetic'; g.fillStyle = o.c; g.font = nf; g.fillText(p[0], x, o.y); x += wa;
	if (wm) { x += gap; g.fillStyle = o.ac || o.c; g.font = af; g.fillText(A, x, o.y); x += wm + gap; }
	g.fillStyle = o.c; g.font = nf; g.fillText(p[1], x, o.y);
	return tot;
}
function fitRatio(g, t, o) {
	if (!o.m) return 1;
	let w;
	if (o.t === 'pair') w = pairDraw(g, o, o.s, false);
	else { if (!t) return 1; g.font = o.w + ' ' + o.s + 'px ' + o.f; w = g.measureText(t).width; }
	return w > o.m ? o.m / w : 1;
}
function drawTexts(g, T) {
	const R0 = {};
	T.forEach((o, i) => { const r = fitRatio(g, prep(o), o), key = o.g || ('_' + i); if (R0[key] === undefined || r < R0[key]) R0[key] = r; });
	T.forEach((o, i) => {
		const r = R0[o.g || ('_' + i)], s = Math.max(10, Math.floor(o.s * r));
		if (o.t === 'pair') { pairDraw(g, o, s, true); return; }
		const t = prep(o); if (!t) return;
		g.font = o.w + ' ' + s + 'px ' + o.f; g.fillStyle = o.c; g.textAlign = o.al || 'center'; g.textBaseline = 'alphabetic';
		const hl = o.ls && (g.letterSpacing !== undefined);
		if (hl) g.letterSpacing = o.ls + 'px';
		g.fillText(t, o.x, o.y);
		if (hl) g.letterSpacing = '0px';
	});
}
const shift = (o, dx) => Object.assign({}, o, { x: o.x + dx });
/* felirat-csoportok: csíknál csíkonként egy (a második csík a sablonban vagy az első másolata) */
function textGroups(V, L) {
	let T = (st.fs && V.text2) ? V.text2 : (V.text || []);
	if (logoActive() && !st.keepName) T = T.filter(o => !o.nl);
	if (!L.strip) return [T];
	if (L.single) return [T, T.map(o => shift(o, L.UW))];
	const left = T.filter(o => o.x < L.UW), right = T.filter(o => o.x >= L.UW);
	return [left, right.length ? right : left.map(o => shift(o, L.UW))];
}
/* méret (k) a csoport közepéhez képest + eltolás */
function adjust(T, k, dx, dy) {
	if (!T.length) return T;
	const xs = T.map(o => o.x), ys = T.map(o => o.y);
	const cx = (Math.min.apply(null, xs) + Math.max.apply(null, xs)) / 2, cy = (Math.min.apply(null, ys) + Math.max.apply(null, ys)) / 2;
	return T.map(o => Object.assign({}, o, { x: cx + (o.x - cx) * k + dx, y: cy + (o.y - cy) * k + dy, s: o.s * k, m: o.m ? o.m * k : o.m }));
}

/* ------------------------------------------------------------ logó */
function logoBase(V, L) {
	if (V.logo) return { x: V.logo[0], y: V.logo[1], w: V.logo[2], h: V.logo[3] };
	/* nincs kijelölt logóhely: a felirat fölé, középre – onnan húzható */
	const T = (V.text || []).filter(o => o.x < L.UW);
	const xs = T.map(o => o.x), tops = T.map(o => o.y - o.s);
	const cx = T.length ? (Math.min.apply(null, xs) + Math.max.apply(null, xs)) / 2 : L.UW / 2;
	const top = T.length ? Math.min.apply(null, tops) : L.H * 0.8;
	const w = L.UW * 0.3, h = L.H * 0.1;
	return { x: cx - w / 2, y: Math.max(8, top - h - L.H * 0.015), w, h };
}
function logoRects(V, L) {
	const src = st.logoProc || st.logo;
	if (!src) return [];
	const b = logoBase(V, L), k = st.ls / 100, sw = src.naturalWidth || src.width, sh = src.naturalHeight || src.height;
	const r = Math.min(b.w * k / sw, b.h * k / sh), w = sw * r, h = sh * r;
	const cx = b.x + b.w / 2 + st.ldx, cy = b.y + b.h / 2 + st.ldy;
	return L.copies.map(off => ({ x: off + cx - w / 2, y: cy - h / 2, w, h }));
}
/* fehér háttér kivágása (JPG logóhoz) */
function whiteCut(im) {
	const w = im.naturalWidth || im.width, h = im.naturalHeight || im.height;
	const c = document.createElement('canvas'); c.width = w; c.height = h;
	const x = c.getContext('2d'); x.drawImage(im, 0, 0);
	const id = x.getImageData(0, 0, w, h), d = id.data;
	for (let i = 0; i < d.length; i += 4) {
		const m = Math.min(d[i], d[i + 1], d[i + 2]);
		if (m >= 245) d[i + 3] = 0;
		else if (m > 215) d[i + 3] = Math.min(d[i + 3], Math.round((245 - m) / 30 * 255));
	}
	x.putImageData(id, 0, 0);
	return c;
}

/* ------------------------------------------------------------ összerakás */
function cover(g, im, s) {
	if (!im) return;
	const iw = im.naturalWidth || im.width, ih = im.naturalHeight || im.height;
	const r = Math.max(s[2] / iw, s[3] / ih), sw = s[2] / r, sh = s[3] / r;
	g.drawImage(im, (iw - sw) / 2, (ih - sh) / 2, sw, sh, s[0], s[1], s[2], s[3]);
}
function compose(g, A, mode) {
	const V = A.V, ov = A.ov, L = A.L;
	g.setTransform(1, 0, 0, 1, 0, 0);
	g.clearRect(0, 0, L.OW, L.OH);
	g.setTransform(L.sx, 0, 0, L.sy, 0, 0);
	g.imageSmoothingEnabled = true; g.imageSmoothingQuality = 'high';
	g.fillStyle = V.bg || '#fff'; g.fillRect(0, 0, L.NW, L.NH);
	if (mode === 'photo') {
		const bw = V.bw && ('filter' in g);
		if (bw) g.filter = 'grayscale(1)';
		L.holes.forEach((s, i) => cover(g, A.phs[i % Math.max(1, A.phs.length)], s));
		if (bw) g.filter = 'none';
	} else {
		L.holes.forEach(s => g.clearRect(s[0], s[1], s[2], s[3]));
	}
	g.drawImage(ov, 0, 0, L.W, L.H);
	if (L.single) g.drawImage(ov, L.W, 0, L.W, L.H);
	if (V.wf) winFrames(g, V, L.holes);
	if (logoActive()) { const src = st.logoProc || st.logo; logoRects(V, L).forEach(r => g.drawImage(src, r.x, r.y, r.w, r.h)); }
	textGroups(V, L).forEach(T => drawTexts(g, adjust(T, st.ts / 100, st.tdx, st.tdy)));
	g.setTransform(1, 0, 0, 1, 0, 0);
}

/* fotóablak-keret: oldalanként annyira kintebb, amennyi hely van (szomszédos ablakoknál a rés feléig) */
function winFrames(g, V, hs) {
	const k = V.wk || 1, st0 = V.wf;
	const room = (s, side) => {
		let r = 60 * k;
		hs.forEach(o => {
			if (o === s) return;
			const ovY = o[1] < s[1] + s[3] && o[1] + o[3] > s[1], ovX = o[0] < s[0] + s[2] && o[0] + o[2] > s[0];
			if (side === 0 && ovY && o[0] + o[2] <= s[0]) r = Math.min(r, (s[0] - o[0] - o[2]) / 2);
			if (side === 2 && ovY && o[0] >= s[0] + s[2]) r = Math.min(r, (o[0] - s[0] - s[2]) / 2);
			if (side === 1 && ovX && o[1] + o[3] <= s[1]) r = Math.min(r, (s[1] - o[1] - o[3]) / 2);
			if (side === 3 && ovX && o[1] >= s[1] + s[3]) r = Math.min(r, (o[1] - s[1] - s[3]) / 2);
		});
		return r;
	};
	/* a téglalap oldalanként d-vel kintebb, de legfeljebb (hely - tartalék) */
	const grow = (s, R, d, keep) => { const o = R.map(r => Math.max(1, Math.min(d, r - keep))); return [s[0] - o[0], s[1] - o[1], s[2] + o[0] + o[2], s[3] + o[1] + o[3]]; };
	const stroke = (r, lw, col) => { g.lineWidth = lw; g.strokeStyle = col; g.strokeRect(r[0], r[1], r[2], r[3]); };
	const gold = r => { const gr = g.createLinearGradient(r[0], r[1], r[0] + r[2], r[1] + r[3]); [[0, '#A87A28'], [0.25, '#E9CF84'], [0.45, '#B8862F'], [0.7, '#F3DC92'], [1, '#A87A28']].forEach(x => gr.addColorStop(x[0], x[1])); return gr; };
	g.save();
	hs.forEach(s => {
		const R = [0, 1, 2, 3].map(i => room(s, i));
		if (st0 === 'goldmat' || st0 === 'mat') {
			const lw = (st0 === 'mat' ? 2.5 : 6) * k, m = grow(s, R, 24 * k, lw / 2 + 1);
			g.save();
			g.shadowColor = 'rgba(0,0,0,.16)'; g.shadowBlur = 10; g.shadowOffsetY = 2;
			g.beginPath(); g.rect(m[0], m[1], m[2], m[3]); g.rect(s[0], s[1], s[2], s[3]);
			g.fillStyle = '#FFFFFF'; g.fill('evenodd');
			g.restore();
			const r = [m[0] + lw / 2, m[1] + lw / 2, m[2] - lw, m[3] - lw];
			stroke(r, lw, st0 === 'mat' ? V.wc : gold(r));
			if (st0 === 'goldmat') stroke(grow(s, R, 3 * k, 1), 1.5 * k, gold(m));
		} else if (st0 === 'gold') {
			const lw = 7 * k, r = grow(s, R, 2 + lw / 2, lw / 2 + 1); stroke(r, lw, gold(r));
		} else if (st0 === 'line') {
			const lw = 4 * k; stroke(grow(s, R, 2 + lw / 2, lw / 2 + 1), lw, V.wc);
		} else if (st0 === 'double') {
			stroke(grow(s, R, 3 * k, 2), 2 * k, V.wc);
			stroke(grow(s, R, 12 * k, 3 * k), 4 * k, V.wc);
		}
	});
	g.restore();
}

/* ------------------------------------------------------------ PNG 300 dpi (pHYs blokk) */
let CRC = null;
function crc32(b) {
	if (!CRC) { CRC = new Uint32Array(256); for (let n = 0; n < 256; n++) { let c = n; for (let k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; CRC[n] = c >>> 0; } }
	let c = 0xFFFFFFFF;
	for (let i = 0; i < b.length; i++) c = CRC[(c ^ b[i]) & 0xFF] ^ (c >>> 8);
	return (c ^ 0xFFFFFFFF) >>> 0;
}
async function withDpi(blob, dpi) {
	try {
		const u = new Uint8Array(await blob.arrayBuffer());
		if (u.length < 33 || u[12] !== 73 || u[13] !== 72 || u[14] !== 68 || u[15] !== 82) return blob; /* IHDR */
		const ppm = Math.round(dpi / 0.0254);
		const ch = new Uint8Array(21), dv = new DataView(ch.buffer);
		dv.setUint32(0, 9); ch.set([112, 72, 89, 115], 4); dv.setUint32(8, ppm); dv.setUint32(12, ppm); ch[16] = 1;
		dv.setUint32(17, crc32(ch.subarray(4, 17)));
		return new Blob([u.subarray(0, 33), ch, u.subarray(33)], { type: 'image/png' });
	} catch (e) { return blob; }
}
function download(blob, name) {
	const a = document.createElement('a');
	a.href = URL.createObjectURL(blob); a.download = name;
	document.body.appendChild(a); a.click(); a.remove();
	setTimeout(() => URL.revokeObjectURL(a.href), 5000);
}
const slug = s => String(s || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/&/g, ' es ').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 40);
function fname(ext, suf) {
	const v = vals();
	const p = [(st.deal && st.deal.date) || '', slug(v[0]), slug(v[1]), slug(D[st.di].name), slug(cur().label)].filter(Boolean);
	return (p.join('_') || 'sablon') + (suf || '') + '.' + ext;
}

/* ------------------------------------------------------------ ügyfél választása (deal megjegyzés) */
function parseNote(note) {
	const m = String(note || '').match(/Választott fotósablon:\s*([^\n\r]+)/i);
	if (!m) return null;
	const segs = m[1].split('|').map(x => x.trim()).filter(Boolean);
	const head = segs.shift() || '';
	let design = head, layout = '';
	const hm = head.match(/^(.*?)\s*\(élő előnézet\)\s*(?:,\s*(.*))?$/i);
	if (hm) { design = hm[1].trim(); layout = (hm[2] || '').trim(); }
	else { const i = head.indexOf(','); if (i > 0) { design = head.slice(0, i).trim(); layout = head.slice(i + 1).trim(); } }
	const out = { raw: m[1].trim(), design, layout, texts: [], fs: -1, logo: false, img: '' };
	segs.forEach(x => {
		if (/^kép:\s*/i.test(x)) { out.img = x.replace(/^kép:\s*/i, '').trim(); return; }
		if (/^saját logóval$/i.test(x)) { out.logo = true; return; }
		const f = x.match(/^(klasszikus|kézírásos) betű$/i);
		if (f) { out.fs = /kézírásos/i.test(f[1]) ? 1 : 0; return; }
		out.texts.push(x);
	});
	return out;
}
const lc = s => String(s || '').trim().toLocaleLowerCase('hu');
const isDate = s => /^\d{4}\s*[.\-\/]/.test(s) || /^\d{1,2}\s*[.\/]\s*\d{1,2}\s*[.\/]\s*\d{2,4}/.test(s);
function applyParsed(p, d) {
	const miss = [];
	let i = D.findIndex(x => lc(x.name) === lc(p.design));
	if (i < 0) i = D.findIndex(x => lc(p.design).indexOf(lc(x.name)) >= 0 || lc(x.name).indexOf(lc(p.design)) >= 0);
	if (i >= 0) st.di = i; else if (p.design) miss.push('A(z) „' + p.design + '” sablon nincs a listában – válaszd ki kézzel.');
	const vi = p.layout ? D[st.di].vars.findIndex(v => lc(v.label) === lc(p.layout)) : 0;
	st.vi = Math.max(0, vi);
	if (p.layout && vi < 0 && i >= 0) miss.push('Az „' + p.layout + '” elrendezés nincs meg ennél a sablonnál.');
	const set = D[st.di].set, v = ['', '', ''];
	const date = p.texts.find(isDate), others = p.texts.filter(t => !isDate(t));
	if (set === 'wed') {
		const nm = String(others[0] || '').replace(/^\s*(&|és|and)\s+/i, '').replace(/\s+(&|és|and)\s*$/i, '').split(/\s+(&|és|and)\s+/i);
		v[0] = (nm[0] || '').trim();
		if (nm.length >= 3) { v[1] = nm.slice(2).join(' ').trim(); const j = lc(nm[1]); st.j = j === 'és' ? 'és' : j === 'and' ? 'and' : '&'; }
	} else { v[0] = others[0] || ''; v[1] = others[1] || ''; }
	v[2] = date || dotDate(d.date) || '';
	st.vals[set] = v;
	if (p.fs >= 0) st.fs = p.fs;
	resetTune(); resetLogoPos();
	d.miss = miss.join(' ');
}

/* mentett beállítás dealenként (ebben a böngészőben) */
const SKEY = id => 'cpsb_' + id, LKEY = id => 'cpsb_logo_' + id;
function snapshot() { return { d: D[st.di].name, l: cur().label, v: vals().slice(), j: st.j, fs: st.fs, ts: st.ts, tdx: st.tdx, tdy: st.tdy, ls: st.ls, ldx: st.ldx, ldy: st.ldy, lw: st.lwhite, kn: st.keepName, at: Date.now() }; }
let saveT = 0;
function saveNow() {
	clearTimeout(saveT); saveT = 0;
	if (!st.deal) return;
	try { localStorage.setItem(SKEY(st.deal.id), JSON.stringify(snapshot())); } catch (e) { /* tele / tiltva */ }
}
function saveSoon() { if (!st.deal) return; clearTimeout(saveT); saveT = setTimeout(saveNow, 500); }
/* dealváltás előtt a függő mentés még az előző dealhez kerüljön */
function flushSave() { if (saveT) saveNow(); }
function loadSaved(id) { try { return JSON.parse(localStorage.getItem(SKEY(id)) || 'null'); } catch (e) { return null; } }
function restore(s) {
	const i = D.findIndex(d => d.name === s.d);
	if (i >= 0) { st.di = i; st.vi = Math.max(0, D[i].vars.findIndex(v => v.label === s.l)); }
	if (Array.isArray(s.v)) st.vals[D[st.di].set] = [0, 1, 2].map(k => String(s.v[k] == null ? '' : s.v[k]));
	st.j = s.j || '&'; st.fs = +s.fs || 0;
	st.ts = +s.ts || 100; st.tdx = +s.tdx || 0; st.tdy = +s.tdy || 0;
	st.ls = +s.ls || 100; st.ldx = +s.ldx || 0; st.ldy = +s.ldy || 0; st.lwhite = !!s.lw; st.keepName = !!s.kn;
}
function saveLogo() {
	if (!st.deal) return;
	try { if (st.logo && st.logo.src) localStorage.setItem(LKEY(st.deal.id), st.logo.src); else localStorage.removeItem(LKEY(st.deal.id)); } catch (e) { /* túl nagy */ }
}
function loadLogo(id) {
	let u = null;
	try { u = localStorage.getItem(LKEY(id)); } catch (e) { u = null; }
	if (!u) return Promise.resolve(false);
	return new Promise(res => { const i = new Image(); i.onload = () => { st.logo = i; st.logoProc = st.lwhite ? whiteCut(i) : null; res(true); }; i.onerror = () => res(false); i.src = u; });
}
/* feltöltött logó: legfeljebb 1200 px, PNG adat-URL (így menthető és nem szennyezi a canvast) */
function readLogo(file) {
	return new Promise((res, rej) => {
		const u = URL.createObjectURL(file), i = new Image();
		i.onload = () => {
			const r = Math.min(1, 1200 / Math.max(i.naturalWidth, i.naturalHeight));
			const c = document.createElement('canvas'); c.width = Math.max(1, Math.round(i.naturalWidth * r)); c.height = Math.max(1, Math.round(i.naturalHeight * r));
			c.getContext('2d').drawImage(i, 0, 0, c.width, c.height);
			URL.revokeObjectURL(u);
			const o = new Image(); o.onload = () => res(o); o.onerror = () => rej(new Error('A logó nem olvasható.')); o.src = c.toDataURL('image/png');
		};
		i.onerror = () => { URL.revokeObjectURL(u); rej(new Error('Ez a fájl nem kép (PNG, JPG vagy SVG kell).')); };
		i.src = u;
	});
}

/* ------------------------------------------------------------ AJAX */
async function call(action, data) {
	const q = new URLSearchParams(Object.assign({ action: action, t: C.t || '' }, data || {}));
	const r = await fetch((C.ajax || '/wp-admin/admin-ajax.php') + '?' + q.toString(), { credentials: 'same-origin' });
	let j;
	try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')'); }
	if (!j.success) throw new Error(j.data && j.data.msg === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : (j.data && j.data.msg) || 'Hiba');
	return j.data;
}

/* ------------------------------------------------------------ felület */
let R = null, X = {}, A = null, seq = 0, raf = 0, drag = null, dealsLoaded = false;
const q = s => R.querySelector(s), qa = s => R.querySelectorAll(s);
const alive = () => R && R.isConnected;
function resetTune() { st.ts = 100; st.tdx = 0; st.tdy = 0; }
function resetLogoPos() { st.ls = 100; st.ldx = 0; st.ldy = 0; }
const segBtns = (attr, list) => list.map(x => '<button type="button" ' + attr + '="' + esc(x[0]) + '">' + esc(x[1]) + '</button>').join('');
const nudge = n => '<div class="sb-nudge" data-nudge="' + n + '"><button type="button" data-d="u" title="Fel" aria-label="Fel">↑</button><button type="button" data-d="d" title="Le" aria-label="Le">↓</button><button type="button" data-d="l" title="Balra" aria-label="Balra">←</button><button type="button" data-d="r" title="Jobbra" aria-label="Jobbra">→</button><small>Shift = nagyobb lépés · vagy húzd a képen</small></div>';

/* egyedi sablonok – három kártya a sablonválasztó alatt (csak a panelben, a csapatnak):
 *   1. keret AI-val   2. saját keret feltöltése   3. a vázlat / kiválasztott saját sablon beállításai és mentése */
const layBtns = attr => segBtns(attr, Object.keys(OWN_LAY).map(k => [k, OWN_LAY[k].label + (OWN_LAY[k].half ? ' (dupla)' : '')]));
const AI_TPL = '<details class="sb-card sb-ai" data-ai><summary class="sb-lbl">✨ Keret AI-val<small>Az AI csak a díszítő hátteret / keretet rajzolja meg (álló vagy fekvő 10×15, az elrendezés szerint). A fotóablakokat a panel hagyja szabadon, a neveket, a dátumot és a logót a panel teszi rá, mint eddig.</small></summary>' +
	'<p class="sb-warn" data-aino hidden>Az OpenAI kulcs nincs beállítva – WordPress admin → Beállítások → Foglaltsági panel → „Sablonkészítő – egyedi keret AI-val”.</p>' +
	'<div class="sb-f"><span>Elrendezés (fotóablakok)</span><div class="sb-seg" data-al>' + layBtns('data-alv') + '</div></div>' +
	'<label class="sb-f"><span>Milyen legyen a keret?</span><textarea data-aip rows="3" maxlength="1200" placeholder="pl. elegáns arany-fehér pünkösdi rózsás akvarell keret, zöld levelekkel, sok levegővel"></textarea></label>' +
	'<div class="sb-ai-go"><button type="button" class="cpx-btn" data-aigo>Keret generálása</button><small data-aiinfo>Kb. 30–90 másodperc.</small></div>' +
	'<p class="sb-msg" data-aimsg role="status" aria-live="polite" hidden></p></details>' +
	'<details class="sb-card sb-ai" data-up><summary class="sb-lbl">📁 Saját keret feltöltése<small>Kész keretgrafika (pl. Photoshop / Canva) álló vagy fekvő 10×15-ben (1200×1800 / 1800×1200 px) – a tájolást a képből ismerjük fel. A neveket, a dátumot és a logót a panel teszi rá.</small></summary>' +
	'<div class="sb-ai-go"><label class="sb-up"><input type="file" accept="image/png,image/jpeg,image/webp" data-aiup hidden><span>Kép kiválasztása (PNG / JPG / WebP)</span></label></div>' +
	'<div class="sb-f"><span>Ha a képen nincs átlátszó fotóablak, ezeket vágjuk bele (fekvő képnél a fekvő párját)</span><div class="sb-seg" data-ul>' + layBtns('data-ulv') + '</div></div>' +
	'<small class="cpx-muted">Átlátszó ablakos PNG-nél a saját ablakait használjuk, ilyenkor az elrendezés nem számít.</small>' +
	'<p class="sb-msg" data-upmsg role="status" aria-live="polite" hidden></p></details>' +
	'<div class="sb-card sb-ai sb-own" data-ownc hidden><div class="sb-lbl" data-ownh>Egyedi sablon beállításai</div>' +
	'<div class="sb-ai-grid"><div>' +
	'<div class="sb-f"><span>Kategória (melyik fülön jelenjen meg)</span><div class="sb-seg" data-acat>' + segBtns('data-acatv', OWN_CATS) + '</div></div>' +
	'<div class="sb-f"><span>Felirat típusa</span><div class="sb-seg" data-as>' + segBtns('data-asv', OWN_SETS) + '</div></div>' +
	'<div class="sb-f"><span>Betűstílus</span><div class="sb-seg" data-af>' + segBtns('data-afv', OWN_FONTS) + '</div></div>' +
	'<label class="sb-f sb-ai-col"><span>Felirat színe</span><input type="color" data-ac></label>' +
	'</div><div>' +
	'<div class="sb-f"><span>Fotóablak kerete (a panel rajzolja, pontosan az ablak szélére)</span><div class="sb-seg" data-awf>' + segBtns('data-awfv', OWN_WF) + '</div></div>' +
	'<label class="sb-f sb-ai-col" data-awcwrap><span>Ablakkeret színe</span><input type="color" data-awc></label>' +
	'<small class="cpx-muted">Élőben látszik az előnézeten; mentett sablonnál a „Beállítások mentése” rögzíti.</small>' +
	'</div></div>' +
	'<div class="sb-ai-draft" data-aidraft hidden><b>Kész a vázlat</b> – nézd meg lent az előnézetben (mintafotóval is). Ha tetszik, mentsd el:' +
	'<div class="sb-ai-save"><input type="text" data-ain maxlength="40" placeholder="Sablon neve, pl. Pünkösdi rózsa"><button type="button" class="cpx-btn" data-aisave>Mentés új sablonként</button><button type="button" class="sb-link danger" data-aidrop>vázlat elvetése</button></div></div>' +
	'<div class="sb-ai-own" data-aiown hidden></div>' +
	'<p class="sb-msg" data-ownmsg role="status" aria-live="polite" hidden></p></div>';

const TPL = '<div class="sb">' +
	'<div class="sb-card sb-deal"><div class="sb-dealrow"><label class="sb-lbl" for="sb-deal">Rendezvény</label>' +
	'<select id="sb-deal" data-deal><option value="">– nincs (kézi kitöltés) –</option></select>' +
	'<input type="text" data-dealid inputmode="numeric" placeholder="vagy Deal ID" aria-label="Deal ID"><button type="button" class="cpx-btn ghost sm" data-dealgo>Betöltés</button></div>' +
	'<div class="sb-dinfo" data-dinfo hidden></div></div>' +
	'<div class="sb-card sb-pick"><div class="sb-pick-top"><div class="sb-lbl">Sablon<small>★ = ezt választotta az ügyfél</small></div><div class="sb-seg" data-cats>' + segBtns('data-c', CATS) + '</div></div><div class="sb-designs" data-designs></div></div>' + AI_TPL +
	'<div class="sb-grid"><div class="sb-card sb-side">' +
	'<div class="sb-lbl">Elrendezés</div><div class="sb-vars" data-vars></div>' +
	'<label class="sb-f"><span data-la></span><input type="text" data-fi="0" maxlength="60" autocomplete="off"></label>' +
	'<label class="sb-f"><span data-lb></span><input type="text" data-fi="1" maxlength="60" autocomplete="off"></label>' +
	'<div class="sb-f" data-connwrap><span>Összekötő a nevek között</span><div class="sb-seg" data-conn>' + segBtns('data-j', [['&', '&'], ['és', 'és'], ['and', 'and']]) + '</div></div>' +
	'<label class="sb-f"><span data-lc></span><input type="text" data-fi="2" maxlength="40" autocomplete="off"></label>' +
	'<div class="sb-f" data-fswrap hidden><span>Betűstílus</span><div class="sb-seg" data-fs>' + segBtns('data-f', [['0', 'Klasszikus'], ['1', 'Kézírásos']]) + '</div></div>' +
	'<div class="sb-box"><div class="sb-boxh">Felirat<button type="button" class="sb-link" data-treset>alaphelyzet</button></div>' +
	'<label class="sb-range"><span>Méret <b data-tsv></b></span><input type="range" min="50" max="160" step="1" data-ts></label>' + nudge('t') + '</div>' +
	'<div class="sb-box" data-logobox hidden><div class="sb-boxh">Logó<button type="button" class="sb-link" data-lreset>alaphelyzet</button></div>' +
	'<label class="sb-up"><input type="file" accept="image/*" data-logo hidden><span data-logolbl>Logó feltöltése</span></label>' +
	'<div data-logoctl hidden><label class="sb-range"><span>Méret <b data-lsv></b></span><input type="range" min="15" max="300" step="1" data-ls></label>' + nudge('l') +
	'<label class="sb-chk"><input type="checkbox" data-lwhite> Fehér háttér kivágása (JPG logóhoz)</label>' +
	'<label class="sb-chk" data-keepwrap hidden><input type="checkbox" data-keep> A cégnév felirat is maradjon a logó mellett</label>' +
	'<button type="button" class="sb-link danger" data-logodel>Logó eltávolítása</button></div></div>' +
	'<div class="sb-out"><button type="button" class="cpx-btn sb-dl" data-png>PNG letöltése (lyukasztott)</button><small class="sb-oinfo" data-oinfo></small>' +
	'<div class="sb-out2"><button type="button" class="cpx-btn ghost sm" data-jpg title="Mintafotókkal, JPG – jóváhagyásra küldhető">Előnézet JPG</button><button type="button" class="cpx-btn ghost sm" data-tp hidden>✓ Template pipa a Követésben</button></div>' +
	'<p class="sb-msg" data-msg role="status" aria-live="polite" hidden></p></div>' +
	'</div><div class="sb-card sb-stage"><div class="sb-stage-top"><div class="sb-seg" data-mode>' + segBtns('data-m', [['punch', 'Lyukasztva'], ['photo', 'Mintafotóval']]) + '</div>' +
	'<label class="sb-up sm"><input type="file" accept="image/*" data-photo hidden><span data-photolbl>Saját fotó kipróbálása</span></label></div>' +
	'<div class="sb-cvwrap"><canvas data-cv aria-label="Sablon előnézet" width="1200" height="1800"></canvas><div class="sb-load" data-load>Betöltés…</div></div>' +
	'<p class="sb-hint">A feliratot és a logót egérrel húzva is mozgathatod. Lyukasztott nézetben a sakktábla mintás rész lesz átlátszó a PNG-ben.</p>' +
	'<details class="sb-holes"><summary data-hsum>Fotóablakok</summary><div data-holes></div></details></div></div></div>';

function msg(t, kind) { if (!alive()) return; const m = q('[data-msg]'); m.hidden = !t; m.textContent = t || ''; m.className = 'sb-msg' + (kind ? ' ' + kind : ''); }

function renderCats() { qa('[data-cats] button').forEach(b => b.classList.toggle('on', b.getAttribute('data-c') === st.cat)); }
function inCat(d) { return st.cat === 'all' || (d.own ? d.ocat === st.cat : st.cat !== 'own' && (st.cat === 'wed' ? d.set === 'wed' : d.set !== 'wed')); }
function renderPicker() {
	const p = st.deal && st.deal.parsed;
	const none = st.cat === 'own' && !D.some(d => d.own && inCat(d));
	q('[data-designs]').innerHTML = (none ? '<p class="cpx-muted sb-empty">Még nincs egyedi stílus – készíts egyet lent az AI-keretkészítővel, és mentsd el.</p>' : '') + D.map((d, i) => '<button type="button" class="sb-d' + (i === st.di ? ' on' : '') + '" data-di="' + i + '"' + (inCat(d) ? '' : ' hidden') + '><span class="i" style="background-image:url(' + esc(d.thumbUrl || IMG(d.thumb)) + ')"></span>' + esc(d.name) + (p && lc(p.design) === lc(d.name) ? ' <b class="sb-star" title="Ezt választotta az ügyfél">★</b>' : '') + '</button>').join('');
}
function renderVars() {
	const p = st.deal && st.deal.parsed && lc(st.deal.parsed.design) === lc(D[st.di].name) ? st.deal.parsed : null;
	q('[data-vars]').innerHTML = D[st.di].vars.map((v, i) => '<button type="button" class="sb-v' + (i === st.vi ? ' on' : '') + '" data-vi="' + i + '">' + esc(v.label) + (p && lc(p.layout) === lc(v.label) ? ' ★' : '') + '</button>').join('');
}
function syncInputs() {
	const set = D[st.di].set, s = SETS[set], v = vals(), V = cur();
	q('[data-la]').textContent = s.l[0]; q('[data-lb]').textContent = s.l[1]; q('[data-lc]').textContent = s.l[2];
	qa('[data-fi]').forEach(i => { const k = +i.getAttribute('data-fi'); if (i.value !== v[k]) i.value = v[k] || ''; });
	q('[data-connwrap]').hidden = set !== 'wed';
	qa('[data-conn] button').forEach(b => b.classList.toggle('on', b.getAttribute('data-j') === conn()));
	q('[data-fswrap]').hidden = !V.text2;
	qa('[data-fs] button').forEach(b => b.classList.toggle('on', +b.getAttribute('data-f') === st.fs));
	q('[data-logobox]').hidden = !logoOn();
	q('[data-logoctl]').hidden = !st.logo;
	q('[data-logolbl]').textContent = st.logo ? '✓ Logó feltöltve – csere' : (V.logo ? 'Logó feltöltése (a sablon logóhelyére kerül)' : 'Logó feltöltése');
	q('[data-keepwrap]').hidden = !(V.text || []).some(o => o.nl);
	q('[data-keep]').checked = st.keepName;
	q('[data-lwhite]').checked = st.lwhite;
	qa('[data-mode] button').forEach(b => b.classList.toggle('on', b.getAttribute('data-m') === st.mode));
	q('[data-photolbl]').textContent = st.own ? '✓ Saját fotó – csere' : 'Saját fotó kipróbálása';
	syncTune();
}
function syncTune() {
	q('[data-ts]').value = st.ts; q('[data-tsv]').textContent = Math.round(st.ts) + '%' + (st.tdx || st.tdy ? ' · eltolva' : '');
	q('[data-ls]').value = st.ls; q('[data-lsv]').textContent = Math.round(st.ls) + '%' + (st.ldx || st.ldy ? ' · eltolva' : '');
}
function drawAllUi() { renderCats(); renderPicker(); renderVars(); syncInputs(); drawDeal(); syncAi(); }

function outInfo(L) {
	const n = L.holes.length;
	q('[data-oinfo]').textContent = L.OW + '×' + L.OH + ' px PNG · ' + (L.OW < L.OH ? '10×15' : '15×10') + ' cm, 300 dpi · ' + n + ' fotóablak' + (L.strip ? ' (2 csík egymás mellett)' : '') + (cur().bw ? ' · fekete-fehér sablon: a szoftverben a fotókat is állítsd fekete-fehérre' : '');
	q('[data-hsum]').textContent = 'Fotóablakok helye (' + n + ' db) – a szoftver beállításához';
	q('[data-holes]').innerHTML = '<table><thead><tr><th>#</th><th>bal</th><th>fent</th><th>szélesség</th><th>magasság</th></tr></thead><tbody>' +
		L.holes.map((s, i) => '<tr><td>' + (i + 1) + '</td><td>' + Math.round(s[0] * L.sx) + '</td><td>' + Math.round(s[1] * L.sy) + '</td><td>' + Math.round(s[2] * L.sx) + '</td><td>' + Math.round(s[3] * L.sy) + '</td></tr>').join('') +
		'</tbody></table><small class="cpx-muted">Pixelben, a ' + L.OW + '×' + L.OH + '-es PNG-n. Az ablakok pár pixellel nagyobbak a látható résznél, hogy a fotó a keret alá érjen.</small>';
}

function render() {
	if (!alive()) return;
	const my = ++seq, V = cur();
	const usePh = st.mode === 'photo' && !st.own;
	q('[data-load]').textContent = 'Betöltés…';
	q('[data-load]').hidden = false;
	Promise.all([img(V.ov)].concat(usePh ? photos().map(img) : [])).then(a => {
		if (my !== seq || !alive()) return;
		const ov = a[0];
		if (!ov) { q('[data-load]').textContent = 'A sablon képe nem tölthető be (' + V.ov + '). Próbáld újra, vagy nézd meg, elérhető-e a crazyphoto.hu.'; return; }
		let L;
		try { L = layout(V, ov); } catch (e) { q('[data-load]').textContent = 'A fotóablakok felismerése nem sikerült: ' + e.message; return; }
		const phs = st.own ? [st.own] : a.slice(1).filter(Boolean);
		A = { V, ov, L, phs };
		const cv = q('[data-cv]');
		if (cv.width !== L.OW || cv.height !== L.OH) { cv.width = L.OW; cv.height = L.OH; }
		const mode = st.mode === 'photo' ? 'photo' : 'punch';
		compose(cv.getContext('2d'), A, mode);
		cv.classList.toggle('punch', mode === 'punch');
		cv.classList.toggle('land', L.OW > L.OH);
		q('[data-load]').hidden = true;
		outInfo(L);
	});
}
/* setTimeout és nem requestAnimationFrame: háttérben / takart ablakban is frissüljön */
function schedule() { if (raf) return; raf = setTimeout(() => { raf = 0; render(); }, 16); }
function changed() { schedule(); saveSoon(); }

/* ------------------------------------------------------------ deal */
function dealTitle(ev, d) {
	if (ev && X.who) return X.who(ev);
	const t = String((d && d.title) || '').replace(/^\s*\[[^\]]*\]\s*/, '').replace(/\s*\|.*$/, '').trim();
	return t || (d && d.contact) || ('Deal #' + (d && d.id));
}
function events() { try { return X.events ? X.events() : []; } catch (e) { return []; } }
function fillDeals() {
	const sel = q('[data-deal]');
	const t = ymdToday();
	const evs = events().filter(e => e.date >= t);
	const cid = st.deal ? st.deal.id : '';
	let h = '<option value="">' + (dealsLoaded ? '– nincs (kézi kitöltés) –' : 'Rendezvények betöltése…') + '</option>';
	if (cid && !evs.some(e => String(e.id) === cid)) h += '<option value="' + esc(cid) + '" selected>' + esc((st.deal.date ? dotDate(st.deal.date) + ' · ' : '') + st.deal.title) + '</option>';
	h += evs.map(e => '<option value="' + esc(e.id) + '"' + (String(e.id) === cid ? ' selected' : '') + '>' + esc(dotDate(e.date) + ' · ' + (X.who ? X.who(e) : e.title) + (e.state !== 'booked' ? ' (függő)' : '') + (/Választott fotósablon/i.test(e.note || '') ? ' ★' : '') + (X.tp && X.tp(e.id) ? ' – template ✓' : '')) + '</option>').join('');
	sel.innerHTML = h;
}
async function loadMonths() {
	if (dealsLoaded || !X.load) { dealsLoaded = true; if (alive()) fillDeals(); return; }
	const ms = [], d = new Date();
	for (let i = 0; i < 4; i++) { const x = new Date(d.getFullYear(), d.getMonth() + i, 1); ms.push(x.getFullYear() + '-' + pad(x.getMonth() + 1)); }
	for (const m of ms) { try { await X.load(m); } catch (e) { /* állapotsor */ } }
	dealsLoaded = true;
	if (alive()) fillDeals();
}
function drawDeal() {
	if (!alive()) return;
	const box = q('[data-dinfo]'), d = st.deal, tp = q('[data-tp]');
	if (!d) { box.hidden = true; box.innerHTML = ''; tp.hidden = true; return; }
	box.hidden = false;
	let h = '<div class="sb-dh"><b>' + esc(d.title) + '</b>' + (d.date ? ' · ' + esc(X.huDate ? X.huDate(d.date) : dotDate(d.date)) : '') +
		(C.sm ? ' · <a href="' + esc(C.sm + d.id + '/detail') + '" target="_blank" rel="noopener">Salesmate ↗</a>' : '') +
		'<button type="button" class="sb-link" data-dealclear>leválasztás</button></div>';
	if (d.loading) h += '<p class="cpx-muted">A deal adatainak betöltése…</p>';
	if (d.err) h += '<p class="sb-warn">A Salesmate-ből nem sikerült lekérni: ' + esc(d.err) + (d.note ? ' (a panelen tárolt megjegyzésből dolgozom)' : '') + '</p>';
	const im = d.img || (d.parsed && d.parsed.img);
	if (d.parsed) h += '<div class="sb-choice">' + (im ? '<a href="' + esc(im) + '" target="_blank" rel="noopener" class="sb-cimg" title="Az ügyfél által csatolt sablonkép"><img src="' + esc(im) + '" alt="" loading="lazy"></a>' : '') + '<div><small>Az ügyfél választása a crazyphoto.hu-n</small><b>' + esc(d.parsed.raw) + '</b>' + (im ? '<a href="' + esc(im) + '" target="_blank" rel="noopener">csatolt kép megnyitása ↗</a>' : '') + '</div></div>';
	else if (!d.loading) h += '<p class="cpx-muted">Ennél a dealnél nincs crazyphoto.hu-s sablonválasztás – válassz sablont lent' + (d.date ? ' (a dátumot beírtam)' : '') + '.</p>';
	if (d.parsed && d.parsed.logo) h += '<p class="sb-warn">Az ügyfél saját logóval kérte – töltsd fel a logót a bal oldali „Logó” résznél.</p>';
	if (d.miss) h += '<p class="sb-warn">' + esc(d.miss) + '</p>';
	if (d.src === 'saved') h += '<p class="cpx-muted">Korábban itt szerkesztett beállítás betöltve. <button type="button" class="sb-link" data-reparse>Újrakezdés az ügyfél választásából</button></p>';
	box.innerHTML = h;
	tp.hidden = !(X.markTp && !(X.tp && X.tp(d.id)));
}
async function loadDeal(id, opt) {
	id = String(id || '').replace(/\D/g, '');
	flushSave();
	if (!id) { st.deal = null; drawDeal(); fillDeals(); return; }
	const ev = events().find(e => String(e.id) === id) || null;
	const d = { id, title: dealTitle(ev, { id }), date: ev ? ev.date : '', note: ev ? (ev.note || '') : '', img: '', loading: true };
	st.deal = d;
	drawDeal(); fillDeals();
	try {
		const r = await call('cp_sb_deal', { id });
		if (st.deal !== d) return;
		d.title = dealTitle(ev, r); d.date = r.date || d.date; d.note = r.note || d.note; d.img = r.img || ''; d.contact = r.contact || '';
	} catch (e) {
		if (st.deal !== d) return;
		d.err = e.message;
	}
	d.loading = false;
	applyDeal(opt && opt.fresh);
}
async function applyDeal(fresh) {
	const d = st.deal;
	if (!d) return;
	d.parsed = parseNote(d.note);
	d.miss = '';
	const saved = fresh ? null : loadSaved(d.id);
	if (st.logo && st.logoDeal && st.logoDeal !== d.id) { st.logo = null; st.logoProc = null; } /* másik deal logója ne maradjon */
	if (saved) { restore(saved); d.src = 'saved'; }
	else if (d.parsed) { applyParsed(d.parsed, d); d.src = 'note'; }
	else {
		d.src = 'date';
		/* nincs ügyfélválasztás: egy előző deal szövegei ne maradjanak benne; kézi kitöltés megmarad */
		const set = D[st.di].set, isSample = vals().every((x, k) => x === SETS[set].v[k]);
		if ((st.textsFrom && st.textsFrom !== d.id) || isSample) st.vals[set] = ['', '', ''];
		if (d.date) vals()[2] = dotDate(d.date);
		resetTune();
	}
	st.textsFrom = d.id;
	if (!fresh && await loadLogo(d.id)) st.logoDeal = d.id;
	else if (st.logo && logoOn()) { st.logoDeal = d.id; saveLogo(); }  /* a deal kiválasztása előtt feltöltött logó ehhez a dealhez tartozik */
	if (st.deal !== d || !alive()) return;
	const di = D[st.di];
	if (!inCat(di)) st.cat = 'all';
	drawAllUi(); fillDeals(); schedule();   /* mentés csak tényleges módosításkor (changed → saveSoon) */
}

/* ------------------------------------------------------------ események */
function bind() {
	R.addEventListener('click', onClick);
	R.addEventListener('input', onInput);
	R.addEventListener('change', onChange);
	R.addEventListener('keydown', ev => { if (ev.key === 'Enter' && ev.target.hasAttribute('data-dealid')) { ev.preventDefault(); const v = ev.target.value.replace(/\D/g, ''); if (v) loadDeal(v); } });
	const cv = q('[data-cv]');
	cv.addEventListener('pointerdown', onDown);
	cv.addEventListener('pointermove', onMove);
	cv.addEventListener('pointerup', onUp);
	cv.addEventListener('pointercancel', onUp);
}
function pick(i) {
	const prev = (D[st.di] || D[0]).set;
	st.di = i; st.vi = 0;
	resetTune(); resetLogoPos();
	aiFromDesign(D[i]);
	if (D[i].set !== prev && D[i].set === 'wed') st.fs = 0;
	renderPicker(); renderVars(); syncInputs(); syncAi(); changed();
}
function onClick(ev) {
	const t = ev.target;
	let b;
	if ((b = t.closest('[data-di]'))) { pick(+b.getAttribute('data-di')); return; }
	if ((b = t.closest('[data-vi]'))) { st.vi = +b.getAttribute('data-vi'); resetTune(); resetLogoPos(); renderVars(); syncInputs(); changed(); return; }
	if ((b = t.closest('[data-c]'))) {
		st.cat = b.getAttribute('data-c'); renderCats();
		if (!inCat(D[st.di])) { const f = D.findIndex(inCat); if (f >= 0) { pick(f); return; } }
		renderPicker(); return;
	}
	if ((b = t.closest('[data-j]'))) { st.j = b.getAttribute('data-j'); syncInputs(); changed(); return; }
	if ((b = t.closest('[data-f]')) && b.closest('[data-fs]')) { st.fs = +b.getAttribute('data-f'); syncInputs(); changed(); return; }
	if ((b = t.closest('[data-m]'))) { st.mode = b.getAttribute('data-m'); syncInputs(); schedule(); return; }
	if ((b = t.closest('[data-d]')) && b.closest('[data-nudge]')) {
		const L = A && A.L, step = Math.max(2, Math.round(((L && L.NH) || 1800) / 360)) * (ev.shiftKey ? 6 : 1);
		const d = b.getAttribute('data-d'), dx = d === 'l' ? -step : d === 'r' ? step : 0, dy = d === 'u' ? -step : d === 'd' ? step : 0;
		if (b.closest('[data-nudge]').getAttribute('data-nudge') === 'l') { st.ldx += dx; st.ldy += dy; } else { st.tdx += dx; st.tdy += dy; }
		syncTune(); changed(); return;
	}
	if (t.closest('[data-treset]')) { resetTune(); syncTune(); changed(); return; }
	if (t.closest('[data-lreset]')) { resetLogoPos(); syncTune(); changed(); return; }
	if (t.closest('[data-logodel]')) { st.logo = null; st.logoProc = null; resetLogoPos(); q('[data-logo]').value = ''; saveLogo(); syncInputs(); changed(); return; }
	if (aiClick(t)) return;
	if (t.closest('[data-sbsave]')) { clientSave(); return; }
	if (t.closest('[data-png]')) { exportPng(); return; }
	if (t.closest('[data-jpg]')) { exportJpg(); return; }
	if (t.closest('[data-tp]')) { markTp(); return; }
	if (t.closest('[data-dealgo]')) { const v = q('[data-dealid]').value.replace(/\D/g, ''); if (v) loadDeal(v); return; }
	if (t.closest('[data-dealclear]')) { flushSave(); st.deal = null; q('[data-dealid]').value = ''; drawDeal(); fillDeals(); renderPicker(); renderVars(); return; }
	if (t.closest('[data-reparse]')) { try { localStorage.removeItem(SKEY(st.deal.id)); } catch (e) { /* */ } applyDeal(true); return; }
}
function onInput(ev) {
	const t = ev.target;
	if (t.hasAttribute('data-fi')) { vals()[+t.getAttribute('data-fi')] = t.value; changed(); return; }
	if (t.hasAttribute('data-ts')) { st.ts = +t.value; syncTune(); changed(); return; }
	if (t.hasAttribute('data-ls')) { st.ls = +t.value; syncTune(); changed(); return; }
	if (t.hasAttribute('data-aip')) { st.ai.prompt = t.value; return; }
	if (t.hasAttribute('data-ac')) { st.ai.color = t.value; aiRestyle(); return; }
	if (t.hasAttribute('data-awc')) { st.ai.wc = t.value; aiRestyle(); return; }
}
function onChange(ev) {
	const t = ev.target;
	if (t.hasAttribute('data-deal')) { loadDeal(t.value); return; }
	if (t.hasAttribute('data-lwhite')) { st.lwhite = t.checked; st.logoProc = st.lwhite && st.logo ? whiteCut(st.logo) : null; changed(); return; }
	if (t.hasAttribute('data-keep')) { st.keepName = t.checked; changed(); return; }
	if (t.hasAttribute('data-logo')) {
		const f = t.files && t.files[0]; if (!f) return;
		readLogo(f).then(i => { st.logo = i; st.logoDeal = st.deal ? st.deal.id : ''; st.logoProc = st.lwhite ? whiteCut(i) : null; resetLogoPos(); saveLogo(); syncInputs(); changed(); msg(''); })
			.catch(e => msg(e.message, 'err'));
		return;
	}
	if (t.hasAttribute('data-aiup')) { const f = t.files && t.files[0]; t.value = ''; if (f) aiUpload(f); return; }
	if (t.hasAttribute('data-photo')) {
		const f = t.files && t.files[0]; if (!f) return;
		const u = URL.createObjectURL(f), i = new Image();
		i.onload = () => { st.own = i; st.mode = 'photo'; syncInputs(); schedule(); };
		i.onerror = () => msg('Ez a fájl nem kép.', 'err');
		i.src = u;
	}
}
function toNative(ev) {
	const r = ev.currentTarget.getBoundingClientRect();
	return { x: (ev.clientX - r.left) / r.width * A.L.NW, y: (ev.clientY - r.top) / r.height * A.L.NH };
}
function onDown(ev) {
	if (!A || (ev.pointerType === 'mouse' && ev.button !== 0)) return;
	const p = toNative(ev);
	let what = 'text';
	if (logoActive() && logoRects(A.V, A.L).some(r => p.x >= r.x - 10 && p.x <= r.x + r.w + 10 && p.y >= r.y - 10 && p.y <= r.y + r.h + 10)) what = 'logo';
	drag = { what, x: p.x, y: p.y, ox: what === 'logo' ? st.ldx : st.tdx, oy: what === 'logo' ? st.ldy : st.tdy };
	try { ev.currentTarget.setPointerCapture(ev.pointerId); } catch (e) { /* */ }
	ev.currentTarget.classList.add('drag');
	ev.preventDefault();
}
function onMove(ev) {
	if (!drag || !A) return;
	const p = toNative(ev), dx = Math.round(p.x - drag.x), dy = Math.round(p.y - drag.y);
	if (drag.what === 'logo') { st.ldx = drag.ox + dx; st.ldy = drag.oy + dy; } else { st.tdx = drag.ox + dx; st.tdy = drag.oy + dy; }
	schedule();
}
function onUp(ev) {
	if (!drag) return;
	drag = null;
	ev.currentTarget.classList.remove('drag');
	syncTune(); saveSoon();
}

/* ------------------------------------------------------------ kimenet */
/* mindig a pillanatnyi állapotból (ne egy félig frissült előnézetből) */
async function build() {
	const V = cur(), ov = await img(V.ov);
	if (!ov) throw new Error('A sablon képe nem tölthető be (' + V.ov + ').');
	return { V, ov, L: layout(V, ov), phs: [] };
}
async function exportPng() {
	const b = q('[data-png]'), label = b.textContent;
	b.disabled = true; b.textContent = 'PNG készítése…';
	try {
		await fontsReady();
		const B = await build();
		const c = document.createElement('canvas'); c.width = B.L.OW; c.height = B.L.OH;
		compose(c.getContext('2d'), B, 'punch');
		const blob = await new Promise((res, rej) => c.toBlob(x => (x ? res(x) : rej(new Error('A PNG nem készült el.'))), 'image/png'));
		const name = fname('png');
		download(await withDpi(blob, DPI), name);
		if (st.deal) { saveSoon(); }
		msg('✓ Letöltve: ' + name + (st.deal && X.markTp && !(X.tp && X.tp(st.deal.id)) ? ' – ha ez a végleges, pipáld be a Template-et.' : ''), 'ok');
	} catch (e) {
		msg('Nem sikerült: ' + e.message, 'err');
	} finally { b.disabled = false; b.textContent = label; }
}
async function exportJpg() {
	try {
		await fontsReady();
		const B = await build();
		B.phs = st.own ? [st.own] : (await Promise.all(photos().map(img))).filter(Boolean);
		const c = document.createElement('canvas'); c.width = B.L.OW; c.height = B.L.OH;
		compose(c.getContext('2d'), B, 'photo');
		const blob = await new Promise((res, rej) => c.toBlob(x => (x ? res(x) : rej(new Error('A JPG nem készült el.'))), 'image/jpeg', 0.9));
		const name = fname('jpg', '_elonezet');
		download(blob, name);
		msg('✓ Előnézet letöltve: ' + name, 'ok');
	} catch (e) { msg('Nem sikerült: ' + e.message, 'err'); }
}
async function markTp() {
	if (!st.deal || !X.markTp) return;
	const b = q('[data-tp]');
	b.disabled = true;
	try {
		const ok = await X.markTp(st.deal.id);
		if (ok) { msg('✓ Template bepipálva a Követés fülön.', 'ok'); b.hidden = true; fillDeals(); }
	} catch (e) { msg(e.message, 'err'); }
	b.disabled = false;
}

/* ------------------------------------------------------------ egyedi keret AI-val */
async function post(action, data) {
	const f = new FormData();
	f.append('action', action); f.append('t', C.t || '');
	Object.keys(data || {}).forEach(k => f.append(k, data[k]));
	const r = await fetch(C.ajax || '/wp-admin/admin-ajax.php', { method: 'POST', body: f, credentials: 'same-origin' });
	let j;
	try { j = await r.json(); } catch (e) { throw new Error('Szerverhiba (' + r.status + ')' + (r.status === 413 ? ' – túl nagy a kép' : r.status === 504 || r.status === 524 ? ' – időtúllépés, próbáld újra' : '')); }
	if (!j.success) throw new Error(j.data && j.data.msg === 'auth' ? 'Lejárt a belépés – töltsd újra az oldalt.' : (j.data && j.data.msg) || 'Hiba');
	return j.data;
}
function boxMsg(sel, t, kind) { if (!alive()) return; const m = q(sel); m.hidden = !t; m.textContent = t || ''; m.className = 'sb-msg' + (kind ? ' ' + kind : ''); }
const aiMsg = (t, kind) => boxMsg('[data-aimsg]', t, kind), upMsg = (t, kind) => boxMsg('[data-upmsg]', t, kind), ownMsg = (t, kind) => boxMsg('[data-ownmsg]', t, kind);
const draftIdx = () => D.findIndex(d => d.own === 'draft');
function syncAi() {
	if (!alive()) return;
	const a = st.ai, d = D[st.di];
	q('[data-aino]').hidden = !!SB.ai;
	q('[data-aigo]').disabled = !SB.ai || a.busy;
	qa('[data-al] button').forEach(b => b.classList.toggle('on', b.getAttribute('data-alv') === a.lay));
	qa('[data-ul] button').forEach(b => b.classList.toggle('on', b.getAttribute('data-ulv') === st.up.lay));
	const cd = d && d.own ? d : (draftIdx() >= 0 ? D[draftIdx()] : null);
	q('[data-ownc]').hidden = !cd;
	q('[data-ownh]').textContent = cd ? (cd.own === 'draft' ? 'Új egyedi sablon (még nincs mentve) – beállítások' : 'Saját sablon beállításai: ' + cd.name) : '';
	qa('[data-acat] button').forEach(b => b.classList.toggle('on', b.getAttribute('data-acatv') === a.cat));
	qa('[data-as] button').forEach(b => b.classList.toggle('on', b.getAttribute('data-asv') === a.set));
	qa('[data-af] button').forEach(b => b.classList.toggle('on', b.getAttribute('data-afv') === a.font));
	const c = q('[data-ac]'); if (c.value.toLowerCase() !== a.color.toLowerCase()) c.value = a.color;
	qa('[data-awf] button').forEach(b => b.classList.toggle('on', b.getAttribute('data-awfv') === a.wf));
	const wc = q('[data-awc]'); if (wc.value.toLowerCase() !== a.wc.toLowerCase()) wc.value = a.wc;
	q('[data-awcwrap]').hidden = !/^(line|double|mat)$/.test(a.wf);
	const p = q('[data-aip]'); if (p.value !== a.prompt && document.activeElement !== p) p.value = a.prompt;
	q('[data-aidraft]').hidden = draftIdx() < 0;
	const own = q('[data-aiown]'), sv = d && d.own && d.own !== 'draft' ? d : null;
	own.hidden = !sv;
	own.innerHTML = sv ? 'Kiválasztva: <b>' + esc(sv.name) + '</b> (' + esc(catName(sv.ocat)) + ')' + (a.dirty ? ' · <button type="button" class="cpx-btn sm" data-aiupd>Beállítások mentése a stílusba</button>' : '') + (sv.meta && sv.meta.prompt ? ' · <button type="button" class="sb-link" data-aireuse>prompt átvétele új generáláshoz</button>' : '') + ' · <button type="button" class="sb-link danger" data-aidel>sablon törlése</button>' : '';
}
/* a vázlat / mentés felirat-beállításai élőben a vázlatra */
function aiRestyle() {
	const i = D[st.di] && D[st.di].own ? st.di : draftIdx();
	if (i >= 0) {
		const m = Object.assign({}, D[i].meta, { set: st.ai.set, font: st.ai.font, color: st.ai.color, wf: st.ai.wf, wc: st.ai.wc, cat: st.ai.cat });
		D[i] = ownDesign(m);
		if (D[i].own !== 'draft') st.ai.dirty = true;
		if (st.di === i) { renderVars(); syncInputs(); changed(); }
	}
	syncAi();
}
/* egyedi stílus kiválasztásakor a kártya az ő beállításait mutatja */
function aiFromDesign(d) {
	const m = d && d.own && d.meta;
	if (!m) return;
	Object.assign(st.ai, { cat: okCat(m.cat), set: m.set || 'wed', font: m.font || 'serif', color: m.color || '#4A4237', wf: m.wf || 'none', wc: m.wc || '#3A3A3A', dirty: false });
}
async function aiUpdate() {
	const d = D[st.di];
	if (!d || !d.own || d.own === 'draft') return;
	const m = d.meta;
	try {
		await post('cp_sb_own_upd', { id: d.own, cat: okCat(m.cat), set: m.set, font: m.font, color: m.color, wf: m.wf || 'none', wc: m.wc || '' });
		st.ai.dirty = false;
		if (st.cat !== 'all' && st.cat !== d.ocat) st.cat = d.ocat;   /* maradjon látható a választóban */
		renderCats(); renderPicker(); syncAi();
		ownMsg('✓ A(z) „' + d.name + '” sablon beállításai elmentve (kategória: ' + catName(d.ocat) + ').', 'ok');
	} catch (e) { ownMsg('Nem sikerült menteni: ' + e.message, 'err'); }
}
/* vázlat (szürke fotóablakok) és maszk (átlátszó = az AI festheti) 1024×1536-ban (fekvőnél 1536×1024) */
function aiSketch(lay) {
	const L = OWN_LAY[lay], land = L.or === 'l', W = land ? 1536 : 1024, H = land ? 1024 : 1536, k = W / sheet(L.or)[0];
	const mk = () => { const c = document.createElement('canvas'); c.width = W; c.height = H; return c; };
	const im = mk(), g = im.getContext('2d');
	g.fillStyle = '#F6F3EE'; g.fillRect(0, 0, W, H);
	/* fotóablak: szürke mező, körülötte vékony sötét keretvonal – ehhez igazítsa az AI a díszítést */
	L.slots.forEach(s => {
		const x = Math.round(s[0] * k), y = Math.round(s[1] * k), w = Math.round(s[2] * k), h = Math.round(s[3] * k);
		g.fillStyle = '#8C8C8C'; g.fillRect(x + 3, y + 3, w - 6, h - 6);
		g.lineWidth = 3; g.strokeStyle = '#3A3A3A'; g.strokeRect(x - 6, y - 6, w + 12, h + 12);
	});
	const mask = mk(), h = mask.getContext('2d');
	h.fillStyle = '#000';
	L.slots.forEach(s => h.fillRect(Math.round(s[0] * k), Math.round(s[1] * k), Math.round(s[2] * k), Math.round(s[3] * k)));
	return { image: im.toDataURL('image/png'), mask: mask.toDataURL('image/png') };
}
const loadUrl = u => new Promise((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = () => rej(new Error('A kapott kép nem olvasható.')); i.src = u; });
/* kész keretkép (canvas) → PNG + bélyegkép */
async function packSheet(c) {
	const t = document.createElement('canvas'); t.width = c.width / 5; t.height = c.height / 5;
	t.getContext('2d').drawImage(c, 0, 0, t.width, t.height);
	const png = c.toDataURL('image/png');
	return { png, thumb: t.toDataURL('image/png'), img: await loadUrl(png) };
}
/* AI-kép → 1200×1800 / 1800×1200, csíknál a bal fél másolata a jobb félre, fotóablakok kilyukasztva */
async function aiFinish(url, lay) {
	const src = await loadUrl(url), L = OWN_LAY[lay], iw = src.naturalWidth, ih = src.naturalHeight, S = sheet(L.or);
	const c = document.createElement('canvas'); c.width = S[0]; c.height = S[1];
	const g = c.getContext('2d');
	g.imageSmoothingEnabled = true; g.imageSmoothingQuality = 'high';
	g.drawImage(src, 0, 0, S[0], S[1]);
	if (L.half) g.drawImage(src, 0, 0, iw / 2, ih, S[0] / 2, 0, S[0] / 2, S[1]);   /* a két csík legyen egyforma */
	L.slots.forEach(s => g.clearRect(s[0], s[1], s[2], s[3]));
	return packSheet(c);
}
/* vázlat (nem mentett egyedi stílus) a kész, lyukasztott keretképből */
function setDraft(f, m) {
	const a = st.ai, key = 'ai-vazlat-' + (++a.n);
	cache[key] = Promise.resolve(f.img);
	Object.assign(m, { own: 'draft', cat: a.cat, set: a.set, font: a.font, color: a.color, wf: a.wf, wc: a.wc, ov: key, thumbUrl: f.thumb, png: f.png, thumb: '' });
	const old = draftIdx(), d = ownDesign(m);
	if (old >= 0) { if (D[old].meta && D[old].meta.ov) delete cache[D[old].meta.ov]; D[old] = d; } else D.push(d);
	a.draft = m;
	st.cat = 'own'; st.mode = 'photo';
	pick(draftIdx());
	renderCats();
	if (alive() && !q('[data-ain]').value) q('[data-ain]').focus();
}
/* saját keret feltöltése: a kép tájolása szerint álló 1200×1800-ra vagy fekvő 1800×1200-ra (kitöltve, középre vágva); átlátszó ablakok felismerése */
async function aiUpload(file) {
	const u = URL.createObjectURL(file);
	try {
		const src = await loadUrl(u).catch(() => { throw new Error('Ez a fájl nem kép (PNG, JPG vagy WebP kell).'); });
		const iw = src.naturalWidth, ih = src.naturalHeight;
		if (iw < 300 || ih < 300) throw new Error('Túl kicsi a kép (legalább 1200×1800 px ajánlott).');
		const or = iw > ih ? 'l' : 'p', S = sheet(or);
		const c = document.createElement('canvas'); c.width = S[0]; c.height = S[1];
		const g = c.getContext('2d');
		g.imageSmoothingEnabled = true; g.imageSmoothingQuality = 'high';
		cover(g, src, [0, 0, S[0], S[1]]);
		const r = Math.max(iw, ih) / Math.min(iw, ih), warn = [];
		if (Math.abs(r - 1.5) > 0.08) warn.push('a kép nem 2:3 arányú, ezért a széleiből levágtunk');
		if (Math.min(iw, ih) < 1000) warn.push('kis felbontás (' + iw + '×' + ih + ') – nyomtatásban életlen lehet');
		const probe = await loadUrl(c.toDataURL('image/png'));
		const det = holes(probe, '').map(s => s.map(Math.round));
		let m, lay = st.up.lay;
		if (det.length) {
			m = { name: 'Saját keret (nem mentett)', layout: 'custom', or, slots: det, prompt: '' };
			st.ai.wf = 'none';   /* a saját keretnek már van ablakkerete */
		} else {
			if (layOr(lay) !== or) { lay = LAY_PAIR[lay] || (or === 'l' ? 'f1x' : '1x'); st.up.lay = lay; }
			m = { name: 'Saját keret (nem mentett)', layout: lay, or, prompt: '' };
			OWN_LAY[lay].slots.forEach(s => g.clearRect(s[0], s[1], s[2], s[3]));
		}
		setDraft(await packSheet(c), m); aiMsg('');
		upMsg('✓ Feltöltve (' + (or === 'l' ? 'fekvő, 15×10' : 'álló, 10×15') + ') – ' + (det.length ? det.length + ' átlátszó fotóablakot találtam a képen, azokat használom' : 'nem volt átlátszó ablak, a(z) „' + OWN_LAY[lay].label + '” elrendezés ablakait vágtam bele (más elrendezéshez válassz a feltöltés kártyán, és töltsd fel újra)') + '.' + (warn.length ? ' Figyelem: ' + warn.join('; ') + '.' : '') + ' Lent állítsd be, és ha tetszik, mentsd el.', warn.length ? '' : 'ok');
	} catch (e) {
		upMsg('Nem sikerült: ' + e.message, 'err');
	} finally { URL.revokeObjectURL(u); }
}
async function aiGenerate() {
	const a = st.ai, lay = a.lay, prompt = (q('[data-aip]').value || '').trim();
	a.prompt = prompt;
	if (prompt.length < 3) { aiMsg('Írd le, milyen keretet szeretnél.', 'err'); return; }
	if (a.busy) return;
	a.busy = true; syncAi();
	const b = q('[data-aigo]'), label = b.textContent, t0 = Date.now();
	const tick = setInterval(() => { if (alive()) b.textContent = 'Generálás… ' + Math.round((Date.now() - t0) / 1000) + ' mp'; }, 1000);
	b.textContent = 'Generálás…';
	aiMsg('Az AI dolgozik a kereten – ez általában 30–90 másodperc. Közben nyugodtan szerkesztheted a többit.');
	try {
		const sk = aiSketch(lay);
		const r = await post('cp_sb_ai', { prompt, layout: lay, image: sk.image, mask: sk.mask });
		const f = await aiFinish(r.img, lay);
		a.left = r.left;
		setDraft(f, { name: 'AI vázlat (nem mentett)', layout: lay, prompt }); upMsg('');
		aiMsg('✓ Kész a vázlat' + (r.left >= 0 ? ' (ma még ' + r.left + ' generálás fér bele)' : '') + '. Ha nem tetszik, írj át a leíráson és generáld újra.', 'ok');
	} catch (e) {
		aiMsg('Nem sikerült: ' + e.message, 'err');
	} finally {
		clearInterval(tick);
		a.busy = false;
		if (alive()) { b.textContent = label; syncAi(); }
	}
}
async function aiSave() {
	const i = draftIdx();
	if (i < 0) return;
	const name = (q('[data-ain]').value || '').trim();
	if (!name) { ownMsg('Adj nevet a sablonnak.', 'err'); q('[data-ain]').focus(); return; }
	if (D.some(d => d.own !== 'draft' && lc(d.name) === lc(name))) { ownMsg('Már van ilyen nevű sablon – adj más nevet.', 'err'); return; }
	const b = q('[data-aisave]'); b.disabled = true;
	const m = D[i].meta;
	try {
		const e = await post('cp_sb_own_save', { name, png: m.png, thumb: m.thumbUrl, cat: okCat(m.cat), set: m.set, layout: m.layout, font: m.font, color: m.color, wf: m.wf || 'none', wc: m.wc || '', slots: m.layout === 'custom' ? JSON.stringify(m.slots) : '', prompt: m.prompt || '' });
		cache[e.file] = cache[m.ov];
		const j = draftIdx(); if (j < 0) return;
		D[j] = ownDesign(Object.assign({}, e, { ov: e.file, own: e.id }));
		st.ai.draft = null;
		if (alive()) q('[data-ain]').value = '';
		st.cat = okCat(e.cat);
		renderCats(); renderPicker(); renderVars(); syncInputs(); syncAi(); changed();
		ownMsg('✓ Elmentve: „' + e.name + '” – mostantól a(z) „' + catName(e.cat) + '” kategóriában választható.', 'ok');
	} catch (e) { ownMsg('Nem sikerült menteni: ' + e.message, 'err'); }
	if (alive()) b.disabled = false;
}
function dropDesign(i) {
	if (i < 0) return;
	const was = st.di;
	if (D[i].meta && D[i].meta.ov && D[i].own === 'draft') delete cache[D[i].meta.ov];
	D.splice(i, 1);
	if (was === i) { st.di = 0; const f = D.findIndex(inCat); pick(f >= 0 ? f : 0); }
	else { if (was > i) st.di = was - 1; renderPicker(); }
	syncAi();
}
async function aiDel() {
	const d = D[st.di];
	if (!d || !d.own || d.own === 'draft') return;
	if (!window.confirm('Biztosan törlöd a(z) „' + d.name + '” egyedi stílust? A kép is törlődik a szerverről.')) return;
	try {
		await post('cp_sb_own_del', { id: d.own });
		dropDesign(D.indexOf(d));
		ownMsg('✓ Törölve: „' + d.name + '”.', 'ok'); msg('✓ Törölve: „' + d.name + '” sablon.', 'ok');   /* a kártya eltűnhet */
	} catch (e) { ownMsg('Nem sikerült törölni: ' + e.message, 'err'); }
}
function aiClick(t) {
	let b;
	if ((b = t.closest('[data-alv]'))) { st.ai.lay = b.getAttribute('data-alv'); syncAi(); return true; }
	if ((b = t.closest('[data-ulv]'))) { st.up.lay = b.getAttribute('data-ulv'); syncAi(); return true; }
	if ((b = t.closest('[data-acatv]'))) { st.ai.cat = b.getAttribute('data-acatv'); aiRestyle(); renderPicker(); return true; }
	if ((b = t.closest('[data-asv]'))) { st.ai.set = b.getAttribute('data-asv'); aiRestyle(); return true; }
	if ((b = t.closest('[data-afv]'))) { st.ai.font = b.getAttribute('data-afv'); aiRestyle(); return true; }
	if ((b = t.closest('[data-awfv]'))) { st.ai.wf = b.getAttribute('data-awfv'); aiRestyle(); return true; }
	if (t.closest('[data-aiupd]')) { aiUpdate(); return true; }
	if (t.closest('[data-aigo]')) { aiGenerate(); return true; }
	if (t.closest('[data-aisave]')) { aiSave(); return true; }
	if (t.closest('[data-aidrop]')) { st.ai.draft = null; dropDesign(draftIdx()); ownMsg(''); return true; }
	if (t.closest('[data-aidel]')) { aiDel(); return true; }
	if (t.closest('[data-aireuse]')) {
		const m = D[st.di] && D[st.di].meta;
		if (m) { Object.assign(st.ai, { prompt: m.prompt || '', lay: OWN_LAY[m.layout] ? m.layout : st.ai.lay }); q('[data-aip]').value = st.ai.prompt; syncAi(); q('[data-aip]').focus(); }
		return true;
	}
	return false;
}

/* ------------------------------------------------------------ belépési pont */
function view(main, ctx) {
	X = ctx || {};
	main.innerHTML = TPL;
	R = main.querySelector('.sb');
	A = null;
	bind();
	drawAllUi();
	fillDeals();
	loadMonths();
	const pend = X.take ? X.take() : '';
	if (pend) loadDeal(pend);
	if (CLIENT) clientStart();
	fontsReady().then(() => { schedule(); lazyFonts(); });
}

/* ------------------------------------------------------------ ügyfél-mód (ügyfélportál) */
async function clientStart() {
	R.classList.add('sb-client');
	st.mode = 'photo';   /* az ügyfél mintafotókkal látja, hogyan fog kinézni */
	q('.sb-out').insertAdjacentHTML('afterbegin', '<div class="sb-save-wrap"><button type="button" class="cpx-btn sb-dl" data-sbsave' + (X.locked ? ' disabled' : '') + '>Sablon mentése</button><small class="sb-oinfo">A mentéssel elküldöd nekünk – a nyomtatható változatot a csapatunk készíti el.</small></div>');
	const d = X.deal || null;
	if (!d) return;
	st.deal = { id: String(d.id), title: d.title || '', date: d.date || '', note: '', img: '', loading: false, parsed: null, src: '' };
	if (X.saved && X.saved.d) { restore(X.saved); st.deal.src = 'saved'; }
	else {
		const set = D[st.di].set;
		st.vals[set] = ['', '', ''];
		if (d.date) vals()[2] = dotDate(d.date);
	}
	st.textsFrom = st.deal.id;
	if (X.logoUrl) {
		await new Promise(res => { const i = new Image(); i.onload = () => { st.logo = i; st.logoProc = st.lwhite ? whiteCut(i) : null; st.logoDeal = st.deal.id; res(); }; i.onerror = () => res(); i.src = X.logoUrl; });
	}
	if (!inCat(D[st.di])) st.cat = 'all';
	if (alive()) { drawAllUi(); schedule(); }
}
async function clientSave() {
	if (!X.save || X.locked) return;
	const b = q('[data-sbsave]'), label = b.textContent;
	b.disabled = true; b.textContent = 'Mentés…';
	try {
		await fontsReady();
		const B = await build();
		B.phs = st.own ? [st.own] : (await Promise.all(photos().map(img))).filter(Boolean);
		const c = document.createElement('canvas'); c.width = B.L.OW; c.height = B.L.OH;
		compose(c.getContext('2d'), B, 'photo');
		const k = Math.min(1, 900 / Math.max(c.width, c.height));
		const c2 = document.createElement('canvas'); c2.width = Math.round(c.width * k); c2.height = Math.round(c.height * k);
		c2.getContext('2d').drawImage(c, 0, 0, c2.width, c2.height);
		const src = st.logo && logoOn() ? String(st.logo.src || '') : '';
		await X.save({ snap: snapshot(), logo: src === '' ? '' : (/^data:/.test(src) ? src : 'keep'), preview: c2.toDataURL('image/jpeg', 0.85) });
		msg('✓ Elmentve – megkaptuk a sablonodat. Később is módosíthatod a határidőig.', 'ok');
	} catch (e) {
		msg('Nem sikerült menteni: ' + e.message, 'err');
	} finally { b.disabled = false; b.textContent = label; }
}

window.CPSablon = { view: view, parseNote: parseNote, _st: st, _D: D };
})();
