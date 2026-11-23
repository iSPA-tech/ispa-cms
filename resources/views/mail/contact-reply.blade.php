@extends('mail.layout')

@section('content')
    <tr>
        <td style="margin:0 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#1f2937;">
            @if (isset($message_html))
                {!! $message_html !!}
            @endif
        </td>
    </tr>
@endsection
