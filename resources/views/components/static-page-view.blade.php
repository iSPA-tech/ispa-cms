@if(isset($page))
    @if (isset($page->content) && count($page->content) > 0)
        @foreach($page->content as $key => $item)
            <section>
                @if ($item['type'] === 'text' || $item['type'] === 'glossary')
                    <div class="container{{ $key > 0 ? ' py-3 md:py-4' : '' }}">
                        {!! str($item['value'])->replace(htmlentities('<p><br></p>'), '') !!}
                    </div>
                @endif
                @if ($item['type'] === 'image')
                    @if (! empty($item['value']))
                        <div class="container">
                            @include('components.content-image-block', ['item' => $item])
                        </div>
                    @endif
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
                        <div class="container py-3 md:py-4">
                            <iframe class="w-full aspect-video" src="{{ $url }}" frameborder="0"
                                    allowfullscreen></iframe>
                        </div>
                    @endif
                @endif
                @if ($item['type'] === 'widget' && !empty($item['widget_id']))
                    <x-widget-view :widget="$item['widget_id']"
                                   :templateId="$item['template_id']"
                                   :value="$item['value']"
                                   :inputs="$item['inputs']"
                                   template />
                @endif

                {{-- Display ads in every 3 items --}}
                @if (isset($isEnableAds) && $isEnableAds && ($key + 1) % (int)iSeo('ads_frequency', 3) === 0)
                    <div class="container py-3 md:py-4">
                        <div class="content_view_qc">
                            {!! iSeo('ads_content_view') !!}
                        </div>
                    </div>
                @endif
            </section>
        @endforeach
    @endif
@endif
