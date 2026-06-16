@extends('exports.layout')

@section('content')
    @if(!empty($summary))
        <table class="summary-table">
            @if(count($summary) <= 3)
                <tr>
                    @foreach($summary as $item)
                        <td>
                            <div class="summary-label">{{ $item['label'] }}</div>
                            <div class="summary-value">{!! nl2br(e($item['value'])) !!}</div>
                        </td>
                    @endforeach
                </tr>
            @else
                @foreach($summary as $item)
                    <tr>
                        <td style="width:45%">
                            <div class="summary-label">{{ $item['label'] }}</div>
                        </td>
                        <td>
                            <div class="summary-value">{!! nl2br(e($item['value'])) !!}</div>
                        </td>
                    </tr>
                @endforeach
            @endif
        </table>
    @endif

    @if(!empty($fields))
        <div class="fields">
            @foreach($fields as $field)
                <div class="field-row">
                    <div class="field-label">{{ $field['label'] }}</div>
                    <div class="field-value">{{ $field['value'] }}</div>
                </div>
            @endforeach
        </div>
    @endif

    @if(!empty($sections))
        @foreach($sections as $section)
            <div class="section-title">{{ $section['title'] }}</div>
            <table class="data">
                <thead>
                    <tr>
                        @foreach($section['headers'] as $header)
                            <th>{{ $header }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($section['rows'] as $row)
                        <tr>
                            @foreach($row as $cell)
                                <td>{{ is_int($cell) || is_float($cell) ? number_format((float) $cell, 3, '.', ',') : $cell }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr>
                            <td colspan="{{ count($section['headers']) }}" class="empty">لا توجد بيانات</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        @endforeach
    @endif
@endsection
