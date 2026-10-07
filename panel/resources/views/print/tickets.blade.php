<!doctype html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>টিকিট · {{ $employee }} · {{ $now->format('d M Y') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        @page { size: A4 landscape; margin: 10mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Noto Sans Bengali', Arial, sans-serif; color: #111; margin: 0; padding: 16px; font-size: 12px; background: #fff; }
        header { display: flex; justify-content: space-between; align-items: flex-end; border-bottom: 2px solid #0f7c7b; padding-bottom: 8px; margin-bottom: 10px; }
        h1 { margin: 0; font-size: 18px; color: #0f7c7b; }
        .sub { margin: 2px 0 0; font-size: 13px; }
        .meta { text-align: right; font-size: 12px; line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #9ca3af; padding: 5px 6px; vertical-align: top; text-align: left; }
        th { background: #e6f3f2; font-weight: 700; }
        tr { page-break-inside: avoid; }
        .num { width: 28px; text-align: center; }
        .done { width: 110px; }
        .muted { color: #555; }
        .high { font-weight: 700; color: #b91c1c; }
        footer { margin-top: 28px; display: flex; justify-content: space-between; font-size: 12px; }
        .sign { border-top: 1px solid #111; padding-top: 4px; width: 200px; text-align: center; }
        .bar { margin-bottom: 12px; display: flex; gap: 8px; }
        .bar button { font: inherit; padding: 6px 14px; border: 0; border-radius: 8px; background: #0f7c7b; color: #fff; cursor: pointer; }
        .empty { padding: 30px; text-align: center; border: 1px dashed #9ca3af; }
        @media print { .bar { display: none; } body { padding: 0; } }
    </style>
</head>
<body>
<div class="bar"><button onclick="window.print()">প্রিন্ট করুন</button></div>
<header>
    <div>
        <h1>{{ $company->name }}</h1>
        <p class="sub">কাজ চলছে এমন টিকিট · কর্মী: <b>{{ $employee }}</b></p>
    </div>
    <div class="meta">
        মোট টিকিট: <b>{{ $tickets->count() }}</b><br>
        প্রিন্টের সময়: {{ $now->format('d M Y, g:i A') }}
    </div>
</header>

@if ($tickets->isEmpty())
    <div class="empty">{{ $employee }}-এর নামে এখন কাজ চলছে এমন কোনো টিকিট নেই।</div>
@else
    <table>
        <thead>
        <tr>
            <th class="num">#</th>
            <th>টিকিট</th>
            <th>কাস্টমার</th>
            <th>মোবাইল (বিলিং)</th>
            <th>অভিযোগের নম্বর</th>
            <th>ঠিকানা (Zone / Subzone / Box)</th>
            <th>সমস্যা</th>
            <th>খোলা হয়েছে</th>
            <th>মন্তব্য</th>
            <th class="done">কাজের ফল / সই</th>
        </tr>
        </thead>
        <tbody>
        @foreach ($tickets as $i => $t)
            <tr>
                <td class="num">{{ $i + 1 }}</td>
                <td>#{{ $t->complain_id }}<br><span class="{{ $t->priority === 'high' ? 'high' : 'muted' }}">{{ \App\Models\BillingTicket::PRIORITIES[$t->priority] ?? '' }}</span></td>
                <td>{{ $t->customer_name }}<br><span class="muted">ID {{ $t->customer_id }}{{ $t->username ? ' · '.$t->username : '' }}</span></td>
                <td>{{ $t->mobile }}</td>
                <td>{{ $t->complainNumber() }}</td>
                <td>{{ collect([$t->zone, $t->subzone, $t->box])->filter()->join(' / ') }}</td>
                <td>{{ $t->category }}</td>
                <td>{{ $t->opened_at?->timezone('Asia/Dhaka')->format('d M, g:i A') }}<br><span class="muted">{{ $t->created_by }}</span></td>
                <td>{{ $t->note }}</td>
                <td class="done"></td>
            </tr>
        @endforeach
        </tbody>
    </table>
@endif

<footer>
    <div class="sign">কর্মীর সই</div>
    <div class="sign">দায়িত্বপ্রাপ্ত কর্মকর্তার সই</div>
</footer>
<script>window.addEventListener('load', () => setTimeout(() => window.print(), 400));</script>
</body>
</html>
