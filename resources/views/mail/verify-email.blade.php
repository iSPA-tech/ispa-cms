@extends('mail.layout')

@section('content')
    <tr>
        <td align="center" style="background-color: white; padding-top: 25px; padding-bottom: 0;">
            <table width="90%" border="0" align="center" cellpadding="0" cellspacing="0">
                <tr>
                    <td class="padding: 0; margin: 0">
                        <h1 class="h1m"
                            style="font-size: 22px; line-height: 28px;letter-spacing: -.20px; margin: 10px 0 16px 0; font-family: Helvetica Neue, Arial, sans-serif; color: #ae006d; text-align: left;">
                            {{ $subTitle ?? $subject ?? '' }}
                        </h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 0; margin: 0">
                        <div
                            style="margin: 0 0 15px 0 ; font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #3C4043; text-align: left; line-height: 24px;">
                            Thank you for registering a {{ $brand ?? '' }} account.
                            In order to activate your account, please click
                            <a href="{{ $url ?? '#' }}" style="color: rgba(219, 39, 119, 1)">this link</a>
                            or paste the following address in your browser:
                        </div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    @if ($uid)
        <tr>
            <td align="center" style="background-color: white; padding-top: 15px; padding-bottom: 10px;">
                <table width="90%" border="0" align="center" cellpadding="0" cellspacing="0">
                    <tr>
                        <td class=""
                            style="font-size: 16px; font-family: Helvetica Neue, Arial, sans-serif; color: #969696;">
                            This is your ID, please use it to login:
                        </td>
                    </tr>
                    <tr>
                        <td class=""
                            style="padding-top: 5px; font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #111; text-align: center; line-height: 1.2em; font-weight: 500;">
                            {{ $uid ?? 'N/A' }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    @endif
    @if ($url)
        <tr>
            <td align="center" style="background-color: white; padding-top: 15px; padding-bottom: 10px;">
                <table width="90%" border="0" align="center" cellpadding="0" cellspacing="0">
                    <tr>
                        <td class=""
                            style="font-size: 16px; font-family: Helvetica Neue, Arial, sans-serif; color: #969696;">
                            Please click into this link below to activate your email address.
                        </td>
                    </tr>
                    <tr>
                        <td class=""
                            style="padding-top: 5px; font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #3C4043; text-align: center; line-height: 1.2em; font-weight: 500;">
                            {{ $url ?? '' }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    @endif
    <tr>
        <td align="center" style="background-color: white; padding-top: 10px; padding-bottom: 10px;">
            <table width="90%" border="0" align="center" cellpadding="0" cellspacing="0">
                <tr>
                    <td class=""
                        style="font-size: 13px; font-family: Helvetica Neue, Arial, sans-serif; color: #969696; text-align: left; font-weight: bold; padding-bottom: 5px;">
                        If this is not your request, please ignore this email, your account will be automatically
                        deleted after 30 days.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
@endsection
