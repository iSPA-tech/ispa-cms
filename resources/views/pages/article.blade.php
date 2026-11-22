@extends(config('ispa-cms.folder') . 'layout.index')

{{--        SEO configuration--}}
@if (isset($view) && $view->id > 0)
    @section('seo-title', !empty(optional($view->seo)->title) ? optional($view->seo)->title : $view->name)
    @section('seo-description', !empty(optional($view->seo)->description) ? optional($view->seo)->description : $view->description)
    @section('seo-image', url($view->image->url ?? ''))
    @section('seo-hashtag', $view->seo->hashtag ?? $view->hashtag ?? '')
@endif
{{--        END SEO configuration--}}

@section('content')
    @if (isset($view))

        <div class="px-4">

            @if (! empty($view->video_url))
                <div class="">
                    <iframe class="w-full aspect-video" src="{{ video_embed($view->video_url) }}"></iframe>
                </div>
            @endif
            <div class="flex flex-col md:flex-row">
                <div class="py-4 sm:py-6 overflow-hidden w-full md:flex-1">
                    <div class="text-lg">
                        <h1>
                            <span class="main-title">{{ $view->name }}</span>
                        </h1>
                        <div class="flex items-center pt-4">
                            @if (isset($view->author, $view->author->avatar))
                                <img loading="lazy" src="{{ $view->author->avatar->thumbnail_sm }}" alt=""
                                     class="w-8">
                            @else
                                <span class="text-4xl text-gray-400"><i class="fa fa-user-circle"></i></span>
                            @endif
                            <div class="pl-4">
                                <span class="text-base font-medium">{{ $view->author->name ?? 'Administrator' }}</span>
                                <div class="flex items-center space-x-4">
                                    <span class="block text-sm text-gray-500"><i class="fa fa-calendar"></i> {{ $view->published_date->format('Y-m-d H:i') }}</span>
                                    <span class="block text-sm text-gray-500"><i class="fa fa-eye"></i> {{ $view->total_view }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="mt-6 w-full max-w-none text-gray-500 border-t pt-4">
                        @if (check_valid_access($view->config))
                            @if (isset($view->attachs) && count($view->attachs) > 0)
                                <div class="rounded-sm border border-gray-100 mb-4 p-4">
                                    <h4 class="text-sm text-gray-500">@lang('nav.a.download')</h4>
                                    <dd class="text-base font-semibold tracking-tight text-slate-700 pt-3">
                                        @foreach ($view->attachs as $attach)
                                            <a href="{{ route('document.download.' . lang()->path, ['id' => $attach->id, 'slug' => $attach->name]) }}"
                                               title="{{ $view->name }}"
                                               class="text-white bg-green-500 hover:bg-green-700 px-4 py-1.5 rounded-sm text-sm leading-6 font-normal"
                                               target="_blank">
                                                <i class="fa fa-file"></i> {{ $view->name }}
                                            </a>
                                            <span class="pl-4"><i class="fa fa-download"></i> {{ $attach->custom['downloaded'] ?? 0 }}</span>
                                        @endforeach
                                    </dd>
                                </div>
                            @endif

                            @component('components.contents-view', ['content' => $view->content])@endcomponent
                        @else
                            <span class="text-yellow-700">@lang('mgs.article.required-login')</span>
                        @endif
                    </div>
                </div>

                <div class="related w-full md:w-[280px] min-w-[280px]">
                    @if(isset($cate))
                        <x-related-posts template :categorySlug="$cate->slug" :category_id="$cate->id"
                                         :post_id="$view->id"></x-related-posts>
                    @endif
                </div>
            </div>
        </div>
    @endif
@endsection

@push('script')
    <script type="text/JavaScript">
        function killCopy(e) {
            return false
        }

        function reEnable() {
            return true
        }

        document.onselectstart = new Function('return false')
        if (window.sidebar) {
            document.onmousedown = killCopy
            document.onclick = reEnable
        }
    </script>
@endpush
