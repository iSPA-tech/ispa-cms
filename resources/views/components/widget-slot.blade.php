@if($hasContent)
    {!! $html !!}
@else
    {{ $slot ?? '' }}
@endif
