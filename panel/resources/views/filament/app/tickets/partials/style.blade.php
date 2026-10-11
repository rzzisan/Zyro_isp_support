<style>
    .ztd { --ok:#059669; --warn:#d97706; --bad:#dc2626; --mute:#6b7280; display:flex; flex-direction:column; gap:14px; font-size:.875rem; color:var(--z-fg, inherit) }
    .ztd * { box-sizing:border-box }
    .ztd .tone-good { --tone:var(--ok) } .ztd .tone-warn { --tone:var(--warn) } .ztd .tone-bad { --tone:var(--bad) } .ztd .tone-mute { --tone:var(--mute) }
    .ztd-hero { position:relative; overflow:hidden; display:flex; flex-wrap:wrap; align-items:center; gap:14px; padding:16px 18px; border-radius:16px;
        border:1px solid var(--z-border, #e5e7eb);
        background:linear-gradient(135deg, color-mix(in srgb, var(--z-accent, #0f7c7b) 14%, var(--z-surface, #fff)) 0%, var(--z-surface, #fff) 60%) }
    .ztd-hero::after { content:''; position:absolute; right:-40px; top:-40px; width:160px; height:160px; border-radius:50%;
        background:radial-gradient(circle, color-mix(in srgb, var(--z-accent, #0f7c7b) 22%, transparent), transparent 70%); pointer-events:none }
    .ztd-avatar { width:48px; height:48px; flex:none; border-radius:14px; display:grid; place-items:center; font-weight:800; font-size:1.25rem; color:#fff;
        background:linear-gradient(135deg, var(--z-accent, #0f7c7b), color-mix(in srgb, var(--z-accent, #0f7c7b) 55%, #22d3ee)); box-shadow:0 6px 16px -6px var(--z-accent, #0f7c7b) }
    .ztd-name { font-weight:800; font-size:1.1rem; line-height:1.2 }
    .ztd-pills { display:flex; flex-wrap:wrap; gap:6px; margin-top:6px }
    .ztd-pill { font-size:.72rem; padding:2px 9px; border-radius:999px; background:var(--z-surface, #fff); border:1px solid var(--z-border, #e5e7eb); color:var(--z-muted, #6b7280) }
    .ztd-pill b { color:var(--z-fg, inherit); font-weight:600 }
    .ztd-chips { margin-left:auto; display:flex; flex-wrap:wrap; gap:6px; position:relative; z-index:1 }
    .ztd-chip { display:inline-flex; align-items:center; gap:6px; padding:4px 11px; border-radius:999px; font-size:.72rem; font-weight:700; color:var(--tone);
        background:color-mix(in srgb, var(--tone) 12%, transparent); border:1px solid color-mix(in srgb, var(--tone) 30%, transparent) }
    .ztd-dot { width:8px; height:8px; border-radius:50%; background:var(--tone); flex:none }
    .ztd-dot.live { animation:ztd-pulse 1.8s ease-out infinite }
    @keyframes ztd-pulse { 0% { box-shadow:0 0 0 0 color-mix(in srgb, var(--tone) 60%, transparent) } 80%,100% { box-shadow:0 0 0 8px transparent } }

    .ztd-stats { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px }
    .ztd-stat { position:relative; padding:12px 14px; border-radius:14px; background:var(--z-surface, #fff); border:1px solid var(--z-border, #e5e7eb);
        transition:transform .15s ease, box-shadow .15s ease }
    .ztd-stat:hover { transform:translateY(-2px); box-shadow:0 10px 24px -14px rgba(0,0,0,.35) }
    .ztd-stat::before { content:''; position:absolute; left:0; top:12px; bottom:12px; width:3px; border-radius:0 3px 3px 0; background:var(--tone) }
    .ztd-label { font-size:.68rem; font-weight:700; letter-spacing:.05em; text-transform:uppercase; color:var(--z-muted, #6b7280) }
    .ztd-big { font-size:1.2rem; font-weight:800; margin-top:4px; display:flex; align-items:center; gap:8px; color:var(--tone) }
    .ztd-sub { font-size:.74rem; color:var(--z-muted, #6b7280); margin-top:2px }
    .ztd-meter { height:6px; border-radius:999px; background:color-mix(in srgb, var(--z-muted, #6b7280) 18%, transparent); margin-top:8px; overflow:hidden }
    .ztd-meter span { display:block; height:100%; border-radius:inherit; background:linear-gradient(90deg, color-mix(in srgb, var(--tone) 55%, transparent), var(--tone)) }

    .ztd-card { padding:14px 16px; border-radius:14px; background:var(--z-surface, #fff); border:1px solid var(--z-border, #e5e7eb) }
    .ztd-head { display:flex; align-items:center; justify-content:space-between; gap:8px; margin-bottom:10px }
    .ztd-title { font-weight:800; font-size:.9rem; display:flex; align-items:center; gap:8px }
    .ztd-ico { width:28px; height:28px; border-radius:9px; display:grid; place-items:center; font-size:.9rem; background:color-mix(in srgb, var(--z-accent, #0f7c7b) 12%, transparent) }
    .ztd-desc { white-space:pre-line; line-height:1.55; padding:10px 12px; border-radius:10px; background:var(--z-surface-soft, #fafafa); border-left:3px solid var(--tone) }
    .ztd-note { margin-top:8px; font-size:.8rem; color:var(--z-muted, #6b7280) }

    .ztd-steps { display:grid; grid-template-columns:repeat(3, 1fr); gap:8px; margin-top:12px }
    .ztd-step { position:relative; padding:8px 10px; border-radius:10px; border:1px dashed var(--z-border, #e5e7eb); opacity:.55 }
    .ztd-step.done { opacity:1; border-style:solid; background:color-mix(in srgb, var(--z-accent, #0f7c7b) 6%, transparent) }
    .ztd-step .k { font-size:.68rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:var(--z-muted, #6b7280); display:flex; align-items:center; gap:6px }
    .ztd-step .k::before { content:''; width:7px; height:7px; border-radius:50%; background:var(--z-border, #d1d5db) }
    .ztd-step.done .k::before { background:var(--z-accent, #0f7c7b) }
    .ztd-step .v { font-weight:600; font-size:.8rem; margin-top:3px; overflow-wrap:anywhere }
    .ztd-step .s { font-size:.72rem; color:var(--z-muted, #6b7280) }

    .ztd-grid2 { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:10px }
    .ztd-rows { display:flex; flex-direction:column }
    .ztd-row { display:flex; justify-content:space-between; gap:12px; padding:6px 0; border-top:1px solid color-mix(in srgb, var(--z-border, #e5e7eb) 70%, transparent) }
    .ztd-row:first-child { border-top:0 }
    .ztd-row > span:first-child { color:var(--z-muted, #6b7280) }
    .ztd-row > span:last-child { font-weight:600; text-align:right; overflow-wrap:anywhere }
    .ztd-mono { font-family:ui-monospace, SFMono-Regular, Menlo, monospace; font-size:.78rem }
    .ztd-traffic { display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-top:10px }
    .ztd-traffic div { padding:8px 10px; border-radius:10px; background:var(--z-surface-soft, #fafafa) }
    .ztd-traffic b { display:block; font-size:1rem; font-weight:800 }
    .ztd-empty { font-size:.82rem; color:var(--z-muted, #6b7280); padding:8px 0 }
    .ztd-err { padding:10px 12px; border-radius:10px; color:var(--bad); background:color-mix(in srgb, var(--bad) 8%, transparent); font-size:.82rem }
    @media (max-width: 640px) { .ztd-steps { grid-template-columns:1fr } .ztd-chips { margin-left:0 } }
</style>
