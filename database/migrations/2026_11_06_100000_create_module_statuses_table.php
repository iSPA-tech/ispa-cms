<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stores module enable/disable status per site for
 * App\Support\Modules\DatabaseActivator. The table lives on the connection
 * named in modules.activators.database.connection (null = default).
 */
return new class extends Migration
{
    /**
     * Resolve the connection the activator reads from.
     *
     * @return string|null Connection name, null for the default connection.
     */
    public function getConnection(): ?string
    {
        return config('modules.activators.database.connection');
    }

    /**
     * Create the module_statuses table.
     */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());

        if ($schema->hasTable('module_statuses')) {
            return;
        }

        $schema->create('module_statuses', static function (Blueprint $table): void {
            $table->string('name', 64)->primary();
            $table->boolean('enabled');
            $table->string('version', 32)->nullable();
            $table->unsignedBigInteger('changed_by')->nullable();
            $table->timestamp('changed_at')->nullable();
        });
    }

    /**
     * Drop the module_statuses table.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists('module_statuses');
    }
};
