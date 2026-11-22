{{-- One admin image block: `value` is a list of media snapshots (the picker allows two). --}}
@php
    $images = array_values(array_filter(
        is_array($item['value'] ?? null) ? $item['value'] : [],
        static fn ($image): bool => is_array($image) && ! empty($image['url']),
    ));
@endphp
@foreach ($images as $image)
    @php
        // Legacy uploads stored a relative path; serve them from the site root.
        $src = str($image['url'])->contains('://') ? $image['url'] : '/' . ltrim($image['url'], '/');
        $alt = collect([$item['options']['alt'] ?? null, $image['alt_text'] ?? null, $image['name'] ?? null])
            ->first(static fn ($value): bool => is_string($value) && trim($value) !== '', '');
    @endphp
    <div class="text-center py-3 md:py-4">
        <img loading="lazy" class="mx-auto max-w-full rounded-lg" src="{{ $src }}"
             alt="{{ $alt }}">
        @if ($loop->last && isset($item['options']['caption']))
            <figcaption
                class="text-sm text-gray-500 italic mt-1">{{ $item['options']['caption'] }}</figcaption>
        @endif
    </div>
@endforeach
