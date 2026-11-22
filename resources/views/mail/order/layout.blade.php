<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml"
>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta content="width=device-width; initial-scale=1.0; maximum-scale=1.0; user-scalable=no;" name="viewport">
    <meta name="viewport" content="width=device-width">
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

        body {
            font-family: Arial, sans-serif;
            font-size: 14px;
            -webkit-text-size-adjust: none;
            -ms-text-size-adjust: none;
            -webkit-font-smoothing: antialiased;
            margin: 0;
            padding: 0;
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
    style="-webkit-text-size-adjust: none;-ms-text-size-adjust: none; margin: 0; width: 100%; background-color: #f3f3f3; padding: 16px;">
@if(isset($content))
    {!! $content !!}
@else
    @yield('content')
@endif

</body>
</html>
