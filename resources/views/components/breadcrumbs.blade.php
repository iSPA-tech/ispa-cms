{{-- Shared breadcrumb of the public pages (used by every project theme that does not ship its own).
     Below `md` each link is at least 44 px tall so it can be tapped (the row grows with it); the home icon also gets
     44 px of width through side padding pulled back by negative margins, so nothing moves sideways. From `md` the
     links are plain inline text again and the row keeps its compact height. --}}
@if (isset($breadcrumbs) && count($breadcrumbs) > 0)
    <nav class="flex border-b" aria-label="Breadcrumb">
        <ol role="list" class="flex flex-wrap items-center py-0.5 md:py-1.5">
            @foreach($breadcrumbs as $key => $bread)
                <li>
                    <div class="{{ $key > 0 ? 'flex items-center' : '' }}">
                        @if ($key > 0)
                            <svg class="shrink-0 h-5 w-5 text-gray-300" xmlns="http://www.w3.org/2000/svg"
                                 fill="currentColor" viewBox="0 0 20 20" aria-hidden="true">
                                <path d="M5.555 17.776l8-16 .894.448-8 16-.894-.448z" />
                            </svg>
                        @endif
                        @if (isset($bread['url']))
                            <a href="{{ $bread['url'] }}"
                               @class([
                                   'inline-flex min-h-11 items-center text-sm font-medium text-gray-600 hover:text-gray-800 md:inline md:min-h-0',
                                   '-mx-3 px-3 md:mx-0 md:px-0' => $key === 0,
                               ])>
                                @if($key === 0)
                                    <svg class="shrink-0 h-5 w-5" xmlns="http://www.w3.org/2000/svg"
                                         viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                        <path
                                            d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z" />
                                    </svg>
                                    <span class="sr-only">{{ $bread['title'] }}</span>
                                @else
                                    {{ $bread['title'] }}
                                @endif
                            </a>
                        @else
                            <span class="text-sm font-medium text-gray-500 line-clamp-1"
                                  aria-current="page">{{ $bread['title'] }}</span>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </nav>
@endif
