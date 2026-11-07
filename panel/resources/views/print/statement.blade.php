@php
    $amt = fn ($v) => number_format((float) $v, 2);
    $debit = collect($rows)->sum('debit');
    $credit = collect($rows)->sum('credit');
@endphp
<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('হিসাব বিবরণী') }} · {{ $c->customer_code }} · {{ $from->format('d M Y') }} - {{ $to->format('d M Y') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        @page { size: A4; margin: 12mm; }
        * { box-sizing: border-box; }
        body { font-family: 'Noto Sans Bengali', Arial, sans-serif; color: #111; margin: 0; padding: 16px; font-size: 12.5px; background: #fff; }
        header { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid #0f7c7b; padding-bottom: 8px; margin-bottom: 12px; }
        h1 { margin: 0; font-size: 19px; color: #0f7c7b; }
        h2 { margin: 0 0 2px; font-size: 15px; }
        .muted { color: #555; }
        .meta { text-align: right; line-height: 1.5; }
        .who { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 4px 16px; margin-bottom: 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #c4c9cf; padding: 5px 6px; text-align: left; vertical-align: top; }
        th { background: #e6f3f2; }
        td.n, th.n { text-align: right; white-space: nowrap; }
        tr { page-break-inside: avoid; }
        tr.sum td { font-weight: 700; background: #f5f7f8; }
        .bar { margin-bottom: 12px; display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
        .bar input { font: inherit; padding: 5px 8px; border: 1px solid #c4c9cf; border-radius: 8px; }
        .bar button { font: inherit; padding: 6px 14px; border: 0; border-radius: 8px; background: #0f7c7b; color: #fff; cursor: pointer; }
        .empty { padding: 24px; text-align: center; border: 1px dashed #9ca3af; }
        footer { margin-top: 14px; font-size: 11px; }
        @media print { .bar { display: none; } body { padding: 0; } }
        @media (max-width: 600px) { header { flex-direction: column; } .meta { text-align: left; } .wrap { overflow-x: auto; } }
    </style>
</head>
<body>
<form class="bar" method="get">
    <label>{{ __('থেকে') }} <input type="date" name="from" value="{{ $from->toDateString() }}"></label>
    <label>{{ __('পর্যন্ত') }} <input type="date" name="to" value="{{ $to->toDateString() }}"></label>
    <button type="submit">{{ __('দেখুন') }}</button>
    <button type="button" onclick="window.print()">{{ __('প্রিন্ট করুন') }}</button>
</form>
<header>
    <div>
        <h1>{{ $settings->company_name ?: $company->name }}</h1>
        @if ($settings->address)<div class="muted">{{ $settings->address }}</div>@endif
        @if ($settings->mobile)<div class="muted">{{ $settings->mobile }}</div>@endif
    </div>
    <div class="meta">
        <h2>{{ __('হিসাব বিবরণী') }}</h2>
        <div>{{ $from->format('d M Y') }} - {{ $to->format('d M Y') }}</div>
    </div>
</header>
<div class="who">
    <div>{{ __('ID') }}: <b>{{ $c->customer_code }}</b></div>
    <div>{{ __('নাম') }}: <b>{{ $c->name }}</b></div>
    <div>{{ __('ইউজারনেম') }}: {{ $c->username ?: '—' }}</div>
    <div>{{ __('মোবাইল') }}: {{ $c->mobile ?: '—' }}</div>
    <div>{{ __('মাসিক বিল') }}: {{ $amt($c->monthly_bill) }}</div>
</div>
<div class="wrap">
<table>
    <thead><tr><th>{{ __('তারিখ') }}</th><th>{{ __('বিবরণ') }}</th><th class="n">{{ __('বিল') }}</th><th class="n">{{ __('জমা/বাদ') }}</th><th class="n">{{ __('বকেয়া') }}</th></tr></thead>
    <tbody>
    <tr class="sum"><td>{{ $from->format('d M Y') }}</td><td>{{ __('শুরুর বকেয়া') }}</td><td></td><td></td><td class="n">{{ $amt($opening) }}</td></tr>
    @forelse ($rows as $r)
        <tr>
            <td>{{ $r['date']->format('d M Y') }}</td>
            <td>{{ __(\App\Support\Statement::KINDS[$r['kind']] ?? $r['kind']) }}@if ($r['detail']) <span class="muted">· {{ $r['detail'] }}</span>@endif</td>
            <td class="n">{{ $r['debit'] ? $amt($r['debit']) : '' }}</td>
            <td class="n">{{ $r['credit'] ? $amt($r['credit']) : '' }}</td>
            <td class="n">{{ $amt($r['balance']) }}</td>
        </tr>
    @empty
        <tr><td colspan="5" class="empty">{{ __('এই সময়ে খাতায় কিছু নেই') }}</td></tr>
    @endforelse
    <tr class="sum"><td>{{ $to->format('d M Y') }}</td><td>{{ __('শেষ বকেয়া') }}</td><td class="n">{{ $amt($debit) }}</td><td class="n">{{ $amt($credit) }}</td><td class="n">{{ $amt($closing) }}</td></tr>
    </tbody>
</table>
</div>
<footer class="muted">{{ __('বকেয়া ঋণাত্মক হলে তা অগ্রিম জমা। ছাপা হয়েছে :t', ['t' => now('Asia/Dhaka')->format('d M Y, g:i A')]) }}</footer>
@if ($autoPrint)
    <script>window.addEventListener('load', () => (document.fonts ? document.fonts.ready : Promise.resolve()).then(() => setTimeout(() => window.print(), 200)));</script>
@endif
</body>
</html>
