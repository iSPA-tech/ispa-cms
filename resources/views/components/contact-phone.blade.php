@php
    $phoneValue = trim((string) iSeo($settingKey ?? 'phone'));
@endphp
@if ($phoneValue !== '')
    @if (str_contains($phoneValue, ','))
        @php
            $break = array_filter(array_map('trim', explode(',', $phoneValue)));
        @endphp
        @if (is_array($break) && count($break) > 1)
            @foreach($break as $phone)
                <a href="tel:{{ $phone }}" title="Click to call">{{ $phone }}</a>
            @endforeach
        @elseif (is_array($break) && count($break) === 1)
            @php
                $phone = reset($break);
            @endphp
            <a href="tel:{{ $phone }}" title="Click to call">{{ $phone }}</a>
        @else
            <a href="tel:{{ $phoneValue }}" title="Click to call">{{ $phoneValue }}</a>
        @endif
    @else
        <a href="tel:{{ $phoneValue }}" title="Click to call">{{ $phoneValue }}</a>
    @endif
@endif
