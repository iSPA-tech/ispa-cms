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
              Xin chào {{ $user->name  }}. Bạn hoặc ai đó đã vừa yêu cầu khôi phục mật khẩu tài khoản của bạn tại {{ $brand }}.
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
          <td class="" style="font-size: 16px; font-family: Helvetica Neue, Arial, sans-serif; color: #969696;">
            Dưới đây là mã của bạn:
          </td>
        </tr>
        <tr>
          <td class="" style="padding-top: 5px; font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #3C4043; text-align: center; line-height: 1.2em; font-weight: 500;">
            {{ $code ?? '' }}
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
          <td class="" style="font-size: 13px; font-family: Helvetica Neue, Arial, sans-serif; color: #969696; text-align: left; font-weight: bold; padding-bottom: 5px;">
            Nếu bạn không thực hiện yêu cầu này, vui lòng bỏ qua email này. Hoặc click vào đường dẫn bên dưới để hệ thống tự động chặn các yêu cầu tương tự. Xin cảm ơn.
          </td>
        </tr>
      </table>
    </td>
  </tr>
@endsection
