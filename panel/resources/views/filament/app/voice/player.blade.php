{{-- A voice template's audio: $url (mp3 or the uploaded recording) and/or $text (what is said, or why there is no audio). --}}
<div style="display:flex;flex-direction:column;gap:.5rem">
    @if ($url)
        <audio controls autoplay preload="auto" src="{{ $url }}" style="width:100%"></audio>
    @endif
    @if ($text)
        <div style="font-size:.85rem;opacity:.8;line-height:1.5">{{ $text }}</div>
    @endif
</div>
