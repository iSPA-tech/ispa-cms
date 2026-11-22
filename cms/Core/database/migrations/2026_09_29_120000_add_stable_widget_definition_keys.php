<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $widgets = DB::table('widgets')->get(['id', 'name']);
        $themes = DB::table('widget_themes')->get(['id', 'widget_id', 'name', 'project_name']);
        $inputs = DB::table('widget_inputs')->get(['id', 'widget_id', 'name']);

        // Every existing row gets a legacy key. A project that wants readable keys for its own definitions re-keys them
        // in a migration of its own (projects/<Project>/databases/migrations), after this one.
        $widgetKeys = [];
        $themeKeys = [];
        $inputKeys = [];
        $seen = [];
        foreach ($widgets as $widget) {
            $key = 'legacy.widget-' . $widget->id;
            if (strlen($key) > 191) {
                throw new RuntimeException("Invalid widget key for row {$widget->id}: {$key}");
            }
            if (isset($seen[$key])) {
                throw new RuntimeException("Duplicate widget key: {$key}");
            }
            $seen[$key] = true;
            $widgetKeys[$widget->id] = $key;
        }

        $seen = [];
        foreach ($themes as $theme) {
            $key = 'legacy.theme-' . $theme->id;
            if (strlen($key) > 191) {
                throw new RuntimeException("Invalid theme key for row {$theme->id}: {$key}");
            }
            $scope = ($theme->widget_id ?? 'orphan') . ':' . $key;
            if (isset($seen[$scope])) {
                throw new RuntimeException("Duplicate theme key in widget {$theme->widget_id}: {$key}");
            }
            $seen[$scope] = true;
            $themeKeys[$theme->id] = $key;
        }

        $seen = [];
        foreach ($inputs as $input) {
            $key = 'legacy.input-' . $input->id;
            if (strlen($key) > 191) {
                throw new RuntimeException("Invalid input key for row {$input->id}: {$key}");
            }
            $scope = $input->widget_id . ':' . $key;
            if (isset($seen[$scope])) {
                throw new RuntimeException("Duplicate input key in widget {$input->widget_id}: {$key}");
            }
            $seen[$scope] = true;
            $inputKeys[$input->id] = $key;
        }

        Schema::table('widgets', static fn (Blueprint $table) => $table->string('widget_key', 191)->nullable());
        Schema::table('widget_themes', static fn (Blueprint $table) => $table->string('theme_key', 191)->nullable());
        Schema::table('widget_inputs', static fn (Blueprint $table) => $table->string('input_key', 191)->nullable());

        foreach ($widgetKeys as $id => $key) {
            DB::table('widgets')->where('id', $id)->update(['widget_key' => $key]);
        }
        foreach ($themeKeys as $id => $key) {
            DB::table('widget_themes')->where('id', $id)->update(['theme_key' => $key]);
        }
        foreach ($inputKeys as $id => $key) {
            DB::table('widget_inputs')->where('id', $id)->update(['input_key' => $key]);
        }

        if (DB::getDriverName() === 'sqlite') {
            Schema::disableForeignKeyConstraints();
        }
        try {
            Schema::table('widgets', static function (Blueprint $table): void {
                $table->string('widget_key', 191)->nullable(false)->change();
                $table->unique('widget_key', 'widgets_widget_key_unique');
            });
            Schema::table('widget_themes', static function (Blueprint $table): void {
                $table->string('theme_key', 191)->nullable(false)->change();
                $table->unique(['widget_id', 'theme_key'], 'widget_themes_widget_key_unique');
            });
            Schema::table('widget_inputs', static function (Blueprint $table): void {
                $table->string('input_key', 191)->nullable(false)->change();
                $table->unique(['widget_id', 'input_key'], 'widget_inputs_widget_key_unique');
            });
        } finally {
            if (DB::getDriverName() === 'sqlite') {
                Schema::enableForeignKeyConstraints();
            }
        }
    }

    public function down(): void
    {
        Schema::table('widget_inputs', static function (Blueprint $table): void {
            $table->dropUnique('widget_inputs_widget_key_unique');
            $table->dropColumn('input_key');
        });
        Schema::table('widget_themes', static function (Blueprint $table): void {
            $table->dropUnique('widget_themes_widget_key_unique');
            $table->dropColumn('theme_key');
        });
        Schema::table('widgets', static function (Blueprint $table): void {
            $table->dropUnique('widgets_widget_key_unique');
            $table->dropColumn('widget_key');
        });
    }
};
