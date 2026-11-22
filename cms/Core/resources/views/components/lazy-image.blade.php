@if ($image)
    @if ($eager)
        <img src="{{ $maskUrl }}" alt="{{ $alt ?? '' }}" class="{{ $class ?? '' }}"
             @if ($srcset !== '') srcset="{{ $srcset }}" sizes="{{ $sizes }}" @endif
             @if ($width > 0 && $height > 0) width="{{ $width }}" height="{{ $height }}" @endif
             fetchpriority="high" decoding="async"
        />
    @else
        <img src="{{ $maskUrl }}" alt="{{ $alt ?? '' }}" class="{{ $class ?? '' }}"
             @if ($width > 0 && $height > 0) width="{{ $width }}" height="{{ $height }}" @endif
             data-lazy
             data-sm="{{ $url['sm'] }}" data-md="{{ $url['md'] }}" data-lg="{{ $url['lg'] }}" data-xl="{{ $url['xl'] }}"
             data-sm-width="{{ $variantWidths['sm'] ?? '' }}" data-md-width="{{ $variantWidths['md'] ?? '' }}"
             data-lg-width="{{ $variantWidths['lg'] ?? '' }}" data-xl-width="{{ $variantWidths['xl'] ?? '' }}"
             data-mask="{{ $maskUrl }}" data-url="{{ $url['url'] }}"
             data-min-size="{{ $minSize ?? 'sm' }}"
        />
    @endif
@endif
