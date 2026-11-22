@extends('mail.layout')

@section('content')
    <tr>
        <td align="center" style="background-color: white; padding-top: 25px; padding-bottom: 0;">
            <table width="90%" border="0" align="center" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="padding: 0; margin: 0">
                        <h1 style="font-size: 22px; line-height: 28px; letter-spacing: -.20px; margin: 10px 0 16px 0; font-family: Helvetica Neue, Arial, sans-serif; color: #ae006d; text-align: left;">
                            {{ $subTitle ?? $subject ?? '' }}
                        </h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 0; margin: 0">
                        <div style="margin: 0 0 15px 0; font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #3C4043; text-align: left; line-height: 24px;">
                            {{ __('mgs.register.verify_greeting', ['name' => $contactName ?? '']) }}
                        </div>
                        <div style="margin: 0 0 15px 0; font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #3C4043; text-align: left; line-height: 24px;">
                            {{ __('mgs.register.verify_intro') }}
                        </div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Verification Code --}}
    @if (!empty($code))
    <tr>
        <td align="center" style="background-color: white; padding-top: 10px; padding-bottom: 10px;">
            <table width="90%" border="0" align="center" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #969696;">
                        {{ __('mgs.register.verify_code_label') }}:
                    </td>
                </tr>
                <tr>
                    <td style="padding-top: 8px; padding-bottom: 8px; font-size: 32px; font-family: Helvetica Neue, Arial, sans-serif; color: #111; text-align: center; line-height: 1.2em; font-weight: 700; letter-spacing: 8px;">
                        {{ $code }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    @endif

    {{-- Verify Button --}}
    @if (!empty($url))
    <tr>
        <td align="center" style="background-color: white; padding-top: 10px; padding-bottom: 5px;">
            <table border="0" align="center" cellpadding="0" cellspacing="0">
                <tr>
                    <td align="center" style="background-color: rgba(6,182,212,1); border-radius: 6px;">
                        <a href="{{ $url }}" target="_blank"
                           style="display: inline-block; padding: 12px 32px; font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #ffffff; text-decoration: none; font-weight: 600;">
                            {{ __('mgs.register.verify_link_label') }}
                        </a>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    <tr>
        <td align="center" style="background-color: white; padding-top: 10px; padding-bottom: 10px;">
            <table width="90%" border="0" align="center" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="font-size: 12px; font-family: Helvetica Neue, Arial, sans-serif; color: #969696; word-break: break-all;">
                        {{ $url }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
    @endif

    {{-- Ignore Notice + Expiry --}}
    <tr>
        <td align="center" style="background-color: white; padding-top: 10px; padding-bottom: 10px;">
            <table width="90%" border="0" align="center" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="font-size: 13px; font-family: Helvetica Neue, Arial, sans-serif; color: #969696; text-align: left; padding-bottom: 5px;">
                        {{ __('mgs.register.verify_ignore') }}
                    </td>
                </tr>
                <tr>
                    <td style="font-size: 13px; font-family: Helvetica Neue, Arial, sans-serif; color: #969696; text-align: left; font-weight: bold; padding-bottom: 5px;">
                        {{ __('mgs.register.verify_expires') }}
                    </td>
                </tr>
            </table>
        </td>
    </tr>
@endsection
