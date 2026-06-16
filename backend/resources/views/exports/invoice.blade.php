@extends('exports.layout')

@section('content')
    <table class="invoice-meta">
        <tr>
            <td class="invoice-meta-block">
                <div class="meta-heading">فاتورة إلى / Bill To</div>
                <div class="meta-name">{{ $client['name'] ?? '' }}</div>
                @if(!empty($client['phone']))
                    <div class="meta-line">هاتف: {{ $client['phone'] }}</div>
                @endif
                @if(!empty($client['civil_id']))
                    <div class="meta-line">الرقم المدني: {{ $client['civil_id'] }}</div>
                @endif
                @if(!empty($client['email']))
                    <div class="meta-line">{{ $client['email'] }}</div>
                @endif
            </td>
            <td class="invoice-meta-block invoice-meta-right">
                <div class="meta-heading">تفاصيل الفاتورة / Invoice Details</div>
                <div class="meta-line"><strong>رقم الفاتورة:</strong> {{ $invoice['ref'] ?? '' }}</div>
                <div class="meta-line"><strong>التاريخ:</strong> {{ $invoice['date'] ?? '' }}</div>
                <div class="meta-line"><strong>الحالة:</strong> {{ $invoice['status'] ?? '' }}</div>
                <div class="meta-line"><strong>العملة:</strong> {{ $currency_label ?? $currency_code ?? $currency ?? '' }}</div>
            </td>
        </tr>
    </table>

    <div class="section-title">بنود الفاتورة / Line Items</div>
    <table class="data">
        <thead>
            <tr>
                <th style="width:55%">الوصف / Description</th>
                <th>المبلغ / Amount ({{ $currency ?? $currency_symbol ?? '' }})</th>
            </tr>
        </thead>
        <tbody>
            @foreach($line_items ?? [] as $item)
                <tr>
                    <td>
                        <strong>{{ $item['description'] ?? '' }}</strong>
                        @if(!empty($item['details']))
                            <div class="item-details">{{ $item['details'] }}</div>
                        @endif
                    </td>
                    <td>{{ number_format((float) ($item['amount'] ?? 0), 3, '.', ',') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if(!empty($summary))
        <table class="summary-table invoice-totals">
            <tr>
                @foreach($summary as $item)
                    <td>
                        <div class="summary-label">{{ $item['label'] }}</div>
                        <div class="summary-value">{{ $item['value'] }}</div>
                    </td>
                @endforeach
            </tr>
        </table>
    @endif

    @if(!empty($payment_method))
        <div class="invoice-note"><strong>طريقة الدفع:</strong> {{ $payment_method }}</div>
    @endif

    @if(!empty($receipts))
        <div class="section-title">سندات القبض / Receipts</div>
        <table class="data">
            <thead>
                <tr>
                    <th>الرقم</th>
                    <th>التاريخ</th>
                    <th>الطريقة</th>
                    <th>المبلغ</th>
                </tr>
            </thead>
            <tbody>
                @foreach($receipts as $receipt)
                    <tr>
                        <td>{{ $receipt['ref'] ?? '' }}</td>
                        <td>{{ $receipt['date'] ?? '' }}</td>
                        <td>{{ $receipt['method'] ?? '' }}</td>
                        <td>{{ $receipt['amount'] ?? '' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    @if(!empty($notes))
        <div class="invoice-note"><strong>ملاحظات:</strong> {{ $notes }}</div>
    @endif

    <div class="invoice-footer">
        شكراً لتعاملكم معنا — Thank you for your business
    </div>

    <style>
        .invoice-meta { width: 100%; border-collapse: collapse; margin-bottom: 18px; }
        .invoice-meta-block { vertical-align: top; width: 50%; padding: 10px 12px; border: 1px solid #e2e8f0; background: #f8fafc; }
        .invoice-meta-right { border-right: none; }
        .meta-heading { font-size: 9pt; color: #64748b; margin-bottom: 6px; font-weight: bold; }
        .meta-name { font-size: 12pt; font-weight: bold; color: #1e3a8a; margin-bottom: 4px; }
        .meta-line { font-size: 9pt; color: #334155; margin-top: 2px; }
        .item-details { font-size: 8pt; color: #64748b; margin-top: 4px; }
        .invoice-totals { margin-top: 14px; }
        .invoice-note { margin-top: 12px; font-size: 9pt; color: #475569; padding: 8px 10px; background: #f8fafc; border: 1px solid #e2e8f0; }
        .invoice-footer { margin-top: 24px; text-align: center; font-size: 10pt; color: #64748b; padding-top: 12px; border-top: 1px solid #e2e8f0; }
    </style>
@endsection
