// New WhatsApp message alerts for the company panel: sound, desktop notification (also when the tab is in
// the background), an in-page toast and a "(3)" counter in the tab title. The bell in the top bar turns it on/off.
(function () {
    const cfg = window.ZyroNotify;
    if (!cfg || !window.Worker) return;

    const store = {
        get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
        set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} },
    };
    const MODE_KEY = 'zyroNotify:mode';
    const LAST_KEY = 'zyroNotify:last:' + cfg.slug;   // shared by tabs, so only one tab alerts per message
    const canDesktop = 'Notification' in window;
    const on = () => store.get(MODE_KEY) !== 'off';

    // ---- sound (Web Audio, no file to load); browsers allow it only after a click on the page
    let ctx = null;
    function unlockAudio() {
        try {
            ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
            if (ctx.state === 'suspended') ctx.resume();
        } catch (e) {}
    }
    ['pointerdown', 'keydown'].forEach(ev => document.addEventListener(ev, unlockAudio, {capture: true}));
    function ding() {
        if (!ctx) return;
        const t = ctx.currentTime;
        [[880, 0], [1320, 0.16]].forEach(([f, d]) => {
            const o = ctx.createOscillator(), g = ctx.createGain();
            o.type = 'sine';
            o.frequency.value = f;
            g.gain.setValueAtTime(0.0001, t + d);
            g.gain.exponentialRampToValueAtTime(0.35, t + d + 0.02);
            g.gain.exponentialRampToValueAtTime(0.0001, t + d + 0.35);
            o.connect(g).connect(ctx.destination);
            o.start(t + d);
            o.stop(t + d + 0.4);
        });
    }

    // ---- tab title counter while the tab is hidden
    const baseTitle = document.title;
    let unseen = 0;
    function bumpTitle(n) {
        unseen += n;
        document.title = '(' + unseen + ') ' + baseTitle;
    }
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) { unseen = 0; document.title = baseTitle; }
    });

    // ---- Web Push: the engine pushes each new message to this browser, shown by /zyro-sw.js even with no tab open
    const canPush = !!(cfg.vapid && 'serviceWorker' in navigator && 'PushManager' in window && window.isSecureContext);
    let pushOn = false;
    function keyBytes(b64) {
        const s = atob((b64 + '='.repeat((4 - b64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(s, c => c.charCodeAt(0));
    }
    function post(url, body) {
        return fetch(url, {method: 'POST', credentials: 'same-origin', body: JSON.stringify(body),
            headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': cfg.csrf}});
    }
    async function subscribePush() {
        if (!canPush || Notification.permission !== 'granted') return;
        try {
            const reg = await navigator.serviceWorker.register(cfg.sw, {scope: '/'});
            await navigator.serviceWorker.ready;
            let sub = await reg.pushManager.getSubscription();
            if (sub && store.get('zyroNotify:vapid') !== cfg.vapid) { await sub.unsubscribe(); sub = null; }
            sub = sub || await reg.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: keyBytes(cfg.vapid)});
            store.set('zyroNotify:vapid', cfg.vapid);
            const r = await post(cfg.push, sub.toJSON());   // every page load: keeps it tied to this user/company
            pushOn = r.ok;
        } catch (e) { pushOn = false; }
    }
    async function unsubscribePush() {
        pushOn = false;
        if (!canPush) return;
        try {
            const reg = await navigator.serviceWorker.getRegistration('/');
            const sub = reg && await reg.pushManager.getSubscription();
            if (sub) { await post(cfg.unpush, {endpoint: sub.endpoint}); await sub.unsubscribe(); }
        } catch (e) {}
    }

    // ---- bell button
    const bell = document.getElementById('zyro-notify-bell');
    function paintBell() {
        if (!bell) return;
        const denied = canDesktop && Notification.permission === 'denied';
        const ready = on() && (!canDesktop || Notification.permission === 'granted');
        bell.dataset.state = !on() ? 'off' : denied ? 'denied' : ready ? 'on' : 'ask';
        bell.title = !on() ? 'মেসেজ নোটিফিকেশন বন্ধ — চালু করতে ক্লিক করুন'
            : denied ? 'ব্রাউজারে নোটিফিকেশন ব্লক করা — ঠিকানার পাশের 🔒 আইকন থেকে Notifications: Allow করুন'
            : ready ? 'মেসেজ নোটিফিকেশন চালু — বন্ধ করতে ক্লিক করুন'
            : 'ডেস্কটপ নোটিফিকেশন চালু করতে ক্লিক করুন';
    }
    function toast(title, body, status) {
        if (window.FilamentNotification) {
            new window.FilamentNotification().title(title).body(body)[status || 'info']().send();
        }
    }
    bell && bell.addEventListener('click', async () => {
        unlockAudio();
        if (on() && (!canDesktop || Notification.permission !== 'default')) {
            if (canDesktop && Notification.permission === 'denied') {
                toast('নোটিফিকেশন ব্লক করা', 'ঠিকানার বারের 🔒 আইকনে ক্লিক করে Notifications → Allow দিন, তারপর পেজ রিলোড করুন।', 'warning');
                return;
            }
            store.set(MODE_KEY, 'off');
            unsubscribePush();
            paintBell();
            toast('মেসেজ নোটিফিকেশন বন্ধ', 'আবার চালু করতে বেল আইকনে ক্লিক করুন।', 'gray');
            return;
        }
        store.set(MODE_KEY, 'on');
        if (canDesktop && Notification.permission === 'default') await Notification.requestPermission();
        paintBell();
        ding();
        await subscribePush();
        if (canDesktop && Notification.permission === 'granted') {
            new Notification('নোটিফিকেশন চালু হয়েছে', {body: 'নতুন WhatsApp মেসেজ এলে এভাবে জানানো হবে।', icon: cfg.icon});
        }
        toast('মেসেজ নোটিফিকেশন চালু', pushOn ? 'নতুন মেসেজ এলে নোটিফিকেশন আসবে, প্যানেলের ট্যাব বন্ধ থাকলেও (ব্রাউজার চালু থাকলে)।'
            : 'নতুন মেসেজ এলে শব্দ ও নোটিফিকেশন আসবে। এই ট্যাবটা খোলা রাখুন (ছোট করে রাখলেও চলবে)।', 'success');
    });
    paintBell();
    if (on()) subscribePush();

    // ---- alerts for new messages
    function alert(items) {
        const last = parseInt(store.get(LAST_KEY) || '0', 10);
        items = items.filter(i => i.id > last);
        if (!items.length) return;
        store.set(LAST_KEY, String(items[items.length - 1].id));
        if (!on()) return;

        // one alert per chat, with its newest message
        const byChat = new Map();
        items.forEach(i => byChat.set(i.contact, i));
        const here = location.pathname;
        let sounded = false;
        byChat.forEach(i => {
            const title = (i.staff ? '[স্টাফ] ' : '') + i.name + (i.human ? ' · উত্তর দিন' : '');
            const viewing = !document.hidden && new URL(i.url, location.href).pathname === here;
            if (viewing) return;   // the open chat refreshes by itself
            if (document.hidden && pushOn) return;   // the push notification covers it
            if (!sounded) { ding(); sounded = true; }
            if (document.hidden && canDesktop && Notification.permission === 'granted') {
                const n = new Notification(title, {body: i.body, tag: 'wa-' + i.contact, renotify: true, icon: cfg.icon});
                n.onclick = () => { window.focus(); location.href = i.url; n.close(); };
            } else if (window.FilamentNotification) {
                const t = new window.FilamentNotification().title('💬 ' + title).body(i.body).info();
                if (window.FilamentNotificationAction) {
                    t.actions([new window.FilamentNotificationAction('open').label('খুলুন').url(i.url).button()]);
                }
                t.send();
            }
        });
        if (document.hidden) bumpTitle(byChat.size);
    }

    const worker = new Worker(cfg.worker);
    worker.onmessage = (e) => {
        if (e.data.type === 'items') alert(e.data.items);
        if (e.data.type === 'stopped') worker.terminate();
    };
    worker.postMessage({type: 'start', url: cfg.poll});
})();
