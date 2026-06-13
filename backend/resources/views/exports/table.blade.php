@extends('exports.layout')

@section('content')
    <table class="data">
        <thead>
            <tr>
                @foreach($headers as $header)
                    <th>{{ $header }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
                <tr>
                    @foreach($row as $cell)
                        <td>{{ is_int($cell) || is_float($cell) ? number_format((float) $cell, 3, '.', ',') : $cell }}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($headers) }}" class="empty">لا توجد بيانات</td>
                </tr>
            @endforelse
        </tbody>
    </table>
@endsection
