<div x-data="{ copied: false }" style="display:flex; gap:10px; align-items:center">
    <code style="font-size:1.2rem; font-weight:700; padding:6px 12px; border-radius:8px; background:rgba(15,124,123,.1)">{{ $password }}</code>
    <button type="button" style="font-size:.8rem; color:#0f7c7b; font-weight:700"
            x-on:click="navigator.clipboard.writeText(@js($password)); copied = true; setTimeout(() => copied = false, 1500)">
        <span x-show="! copied">কপি</span><span x-show="copied">কপি হয়েছে</span>
    </button>
</div>
