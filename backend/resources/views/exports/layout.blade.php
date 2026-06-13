<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>{{ $title ?? 'تصدير' }}</title>
    <style>
        body {
            font-family: xbriyaz, sans-serif;
            font-size: 11pt;
            color: #1e293b;
            direction: rtl;
            text-align: right;
        }
        .header {
            width: 100%;
            margin-bottom: 16px;
            padding-bottom: 10px;
            border-bottom: 2px solid #1E3A8A;
        }
        .header-table { width: 100%; border-collapse: collapse; }
        .header-table td { vertical-align: top; border: none; padding: 0; }
        .header-meta { text-align: left; color: #64748b; font-size: 9pt; width: 30%; }
        .logo { max-height: 48px; max-width: 120px; margin-bottom: 6px; }
        .office-name { font-size: 16pt; font-weight: bold; color: #1e3a8a; }
        .office-code { font-size: 9pt; color: #64748b; margin-top: 2px; }
        .title { font-size: 14pt; font-weight: bold; color: #1e3a8a; margin-top: 6px; }
        .subtitle { font-size: 9pt; color: #64748b; margin-top: 4px; }
        table.data {
            width: 100%;
            border-collapse: collapse;
            direction: rtl;
        }
        table.data th {
            background: #1E3A8A;
            color: #ffffff;
            padding: 7px 8px;
            text-align: right;
            font-size: 9pt;
            border: 1px solid #1E3A8A;
        }
        table.data td {
            padding: 6px 8px;
            border: 1px solid #e2e8f0;
            text-align: right;
            font-size: 9pt;
            vertical-align: top;
        }
        table.data tr:nth-child(even) td { background: #f8fafc; }
        .summary-table { width: 100%; border-collapse: collapse; margin-bottom: 14px; }
        .summary-table td {
            text-align: center;
            padding: 8px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        .summary-label { font-size: 8pt; color: #64748b; }
        .summary-value { font-size: 12pt; font-weight: bold; color: #1e3a8a; margin-top: 4px; }
        .fields { margin-bottom: 14px; }
        .field-row { padding: 5px 0; border-bottom: 1px solid #f1f5f9; }
        .field-label { color: #64748b; font-size: 9pt; }
        .field-value { font-weight: bold; margin-top: 2px; font-size: 10pt; }
        .section-title { font-size: 12pt; font-weight: bold; color: #1e3a8a; margin: 14px 0 8px; }
        .empty { text-align: center; padding: 24px; color: #64748b; }
        @stack('styles')
    </style>
</head>
<body>
    <div class="header">
        <table class="header-table">
            <tr>
                <td>
                    @if(!empty($branding['logo_url']))
                        <img src="{{ $branding['logo_url'] }}" class="logo" alt="">
                    @endif
                    <div class="office-name">{{ $branding['office_name'] ?? '' }}</div>
                    <div class="office-code">{{ $branding['office_code'] ?? '' }}</div>
                    @if(!empty($title))
                        <div class="title">{{ $title }}</div>
                    @endif
                    @if(!empty($subtitle))
                        <div class="subtitle">{{ $subtitle }}</div>
                    @endif
                </td>
                <td class="header-meta">
                    {{ $generatedAt ?? '' }}<br>
                    @if(isset($rowCount))
                        {{ $rowCount }} سجل
                    @endif
                </td>
            </tr>
        </table>
    </div>
    @yield('content')
</body>
</html>
