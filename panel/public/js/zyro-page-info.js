// Page description (Filament subheading) stays hidden behind a small "i" button next to the page title.
// Open/closed state lives on <html> so Livewire re-renders (e.g. বিল কালেকশন every 5 s) don't reset it;
// the button is re-added whenever a morph removes it.
(function () {
    const root = document.documentElement;
    const T = (s) => (window.zyroT ? window.zyroT(s) : s);   // panel language (App\Support\Locale::JS)

    function addButtons() {
        document.querySelectorAll('.fi-header').forEach((header) => {
            const heading = header.querySelector('.fi-header-heading');
            const sub = header.querySelector('.fi-header-subheading');
            if (!heading || !sub || !sub.textContent.trim() || heading.querySelector('.z-info-btn')) return;
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'z-info-btn';
            btn.title = T('তথ্য দেখুন');
            btn.setAttribute('aria-label', T('তথ্য দেখুন'));
            btn.setAttribute('aria-expanded', root.dataset.zInfo === 'open' ? 'true' : 'false');
            btn.innerHTML = '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9Z" clip-rule="evenodd"/></svg>';
            heading.appendChild(btn);
        });
    }

    document.addEventListener('click', (e) => {
        const btn = e.target.closest('.z-info-btn');
        if (!btn) return;
        e.preventDefault();
        const open = root.dataset.zInfo !== 'open';
        if (open) root.dataset.zInfo = 'open'; else delete root.dataset.zInfo;
        document.querySelectorAll('.z-info-btn').forEach((b) => b.setAttribute('aria-expanded', open ? 'true' : 'false'));
    });

    // a new page starts closed
    document.addEventListener('livewire:navigated', () => { delete root.dataset.zInfo; addButtons(); });

    let queued = false;
    new MutationObserver(() => {
        if (queued) return;
        queued = true;
        queueMicrotask(() => { queued = false; addButtons(); });
    }).observe(document.documentElement, { childList: true, subtree: true });

    addButtons();
})();
