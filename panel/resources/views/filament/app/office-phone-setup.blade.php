{{-- What to type into the office IP phone's SIP account (OfficePhoneResource "ফোনের সেটিং"). --}}
<div style="display:grid;grid-template-columns:auto 1fr;gap:6px 14px;align-items:center">
    <span style="opacity:.7">{{ __('SIP সার্ভার') }}</span><b style="user-select:all">{{ \App\Models\OfficePhone::SERVER }}</b>
    <span style="opacity:.7">{{ __('পোর্ট') }}</span><b style="user-select:all">{{ \App\Models\OfficePhone::PORT }}</b>
    <span style="opacity:.7">{{ __('ট্রান্সপোর্ট') }}</span><b>UDP</b>
    <span style="opacity:.7">{{ __('ইউজারনেম / Auth ID') }}</span><b style="user-select:all">{{ $phone->login() }}</b>
    <span style="opacity:.7">{{ __('পাসওয়ার্ড') }}</span><b style="user-select:all;font-family:monospace">{{ $phone->phone_password }}</b>
</div>
<p style="margin-top:12px;font-size:13px;opacity:.75">
    {{ __('ফোনের পুরনো pbx.bd অ্যাকাউন্টটা মুছবেন না, শুধু বন্ধ রাখুন (বা দ্বিতীয় অ্যাকাউন্ট করে রাখুন): আমাদের সার্ভারে সমস্যা হলে সেটা আবার চালু করলেই আগের মতো চলবে। ফোন শুধু অফিসের নেটওয়ার্ক থেকে রেজিস্টার হতে পারবে।') }}
</p>
