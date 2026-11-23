@extends('mail.layout')

@section('content')
    <tr>
        <td>
            <h1 style="margin:0 0 16px;font-family:Arial,Helvetica,sans-serif;font-size:20px;line-height:28px;font-weight:bold;color:#111827;">{{ $subTitle ?? '' }}</h1>
            <p style="margin:0 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#1f2937;">{{ __('mgs.register.verify_greeting', ['name' => $contactName ?? '']) }}</p>
            <p style="margin:0 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#1f2937;">{{ __('mgs.register.verify_intro') }}</p>
            @if (! empty($code))
                <p style="margin:0 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#1f2937;">{{ __('mgs.register.verify_code_label') }}:</p>
                <div style="margin:0 0 16px;padding:14px 16px;background-color:#f3f4f6;border-radius:6px;font-family:Consolas,Menlo,monospace;font-size:26px;line-height:32px;font-weight:bold;letter-spacing:4px;color:#111827;text-align:center;">{{ $code }}</div>
            @endif
            @if (! empty($url))
                <p style="margin:8px 0 12px;text-align:center;"><a href="{{ $url }}" target="_blank" rel="noopener" style="display:inline-block;background-color:#1f2937;color:#ffffff;text-decoration:none;padding:12px 22px;border-radius:6px;font-family:Arial,Helvetica,sans-serif;font-size:15px;font-weight:bold;">{{ __('mgs.register.verify_link_label') }}</a></p>
                <p style="margin:0 0 16px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;color:#6b7280;word-break:break-all;">{{ $url }}</p>
            @endif
            <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:20px;color:#6b7280;">{{ __('mgs.register.verify_ignore') }}</p>
            <p style="margin:6px 0 0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:20px;color:#6b7280;">{{ __('mgs.register.verify_expires') }}</p>
        </td>
    </tr>
@endsection
