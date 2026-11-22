<footer class="bg-gray-100 -mt-2 pb-8">
    <div
        class="max-w-7xl mx-auto py-6 px-4 overflow-hidden sm:px-6 lg:px-8 md:py-16 md:flex md:items-start md:space-x-6">
        <div class="shrink-0 mb-6 md:mb-0">
            @if (is_countable(__('setting.websiteLogo')))
            @else
                <img class="h-20 w-auto sm:h-24" src="{{ asset('img/logo.png') }}" alt=" logo ">
            @endif
        </div>
        <div class="block text-sm md:text-base text-gray-600 mb-2 md:mb-0">
            <p class="mb-1 md:mb-2 text-gray-900 text-xl font-bold">
                <span>@lang('setting.websiteTitle')</span>
            </p>
            <p class="mb-1 md:mb-2 text-gray-600">
                @lang('validation.attributes.address'): <span>@lang('setting.websiteAddress')</span>
            </p>
            <p class="mb-1 md:mb-2 text-gray-600">
                @lang('web.office'): <span>@lang('setting.websiteWorkingTime')</span>
            </p>
            <p class="mb-1 md:mb-2 flex flex-wrap text-gray-600">
                @lang('web.phone'):
                <span class="flex pl-2">
        </span>
            </p>
            <p class="mb-1 md:mb-2 flex flex-wrap text-gray-600">
        <span class="flex">
        </span>
            </p>
        </div>
        <div class="mt-4">
            <h3 class="uppercase">@lang('web.home-social')</h3>
            <div class="flex space-x-4 text-gray-400">
            </div>
            <div class="pt-4 mt-4 border-t flex space-x-4 text-gray-500 text-sm">
            </div>
        </div>
    </div>
    <div class="max-w-7xl mx-auto py-6 px-4 overflow-hidden text-center text-xs text-gray-500 border-t">
        @lang('setting.websiteBrand') &copy; 2021
    </div>
</footer>

<p x-data="{show: false}" id="back-top" :class="{'hidden': !show}"
   @scroll.window="show = document.querySelector('body').getBoundingClientRect().top < -200"
   class="fixed bottom-8 right-8 w-8 h-8 rounded-sm bg-gray-300 flex items-center text-center justify-between transition-all">
    <a class="text-gray-800 text-lg text-shadow w-full h-full leading-8 animate-bounce mt-2"
       onclick="window.scrollTo(0, 0);" href="javascript:void();" data-toggle="tooltip" data-placement="top"
       title="@lang('web.b.top')"><i class="fa fa-angle-up"></i></a>
</p>
