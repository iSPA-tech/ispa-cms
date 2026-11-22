@if ((isset($alwaysShow) && $alwaysShow) || count(languages()) > 1)
    <div class="{{ $class ?? 'flex items-center space-x-2' }} cursor-pointer" @click="toggle()">
        <img loading="lazy" width="24" height="18" src="{{ url('images/flags/4x3/' . lang('flag')) }}" alt="" class="h-4">
        <span
            class="hidden md:block whitespace-nowrap {{ isset($dark) && $dark ? 'text-white' : '' }}">{{ lang('name') ?? '' }}</span>
    </div>

    @section('language-modal')
        <div x-show="openModal" x-cloak id="language-modal"
             x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed z-999 inset-0 overflow-y-auto transition-all" aria-labelledby="modal-title" role="dialog"
             aria-modal="true">
            <div class="flex items-start min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div @click="toggle()" class="fixed inset-0 bg-black/40 transition-opacity z-[-1]"
                     aria-hidden="true"></div>

                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                <div x-show="openModal"
                     x-transition:enter="ease-out duration-300"
                     x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                     x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                     x-transition:leave="ease-in duration-200"
                     x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                     x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                     class="inline-block align-top bg-white rounded-lg px-4 pt-5 pb-4 text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:max-w-2xl w-full sm:p-6">
                    <div>
                        <div class="flex items-center text-gray-600">
                            <div class="flex-1">
                                <h3 class="text-base font-semibold flex items-center space-x-2">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none"
                                         viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                              d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129" />
                                    </svg>
                                    <span>Language</span>
                                </h3>
                            </div>

                            <span @click="toggle()" role="button">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd"
                    d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                    clip-rule="evenodd" />
            </svg>
          </span>
                        </div>
                        <div class="mt-3 sm:mt-5">
                            <div>
                                <p class="text-sm text-gray-500">Vui lòng chọn một ngôn ngữ bên dưới để thay đổi ngôn
                                    ngữ / Please select a language below to change your favorite language.</p>
                            </div>
                            <div class="space-x-4 flex items-center pt-4 mt-4 border-t">
                                @foreach(languages() as $lang)
                                    <a href="{{ route('setLocale', ['locale' => $lang->path]) }}"
                                       title="{{ $lang->name }}" class="">
                                        <button type="button"
                                                class="inline-flex items-center px-6 py-3 border border-gray-300 shadow-xs text-base font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                            <img loading="lazy" width="24" height="18" src="{{ url('images/flags/4x3/' . $lang->flag) }}"
                                                 alt="{{ $lang->name }}" class="h-6 mr-2">
                                            {{ $lang->name }}
                                        </button>
                                    </a>
                                @endforeach
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endsection

    @push('script')
        <script>
            function languageModal() {
                return {
                    openModal: false,
                    toggle() {
                        this.openModal = !this.openModal
                    },
                }
            }
        </script>
    @endpush
@endif
