@extends('mail.layout')

@section('content')
    <tr>
        <td>
            @if (isset($content))
                <div style="margin:0 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#1f2937;">{!! $content !!}</div>
            @endif
            @if (isset($password))
                @if (! isset($created_at))
                    <p style="margin:0 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#1f2937;">{{ __('mgs.mail.auto-password-line') }}</p>
                    <div style="margin:0 0 16px;padding:14px 16px;background-color:#f3f4f6;border-radius:6px;font-family:Consolas,Menlo,monospace;font-size:26px;line-height:32px;font-weight:bold;letter-spacing:4px;color:#111827;text-align:center;">{{ $password }}</div>
                    <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:20px;color:#6b7280;">{{ __('mgs.mail.auto-password-note') }}</p>
                @else
                    <p style="margin:0 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#1f2937;">{{ __('mgs.mail.auto-password-hidden') }}</p>
                @endif
            @endif
        </td>
    </tr>
@endsection
