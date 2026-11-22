<!doctype html>
<html lang="{{ get_language_code() }}">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <meta name="robots" content="noindex, nofollow" />
    <meta name="viewport"
          content="width=device-width, user-scalable=no, initial-scale=1.0, maximum-scale=1.0, minimum-scale=1.0">
    <link href="{{ url('css/web-view.css') }}" rel="stylesheet">
    <title>{{ $title ?? '' }}</title>
</head>
<body>
@yield('content')
</body>
</html>
