@php
    $amt = fn ($v) => number_format((float) $v, 2, '.', '');
    $payable = (float) ($c->payable ?? 0);
    $due = max(0, round($payable - (float) $c->paid - (float) $c->discount, 2));
    $advance = max(0, round((float) $c->paid + (float) $c->discount - $payable, 2));
    $qr = $settings->qrSvg();
@endphp
<!DOCTYPE html>
<html lang="bn">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Money Receipt {{ $receiptNo }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        @page { size: {{ $width }}mm 210mm; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; color: #000; width: {{ $width }}mm; }
        body { font-family: 'Noto Sans Bengali', Arial, sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .r { width: {{ $width == 58 ? 50 : 72 }}mm; margin: 0 auto; padding: 2mm 0 3mm; page-break-inside: avoid; font-size: {{ $width == 58 ? '11px' : '13.5px' }}; line-height: 1.35; }
        .qr { display: flex; align-items: center; gap: 2.5mm; margin-bottom: 2.5mm; }
        .qr svg { width: {{ $width == 58 ? '18mm' : '24mm' }}; height: auto; flex: none; }
        .qr svg .dark, .qr svg rect, .qr svg path { fill: #000; }
        .qr .note { font-size: 1.25em; font-weight: 700; line-height: 1.3; }
        .head { text-align: center; }
        .head .name { font-size: 1.45em; font-weight: 700; }
        .head .mob { font-weight: 700; font-size: 1.1em; }
        .title { border-top: 1.5px solid #000; border-bottom: 1.5px solid #000; text-align: center; font-size: 1.9em; font-weight: 700;
                 letter-spacing: .5px; margin: 2mm 0 2.5mm; padding: .5mm 0; }
        .box { background: #e6e6e6; border-radius: 3mm; padding: 1.2mm 2.5mm; margin-bottom: 1.8mm; }
        .box table { width: 100%; border-collapse: collapse; }
        .box td { padding: .6mm 0; vertical-align: top; }
        .kv td:first-child { font-weight: 700; white-space: nowrap; padding-right: 2mm; width: 1%; }
        .kv td + td { border-left: 1.5px solid #000; padding-left: 2mm; border-bottom: 1px solid #000; word-break: break-all; }
        .kv tr:last-child td + td { border-bottom: 0; }
        .money td { border-bottom: 1px solid #000; }
        .money tr:last-child td { border-bottom: 0; }
        .money td:first-child { font-weight: 700; }
        .money td + td { text-align: right; }
        .by { display: flex; justify-content: space-between; gap: 2mm; }
        .by b { white-space: nowrap; }
        .by span { border-left: 1.5px solid #000; padding-left: 2mm; text-align: center; flex: 1; }
        .dots { border-top: 2px dotted #000; margin: 2.5mm 0 2mm; }
        .foot { white-space: pre-line; text-align: {{ $width == 58 ? 'left' : 'justify' }}; }
        @media screen { html, body { width: auto; } body { background: #f3f4f6; } .r { background: #fff; margin: 8px auto; padding: 4mm {{ $width == 58 ? 6 : 7 }}mm; box-shadow: 0 1px 4px rgba(0,0,0,.15); } }
        @media print { .noprint { display: none; } }
    </style>
</head>
<body>
<div class="r">
    @if ($qr)
        <div class="qr">{!! $qr !!}<div class="note">{{ $settings->qr_note }}</div></div>
    @endif
    <div class="head">
        <div class="name">{{ $settings->company_name ?: $company->name }}</div>
        @if ($settings->address)<div>{{ $settings->address }}</div>@endif
        @if ($settings->email)<div>{{ $settings->email }}</div>@endif
        @if ($settings->mobile)<div class="mob">Mobile: {{ $settings->mobile }}</div>@endif
    </div>
    <div class="title">MONEY RECEIPT</div>

    <div class="box"><table class="kv">
        <tr><td>Client Code</td><td>{{ $c->customer_id }}</td></tr>
        <tr><td>Client Name</td><td>{{ $customer?->name ?? $c->customer_name }}</td></tr>
        <tr><td>User Name</td><td>{{ $c->username ?? $customer?->username }}</td></tr>
        <tr><td>Mobile No.</td><td>{{ $customer?->mobile ?? ($c->mobile ?? '—') }}</td></tr>
    </table></div>

    <div class="box"><table class="kv">
        <tr><td>Receipt No.</td><td>{{ $receiptNo }}</td></tr>
        <tr><td>Pay. Date</td><td>{{ $c->created_at->timezone('Asia/Dhaka')->format('d M Y, h:i A') }}</td></tr>
        <tr><td>P. Method</td><td>{{ $c->payment_method ?: '—' }}</td></tr>
    </table></div>

    <div class="box"><table class="money">
        <tr><td>Total Bill:</td><td>{{ $amt($payable) }}</td></tr>
        <tr><td>Paid Amount:</td><td>{{ $amt($c->paid) }}</td></tr>
        <tr><td>Discount:</td><td>{{ $amt($c->discount) }}</td></tr>
        <tr><td>Due Amount:</td><td>{{ $amt($due) }}</td></tr>
        <tr><td>VAT Amount:</td><td>{{ $amt(0) }}</td></tr>
        <tr><td>Advance:</td><td>{{ $amt($advance) }}</td></tr>
    </table></div>

    <div class="box by"><b>Payment Received By</b><span>{{ $c->received_by ?: '—' }}</span></div>

    @if (filled($settings->footer))
        <div class="dots"></div>
        <div class="foot">{{ trim($settings->footer) }}</div>
    @endif
</div>
@if ($autoPrint)
    <script>window.addEventListener('load', () => (document.fonts ? document.fonts.ready : Promise.resolve()).then(() => setTimeout(() => window.print(), 200)));</script>
@endif
</body>
</html>
