@php
    $socialText = static fn ($value): string => is_scalar($value) ? trim((string) $value) : '';
    $socialMetadata = is_array($metadata ?? null) ? $metadata : [];
    $socialBase = parse_url($socialText($socialMetadata['url'] ?? url('/'))) ?: [];
    $socialScheme = in_array($socialBase['scheme'] ?? '', ['http', 'https'], true) ? $socialBase['scheme'] : 'https';
    $socialOrigin = isset($socialBase['host']) ? $socialScheme.'://'.$socialBase['host'].(isset($socialBase['port']) ? ':'.$socialBase['port'] : '') : url('/');
    $socialUrl = static function ($value) use ($socialText, $socialOrigin, $socialScheme): string {
        $value = html_entity_decode($socialText($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($value === '' || preg_match('/[\x00-\x1F\x7F]/', $value)) return '';
        if (str_starts_with($value, '//')) $value = $socialScheme.':'.$value;
        elseif (parse_url($value, PHP_URL_SCHEME) === null) $value = rtrim($socialOrigin, '/').'/'.ltrim($value, '/');
        $parts = parse_url($value);
        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) ? $value : '';
    };
    $socialImage = $socialUrl($socialMetadata['image'] ?? '');
    $socialFacebookImage = $socialUrl($facebookImage ?? $socialImage);
    $socialTwitterImage = $socialUrl($twitterImage ?? $socialImage);
    $socialGraph = [
        'type' => $socialText($socialMetadata['type'] ?? 'website'),
        'title' => $socialText($socialMetadata['title'] ?? ''),
        'description' => $socialText($socialMetadata['description'] ?? ''),
        'url' => $socialUrl($socialMetadata['url'] ?? ''),
        'image' => $socialFacebookImage,
        'site_name' => $socialText($siteName ?? ''),
    ];
    $socialCards = [
        'card' => $socialTwitterImage !== '' ? 'summary_large_image' : 'summary',
        'title' => $socialGraph['title'],
        'description' => $socialGraph['description'],
        'url' => $socialGraph['url'],
        'image' => $socialTwitterImage,
    ];
@endphp
@foreach ($socialGraph as $socialKey => $socialValue)
    @if ($socialValue !== '')
        <meta property="og:{{ $socialKey }}" content="{!! e($socialValue, false) !!}">
    @endif
@endforeach
@foreach ($socialCards as $socialKey => $socialValue)
    @if ($socialValue !== '')
        <meta name="twitter:{{ $socialKey }}" content="{!! e($socialValue, false) !!}">
    @endif
@endforeach
