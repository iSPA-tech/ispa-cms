<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class LibraryScanController extends Controller
{
    public function scan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'string'],
        ]);

        $image = $this->base64ImageContent($validated['image']);
        if (strlen($image) > 10 * 1024 * 1024) {
            return response()->json(['error' => 'Image payload is too large.'], 422);
        }

        $apiKey = (string) config('services.google_vision.api_key', '');
        if ($apiKey === '') {
            $apiKey = (string)config('ispa-cms.ggApi');
        }

        if ($apiKey === '') {
            return response()->json(['error' => 'Google Vision API key is not configured.'], 500);
        }

        try {
            $response = Http::timeout(30)->post(
                'https://vision.googleapis.com/v1/images:annotate?key=' . urlencode($apiKey),
                [
                    'requests' => [[
                        'image' => ['content' => $image],
                        'features' => [
                            ['type' => 'DOCUMENT_TEXT_DETECTION'],
                            ['type' => 'LABEL_DETECTION'],
                        ],
                        'imageContext' => [
                            'languageHints' => ['vi', 'en'],
                        ],
                    ]],
                ]
            );

            if (!$response->successful()) {
                return response()->json([
                    'error' => 'Google Vision request failed.',
                    'status' => $response->status(),
                ], 502);
            }

            $vision = $response->json('responses.0', []);
            if (isset($vision['error'])) {
                return response()->json([
                    'error' => $vision['error']['message'] ?? 'Google Vision returned an error.',
                ], 502);
            }

            $text = (string)data_get($vision, 'fullTextAnnotation.text', '');
            $labels = collect(data_get($vision, 'labelAnnotations', []))
                ->pluck('description')
                ->filter()
                ->values()
                ->all();

            return response()->json([
                'success' => true,
                'text' => $text,
                'labels' => $labels,
                'details' => $this->parseBookDetails($text),
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Failed to process image.'], 500);
        }
    }

    public function storeBook(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:2024'],
            'author' => ['nullable', 'string', 'max:512'],
            'isbn' => ['nullable', 'string', 'max:1024'],
            'summary' => ['nullable', 'string'],
            'cover_image' => ['nullable', 'string', 'max:10485760'],
        ]);

        try {
            $connection = DB::connection((string) config('services.smart_library.connection', 'pgsql'));

            $book = $connection->transaction(function () use ($connection, $validated): array {
                $authorId = 0;
                $author = trim((string)($validated['author'] ?? ''));

                if ($author !== '') {
                    $existingAuthor = $connection->table('author')
                        ->whereRaw('lower(author_full_name) = ?', [Str::lower($author)])
                        ->where('is_deleted', 0)
                        ->first(['author_id']);

                    $authorId = $existingAuthor?->author_id ?? $connection->table('author')->insertGetId([
                        'author_full_name' => $author,
                        'author_display_name' => $author,
                        'created_by' => 'flutter_scan',
                        'created_at' => now(),
                    ], 'author_id');
                }

                $documentId = $connection->table('documents')->insertGetId([
                    'title' => $validated['title'],
                    'isbn' => $validated['isbn'] ?? null,
                    'summary' => $validated['summary'] ?? null,
                    'cover_image' => $validated['cover_image'] ?? null,
                    'author_id' => $authorId,
                    'created_by' => 'flutter_scan',
                    'created_at' => now(),
                ], 'documents_id');

                return [
                    'documents_id' => $documentId,
                    'title' => $validated['title'],
                    'author_id' => $authorId,
                    'author' => $author,
                    'isbn' => $validated['isbn'] ?? null,
                ];
            });

            return response()->json([
                'success' => true,
                'book' => $book,
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['error' => 'Failed to save book.'], 500);
        }
    }

    private function base64ImageContent(string $image): string
    {
        return preg_replace('/^data:image\/[a-zA-Z0-9.+-]+;base64,/', '', $image) ?? $image;
    }

    /**
     * @return array{title: string, author: string, isbn: string}
     */
    private function parseBookDetails(string $text): array
    {
        $lines = collect(preg_split('/\R/', $text) ?: [])
            ->map(static fn (string $line): string => trim($line))
            ->filter()
            ->values();

        $title = (string)($lines->first() ?? '');
        $author = '';
        $isbn = '';

        foreach ($lines as $line) {
            if ($isbn === '' && preg_match('/ISBN\s?([0-9]{10,13}|[0-9-]{10,17})/i', $line, $match)) {
                $isbn = $match[1];
            }

            if ($author === '' && str_contains(Str::lower($line), 'by ')) {
                $author = trim((string)preg_replace('/by /i', '', $line, 1));
            }
        }

        if ($author === '' && $lines->count() > 1) {
            $author = (string)$lines->get(1);
        }

        return [
            'title' => $title,
            'author' => $author,
            'isbn' => $isbn,
        ];
    }
}
