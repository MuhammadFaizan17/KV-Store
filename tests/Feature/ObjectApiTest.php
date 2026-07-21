<?php

namespace Tests\Feature;

use App\Models\KeyValueEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObjectApiTest extends TestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────
    // STORE (POST /object)
    // ──────────────────────────────────────────────────────────

    public function test_can_store_simple_string_value(): void
    {
        $response = $this->postJson('/api/v1/object', ['mykey' => 'value1']);

        $response->assertStatus(201)
                 ->assertJsonStructure(['message', 'stored'])
                 ->assertJsonFragment(['message' => 'Stored successfully', 'key' => 'mykey']);

        $this->assertDatabaseHas('key_value_entries', ['key' => 'mykey', 'value' => '"value1"']);
    }

    public function test_can_store_json_blob_value(): void
    {
        $data = ['nested' => ['value' => 'test'], 'array' => [1, 2, 3]];
        $response = $this->postJson('/api/v1/object', ['complex_key' => $data]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('key_value_entries', ['key' => 'complex_key']);
    }

    public function test_can_store_multiple_keys_in_one_request(): void
    {
        $response = $this->postJson('/api/v1/object', [
            'key1' => 'value1',
            'key2' => 'value2',
            'key3' => 'value3',
        ]);

        $response->assertStatus(201)
                 ->assertJsonCount(3, 'stored');

        $this->assertDatabaseHas('key_value_entries', ['key' => 'key1']);
        $this->assertDatabaseHas('key_value_entries', ['key' => 'key2']);
        $this->assertDatabaseHas('key_value_entries', ['key' => 'key3']);
    }

    public function test_two_stores_of_same_key_create_two_history_entries(): void
    {
        $this->postJson('/api/v1/object', ['mykey' => 'value1']);
        $this->postJson('/api/v1/object', ['mykey' => 'value2']);

        $entries = KeyValueEntry::where('key', 'mykey')->orderBy('timestamp')->get();
        $this->assertCount(2, $entries);
        $this->assertEquals('value1', $entries[0]->value);
        $this->assertEquals('value2', $entries[1]->value);
    }

    public function test_versions_increment_on_each_update_for_same_key(): void
    {
        $first = $this->postJson('/api/v1/object', ['mykey' => 'value1']);
        $second = $this->postJson('/api/v1/object', ['mykey' => 'value2']);

        $first->assertStatus(201)
            ->assertJsonPath('stored.0.version', 1);

        $second->assertStatus(201)
            ->assertJsonPath('stored.0.version', 2);

        $latest = $this->getJson('/api/v1/object/mykey');
        $latest->assertStatus(200)
            ->assertJsonPath('version', 2)
            ->assertJsonPath('value', 'value2');
    }

    public function test_returns_422_for_empty_body(): void
    {
        $response = $this->postJson('/api/v1/object', []);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors('body');
    }

    public function test_returns_422_for_non_json_object_body(): void
    {
        // Send as array instead of object
        $response = $this->json('POST', '/api/v1/object', []);

        $response->assertStatus(422);
    }

    public function test_can_store_falsy_values(): void
    {
        $response = $this->postJson('/api/v1/object', [
            'null_key'  => null,
            'false_key' => false,
            'zero_key'  => 0,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('key_value_entries', ['key' => 'null_key']);
        $this->assertDatabaseHas('key_value_entries', ['key' => 'false_key']);
        $this->assertDatabaseHas('key_value_entries', ['key' => 'zero_key']);
    }

    public function test_can_store_key_with_special_characters(): void
    {
        $response = $this->postJson('/api/v1/object', [
            'key.with.dots'        => 'value1',
            'key-with-hyphens'     => 'value2',
            'key_with_underscores' => 'value3',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('key_value_entries', ['key' => 'key.with.dots']);
        $this->assertDatabaseHas('key_value_entries', ['key' => 'key-with-hyphens']);
    }

    public function test_can_store_key_with_max_length_255_chars(): void
    {
        $longKey = str_repeat('a', 255);
        $response = $this->postJson('/api/v1/object', [$longKey => 'value']);

        $response->assertStatus(201);
        $this->assertDatabaseHas('key_value_entries', ['key' => $longKey]);
    }

    public function test_returns_422_for_key_longer_than_255_chars(): void
    {
        $tooLongKey = str_repeat('a', 256);
        $response = $this->postJson('/api/v1/object', [$tooLongKey => 'value']);

        $response->assertStatus(422)
                 ->assertJsonValidationErrors('key');
    }

    public function test_can_store_array_as_value(): void
    {
        $response = $this->postJson('/api/v1/object', [
            'array_key' => [1, 2, 3, 4, 5],
        ]);

        $response->assertStatus(201);
    }

    public function test_can_store_boolean_as_value(): void
    {
        $response = $this->postJson('/api/v1/object', [
            'bool_true'  => true,
            'bool_false' => false,
        ]);

        $response->assertStatus(201);
    }

    public function test_can_store_integer_as_value(): void
    {
        $response = $this->postJson('/api/v1/object', [
            'int_key' => 42,
        ]);

        $response->assertStatus(201);
    }

    public function test_idempotency_key_replays_same_response_without_duplicate_write(): void
    {
        $headers = ['Idempotency-Key' => 'idem-001'];

        $first = $this->postJson('/api/v1/object', ['mykey' => 'value1'], $headers);
        $second = $this->postJson('/api/v1/object', ['mykey' => 'value1'], $headers);

        $first->assertStatus(201)
              ->assertHeader('Idempotent-Replayed', 'false');

        $second->assertStatus(201)
               ->assertHeader('Idempotent-Replayed', 'true')
               ->assertExactJson($first->json());

        $this->assertEquals(1, KeyValueEntry::where('key', 'mykey')->count());
    }

    public function test_idempotency_key_conflicts_on_different_payload(): void
    {
        $headers = ['Idempotency-Key' => 'idem-002'];

        $this->postJson('/api/v1/object', ['mykey' => 'value1'], $headers)->assertStatus(201);

        $response = $this->postJson('/api/v1/object', ['mykey' => 'value2'], $headers);

        $response->assertStatus(409)
                 ->assertJsonFragment([
                     'error' => 'Idempotency-Key has already been used with a different payload',
                 ]);

        $this->assertEquals(1, KeyValueEntry::where('key', 'mykey')->count());
    }

    public function test_returns_422_for_empty_idempotency_key_header(): void
    {
        $response = $this->postJson('/api/v1/object', ['mykey' => 'value1'], [
            'Idempotency-Key' => '   ',
        ]);

        $response->assertStatus(422)
                 ->assertJsonFragment([
                     'error' => 'Idempotency-Key header must not be empty',
                 ]);
    }

    // ──────────────────────────────────────────────────────────
    // GET LATEST (GET /object/{key})
    // ──────────────────────────────────────────────────────────

    public function test_get_returns_latest_value_after_one_store(): void
    {
        $this->postJson('/api/v1/object', ['mykey' => 'value1']);

        $response = $this->getJson('/api/v1/object/mykey');

        $response->assertStatus(200)
                 ->assertJsonFragment(['key' => 'mykey', 'value' => 'value1']);
    }

    public function test_get_returns_latest_value_after_multiple_stores(): void
    {
        $this->postJson('/api/v1/object', ['mykey' => 'value1']);
        $this->postJson('/api/v1/object', ['mykey' => 'value2']);
        $this->postJson('/api/v1/object', ['mykey' => 'value3']);

        $response = $this->getJson('/api/v1/object/mykey');

        $response->assertStatus(200)
                 ->assertJsonFragment(['value' => 'value3']);
    }

    public function test_returns_404_for_unknown_key(): void
    {
        $response = $this->getJson('/api/v1/object/nonexistent');

        $response->assertStatus(404)
                 ->assertJsonFragment(['error' => "No value found for key 'nonexistent'."]);
    }

    public function test_get_key_with_special_characters(): void
    {
        $this->postJson('/api/v1/object', ['key.with.dots' => 'value']);

        $response = $this->getJson('/api/v1/object/key.with.dots');

        $response->assertStatus(200)
                 ->assertJsonFragment(['value' => 'value']);
    }

    public function test_get_key_with_hyphens(): void
    {
        $this->postJson('/api/v1/object', ['key-with-hyphens' => 'value']);

        $response = $this->getJson('/api/v1/object/key-with-hyphens');

        $response->assertStatus(200);
    }

    // ──────────────────────────────────────────────────────────
    // TIME-TRAVEL (GET /object/{key}?timestamp=X)
    // ──────────────────────────────────────────────────────────

    public function test_timestamp_query_returns_historical_value(): void
    {
        // Create two versions with specific timestamps
        KeyValueEntry::create(['key' => 'mykey', 'value' => 'value1', 'timestamp' => 1000]);
        KeyValueEntry::create(['key' => 'mykey', 'value' => 'value2', 'timestamp' => 2000]);

        // Query at timestamp 1500 (between the two) - should get value1
        $response = $this->getJson('/api/v1/object/mykey?timestamp=1500');

        $response->assertStatus(200)
                 ->assertJsonFragment(['value' => 'value1', 'timestamp' => 1000]);
    }

    public function test_timestamp_query_exact_match(): void
    {
        KeyValueEntry::create(['key' => 'mykey', 'value' => 'value1', 'timestamp' => 1440568800]);

        $response = $this->getJson('/api/v1/object/mykey?timestamp=1440568800');

        $response->assertStatus(200)
                 ->assertJsonFragment(['value' => 'value1', 'timestamp' => 1440568800]);
    }

    public function test_timestamp_query_at_or_before_latest(): void
    {
        KeyValueEntry::create(['key' => 'mykey', 'value' => 'value1', 'timestamp' => 1440568800]);
        KeyValueEntry::create(['key' => 'mykey', 'value' => 'value2', 'timestamp' => 1440569100]);

        // Query at 1440569100 - should get value2
        $response = $this->getJson('/api/v1/object/mykey?timestamp=1440569100');

        $response->assertStatus(200)
                 ->assertJsonFragment(['value' => 'value2']);
    }

    public function test_timestamp_before_first_write_returns_404(): void
    {
        KeyValueEntry::create(['key' => 'mykey', 'value' => 'value1', 'timestamp' => 1440568800]);

        $response = $this->getJson('/api/v1/object/mykey?timestamp=1000');

        $response->assertStatus(404)
                 ->assertJsonFragment(['error' => "No value found for key 'mykey' at or before timestamp 1000."]);
    }

    public function test_returns_422_for_non_numeric_timestamp(): void
    {
        $response = $this->getJson('/api/v1/object/mykey?timestamp=abc');

        $response->assertStatus(422)
                 ->assertJsonFragment(['error' => 'timestamp must be a non-negative integer']);
    }

    public function test_returns_422_for_float_timestamp(): void
    {
        $response = $this->getJson('/api/v1/object/mykey?timestamp=1440568980.5');

        $response->assertStatus(422)
                 ->assertJsonFragment(['error' => 'timestamp must be a non-negative integer']);
    }

    public function test_returns_422_for_negative_timestamp(): void
    {
        $response = $this->getJson('/api/v1/object/mykey?timestamp=-100');

        $response->assertStatus(422)
                 ->assertJsonFragment(['error' => 'timestamp must be a non-negative integer']);
    }

    public function test_timestamp_zero_is_valid(): void
    {
        KeyValueEntry::create(['key' => 'mykey', 'value' => 'value1', 'timestamp' => 1000]);

        $response = $this->getJson('/api/v1/object/mykey?timestamp=0');

        $response->assertStatus(404);
    }

    // ──────────────────────────────────────────────────────────
    // GET ALL RECORDS (GET /object/get_all_records)
    // ──────────────────────────────────────────────────────────

    public function test_get_all_records_returns_empty_array_when_empty(): void
    {
        $response = $this->getJson('/api/v1/object/get_all_records');

        $response->assertStatus(200)
                 ->assertExactJson([]);
    }

    public function test_get_all_records_returns_one_entry_per_key(): void
    {
        KeyValueEntry::create(['key' => 'key1', 'value' => 'value1', 'timestamp' => 1000]);
        KeyValueEntry::create(['key' => 'key2', 'value' => 'value2', 'timestamp' => 1000]);
        KeyValueEntry::create(['key' => 'key3', 'value' => 'value3', 'timestamp' => 1000]);

        $response = $this->getJson('/api/v1/object/get_all_records');

        $response->assertStatus(200)
                 ->assertJsonCount(3);
    }

    public function test_get_all_records_returns_only_latest_per_key(): void
    {
        // Create multiple versions of key1
        KeyValueEntry::create(['key' => 'key1', 'value' => 'value1_v1', 'timestamp' => 1000]);
        KeyValueEntry::create(['key' => 'key1', 'value' => 'value1_v2', 'timestamp' => 2000]);
        KeyValueEntry::create(['key' => 'key1', 'value' => 'value1_v3', 'timestamp' => 3000]);

        $response = $this->getJson('/api/v1/object/get_all_records');

        $response->assertStatus(200)
                 ->assertJsonCount(1)
                 ->assertJsonFragment(['value' => 'value1_v3']);
    }

    public function test_get_all_records_supports_limit_and_cursor_header(): void
    {
        KeyValueEntry::create(['key' => 'a', 'value' => 'va', 'timestamp' => 1000]);
        KeyValueEntry::create(['key' => 'b', 'value' => 'vb', 'timestamp' => 1000]);
        KeyValueEntry::create(['key' => 'c', 'value' => 'vc', 'timestamp' => 1000]);

        $page1 = $this->getJson('/api/v1/object/get_all_records?limit=2');

        $page1->assertStatus(200)
            ->assertHeader('X-Next-After-Key', 'b')
            ->assertJsonCount(2)
            ->assertJsonPath('0.key', 'a')
            ->assertJsonPath('1.key', 'b');

        $page2 = $this->getJson('/api/v1/object/get_all_records?limit=2&after_key=b');

        $page2->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonPath('0.key', 'c');
    }

    public function test_get_all_records_returns_422_for_invalid_limit(): void
    {
        $response = $this->getJson('/api/v1/object/get_all_records?limit=0');

        $response->assertStatus(422)
                 ->assertJsonFragment([
                     'error' => 'limit must be an integer between 1 and 1000',
                 ]);
    }

    public function test_get_all_records_reflects_latest_value(): void
    {
        KeyValueEntry::create(['key' => 'key1', 'value' => 'v1', 'timestamp' => 1000]);
        KeyValueEntry::create(['key' => 'key2', 'value' => 'v2', 'timestamp' => 1000]);
        KeyValueEntry::create(['key' => 'key1', 'value' => 'v1_updated', 'timestamp' => 2000]);

        $response = $this->getJson('/api/v1/object/get_all_records');

        $response->assertStatus(200)
                 ->assertJsonCount(2);

        $data = $response->json();
        $key1Record = collect($data)->firstWhere('key', 'key1');
        $this->assertEquals('v1_updated', $key1Record['value']);
    }

    public function test_get_all_records_does_not_collide_with_literal_key(): void
    {
        // Store a key literally named "get_all_records"
        $this->postJson('/api/v1/object', ['get_all_records' => 'special_value']);

        // GET /object/get_all_records should return all records, not that specific key
        $response = $this->getJson('/api/v1/object/get_all_records');

        $response->assertStatus(200)
                 ->assertJsonCount(1)
                 ->assertJsonFragment(['key' => 'get_all_records', 'value' => 'special_value']);
    }

    // ──────────────────────────────────────────────────────────
    // EDGE CASES
    // ──────────────────────────────────────────────────────────

    public function test_store_then_immediately_query_returns_stored_value(): void
    {
        $this->postJson('/api/v1/object', ['mykey' => 'myvalue']);

        $response = $this->getJson('/api/v1/object/mykey');

        $response->assertStatus(200)
                 ->assertJsonFragment(['value' => 'myvalue']);
    }

    public function test_concurrent_stores_both_stored(): void
    {
        $this->postJson('/api/v1/object', ['mykey' => 'value1']);
        $this->postJson('/api/v1/object', ['mykey' => 'value2']);

        $entries = KeyValueEntry::where('key', 'mykey')->count();

        $this->assertEquals(2, $entries);
    }

    public function test_response_includes_timestamp(): void
    {
        $this->postJson('/api/v1/object', ['mykey' => 'value']);

        $response = $this->getJson('/api/v1/object/mykey');

        $response->assertStatus(200)
                 ->assertJsonStructure(['key', 'value', 'timestamp']);

        $this->assertIsInt($response['timestamp']);
        $this->assertGreaterThan(0, $response['timestamp']);
    }
}
