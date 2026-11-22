@if($banners && $banners->count() > 0)
    @php $ids = []; @endphp
    <div x-data="bannerW336H280" class="relative">
        @foreach($banners as $key => $banner)
            @php $ids[] = $banner->id; @endphp
            <span @click="$store.banner.updateClick({{ $banner->id }}, '{{ $banner->url }}')" class="cursor-pointer">
                <x-lazy-image :image="$banner->image" type="scale" :alt="$banner->name"
                              class="rounded-sm w-full object-contain mx-auto"></x-lazy-image>
            </span>
        @endforeach
    </div>

    @push('script')
        <script>
            function bannerW336H280() {
                return {
                    ids: @json($ids, JSON_THROW_ON_ERROR),
                    delay() {
                        setTimeout(() => {
                            console.log('called')
                            this.ids.forEach((key) => {
                                console.log(key)
                                Alpine.store('banner').updateView(key)
                            })
                        }, 3000)

                    },
                    init() {
                        this.delay()
                    },
                    updateClick(id, url) {
                        Alpine.store('banner').updateClick(id, url)
                    },
                }
            }
        </script>
    @endpush
@endif
