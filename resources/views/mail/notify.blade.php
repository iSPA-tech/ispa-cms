@extends('mail.layout')

@section('content')
    @php($adminUrl = rtrim((string) config('app.url'), '/') . '/iPanel')
    <tr>
        <td>
            <h1 style="margin:0 0 16px;font-family:Arial,Helvetica,sans-serif;font-size:20px;line-height:28px;font-weight:bold;color:#111827;">{{ $subTitle ?? __('mgs.mail.new-contact-subject') }}</h1>
            <p style="margin:0 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#1f2937;">{{ __('mgs.mail.new-contact-intro', ['email' => $email ?? '']) }}</p>
            <p style="margin:0 0 14px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;color:#1f2937;">{{ __('mgs.mail.new-contact-admin-link') }} <a href="{{ $adminUrl }}" style="color:#1d4ed8;text-decoration:underline;">{{ $adminUrl }}</a></p>
        </td>
    </tr>
@endsection
