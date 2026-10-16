{{-- Placeholder chips: a click puts {key} into the SMS text (textarea[data-sms-body]) at the cursor, keeps the focus,
     and fires "input" so Livewire updates the length / parts / preview. $items = [key => label]. --}}
<div class="zyro-sms-chips" style="display:flex;flex-wrap:wrap;gap:.35rem;align-items:center">
    <span style="font-size:.75rem;opacity:.7">{{ __('তথ্য বসাতে ক্লিক করুন:') }}</span>
    @foreach ($items as $key => $label)
        <button type="button"
            title="{{ $label }}"
            style="font-size:.75rem;line-height:1.2;padding:.2rem .5rem;border-radius:999px;border:1px solid rgba(15,124,123,.35);background:rgba(15,124,123,.08);cursor:pointer"
            x-on:mousedown.prevent
            x-on:click="
                const root = $el.closest('.fi-modal-window') ?? $el.closest('form') ?? document;
                const t = root.querySelector('textarea[data-sms-body]') ?? document.querySelector('textarea[data-sms-body]');
                if (! t) return;
                const v = @js('{'.$key.'}');
                const s = t.selectionStart ?? t.value.length, e = t.selectionEnd ?? s;
                t.value = t.value.slice(0, s) + v + t.value.slice(e);
                t.focus();
                t.setSelectionRange(s + v.length, s + v.length);
                t.dispatchEvent(new Event('input', { bubbles: true }));
            ">{{ '{'.$key.'}' }} <span style="opacity:.65">{{ $label }}</span></button>
    @endforeach
</div>
