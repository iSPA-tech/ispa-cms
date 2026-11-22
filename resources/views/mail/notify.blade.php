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
              <p>Xin chào Admin. Vừa có một khách hàng với địa chỉ email "{{ $email ?? '' }}" đã gửi cho bạn một liên hệ mới. Vui lòng vào admin để kiểm tra.</p>
              <p>Đường dẫn admin nếu bạn chưa thể nhớ: <a href="{{ rtrim(config('app.url'), '/') }}/iPanel">{{ rtrim(config('app.url'), '/') }}/iPanel</a></p>
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
            Email được gửi tự động bởi {{ $brand ?? config('setting.brand') }}.
          </td>
        </tr>
      </table>
    </td>
  </tr>
@endsection
