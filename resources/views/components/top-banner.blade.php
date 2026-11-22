@if (isset($banner, $banner->image))
    <a href="{{ $banner->url }}" title="{{ $banner->name }}">
        <img class="mx-auto max-w-full" src="{{ $banner->image->url }}" alt="{{ $banner->name }}">
    </a>
@endif
