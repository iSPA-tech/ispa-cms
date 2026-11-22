<?php

namespace Tests\Unit\Services;

use Tests\TestCase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Session;
use App\Services\TemplatePreviewService;

class TemplatePreviewServiceTest extends TestCase
{
    public function test_render_returns_file_content(): void
    {
        config(['ispa-cms.folder_name' => 'TempProj']);
        $base = base_path('projects/TempProj/dev/template');
        File::ensureDirectoryExists($base);
        $content = 'hello';
        File::put("$base/sample.blade.php", $content);
        // Prime session to bypass DB calls in AppComposer during view composition
        config(['app.locale' => 'vi']);
        Session::put('language-locale-vi', (object)[
            'id' => 1, 'name' => 'Vietnamese', 'path' => 'vi', 'path_alias' => 'vi', 'flag' => 'vn', 'currency' => 'VND',
        ]);
        Session::put('language-locales', []);

        $service = new TemplatePreviewService();
        $output = $service->render('sample');
        $this->assertEquals('hello', trim($output));

        File::delete("$base/sample.blade.php");
    }
}
