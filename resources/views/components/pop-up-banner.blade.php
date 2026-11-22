@if($banner && $bannerId && $bannerId > 0)
    <div x-data="popupModal">
        <div x-show="openModal" x-cloak id="popup-modal"
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
                     class="relative inline-block align-top bg-white rounded-lg text-left shadow-xl transform transition-all sm:my-8 sm:max-w-2xl w-full">
                    <span @click="toggle()" role="button"
                          class="absolute z-10 top-0 right-0 bg-white rounded-full -mt-2 -mr-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" viewBox="0 0 20 20"
                             fill="currentColor">
                            <path fill-rule="evenodd"
                                  d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                                  clip-rule="evenodd" />
                        </svg>
                    </span>
                    <div class="relative">
                        <span @click="updateClick('{{ $banner->url }}')" class="cursor-pointer">
                            <img src="{{ optional($banner->image)?->xl }}" alt="{{ $banner->name }}"
                                 class="rounded-lg w-full object-contain mx-auto">
{{--                            <x-lazy-image :image="$banner->image" type="scale" :alt="$banner->name"--}}
                            {{--                                          class="rounded-lg w-full object-contain mx-auto"></x-lazy-image>--}}
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('script')
        <script>
            function popupModal() {
                return {
                    openModal: false, currentId: {{ $bannerId }},
                    toggle() {
                        this.updateClose()
                    },
                    delay() {
                        setTimeout(() => {
                            this.openModal = true
                            setTimeout(() => {
                                Alpine.store('banner').updateView(this.currentId)
                            }, 3000)
                        }, 1000)

                    },
                    init() {
                        this.delay()
                    },
                    updateView() {
                        Alpine.store('banner').updateView(this.currentId)
                    },
                    updateClose() {
                        Alpine.store('banner').updateClose(this.currentId)

                        this.openModal = false
                    },
                    updateClick(url) {
                        Alpine.store('banner').updateClick(this.currentId, url)

                        this.openModal = false
                    },
                }
            }
        </script>
    @endpush
@endif
