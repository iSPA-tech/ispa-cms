@if (isset($audios) && count($audios) > 0)
    <div class="pt-6">
        <h3 class="font-bold">Audio guide</h3>
        <audio controls>
            <source src="{{ $audios[0]->url }}" type="{{ $audios[0]->mime_type }}">
            Your browser does not support the audio element.
        </audio>
    </div>
@endif
