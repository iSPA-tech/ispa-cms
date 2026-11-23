@props([
    // Title of the HTML document (the mail subject); never shown in the body.
    'title' => null,
    // Link to the online copy of the mail (legacy mail log); printed above the card when given.
    'liveUrl' => null,
    // Brand name already resolved by the caller (legacy mail data); defaults to the site brand.
    'brand' => null,
])
@php
    // Shared frame of every transactional mail: logo (or brand name), a white card with the content, brand footer.
    // Inline styles only: mail clients drop <style> blocks and classes.
    $mailBrand = filled($brand) ? trim((string) $brand) : \App\Support\Mail\MailBrand::name();
    $mailLogo = \App\Support\Mail\MailBrand::logoUrl();
    $mailHome = rtrim((string) config('app.url'), '/') ?: '#';
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light">
    <meta name="supported-color-schemes" content="light">
    <title>{{ $title ?? $mailBrand }}</title>
</head>
<body style="margin:0;padding:0;width:100%;background-color:#f1f4f8;-webkit-text-size-adjust:none;-ms-text-size-adjust:none;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;background-color:#f1f4f8;">
    <tr>
        <td align="center" style="padding:24px 12px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:600px;">
                @if ($liveUrl)
                    <tr>
                        <td align="center" style="padding:0 0 12px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;color:#6b7280;">
                            {{ __('mgs.mail.cannot-view') }}
                            <a href="{{ $liveUrl }}" style="color:#374151;text-decoration:underline;">{{ __('mgs.mail.view-live') }}</a>
                        </td>
                    </tr>
                @endif
                <tr>
                    <td align="center" style="padding:8px 0 20px;">
                        <a href="{{ $mailHome }}" style="text-decoration:none;color:#111827;">
                            @if ($mailLogo)
                                <img src="{{ $mailLogo }}" alt="{{ $mailBrand }}" height="48" style="display:block;height:48px;width:auto;max-width:220px;border:0;outline:none;text-decoration:none;font-family:Arial,Helvetica,sans-serif;font-size:20px;font-weight:bold;color:#111827;">
                            @else
                                <span style="font-family:Arial,Helvetica,sans-serif;font-size:22px;line-height:28px;font-weight:bold;color:#111827;">{{ $mailBrand }}</span>
                            @endif
                        </a>
                    </td>
                </tr>
                <tr>
                    <td style="background-color:#ffffff;border:1px solid #e5e7eb;border-radius:8px;padding:28px 24px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#1f2937;text-align:left;word-wrap:break-word;">
                        {{ $slot }}
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:20px 8px 0;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;color:#6b7280;">
                        @isset($footer)
                            <div style="margin:0 0 6px;">{{ $footer }}</div>
                        @endisset
                        <div style="margin:0;">{{ __('mgs.mail.footer-received', ['brand' => $mailBrand]) }}</div>
                        <div style="margin:4px 0 0;">{{ __('mgs.mail.footer-copyright', ['year' => now()->year, 'brand' => $mailBrand]) }}</div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
