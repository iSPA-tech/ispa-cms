@extends('mail.layout')

@section('content')
    <tr>
        <td>
            <h1 style="margin:0 0 16px;font-family:Arial,Helvetica,sans-serif;font-size:20px;line-height:28px;font-weight:bold;color:#111827;">{{ __('mgs.mail.contact-received-hi', ['name' => ($name ?? $email ?? '')]) }}</h1>
            <p style="margin:0 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#1f2937;">{!! __('mgs.mail.contact-received-content') !!}</p>
        </td>
    </tr>
@endsection
