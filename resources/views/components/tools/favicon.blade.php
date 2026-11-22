@if (isset($upload))
    <link rel="shortcut icon" href="{{ $upload->makeUrl(60, 60) }}" type="image/x-icon" />
    <link rel="apple-touch-icon" href="{{ $upload->makeUrl(60, 60) }}">
    <link rel="apple-touch-icon" sizes="76x76" href="{{ $upload->makeUrl(76, 76) }}">
    <link rel="apple-touch-icon" sizes="120x120" href="{{ $upload->makeUrl(120, 120) }}">
    <link rel="apple-touch-icon" sizes="152x152" href="{{ $upload->makeUrl(152, 152) }}">
@endif
