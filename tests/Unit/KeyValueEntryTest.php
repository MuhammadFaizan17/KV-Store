<?php

namespace Tests\Unit;

use App\Models\KeyValueEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KeyValueEntryTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_has_correct_fillable_attributes(): void
    {
        $entry = new KeyValueEntry();

        $this->assertEquals(['key', 'version', 'value', 'timestamp'], $entry->getFillable());
    }

    public function test_value_is_cast_to_array(): void
    {
        KeyValueEntry::create([
            'key'       => 'test',
            'value'     => ['nested' => 'value'],
            'timestamp' => 1000,
        ]);

        $entry = KeyValueEntry::first();

        $this->assertIsArray($entry->value);
        $this->assertEquals(['nested' => 'value'], $entry->value);
    }

    public function test_timestamp_is_cast_to_integer(): void
    {
        KeyValueEntry::create([
            'key'       => 'test',
            'version'   => 1,
            'value'     => 'value',
            'timestamp' => '1234567890',
        ]);

        $entry = KeyValueEntry::first();

        $this->assertIsInt($entry->timestamp);
        $this->assertEquals(1234567890, $entry->timestamp);
        $this->assertIsInt($entry->version);
    }

    public function test_latest_method_returns_most_recent_entry(): void
    {
        KeyValueEntry::create(['key' => 'mykey', 'value' => 'v1', 'timestamp' => 1000]);
        KeyValueEntry::create(['key' => 'mykey', 'value' => 'v2', 'timestamp' => 2000]);
        KeyValueEntry::create(['key' => 'mykey', 'value' => 'v3', 'timestamp' => 3000]);

        $entry = KeyValueEntry::latest('mykey');

        $this->assertEquals('v3', $entry->value);
        $this->assertEquals(3000, $entry->timestamp);
    }

    public function test_latest_method_returns_null_for_nonexistent_key(): void
    {
        $entry = KeyValueEntry::latest('nonexistent');

        $this->assertNull($entry);
    }

    public function test_at_timestamp_method_returns_value_at_exact_timestamp(): void
    {
        KeyValueEntry::create(['key' => 'mykey', 'value' => 'v1', 'timestamp' => 1440568800]);

        $entry = KeyValueEntry::atTimestamp('mykey', 1440568800);

        $this->assertEquals('v1', $entry->value);
    }

    public function test_at_timestamp_method_returns_value_before_given_timestamp(): void
    {
        KeyValueEntry::create(['key' => 'mykey', 'value' => 'v1', 'timestamp' => 1000]);
        KeyValueEntry::create(['key' => 'mykey', 'value' => 'v2', 'timestamp' => 2000]);

        $entry = KeyValueEntry::atTimestamp('mykey', 1500);

        $this->assertEquals('v1', $entry->value);
        $this->assertEquals(1000, $entry->timestamp);
    }

    public function test_at_timestamp_method_returns_null_before_first_entry(): void
    {
        KeyValueEntry::create(['key' => 'mykey', 'value' => 'v1', 'timestamp' => 1000]);

        $entry = KeyValueEntry::atTimestamp('mykey', 500);

        $this->assertNull($entry);
    }

    public function test_all_latest_returns_one_per_key(): void
    {
        KeyValueEntry::create(['key' => 'key1', 'value' => 'v1', 'timestamp' => 1000]);
        KeyValueEntry::create(['key' => 'key1', 'value' => 'v1_updated', 'timestamp' => 2000]);
        KeyValueEntry::create(['key' => 'key2', 'value' => 'v2', 'timestamp' => 1500]);

        $entries = KeyValueEntry::allLatest();

        $this->assertCount(2, $entries);
        $this->assertEquals('v1_updated', $entries->firstWhere('key', 'key1')->value);
        $this->assertEquals('v2', $entries->firstWhere('key', 'key2')->value);
    }

    public function test_all_latest_returns_empty_collection_when_empty(): void
    {
        $entries = KeyValueEntry::allLatest();

        $this->assertCount(0, $entries);
    }

    public function test_all_latest_honors_limit(): void
    {
        KeyValueEntry::create(['key' => 'a', 'value' => 'v1', 'timestamp' => 1000]);
        KeyValueEntry::create(['key' => 'b', 'value' => 'v2', 'timestamp' => 1000]);
        KeyValueEntry::create(['key' => 'c', 'value' => 'v3', 'timestamp' => 1000]);

        $entries = KeyValueEntry::allLatest(2);

        $this->assertCount(2, $entries);
        $this->assertEquals('a', $entries[0]->key);
        $this->assertEquals('b', $entries[1]->key);
    }

    public function test_all_latest_honors_after_key_cursor(): void
    {
        KeyValueEntry::create(['key' => 'a', 'value' => 'v1', 'timestamp' => 1000]);
        KeyValueEntry::create(['key' => 'b', 'value' => 'v2', 'timestamp' => 1000]);
        KeyValueEntry::create(['key' => 'c', 'value' => 'v3', 'timestamp' => 1000]);

        $entries = KeyValueEntry::allLatest(10, 'a');

        $this->assertCount(2, $entries);
        $this->assertEquals('b', $entries[0]->key);
        $this->assertEquals('c', $entries[1]->key);
    }

    public function test_model_uses_timestamps(): void
    {
        $entry = KeyValueEntry::create([
            'key'       => 'test',
            'value'     => 'value',
            'timestamp' => 1000,
        ]);

        $this->assertNotNull($entry->created_at);
        $this->assertNotNull($entry->updated_at);
    }
}
