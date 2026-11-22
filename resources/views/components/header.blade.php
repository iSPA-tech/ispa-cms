<?php

use Cms\Core\Models\Menu;

$news = new App\Models\News;

$menus = Menu::select('*')->with([
    'items' => function ($q) {
        $q->where('parent_id', null);
    }
])->where('group_key', 'main_menu')
    ->where([
        'status' => 1,
        'languages_id' => $lang->id ?? 1,
    ])->orderBy('id', 'DESC')->first();

$xData = '{ mobileMenuOpen: false';
$arrayData = collect([]);

if (isset($menus->items) && $menus->items->count() > 0) {
    foreach ($menus->items as $key => $value) {
        $xData .= ', I' . $value->id . ': false';
        $arrayData->push('I' . $value->id);

        if ($value->type !== 'url' && $value->target_id > 0 && $value->target_type !== null) {
            $value->load('target');
        }
    }
}
$xData .= ' }';
?>
<div class="relative" x-data="{{ $xData }}">
    <div class="relative z-20 shadow-sm">
        <div class="max-w-7xl mx-auto flex items-center px-4 py-5 md:px-0 md:justify-start md:space-x-6">
            <div>
                <a href="{{ route('home') }}" class="flex" title="@lang('web.home')">
                    <span class="sr-only">@lang('setting.websiteTitle')</span>
                    <img src="{{ asset('img/icon-home.png') }}" alt="" class="max-h-8">
                </a>
            </div>
            <div class="-mr-2 -my-2 md:hidden">
                <button type="button"
                        @click="mobileMenuOpen = !mobileMenuOpen; moreMenuOpen = false; solutionsMenuOpen = false"
                        class="bg-white rounded-md p-2 inline-flex items-center justify-center text-gray-900">
                    <span class="sr-only">Open menu</span>
                    <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                         stroke="currentColor" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                              d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
            </div>
            <div class="hidden md:flex md:items-center md:justify-between w-full">
                <nav class="flex space-x-10 mx-auto">
                    @if (isset($menus->items) && $menus->items->count() > 0)
                        @foreach ($menus->items as $key => $value)
                                <?php
                                $nameSpace = 'I' . $value->id;
                                $filter = $arrayData->map(function ($item) use ($nameSpace) {
                                    return $item !== $nameSpace ? $item : false;
                                });
                                $defineFalse = '';
                                foreach ($filter as $ikey => $ifil) {
                                    if ($ifil) {
                                        if ($ikey > 0) {
                                            $defineFalse .= '; ';
                                        }
                                        $defineFalse .= $ifil . ' = false';
                                    }
                                }
                                ?>
                            @if ($value->type === 'category' && $value->target && $value->target->childrens && $value->target->childrens->count() > 0)
                                <div class="group inline-block relative">
                                    <a href="{{ strpos($value->url, '://') > 0 ? $value->url : route('category', ['slug' => $value->url]) }}"
                                       title="{{ $value->name }}" target="{{ $value->custom['action'] }}"
                                       class="group inline-flex items-center text-gray-600 text-base font-semibold hover:text-black uppercase"
                                       aria-expanded="false">
                                        @if ($value->custom['icon'] !== null)
                                            <span class="mr-2">
                  <i class="{{ $value->custom['icon'] }}"></i>
                </span>
                                        @endif
                                        <span>{{ $value->name }}</span>
                                    </a>

                                    <div
                                        class="absolute z-10 left-1/2 transform -translate-x-1/2 px-2 w-screen max-w-sm sm:px-0 hidden group-hover:block">
                                        <div
                                            class="rounded-lg shadow-lg ring-1 ring-black/5 overflow-hidden">
                                            <div class="relative grid gap-4 bg-white p-4">
                                                @foreach($value->target->childrens as $k => $cVal)
                                                    <a href="{{ route('category', ['slug' => $cVal->slug]) }}"
                                                       target="{{ $value->custom['action'] }}"
                                                       class="-m-3 p-3 block rounded-md hover:bg-gray-50 transition ease-in-out duration-150">
                                                        <p class="text-base font-medium text-gray-900">
                                                            {{ $cVal->name }}
                                                        </p>
                                                        @if ($cVal->description && strlen($cVal->description) > 0)
                                                            <p class="mt-1 text-sm text-gray-500">
                                                                {{ $cVal->description }}
                                                            </p>
                                                        @endif
                                                    </a>
                                                    @php
                                                        if ($value->target_type === 'Cms\Core\Models\Category') {
                                                          $cVal->childrends = $cVal->childrens()->get();
                                                        }
                                                    @endphp
                                                    @if ($cVal->childrends && $cVal->childrends->count() > 0)
                                                        <div class="-m-3 pr-3 w-full group-hover:block pl-4">
                                                            @foreach($cVal->childrends as $c)
                                                                <a href="{{ route('category', ['slug' => $c->slug]) }}"
                                                                   title="{{ $c->name }}"
                                                                   target="{{ $value->custom['action'] }}"
                                                                   class="block text-sm text-gray-600 hover:text-gray-800 py-1 pl-3">
                                                                    {{ $c->name }}
                                                                </a>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @else
                                @if ($value->childrens->count() > 0)
                                    <div class="group inline-block relative">
                                        @if ($value->url !== '#')
                                            <a href="{{ strpos($value->url, '://') > 0 ? $value->url : route('category', ['slug' => $value->url]) }}"
                                               title="{{ $value->name }}" target="{{ $value->custom['action'] }}"
                                               class="group inline-flex items-center text-gray-600 text-base font-semibold hover:text-black uppercase"
                                               aria-expanded="false">
                                                @if ($value->custom['icon'] !== null)
                                                    <span class="mr-2">
                        <i class="{{ $value->custom['icon'] }}"></i>
                      </span>
                                                @endif
                                                <span>{{ $value->name }}</span>
                                            </a>
                                        @else
                                            <span
                                                class="group inline-flex items-center text-gray-600 text-base font-semibold hover:text-black uppercase"
                                                aria-expanded="false">
                    @if ($value->custom['icon'] !== null)
                                                    <span class="mr-2">
                      <i class="{{ $value->custom['icon'] }}"></i>
                    </span>
                                                @endif
                    <span>{{ $value->name }}</span>
                  </span>
                                        @endif

                                        <div
                                            class="absolute z-10 left-1/2 transform -translate-x-1/2 px-2 w-screen max-w-sm sm:px-0 hidden group-hover:block">
                                            <div
                                                class="rounded-lg shadow-lg ring-1 ring-black/5 overflow-hidden">
                                                <div class="relative grid gap-4 bg-white p-4">
                                                    @foreach($value->childrens as $k => $cVal)
                                                        @if ($cVal->type === 'category' && $cVal->target_id > 0)
                                                            <a href="{{ (strpos($cVal->url, '://') > 0) ? $cVal->url : ($cVal->url ? route('category', ['slug' => $cVal->url]) : '#') }}"
                                                               title="{{ $cVal->name }}"
                                                               target="{{ $value->custom['action'] }}"
                                                               class="-m-3 p-3 block rounded-md hover:bg-gray-50 transition ease-in-out duration-150">
                                                                <p class="text-base font-medium text-gray-900">
                                                                    {{ $cVal->name }}
                                                                </p>
                                                            </a>
                                                            @if ($cVal->target->childrens->count() > 0)
                                                                <div class="relative grid gap-4 bg-white px-4">
                                                                    @foreach($cVal->target->childrens as $k => $val)
                                                                        <a href="{{ route('category', [
                                  'slug' => ((strpos($cVal->url, 'van-kien') >= 0 || strpos($cVal->url, 'document') >= 0) ? $cVal->url.'/'.$val->slug : $val->slug)
                                ]) }}" target="{{ $cVal->custom['action'] }}"
                                                                           class="-m-3 p-3 block rounded-md hover:bg-gray-50 transition ease-in-out duration-150">
                                                                            <p class="text-base font-medium text-gray-600">
                                                                                {{ $val->name }}
                                                                            </p>
                                                                        </a>
                                                                        @php
                                                                            if ($cVal->target_type === 'Cms\Core\Models\Category') {
                                                                              $val->childrens = $val->childrens()->get();
                                                                            }
                                                                        @endphp
                                                                        @if ($val->childrens && $val->childrens->count() > 0)
                                                                            <div
                                                                                class="-m-3 pr-3 w-full group-hover:block pl-4">
                                                                                @foreach($val->childrends as $c)
                                                                                    <a href="{{ route('category', ['slug' => $c->slug]) }}"
                                                                                       title="{{ $c->name }}"
                                                                                       target="{{ $cVal->custom['action'] }}"
                                                                                       class="block text-sm text-gray-600 hover:text-gray-800 py-1 pl-3">
                                                                                        {{ $c->name }}
                                                                                    </a>
                                                                                @endforeach
                                                                            </div>
                                                                        @endif
                                                                    @endforeach
                                                                </div>
                                                            @endif
                                                        @else
                                                            <a href="{{ (strpos($cVal->url, '://') > 0) ? $cVal->url : route('category', ['slug' => $cVal->url]) }}"
                                                               title="{{ $cVal->name }}"
                                                               target="{{ $value->custom['action'] }}"
                                                               class="-m-3 p-3 block rounded-md hover:bg-gray-50 transition ease-in-out duration-150">
                                                                <p class="text-base font-medium text-gray-900">
                                                                    {{ $cVal->name }}
                                                                </p>
                                                            </a>
                                                        @endif
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    <a href="{{ (
                  $value->target_type === 'Cms\Core\Models\Category' ? route('category', ['slug' => $value->url]) : (strpos($value->url, '://') > 0 ? $value->url : '/'.$value->url)
                ) }}" class="text-base font-medium text-gray-600 hover:text-black uppercase" title="{{ $value->name }}"
                                       target="{{ $value->custom['action'] }}">
                                        @if ($value->custom['icon'] !== null)
                                            <span class="mr-2">
                      <i class="{{ $value->custom['icon'] }}"></i>
                    </span>
                                        @endif{{ $value->name }}
                                    </a>
                                @endif
                            @endif
                        @endforeach
                    @endif
                </nav>
                <nav x-data="{ inputShow: false }" class="flex flex-wrap">
                    <ul>
                        <li class="relative pl-4">
                            <form x-show="inputShow" x-cloak action="{{ route('search') }}"
                                  class="absolute z-10 right-full top-0">
                                <input name="q" value="{{ request('q') }}"
                                       class="py-1 px-2 rounded-sm bg-white/90" type="search"
                                       placeholder="@lang('web.search')">
                            </form>
                            <span @click="inputShow = !inputShow"
                                  class="text-gray-600 cursor-pointer hover:text-black text-lg">
                <i class="fa fa-search"></i>
              </span>
                        </li>
                    </ul>
                </nav>
                @if (isset($langs) && $langs->count() > 1)
                    <div id="modal"></div>
                @endif
            </div>
        </div>
    </div>

    <div x-cloak x-on:click.away="mobileMenuOpen = false" x-show="mobileMenuOpen"
         x-transition:enter="duration-200 ease-out" x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100" x-transition:leave="duration-100 ease-in"
         x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
         class="absolute z-40 top-0 inset-x-0 p-2 transition transform origin-top-right md:hidden">
        <div class="rounded-lg shadow-lg ring-1 ring-black/5 bg-white divide-y-2 divide-gray-50">
            <div class="pt-5 pb-6 px-5 sm:pb-8">
                <div class="flex items-center justify-between">
                    @if (is_countable(__('setting.websiteLogo')))
                    @else
                        <img class="h-8 w-auto sm:h-10" src="{{ asset('img/logo.png') }}" alt=" logo ">
                    @endif
                    <div class="ml-auto">
                        <button @click="mobileMenuOpen = false" type="button"
                                class="bg-white rounded-md p-2 inline-flex items-center justify-center text-gray-500 hover:text-white hover:bg-gray-100">
                            <span class="sr-only">Close menu</span>
                            <svg class="h-6 w-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"
                                 stroke="currentColor" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
            <div class="py-6 px-5">
                @if (isset($menus->items) && $menus->items->count() > 0)
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @foreach($menus->items as $key => $value)
                            @if ($value->type === 'category' && $value->target && $value->target->childrens && $value->target->childrens->count() > 0)
                                <div class="group inline-block relative">
                                    <a href="{{ strpos($value->url, '://') > 0 ? $value->url : route('category', ['slug' => $value->url]) }}"
                                       target="{{ $value->custom['action'] }}"
                                       class="-m-3 p-3 block rounded-md hover:bg-gray-50 transition ease-in-out duration-150">
                  <span class="group inline-flex items-center uppercase">
                    @if ($value->custom['icon'] !== null)
                          <span class="mr-2">
                        <i class="{{ $value->custom['icon'] }}"></i>
                      </span>
                      @endif
                      {{ $value->name }}
                  </span>
                                    </a>
                                    <div class="max-w-full">
                                        <div class="rounded-lg shadow-sm">
                                            <div class="relative grid gap-4 bg-white p-4">
                                                @foreach($value->target->childrens as $k => $cVal)
                                                    <a href="{{ strpos($cVal->url, '://') > 0 ? $cVal->url : route('category', ['slug' => $cVal->slug]) }}"
                                                       target="{{ $value->custom['action'] }}"
                                                       class="-m-3 p-3 block rounded-md hover:bg-gray-50 transition ease-in-out duration-150">
                                                        <p class="text-base font-medium text-gray-900">
                                                            {{ $cVal->name }}
                                                        </p>
                                                    </a>
                                                    @php
                                                        if ($value->target_type === 'Cms\Core\Models\Category') {
                                                          $cVal->childrends = $cVal->childrens()->get();
                                                        }
                                                    @endphp
                                                    @if ($cVal->childrends && $cVal->childrends->count() > 0)
                                                        <div class="-m-3 pr-3 w-full group-hover:block pl-4">
                                                            @foreach($cVal->childrends as $c)
                                                                <a href="{{ route('category', ['slug' => $c->slug]) }}"
                                                                   title="{{ $c->name }}"
                                                                   target="{{ $value->custom['action'] }}"
                                                                   class="block text-sm text-gray-600 hover:text-gray-800 py-1 pl-3">
                                                                    {{ $c->name }}
                                                                </a>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                @endforeach
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @else
                                @if ($value->childrens->count() > 0)
                                    <div class="group inline-block relative">
                  <span class="group inline-flex items-center">
                    @if ($value->custom['icon'] !== null)
                          <span class="mr-2">
                        <i class="{{ $value->custom['icon'] }}"></i>
                      </span>
                      @endif
                      {{ $value->name }}
                  </span>
                                        <div class="max-w-full">
                                            <div class="rounded-lg shadow-sm">
                                                <div class="relative grid gap-4 bg-white p-4">
                                                    @foreach($value->childrens as $k => $cVal)
                                                        @if (strpos($cVal->url, '://') > 0)
                                                            <a href="{{ $cVal->url }}" title="{{ $cVal->name }}"
                                                               target="{{ $value->custom['action'] }}"
                                                               class="-m-3 p-3 block rounded-md hover:bg-gray-50 transition ease-in-out duration-150">
                                                                <p class="text-base font-medium text-gray-900">
                                                                    {{ $cVal->name }}
                                                                </p>
                                                            </a>
                                                        @else
                                                            @if ($cVal->url)
                                                                <a href="{{ route('category', ['slug' => $cVal->url]) }}"
                                                                   title="{{ $cVal->name }}"
                                                                   target="{{ $value->custom['action'] }}"
                                                                   class="-m-3 p-3 block rounded-md hover:bg-gray-50 transition ease-in-out duration-150">
                                                                    <p class="text-base font-medium text-gray-900">
                                                                        {{ $cVal->name }}
                                                                    </p>
                                                                </a>
                                                            @endif
                                                        @endif
                                                    @endforeach
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    <a href="{{ (
                  $value->target_type === 'Cms\Core\Models\Category' ? route('category', ['slug' => $value->url]) : '/'.$value->url
                ) }}" class="text-base font-medium text-gray-600 hover:text-black uppercase" title="{{ $value->name }}"
                                       target="{{ $value->custom['action'] }}"{{ isset($value->custom['color']) ? ' style="color: '.$value->custom['color'].'"' : '' }}>
                                        @if ($value->custom['icon'] !== null)
                                            <span class="mr-2">
                      <i class="{{ $value->custom['icon'] }}"></i>
                    </span>
                                        @endif{{ $value->name }}</a>
                                @endif
                            @endif
                        @endforeach
                    </div>
                @endif

                <nav class="flex flex-wrap">
                    <ul>
                        <li class="relative pt-2 w-full">
                            <form action="{{ route('search') }}" class="flex items-center">
                                <input name="q" value="{{ request('q') }}"
                                       class="py-1 px-2 rounded-sm bg-white border w-full" type="search"
                                       placeholder="@lang('web.search')">
                                <button type="submit" class="pl-3"><i class="fa fa-search"></i></button>
                            </form>
                        </li>
                    </ul>
                </nav>
                @if (isset($langs) && $langs->count() > 1)
                    <div id="modal-mobile" class="spc-language-modal mt-4"></div>
                @endif
                <div class="mt-6 border-t">
                    <p class="mt-6 text-center text-sm font-medium text-gray-500">
                        @lang('setting.websiteBrand') &copy; 2020
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

@section('header-script')
    <script>
        function dropdown() {
            return {
                show: false,
                filter: { city: 0, category: 0 },
                open() {
                    this.show = true
                },
                close() {
                    this.show = false
                },
                isOpen() {
                    return this.show === true
                },
            }
        }

        function menu() {
            return {
                show: false,
                mOpen() {
                    this.show = true
                },
                mClose() {
                    this.show = false
                },
                mIsOpen() {
                    return this.show === true
                },
            }
        }
    </script>
@endsection
