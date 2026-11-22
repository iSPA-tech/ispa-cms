<h1 class="text-2xl font-bold">Template Preview List</h1>
<ul class="mt-4 space-y-2">
    @foreach ($files as $file)
        <li><a class="text-blue-600 hover:underline" href="{{ $file['url'] }}">{{ $file['name'] }}</a></li>
    @endforeach
</ul>
