<?php

use Cms\Widget\Constants\WidgetTableConstant as Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * widget_idempotency_keys: durable replay protection for Instance create/
 * update/clone, update-and-repin, publish, rollback, and post-MVP Safe
 * Import store (plan Chunk 2 Task 13a; architecture.md
 * "widget_idempotency_keys"; frozen contract
 * cms/Widget/tests/Fixtures/widget-site-layout-api-contract.json
 * "idempotency.retained_fields"/"banned_retained_fields").
 *
 * Retains ONLY actor/project/action/resource scope, the caller-provided
 * key, canonical request hash, processing/committed state, safe HTTP
 * status, and committed resource type/ID/version identity. Never stores
 * raw request values, provider payloads, preview overlays, or serialized
 * response bodies (proven absent by WidgetSiteLayoutSchemaTest).
 *
 * `resource_scope` defaults to '' (not nullable): MySQL/PostgreSQL treat
 * NULL as distinct in unique indexes, which would silently let duplicate
 * idempotency_key values through for scope-less actions (e.g.
 * instance_create) if the column were nullable.
 *
 * `project_name`/`resource_scope`/`idempotency_key` are explicitly bounded
 * (100/191/191 chars) rather than Laravel's default 255: the four-column
 * `widget_idem_scope_key_unique` unique index exceeded MySQL InnoDB's 3072
 * byte max index key length under utf8mb4 (4 bytes/char) at the default
 * width -- caught by this task's own dual-engine matrix run, not a
 * theoretical concern. This table has no FK relationships to/from any other
 * table, so narrowing these columns cannot break composite-FK type matching
 * elsewhere in this schema.
 *
 * No DB enum on `state` -- plain string, app-level processing|committed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create(Tables::IDEMPOTENCY_KEY, static function (Blueprint $table): void {
            $table->id();
            $table->string('project_name', 100);
            $table->string('action', 60);
            $table->string('resource_scope', 191)->default('');
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('idempotency_key', 191);
            $table->char('request_hash', 64);
            $table->string('state', 20)->default('processing');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('resource_type')->nullable();
            $table->string('resource_id')->nullable();
            $table->string('resource_version', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['project_name', 'action', 'resource_scope', 'idempotency_key'], 'widget_idem_scope_key_unique');
            $table->index('expires_at', 'widget_idem_expires_idx');
            $table->index(['project_name', 'action', 'state'], 'widget_idem_project_action_state_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::IDEMPOTENCY_KEY);
    }
};
