---
target: Crazy panel (assets)
total_score: 25
max_score: 40
na_heuristics: 
p0_count: 0
p1_count: 4
target_identity: "file:/home/user/Weblion/crazy-panel/assets"
timestamp: 2026-11-06T15-34-45Z
slug: crazy-panel-assets
---
Method: dual-agent (A: design review · B: detector + browser overlay)

# Crazy panel – Impeccable critique (2026-11-06), Operate mode

Score 25/40 (Acceptable). H1 3 · H2 3 · H3 2 · H4 2 · H5 2 · H6 2 · H7 3 · H8 2 · H9 2 · H10 2.

Design specificity: content authored for the product; visual layer category-interchangeable (navy sidebar, cream bg, Inter, rounded white cards).

Detector: CLI 20 findings (side-tab 18, overused-font 2); overlay per view 10–95 (low-contrast #8a8e96 on white 3.3:1 ×45 on deal page, .crm-st.won 3.0:1, #e8894a text 2.6:1, badges 3.6:1; nested-cards 17–18 on deal page; undersized text <11px). False positives: Arial in email HTML, sablon fonts, blockquote stripe, logo gradient, dark-mode contrast (invert filter).

Priority issues:
1. [P1] Dark mode = full-page invert filter (panel.css:730) → real dark tokens; migrate ~860 hardcoded hex to vars. /impeccable colorize, harden
2. [P1] Low contrast muted grey/status pills/badges/orange text → ~5 token changes. /impeccable audit, polish
3. [P1] Inconsistent primary buttons (3 styles), note action differs mail vs deal, emoji+line icons mixed → one btn-primary/secondary, line icons only. /impeccable polish
4. [P1] Deal page action overload (19 options), Törlés styled like Elvesztett next to it → next-stage primary, overflow menu with danger zone, mobile 2+Több. /impeccable distill
5. [P2] Thread toolbar ~15 controls, 5 icon-only; ~30 font sizes, 23% bold → labels + overflow + j/k/e/r; 6-step rem scale. /impeccable typeset, distill

Personas: power user (no mail shortcuts, single-select timeline filters), mobile (1 deal per screen, top-bar actions, FAB overlaps, tiny ✓/✎), first-timer (jargon, unlabeled ●/⏰, "Kilépés (admin)").

Minor: no prefers-reduced-motion; non-dismissible Próbaüzem/Salesmate banners; ambiguous group checkbox in Mai teendőim; folder counts only on Beérkezett.

Questions: 40 "Régi lejárt" as headline? Deal page = record or process? Merge thread view and deal timeline?
