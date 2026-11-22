@extends('mail.layout')

@section('content')
  <tr>
    <td align="center" style="background-color: white; padding-top: 25px; padding-bottom: 0;">
      <table width="90%" border="0" align="center" cellpadding="0" cellspacing="0">
        <tr>
          <td class="padding: 0; margin: 0">
            <h1 class="h1m" style="font-size: 22px; line-height: 28px;letter-spacing: -.20px; margin: 10px 0 16px 0; font-family: Helvetica Neue, Arial, sans-serif; color: #ae006d; text-align: left;">
              {{ $subTitle ?? $subject ?? '' }}
            </h1>
          </td>
        </tr>
        <tr>
          <td style="padding: 0; margin: 0">
            <div style="margin: 0 0 15px 0 ; font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #3C4043; text-align: left; line-height: 24px;">
              <p>Your {{ $brand ?? '' }} account's password has been changed. If you didn't make this request, please try to reset your password. If you require more assistance you can contact our support team at {{ $support ?? '' }}</p>

              <p style="padding-top: 2rem;">If you lose your password, you lose your data. Please remember your password and improve your security level.</p>
            </div>
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <tr>
    <td align="center" style="background-color: white; padding-top: 10px; padding-bottom: 10px;">
      <table width="90%" border="0" align="center" cellpadding="0" cellspacing="0">
        <tr>
          <td class="" style="font-size: 13px; font-family: Helvetica Neue, Arial, sans-serif; color: #969696; text-align: left; font-weight: bold; padding-bottom: 5px;">
            This is your information changes.
          </td>
        </tr>
      </table>
    </td>
  </tr>

  <tr>
    <td align="center" style="background-color: white; padding-top: 0; padding-bottom: 20px;">
      <table width="90%" border="0" align="center" cellpadding="0" cellspacing="0">
        <tr>
          <td class="" style="padding-top: 5px; padding-bottom: 5px; font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #3C4043; text-align: left; line-height: 1.55em;" width="50%">
            <div style="color:#3C4043;margin:0px;font-size:12px;line-height:22px;  font-weight: normal; font-size: 15px; padding-right: 10px;">
              Platform
            </div>
          </td>
          <td class="" style="padding-top: 5px; padding-bottom: 5px; font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #3C4043; text-align: left; line-height: 1.55em;" width="50%">
            <div style="color:#3C4043;margin:0px;font-size:12px;line-height:22px;  font-weight: normal; font-size: 15px;">
              {{ $platform ?? 'N/A' }}
            </div>
          </td>
        </tr>
        <tr>
          <td class="" style="padding-top: 5px; padding-bottom: 5px; font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #3C4043; text-align: left; line-height: 1.55em;" width="50%">
            <div style="color:#3C4043;margin:0px;font-size:12px;line-height:22px;  font-weight: normal; font-size: 15px; padding-right: 10px;">
              Device
            </div>
          </td>
          <td class="" style="padding-top: 5px; padding-bottom: 5px; font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #3C4043; text-align: left; line-height: 1.55em;" width="50%">
            <div style="color:#3C4043;margin:0px;font-size:12px;line-height:22px;  font-weight: normal; font-size: 15px;">
              {{ $device ?? 'N/A' }}
            </div>
          </td>
        </tr>
        <tr>
          <td class="" style="padding-top: 5px; padding-bottom: 5px; font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #3C4043; text-align: left; line-height: 1.55em;" width="50%">
            <div style="color:#3C4043;margin:0px;font-size:12px;line-height:22px;  font-weight: normal; font-size: 15px; padding-right: 10px;">
              Ip Address
            </div>
          </td>
          <td class="" style="padding-top: 5px; padding-bottom: 5px; font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #3C4043; text-align: left; line-height: 1.55em;" width="50%">
            <div style="color:#3C4043;margin:0px;font-size:12px;line-height:22px;  font-weight: normal; font-size: 15px;">
              {{ $ipAddress ?? 'N/A' }}
            </div>
          </td>
        </tr>
        <tr>
          <td class="" style="padding-top: 5px; padding-bottom: 5px; font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #3C4043; text-align: left; line-height: 1.55em;" width="50%">
            <div style="color:#3C4043;margin:0px;font-size:12px;line-height:22px;  font-weight: normal; font-size: 15px; padding-right: 10px;">
              Changed at
            </div>
          </td>
          <td class="" style="padding-top: 5px; padding-bottom: 5px; font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #3C4043; text-align: left; line-height: 1.55em;" width="50%">
            <div style="color:#3C4043;margin:0px;font-size:12px;line-height:22px;  font-weight: normal; font-size: 15px;">
              {{ $time ?? 'N/A' }}
            </div>
          </td>
        </tr>
      </table>
    </td>
  </tr>
@endsection
