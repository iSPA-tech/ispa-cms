@extends('mail.layout')

@section('content')
    <tr>
        <td align="center" style="background-color: white; padding-top: 25px; padding-bottom: 0;">
            <table width="90%" border="0" align="center" cellpadding="0" cellspacing="0">
                <tr>
                    <td style="padding: 0; margin: 0">
                        <div
                            style="margin: 0 0 15px 0 ; font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #3C4043; text-align: left; line-height: 24px;">
                            @if (isset($content))
                                {!! $content !!}
                            @endif
                            @if (isset($password))
                                @if (!isset($created_at))
                                    @if (isset($username))
                                        <p>This is your new password: {{ $password }} </p>
                                    @endif
                                    <p>This is your new password: {{ $password }} </p>
                                @else
                                    <p>Please check your new password in email app or website. We do not display your
                                        new password here.</p>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
@endsection
