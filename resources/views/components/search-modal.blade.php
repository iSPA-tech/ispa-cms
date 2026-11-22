<div x-show="openModal" x-cloak id="search-modal"
     x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     class="fixed z-90 inset-0 overflow-y-auto transition-all" aria-labelledby="modal-title" role="dialog"
     aria-modal="true">
    <div class="flex items-start min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div @click="toggle()" class="fixed inset-0 bg-black/40 transition-opacity z-[-1]" aria-hidden="true"></div>

        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

        <div x-show="openModal"
             x-transition:enter="ease-out duration-300"
             x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
             x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
             x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
             class="inline-block align-top bg-white rounded-lg px-4 pt-5 pb-4 text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:max-w-2xl w-full sm:p-6">
            <div>
                <div class="flex items-center text-gray-600">
            <span @click="toggle()" role="button">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd"
                      d="M7.707 14.707a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 1.414L5.414 9H17a1 1 0 110 2H5.414l2.293 2.293a1 1 0 010 1.414z"
                      clip-rule="evenodd" />
              </svg>
            </span>

                    <input x-show="type === 'services'" x-model="form.services" @input.debounce.500="searchKeyword()"
                           @keyup.enter="submit()" id="search-input"
                           class="border-0 ml-3 flex-1 text-sm placeholder-gray-400 border-b border-gray-300 focus:ring-0 focus:outline-hidden"
                           type="text" placeholder="@lang('web.search.phd-service')">
                    <input x-show="type === 'location'" x-model="form.location" @input.debounce.500="delay(100)"
                           @keyup.enter="submit()" id="search-input-autocomplete"
                           class="border-0 ml-3 flex-1 text-sm placeholder-gray-400 border-b border-gray-300 focus:ring-0 focus:outline-hidden"
                           type="text" placeholder="@lang('web.search.where')">
                </div>
                <div class="mt-3 sm:mt-5">
                    <template x-if="type === 'services'">
                        @if (isset($services, $services->items) && count($services->items) > 0)
                            <div>
                                <h3 class="text-lg leading-6 font-medium text-gray-900 uppercase" id="modal-title">
                                    @lang('web.search.pupular-services')
                                </h3>
                                <div class="mt-2">
                                    <p class="text-sm text-gray-500">
                                        @foreach($services->items as $item)
                                            <span
                                                @click="withService('{{ $item->keywordId.'_'.str()->slug($item->name) }}')"
                                                role="button"
                                                class="inline-flex items-center border px-3 py-1 mb-1 rounded-full text-sm font-medium bg-gray-50 hover:bg-gray-100 text-gray-800">{{ $item->name }}</span>
                                        @endforeach
                                    </p>
                                </div>
                            </div>
                        @endif
                    </template>

                    <template x-if="type === 'location'">
                        <div>
                            <div x-show="isDenied" class="rounded-md bg-blue-50 p-4">
                                <div class="flex">
                                    <div class="shrink-0">
                                        <svg class="h-5 w-5 text-blue-400" xmlns="http://www.w3.org/2000/svg"
                                             viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                            <path fill-rule="evenodd"
                                                  d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z"
                                                  clip-rule="evenodd" />
                                        </svg>
                                    </div>
                                    <div class="ml-3">
                                        <h3 class="text-base font-medium text-blue-800">
                                            Enable Location Services
                                        </h3>
                                        <div class="mt-2 text-sm text-yellow-700">
                                            <p class="text-sm text-blue-700" x-html="positionError"></p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div x-show="!isDenied">
                                <div class="flex items-center py-3 border-t border-b">
                                    <div class="flex-1 flex items-center">
                    <span class="text-sm text-gray-400 hover:text-gray-600" role="button">
                      <svg class="w-6 h-6 mr-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"
                           fill="currentColor" stroke="currentColor">
                        <path
                            d="M444.52 3.52L28.74 195.42c-47.97 22.39-31.98 92.75 19.19 92.75h175.91v175.91c0 51.17 70.36 67.17 92.75 19.19l191.9-415.78c15.99-38.39-25.59-79.97-63.97-63.97z" />
                      </svg>
                    </span>
                                        <span>
                      <span class="block text-gray-400 text-xs">@lang('web.search.current-location')</span>
                      <span x-text="currentLocation"></span>
                    </span>
                                    </div>
                                    <button x-show="currentPosition.lng === null" @click="enableLocation()"
                                            type="button"
                                            class="uppercase rounded-md border border-transparent shadow-xs px-3 py-1.5 bg-indigo-600 text-xs font-medium text-white hover:bg-indigo-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                                        @lang('web.b.enable-location')
                                    </button>
                                </div>
                                <template x-for="location in locations">
                                    <div class="flex items-center py-2">
                                        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 text-gray-400 mr-2"
                                             viewBox="0 0 20 20" fill="currentColor">
                                            <path fill-rule="evenodd"
                                                  d="M5.05 4.05a7 7 0 119.9 9.9L10 18.9l-4.95-4.95a7 7 0 010-9.9zM10 11a2 2 0 100-4 2 2 0 000 4z"
                                                  clip-rule="evenodd" />
                                        </svg>
                                        <template x-if="location.id_name === null">
                                            <span @click="submit(location.place_id)" role="button"
                                                  :title="location.address" x-text="location.address"></span>
                                        </template>
                                        <template x-if="location.place_id === null">
                                            <span @click="withLocation(location.id_name)" role="button"
                                                  :title="location.address" x-text="location.address"></span>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
            <div class="mt-5 sm:mt-8 w-full text-center">
                <button @click="submit()" type="button"
                        class="w-1/2 mx-auto rounded-md border border-transparent shadow-xs px-4 py-3 bg-indigo-600 text-base font-medium text-white hover:bg-indigo-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                    @lang('nav.b.search')
                </button>
            </div>
        </div>
    </div>
</div>
