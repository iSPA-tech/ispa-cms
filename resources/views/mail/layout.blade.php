<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml"
>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <META NAME="ROBOTS" CONTENT="NOINDEX, NOFOLLOW">

    <title>{{ $subject ?? '' }}</title>

    <!--Leave Spaces 1019 characters for iOS Buffering-->

    <!--Leave Spaces 1019 characters for iOS Buffering-->
    <style type="text/css">
        .ExternalClass p,
        .ExternalClass span,
        .ExternalClass font,
        .ExternalClass td,
        .ExternalClass div {
            line-height: 100%;
        }

        table td {
            border-collapse: collapse;
            mso-table-lspace: 0pt;
            mso-table-rspace: 0pt;
        }

        h1,
        h2,
        h3,
        h4,
        h5,
        h6 {
            font-size: inherit;
            padding: 0;
            margin: 0;
            color: black;
            line-height: 100%;
        }

        .applelinks a {
            text-decoration: none;
            color: inherit !important;
        }

        a {
            color: black;
            text-decoration: none;
        }

        a,
        a:link,
        a:visited {
            color: inherit;
            text-decoration: none;
        }

        .gmailfix {
            display: none;
            display: none !important;
        }

        body {
            font-family: Arial, sans-serif;
            font-size: 14px;
            -webkit-text-size-adjust: none;
            -ms-text-size-adjust: none;
            -webkit-font-smoothing: antialiased;
            margin: 0;
            padding: 0;
        }

        @media only screen and (max-width: 480px) {
            .h2m {
                font-size: 14px !important;
            }

            .padt10m {
                padding-top: 10px !important;
            }

            .padb10m {
                padding-bottom: 10px !important;
            }
        }

        @media only screen and (max-width: 512px) {
            .w100 {
                width: 100% !important;
            }

            .minwidth {
                min-width: 100% !important;
            }

            .hide {
                display: none !important;
            }

            .resize img {
                width: 100% !important;
                height: auto !important;
            }
        }

        /* More style by Desmond */
        @media screen and (max-width: 600px) {
            .stack-column-center {
                display: block !important;
                width: 100% !important;
                max-width: 100% !important;
                direction: ltr !important;
            }

            /* And center justify these ones. */
            .stack-column-center {
                text-align: center !important;
            }

            /* What it does: Adjust typography on small screens to improve readability */
            .email-container p {
                font-size: 17px !important;
            }
        }
    </style>

    <!--[if mso]>
    <style type="text/css">
        body {
            margin: 0 !important;
            padding: 0 !important;
            font-family: Arial, sans-serif !important;
        }

        table, td, div, p {
            font-family: Arial, sans-serif !important;
            line-height: normal;
        }
    </style>
    <![endif]-->

    <!--[if gte mso 9]>
    <xml>
        <o:OfficeDocumentSettings>
            <o:AllowPNG />
            <o:PixelsPerInch>96</o:PixelsPerInch>
        </o:OfficeDocumentSettings>
    </xml>
    <![endif]-->

</head>

<body
    style="-webkit-text-size-adjust: none;-ms-text-size-adjust: none; margin: 0; padding: 0; width: 100%; background-color: #f3f3f3;">
<span style="display:none;font-size:0;max-height:0;width:0;line-height:0;mso-hide:all"></span>

<table width="100%" border="0" cellspacing="0" cellpadding="0">
    <tr>
        <td class="minwidth" align="center" width="100%" style="width: 100%; background-color: #f3f3f3;">
            <table width="100%" border="0" align="center" cellpadding="0" cellspacing="0">
                <tr>
                    <td>
                        <!-- TABLES START -->
                        <!-- ||| MAIN BODY TABLE START ||| -->
                        <table width="100%" border="0" align="center" cellpadding="0" cellspacing="0">
                            <tr>
                                <td align="center">
                                    <table class="w100" align="center" width="100%" border="0" cellspacing="0"
                                           cellpadding="0" style="max-width: 512px;">
                                        <!-- ADD ROW(S) HERE START -->
                                        <!-- /// PREHEADER ROW START /// -->
                                        <tr>
                                            <td class="hide" align="center"
                                                style="padding-top: 10px; padding-bottom: 15px;">
                                                <table width="95%" border="0" align="center" cellpadding="0"
                                                       cellspacing="0">
                                                    <tr>
                                                        @if (isset($created_at))
                                                            <td style="font-size: 12px; font-family: Arial, sans-serif; color: #696969; text-align: center;">
                                                                {{ __('mgs.mail.footer-sent-at', ['time' => $created_at]) }}</td>
                                                        @else
                                                            <td style="font-size: 12px; font-family: Arial, sans-serif; color: #696969; text-align: center;">
                                                                {{ __('mgs.mail.cannot-view') }}
                                                                <a href="{{ route('mail-live', ['uid' => $uuid ?? '#']) }}">{{ __('mgs.mail.view-live') }}</a>
                                                            </td>
                                                        @endif
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>

                                        <tr>
                                            <td align="center" style="background-color: white;">
                                                <table width="100%" border="0" align="center" cellpadding="0"
                                                       cellspacing="0">
                                                    <tbody>
                                                    <tr>
                                                        <td style="border-top: 3px solid rgba(6,182,212, 1);border-radius: 4px 4px 0 0;">
                                                        </td>
                                                    </tr>
                                                    </tbody>
                                                </table>
                                            </td>
                                        </tr>

                                        <tr>
                                            <td class="padt10m padb10m" align="center"
                                                style="background-color: #FDFDFE; padding-top: 15px; padding-bottom: 15px;">
                                                <table width="90%" border="0" align="center" cellpadding="0"
                                                       cellspacing="0">
                                                    <tr>
                                                        <td align="left">
                                                            <a href="{{ config('app.url') }}">
                                                                <img loading="lazy"
                                                                     src="{{ url(iSeo('logo.url', 'assets/img/logo.png')) }}"
                                                                     height="35"
                                                                     alt="{{ $brand ?? config('setting.title.'.(lang()->path_alias ?? lang()->path)) }}"
                                                                     style="display: block; border: 0; font-size: 20px; font-weight: bold; font-family: sans-serif; color: #222222;"
                                                                     class="resizem">
                                                            </a>
                                                        </td>
                                                        <td align="left">
                                                            <div
                                                                style="display: block; border: 0; font-size: 16px; font-weight: bold; font-family: sans-serif; color: #222222;">
                                                                {{ $subTitle ?? '' }}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>

                                        <tr>
                                            <td align="center" style="background-color: #F5F5F6;">
                                                <table width="100%" border="0" align="center" cellpadding="0"
                                                       cellspacing="0">
                                                    <tbody>
                                                    <tr>
                                                        <td style="border-top: 1px solid #F5F5F6;"></td>
                                                    </tr>
                                                    </tbody>
                                                </table>
                                            </td>
                                        </tr>

                                        @yield('content')

                                        <tr>
                                            <td align="center"
                                                style="background-color: white; padding-top: 0; padding-bottom: 20px;">
                                                <table width="90%" border="0" align="center" cellpadding="0"
                                                       cellspacing="0">
                                                    <tr>
                                                        <td class="h2m"
                                                            style="padding-top: 5px; font-size: 14px; font-family: Helvetica Neue, Arial, sans-serif; color: #3C4043; text-align: left; line-height: 1.55em;">
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>

                            <tr>
                                <td align="center">
                                    <table class="w100" align="center" width="100%" border="0" cellspacing="0"
                                           cellpadding="0" style="max-width: 512px;">
                                        <!-- ADD ROW(S) HERE START -->
                                        <tr>
                                            <td align="center"
                                                style="background-color: rgba(6,182,212, 1); padding-top: 20px; padding-bottom: 20px;">
                                                <table width="90%" border="0" align="center" cellpadding="0"
                                                       cellspacing="0">
                                                    <tr>
                                                        <td>
                                                            <table width="100%" border="0" align="center"
                                                                   cellpadding="0" cellspacing="0">
                                                                <tbody>
                                                                <tr>
                                                                    <td class="h2m stack-column-center"
                                                                        style="padding: 0; margin: 0">
                                                                        <div class=""
                                                                             style="color:#f3e6ec;display:block;font-family:sans-serif;font-size:13px;font-weight:700;line-height:19px;margin:0px;padding:0px;">
                                                                            {{ $brand ?? '' }}
                                                                        </div>
                                                                        <div
                                                                            style="margin: 5px 0 0 0;color:#f3e6ec;display:block;font-family:sans-serif; font-size:12px;font-weight:normal;line-height:20px;">

                                                                        </div>
                                                                    </td>
                                                                    <td class="h2m stack-column-center "
                                                                        style="padding:0; text-align: right; ">
                                                                        <div
                                                                            style="color:#737373;margin:10px 0;font-size:12px;line-height:22px; text-transform: uppercase; font-weight: bold;">
                                                                        </div>
                                                                    </td>
                                                                </tr>
                                                                </tbody>
                                                            </table>
                                                        </td>
                                                    </tr>

                                                    <tr>
                                                        <td align="center" style="padding: 10px 0 10px;">
                                                            <table width="100%" border="0" align="center"
                                                                   cellpadding="0" cellspacing="0">
                                                                <tbody>
                                                                <tr>
                                                                    <td style="border-top: 1px dashed #ddd;">
                                                                    </td>
                                                                </tr>
                                                                </tbody>
                                                            </table>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td style="padding: 0; margin: 0">
                                                            <div class="text-center"
                                                                 style="color:#fff;display:block;font-family:sans-serif;font-size:11px;font-weight:normal; text-align: center;line-height:17px;margin:0px;padding:0px;">
                                                                <p style="margin: 0 !important; padding: 0 !important;">
                                                                    {{ __('mgs.mail.footer-received', ['brand' => $brand ?? config('setting.title.'.(lang()->path_alias ?? lang()->path))]) }}</p>
                                                                <p style="margin: 0 !important; padding: 0 !important;">
                                                                    {{ __('mgs.mail.footer-sent-at', ['time' => now()->setTimezone(config('ispa-cms.timezone') ?: config('app.timezone', 'UTC'))->format('Y-m-d H:i:s')]) }}</p>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                </table>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td align="center" style="background-color: #F5F5F6;">
                                                <table width="100%" border="0" align="center" cellpadding="0"
                                                       cellspacing="0">
                                                    <tbody>
                                                    <tr>
                                                        <td style="border-top: 1px solid #F5F5F6;"></td>
                                                    </tr>
                                                    </tbody>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                        </table>
                        <!-- ||| MAIN BODY TABLE END ||| -->
                        <!-- NEW FOOTER HERE -->
                        <!-- ||| FOOTER TABLE START ||| -->
                        <!-- FOOTER OLD HERE footer-old.html -->
                        <!-- ||| FOOTER TABLE END ||| -->
                        <!-- TABLES END -->
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>

<div class="gmailfix" style="white-space:nowrap; font:15px courier; line-height:0;">
    &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;
    &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp;
</div>
<table cellpadding="0" cellspacing="0"
       style="border: 0px; padding: 0px; margin: 0px; position: absolute; display: none; float: left">
    <tr>
        <td height="1" style="font-size: 1px; line-height: 1px; padding: 0px;">
            <br>
            </img>
        </td>
    </tr>
</table>
</body>
</html>
