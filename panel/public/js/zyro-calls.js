// Caller popup: polls the hotline calls the company's SIP extension saw ringing (engine/sip_calls.py) and the WhatsApp
// calls ringing for staff (engine/voice_bot.py), and shows the caller with their customer profile in a card at the bottom
// corner. A ringing WhatsApp call has "ধরুন" for members allowed "phone": it opens the Desk softphone, which takes it.
// A ringing card stays; an ended one fades after a while.
(function () {
    const cfg = window.ZyroCalls;
    if (!cfg) return;
    const T = cfg.t;
    const store = {
        get(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } },
        set(k, v) { try { sessionStorage.setItem(k, v); } catch (e) {} },
    };
    const closed = new Set((store.get('zyroCalls:closed') || '').split(',').filter(Boolean));
    const cards = new Map();   // call id => {el, ringing, endedAt}

    const box = document.createElement('div');
    box.style.cssText = 'position:fixed;left:16px;bottom:16px;z-index:60;display:flex;flex-direction:column;gap:8px;max-width:min(360px,calc(100vw - 32px))';
    document.body.appendChild(box);

    let ctx = null;
    ['pointerdown', 'keydown'].forEach(ev => document.addEventListener(ev, () => {
        try { ctx = ctx || new (window.AudioContext || window.webkitAudioContext)(); if (ctx.state === 'suspended') ctx.resume(); } catch (e) {}
    }, {capture: true}));
    function ring() {
        if (!ctx) return;
        const t = ctx.currentTime;
        [0, 0.25].forEach(d => {
            const o = ctx.createOscillator(), g = ctx.createGain();
            o.frequency.value = 660; o.connect(g); g.connect(ctx.destination);
            g.gain.setValueAtTime(0.0001, t + d); g.gain.exponentialRampToValueAtTime(0.25, t + d + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, t + d + 0.2);
            o.start(t + d); o.stop(t + d + 0.22);
        });
    }

    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));

    function take(c) {
        const url = cfg.phone + '?c=' + encodeURIComponent(cfg.company) + '&take=' + encodeURIComponent(c.take)
            + '&name=' + encodeURIComponent(c.caller + (c.customers[0] ? ' · ' + c.customers[0].name : ''));
        window.open(url, 'zyrophone', 'width=380,height=600');
    }

    function label(c) {
        if (c.ringing) return '📞 ' + (c.channel === 'whatsapp' ? T.wa_ringing : c.channel === 'reminder' ? T.reminder_ringing : T.ringing);
        return c.answered_by ? T.answered + ' ' + c.answered_by : T.ended;
    }

    function render(c) {
        const people = c.customers.length
            ? c.customers.map(p => `<a href="${esc(p.url)}" style="display:block;padding:6px 0;border-top:1px solid rgba(128,128,128,.25);color:inherit;text-decoration:none">
                <b>${esc(p.name)}</b> <span style="opacity:.7">ID ${esc(p.id)}${p.left ? ' · ' + esc(T.left) : ''}</span><br>
                <span style="opacity:.75;font-size:12px">${esc(p.place)}${p.due ? ' · ' + esc(T.due) + ' ' + esc(p.due) : ''}</span></a>`).join('')
            : `<div style="padding:6px 0;border-top:1px solid rgba(128,128,128,.25);opacity:.75">${esc(T.unknown)}</div>`;
        return `<div style="display:flex;justify-content:space-between;align-items:center;gap:8px">
                <span style="font-size:12px;opacity:.8">${esc(label(c))} · ${esc(c.at)}</span>
                <button type="button" data-close aria-label="${esc(T.close)}" style="border:0;background:none;color:inherit;font-size:18px;line-height:1;cursor:pointer">×</button></div>
            <div style="font-size:20px;font-weight:600;letter-spacing:.5px;margin:2px 0 4px">${esc(c.caller)}</div>${people}
            ${c.take ? `<button type="button" data-take style="margin-top:8px;width:100%;padding:8px;border:0;border-radius:8px;background:#16a34a;color:#fff;font-weight:600;cursor:pointer">${esc(T.take)}</button>` : ''}`;
    }

    function show(c) {
        const id = String(c.id);
        if (closed.has(id)) return;
        let card = cards.get(id);
        if (!card) {
            if (!c.ringing) return;   // never pop up a call that already ended
            const el = document.createElement('div');
            el.className = 'fi-section';
            el.style.cssText = 'padding:12px 14px;border-radius:12px;box-shadow:0 8px 24px rgba(0,0,0,.25);transition:opacity .4s';
            el.addEventListener('click', e => {
                if (e.target.closest('[data-take]') && card.c && card.c.take) { take(card.c); return; }
                if (!e.target.closest('[data-close]')) return;
                closed.add(id); store.set('zyroCalls:closed', [...closed].slice(-50).join(','));
                el.remove(); cards.delete(id);
            });
            box.prepend(el);
            card = {el, ringing: false, endedAt: 0};
            cards.set(id, card);
            ring();
        }
        const key = label(c) + '|' + (c.take || '');
        if (card.key !== key) { card.el.innerHTML = render(c); card.key = key; }
        card.c = c;
        if (card.ringing && !c.ringing) card.endedAt = Date.now();
        card.ringing = c.ringing;
        card.el.style.opacity = c.ringing ? '1' : '.75';
    }

    async function poll() {
        try {
            const r = await fetch(cfg.poll, {headers: {Accept: 'application/json'}, credentials: 'same-origin'});
            if (!r.ok) return;
            const {items} = await r.json();
            items.slice().reverse().forEach(show);
            const live = new Set(items.map(i => String(i.id)));
            cards.forEach((card, id) => {
                const gone = !live.has(id) || (!card.ringing && Date.now() - card.endedAt > 20000);
                if (gone) { card.el.remove(); cards.delete(id); }
            });
        } catch (e) {}
    }
    poll();
    setInterval(poll, 3000);
})();
