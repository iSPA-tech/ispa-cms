@extends('mail.layout')

@section('content')
    @php($cell = 'padding:6px 0;border-top:1px solid #e5e7eb;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:20px;')
    <tr>
        <td>
            <h1 style="margin:0 0 16px;font-family:Arial,Helvetica,sans-serif;font-size:20px;line-height:28px;font-weight:bold;color:#111827;">{{ __('mgs.mail.password-changed-subject') }}</h1>
            <p style="margin:0 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#1f2937;">{{ __('mgs.mail.password-changed-intro', ['brand' => $brand ?? \App\Support\Mail\MailBrand::name()]) }}</p>
            @if (! empty($support))
                <p style="margin:0 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#1f2937;">{{ __('mgs.mail.password-changed-support', ['support' => $support]) }}</p>
            @endif
            <p style="margin:8px 0 6px;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:20px;font-weight:bold;color:#6b7280;">{{ __('mgs.mail.password-changed-details') }}</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">
                <tr><td width="40%" style="{{ $cell }}color:#6b7280;">{{ __('mgs.mail.password-changed-platform') }}</td><td style="{{ $cell }}color:#111827;">{{ $platform ?? '-' }}</td></tr>
                <tr><td width="40%" style="{{ $cell }}color:#6b7280;">{{ __('mgs.mail.password-changed-device') }}</td><td style="{{ $cell }}color:#111827;">{{ $device ?? '-' }}</td></tr>
                <tr><td width="40%" style="{{ $cell }}color:#6b7280;">{{ __('mgs.mail.password-changed-ip') }}</td><td style="{{ $cell }}color:#111827;">{{ $ipAddress ?? '-' }}</td></tr>
                <tr><td width="40%" style="{{ $cell }}color:#6b7280;">{{ __('mgs.mail.password-changed-at') }}</td><td style="{{ $cell }}color:#111827;">{{ $time ?? '-' }}</td></tr>
            </table>
        </td>
    </tr>
@endsection
