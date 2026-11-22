@extends(config('ispa-cms.folder') . 'layout.index')

@push('seo')
    {{-- Vite CSS --}}
    {{-- {{ module_vite('build-core', 'resources/assets/css/app.css') }} --}}
@endpush

@section('content')
    <div>
        {{ $slot }}
    </div>
@endsection

@push('scripts')
    {{-- Vite JS --}}
    {{-- {{ module_vite('build-core', 'resources/assets/js/app.js') }} --}}
@endpush
