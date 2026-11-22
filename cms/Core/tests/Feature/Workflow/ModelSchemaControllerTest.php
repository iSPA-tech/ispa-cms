<?php

namespace Cms\Core\Tests\Feature\Workflow;

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Cms\Core\Tests\TestCase;

class ModelSchemaControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        Schema::dropIfExists('schema_orders');
        Schema::dropIfExists('schema_customers');

        Schema::create('schema_orders', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->timestamps();
        });

        Schema::create('schema_customers', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->timestamps();
        });
    }

    public function test_schema_returns_fields_and_relations(): void
    {
        $res = $this->getJson('/api/v2/admin/workflow/schema?model=' . urlencode(DummyOrder::class));

        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertEquals(DummyOrder::class, $data['model']);
        $this->assertTrue(collect($data['fields'])->contains(fn($f) => $f['name'] === 'name'));
        $this->assertTrue(collect($data['relations'])->contains(fn($r) => $r['name'] === 'customer'));
    }

    public function test_relation_schema_returns_related_model(): void
    {
        $res = $this->getJson('/api/v2/admin/workflow/schema/relation?model=' . urlencode(DummyOrder::class) . '&relation=customer');
        $res->assertStatus(200);
        $data = $res->json('data');
        $this->assertEquals(DummyCustomer::class, $data['model']);
    }

    public function test_invalid_model_returns_404(): void
    {
        $res = $this->getJson('/api/v2/admin/workflow/schema?model=Unknown\\Model');
        $res->assertStatus(200);
        $this->assertSame('Model class Unknown\\Model does not exist', $res->json('data.message'));
    }
}

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DummyCustomer extends Model
{
    protected $table = 'schema_customers';
    protected $guarded = [];
}

class DummyOrder extends Model
{
    protected $table = 'schema_orders';
    protected $guarded = [];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(DummyCustomer::class, 'customer_id');
    }
}
