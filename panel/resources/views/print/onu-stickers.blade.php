@php
    $qr = $settings->qrSvg();
@endphp
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('ONU স্টিকার') }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        /* same size as the old app's label: 50 × 75 mm, 2 mm inside margin, one sticker per page */
        @page { size: 50mm 75mm; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; color: #000; }
        body { font-family: 'Noto Sans Bengali', Arial, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .s { width: 50mm; height: 75mm; padding: 2mm; overflow: hidden; display: flex; flex-direction: column; align-items: center;
             justify-content: space-between; text-align: center; page-break-after: always; break-after: page; }
        .s:last-child { page-break-after: auto; break-after: auto; }
        .top { width: 100%; }
        .name { font-size: 15px; font-weight: 700; line-height: 1.15; }
        .help { font-size: 9.5px; font-weight: 600; border-bottom: 1px solid #000; padding-bottom: 1mm; width: 100%; }
        .qr svg { width: 27mm; height: 27mm; display: block; margin: 0 auto; }
        .qr svg .dark, .qr svg rect, .qr svg path { fill: #000; }
        .note { font-size: 9.5px; font-weight: 700; line-height: 1.2; margin-top: 1mm; }
        .info { border-top: 1px solid #000; padding-top: 1mm; width: 100%; font-size: 11.5px; line-height: 1.35; }
        .info .id { font-size: 14px; font-weight: 700; }
        .info .mac { font-family: 'Courier New', monospace; font-weight: 700; font-size: 12px; word-break: break-all; }
        @media screen {
            body { background: #f3f4f6; }
            .s { background: #fff; margin: 10px auto; box-shadow: 0 1px 4px rgba(0,0,0,.2); }
            .bar { text-align: center; margin: 10px; font-family: sans-serif; }
            .bar button { font-size: 14px; padding: 6px 14px; margin: 0 4px; cursor: pointer; }
        }
        @media print { .bar { display: none; } }
    </style>
</head>
<body>
<div class="bar"><button onclick="window.print()">🖨️ {{ __('প্রিন্ট করুন') }}</button><button onclick="window.close()">{{ __('বন্ধ করুন') }}</button></div>
@foreach ($rows as $r)
    <div class="s">
        <div class="top">
            <div class="name">{{ $settings->company_name ?: $company->name }}</div>
            @if ($settings->mobile)<div class="help">Helpline: {{ str_replace(',', ' | ', $settings->mobile) }}</div>@endif
        </div>
        @if ($qr)
            <div><div class="qr">{!! $qr !!}</div>
            @if ($settings->qr_note)<div class="note">{{ $settings->qr_note }}</div>@endif</div>
        @endif
        <div class="info">
            <div class="id">Customer ID: {{ $r->isOther() ? '—' : $r->customer_code }}</div>
            <div>{{ $r->brand?->name }}</div>
            <div class="mac">{{ $r->macLabel() }}</div>
            <div>{{ $r->assigned_at->timezone('Asia/Dhaka')->format('d M Y, h:i A') }}</div>
        </div>
    </div>
@endforeach
@if ($autoPrint)
    <script>window.addEventListener('load', () => (document.fonts ? document.fonts.ready : Promise.resolve()).then(() => setTimeout(() => window.print(), 200)));</script>
@endif
</body>
</html>
