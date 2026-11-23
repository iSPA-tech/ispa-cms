@extends('mail.layout')

@section('content')
    <tr>
        <td>
            <h1 style="margin:0 0 16px;font-family:Arial,Helvetica,sans-serif;font-size:20px;line-height:28px;font-weight:bold;color:#111827;">{{ $subTitle ?? '' }}</h1>
            <p style="margin:0 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#1f2937;">{{ __('mgs.mail.reset-code-intro', ['name' => filled($user->name ?? null) ? trim((string) $user->name) : __('mgs.mail.default-recipient'), 'brand' => $brand ?? \App\Support\Mail\MailBrand::name()]) }}</p>
            @if ($code)
                <p style="margin:0 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#1f2937;">{{ __('mgs.mail.reset-code-label') }}</p>
                <div style="margin:0 0 16px;padding:14px 16px;background-color:#f3f4f6;border-radius:6px;font-family:Consolas,Menlo,monospace;font-size:26px;line-height:32px;font-weight:bold;letter-spacing:4px;color:#111827;text-align:center;">{{ $code }}</div>
            @endif
            <p style="margin:0;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:20px;color:#6b7280;">{{ __('mgs.mail.reset-code-ignore') }}</p>
        </td>
    </tr>
@endsection
