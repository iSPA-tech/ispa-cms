<?php

namespace Tests\Unit;

use App\Http\Controllers\V3\Public\Concerns\SerializesPublicPayloadOnce;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * Contract for the helper that stops V3 public endpoints serializing twice.
 *
 * These endpoints write their payload to a Redis key acme-web reads, and
 * RedisHelper json_encodes whatever it is given — so an Eloquent payload was
 * encoded once for the cache and once for the response, re-firing every
 * appended accessor both times. Converting up front pays it once, but only if
 * the JSON comes out the same.
 *
 * @author iSPA Team
 * @since  8.1.0 (2026-08-03)
 */
class SerializesPublicPayloadOnceTest extends TestCase
{
    use SerializesPublicPayloadOnce;

    /** An Arrayable payload becomes an array, and the JSON is unchanged. */
    public function test_arrayable_payload_is_converted_without_changing_json(): void
    {
        $payload = new Collection([
            ['id' => 1, 'name' => 'Thị trường'],
            ['id' => 2, 'name' => 'An toàn'],
        ]);

        $converted = $this->serializeOnce($payload);

        $this->assertIsArray($converted);
        $this->assertSame(json_encode($payload), json_encode($converted));
    }

    /**
     * An empty payload must stay an object.
     *
     * AppResponse decides whether to emit `data` with a plain truthiness check
     * (`if ($this->iData)`), and an empty array is falsy where an empty
     * Collection is not. Converting an empty collection would silently drop the
     * `data` key from the response.
     */
    public function test_empty_arrayable_payload_is_left_alone(): void
    {
        $payload = new Collection();

        $converted = $this->serializeOnce($payload);

        $this->assertSame($payload, $converted);
        $this->assertTrue((bool) $converted, 'an empty payload must stay truthy for AppResponse');
    }

    /** A plain array is walked, so nested models are converted too. */
    public function test_nested_arrayables_inside_an_array_are_converted(): void
    {
        $payload = [
            'tags' => new Collection([['id' => 7]]),
            'tag' => new Collection([['slug' => 'xe-dien']]),
            'count' => 1,
        ];

        $converted = $this->serializeOnce($payload);

        $this->assertIsArray($converted['tags']);
        $this->assertIsArray($converted['tag']);
        $this->assertSame(1, $converted['count']);
        $this->assertSame(json_encode($payload), json_encode($converted));
    }

    /** Scalars and nulls pass straight through. */
    public function test_scalar_payloads_pass_through(): void
    {
        $this->assertSame('plain', $this->serializeOnce('plain'));
        $this->assertNull($this->serializeOnce(null));
        $this->assertSame(42, $this->serializeOnce(42));
    }
}
