@if (isset($content) && count($content) > 0)
    @include('components.inline-media-style')
    @foreach($content as $key => $item)
        @if (isset($limit) && $limit > 0 && $key >= $limit)
            @break
        @endif
        @if ($item['type'] === 'text' || $item['type'] === 'glossary')
            {!! str($item['value'])->replace(htmlentities('<p><br></p>'), '') !!}
        @endif
        @if ($item['type'] === 'image')
            @include('components.content-image-block', ['item' => $item])
        @endif
        @if ($item['type'] === 'video')
            @php
                $url = null; $exp = [];
                if (strpos($item['value'], 'youtube')) {
                  $exp = explode('watch?v=', $item['value']);
                  if (count($exp) === 2) {
                    $url = 'https://www.youtube.com/embed/'.$exp[1];
                  }
                }
                if (strpos($item['value'], 'vimeo')) {
                  $exp = explode('vimeo.com/', $item['value']);
                  if (count($exp) === 2) {
                    $url = 'https://player.vimeo.com/video/'.$exp[1];
                  }
                }
            @endphp
            @if (! empty($url))
                <iframe class="w-full aspect-video" src="{{ $url }}" frameborder="0" allowfullscreen></iframe>
            @endif
        @elseif ($item['type'] === 'widget' && !empty($item['widget_id']))
            <x-widget-view :widget="$item['widget_id']"
                           :templateId="$item['template_id'] ?? 0"
                           :value="$item['value'] ?? []"
                           :inputs="$item['inputs'] ?? []"
                           template />
        @elseif ($item['type'] === 'data_chart')
            {!! \Cms\Content\Services\DataChartRenderer::render($item) !!}
        @endif

        {{-- Display ads in every 3 items --}}
        @if (isset($isEnableAds) && $isEnableAds && ($key + 1) % (int)iSeo('ads_frequency', 3) === 0)
            <div class="content_view_qc">
                {!! iSeo('ads_content_view') !!}
            </div>
        @endif
    @endforeach
    <div class="content-media-clear"></div>
@endif
