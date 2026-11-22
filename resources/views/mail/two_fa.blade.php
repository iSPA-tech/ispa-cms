@extends('mail.layout')

@section('content')
  <tr>
    <td align="center" style="background-color: white; padding-top: 25px; padding-bottom: 0;">
      <table width="90%" border="0" align="center" cellpadding="0" cellspacing="0">
        <tr>
          <td style="padding: 0; margin: 0">
            <h1 class="h1m" style="font-size: 22px; line-height: 28px; letter-spacing: -.20px; margin: 10px 0 16px 0; font-family: Helvetica Neue, Arial, sans-serif; color: #0f172a; text-align: left;">
              {{ $subTitle ?? $subject ?? '' }}
            </h1>
          </td>
        </tr>
        <tr>
          <td style="padding: 0; margin: 0">
            <div style="margin: 0 0 15px 0; font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #3C4043; text-align: left; line-height: 24px;">
              {{ __('mgs.mail.two-fa-intro', ['name' => filled($user->name ?? null) ? trim((string) $user->name) : __('mgs.mail.default-recipient'), 'brand' => $brand]) }}
            </div>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  @if ($code)
    <tr>
      <td align="center" style="background-color: white; padding-top: 15px; padding-bottom: 10px;">
        <table width="90%" border="0" align="center" cellpadding="0" cellspacing="0">
          <tr>
            <td class="" style="font-size: 16px; font-family: Helvetica Neue, Arial, sans-serif; color: #64748b;">
              {{ __('mgs.mail.verification-code') }}
            </td>
          </tr>
          <tr>
            <td class="" style="padding-top: 8px; font-size: 24px; letter-spacing: 4px; font-family: Helvetica Neue, Arial, sans-serif; color: #0f172a; text-align: center; line-height: 1.2em; font-weight: 700;">
              {{ $code }}
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
          <td class="" style="font-size: 13px; font-family: Helvetica Neue, Arial, sans-serif; color: #64748b; text-align: left; font-weight: 500; padding-bottom: 5px;">
            {{ __('mgs.mail.two-fa-security') }}
          </td>
        </tr>
      </table>
    </td>
  </tr>
@endsection
