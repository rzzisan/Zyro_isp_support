// Polls for new incoming WhatsApp messages. Runs in a worker because browsers slow page timers
// in background tabs to once a minute; worker timers keep their pace.
let url = null, after = null, timer = null;
const EVERY = 8000;

async function tick() {
    try {
        const r = await fetch(url + (after !== null ? '?after=' + after : ''),
            {credentials: 'same-origin', cache: 'no-store', headers: {Accept: 'application/json'}});
        if (r.redirected || r.status === 401 || r.status === 403 || r.status === 419) {
            postMessage({type: 'stopped'});   // logged out or no inbox access
            return;
        }
        if (r.ok) {
            const d = await r.json();
            const first = after === null;
            after = d.last;
            if (!first && d.items.length) postMessage({type: 'items', items: d.items});
        }
    } catch (e) { /* offline for a moment: try again next round */ }
    timer = setTimeout(tick, EVERY);
}

onmessage = (e) => {
    if (e.data.type === 'start') {
        url = e.data.url;
        clearTimeout(timer);
        tick();
    }
};
