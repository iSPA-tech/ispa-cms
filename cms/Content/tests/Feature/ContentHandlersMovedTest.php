<?php

declare(strict_types=1);

use App\Events\TextToSpeechContentRequest;
use App\Events\TranslateContentRequest;
use Cms\Content\Jobs\CallApiToTextToSpeechJob;
use Cms\Content\Jobs\TextTranslationJob;
use Cms\Content\Listeners\TextToSpeechContentRequestHandler;
use Cms\Content\Listeners\TranslateContentRequestHandler;
use Cms\Content\Models\Content;
use Cms\Content\Policies\ContentPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

uses(\Tests\TestCase::class, RefreshDatabase::class);

/**
 * Collects the class names of the queued listeners registered for an event.
 *
 * @param string $event Event class name.
 *
 * @return array<int, string>
 */
function p11t21ListenerClasses(string $event): array
{
    $classes = [];
    foreach (Event::getListeners($event) as $listener) {
        $ref = new ReflectionFunction($listener);
        $use = $ref->getStaticVariables();
        $classes[] = is_string($use['listener'] ?? null) ? $use['listener'] : (is_array($use['listener'] ?? null) ? (string) $use['listener'][0] : 'unknown');
    }

    return $classes;
}

test('the translate and text-to-speech events reach the Content listeners exactly once', function () {
    expect(p11t21ListenerClasses(TranslateContentRequest::class))->toBe([TranslateContentRequestHandler::class]);
    expect(p11t21ListenerClasses(TextToSpeechContentRequest::class))->toBe([TextToSpeechContentRequestHandler::class]);
});

test('the old App class names are deprecated subclasses of the Content classes', function () {
    $pairs = [
        \App\Listeners\TranslateContentRequestHandler::class => TranslateContentRequestHandler::class,
        \App\Listeners\TextToSpeechContentRequestHandler::class => TextToSpeechContentRequestHandler::class,
        \App\Jobs\TextTranslationJob::class => TextTranslationJob::class,
        \App\Jobs\CallApiToTextToSpeechJob::class => CallApiToTextToSpeechJob::class,
    ];

    foreach ($pairs as $old => $new) {
        expect(is_subclass_of($old, $new))->toBeTrue();
        expect((new ReflectionClass($old))->getDocComment())->toContain('@deprecated');
    }
});

test('a job serialized under the old App name still unserializes and runs', function () {
    $payload = serialize(new \App\Jobs\TextTranslationJob());
    expect($payload)->toContain('App\\Jobs\\TextTranslationJob');

    $job = unserialize($payload);
    expect($job)->toBeInstanceOf(TextTranslationJob::class);
    $job->handle();

    $speech = unserialize(serialize(new \App\Jobs\CallApiToTextToSpeechJob(1, '1')));
    expect($speech)->toBeInstanceOf(CallApiToTextToSpeechJob::class);
});

test('the Content policy is the one that lives in Content', function () {
    expect(Gate::getPolicyFor(Content::class))->toBeInstanceOf(ContentPolicy::class);
    expect(file_exists(app_path('Policies/ContentPolicy.php')))->toBeFalse();
});

test('the App files no longer import Content classes for the moved handlers', function () {
    foreach (['Providers/EventServiceProvider.php', 'Providers/AuthServiceProvider.php'] as $file) {
        $src = (string) file_get_contents(app_path($file));
        expect($src)->not->toContain('App\\Listeners\\TranslateContentRequestHandler');
        expect($src)->not->toContain('App\\Listeners\\TextToSpeechContentRequestHandler');
        expect($src)->not->toContain('App\\Policies\\ContentPolicy');
    }
});
