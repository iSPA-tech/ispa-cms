@if (isset($post))
    <div class="relative pt-16 pb-20 px-4 sm:px-6 lg:pt-24 lg:pb-28 lg:px-8">
        <div class="absolute inset-0">
            <div class="h-1/3 sm:h-2/3"></div>
        </div>
        <div class="relative max-w-screen-2xl mx-auto">
            <div class="text-center">
                <h2 class="text-3xl tracking-tight font-extrabold text-gray-900 sm:text-4xl">{{ isset($category) ? (isset($category->seo, $category->seo->title) ? $category->seo->name : $category->name) : $title }}</h2>
                <p class="mt-3 max-w-2xl mx-auto text-xl text-gray-500 sm:mt-4">{{ isset($category) ? (isset($category->seo, $category->seo->description) ? $category->seo->description : $category->description) : $description }}</p>
            </div>
            <div class="mt-12 max-w-lg mx-auto grid gap-5 lg:grid-cols-{{ $limit }} lg:max-w-none">
                @foreach($post as $p)
                    @if ($p->categories && count($p->categories) > 0)
                        <div class="flex flex-col rounded-lg shadow-lg overflow-hidden relative">
                            <div class="shrink-0">
                                @if (isset($p->slug, $p->category->slug, $p->image))
                                    <a href="{{ route('article.'.lang()->path, ['slug' => $p->slug->slug, 'cate' => $p->category->slug]) }}">
                                        <img loading="lazy" class="h-48 w-full object-cover"
                                             src="{{ $p->image->thumbnail_lg }}" alt="{{ $p->name }}">
                                    </a>
                                @else
                                    <div class="h-48 w-full bg-gray-100"></div>
                                @endif
                            </div>
                            <div class="flex-1 bg-white p-6 flex flex-col justify-between">
                                <div class="flex-1">
                                    <p class="text-sm font-medium text-indigo-600">
                                        @if (isset($p->slug, $p->category->slug))
                                            <a href="{{ route('category.' . lang()->path, ['slug' => $p->category->slug]) }}"
                                               class="hover:underline"> {{ $p->category->name }} </a>
                                        @endif
                                    </p>
                                    @if (isset($p->slug, $p->category->slug))
                                        <a href="{{ route('article.'.lang()->path, ['slug' => $p->slug->slug, 'cate' => $p->category->slug]) }}"
                                           class="block mt-2">
                                            <p class="text-xl font-semibold text-gray-900">{{ $p->name }}</p>
                                            <p class="mt-3 text-base text-gray-500">{{ $p->description }}</p>
                                        </a>
                                    @endif
                                </div>
                                <!--
                                <div class="mt-6 flex items-center">

                                  <div class="shrink-0">
                                    <a href="#">
                                      <span class="sr-only">Roel Aufderehar</span>
                                      <img loading="lazy" class="h-10 w-10 rounded-full" src="https://images.unsplash.com/photo-1472099645785-5658abf4ff4e?ixlib=rb-1.2.1&ixid=eyJhcHBfaWQiOjEyMDd9&auto=format&fit=facearea&facepad=2&w=256&h=256&q=80" alt="">
                                    </a>
                                  </div>
                                  <div class="ml-3">
                                    <p class="text-sm font-medium text-gray-900">
                                      <a href="#" class="hover:underline"> Roel Aufderehar </a>
                                    </p>
                                    <div class="flex space-x-1 text-sm text-gray-500">
                                      <time datetime="2020-03-16"> Mar 16, 2020 </time>
                                      <span aria-hidden="true"> &middot; </span>
                                      <span> 6 min read </span>
                                    </div>
                                  </div>
                                </div>
                                -->
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>
        </div>
    </div>
@endif
