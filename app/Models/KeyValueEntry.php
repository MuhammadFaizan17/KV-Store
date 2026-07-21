<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class KeyValueEntry extends Model
{
    protected $table = 'key_value_entries';

    protected $fillable = ['key', 'version', 'value', 'timestamp'];

    protected $casts = [
        'version' => 'integer',
        'timestamp' => 'integer',
    ];

    public $timestamps = true;

    protected function value(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => $value === null ? null : json_decode($value, true),
            set: fn ($value) => json_encode($value, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Get the latest entry for a given key.
     *
     * @param string $key
     * @return self|null
     */
    public static function latest(string $key): ?self
    {
        return static::where('key', $key)
            ->orderByDesc('version')
            ->orderByDesc('timestamp')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Get the value of a key at or before a given UTC UNIX timestamp.
     * Uses the composite index (key, timestamp) for O(log n) lookup.
     *
     * @param string $key
     * @param int $timestamp
     * @return self|null
     */
    public static function atTimestamp(string $key, int $timestamp): ?self
    {
        return static::where('key', $key)
            ->where('timestamp', '<=', $timestamp)
            ->orderByDesc('timestamp')
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Get the latest value for every distinct key.
     * Uses a subquery to avoid loading all history into memory.
     *
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public static function allLatest(int $limit = 100, ?string $afterKey = null): \Illuminate\Database\Eloquent\Collection
    {
        $query = static::latestPerKeyQuery();

        if ($afterKey !== null && $afterKey !== '') {
            $query->where('key', '>', $afterKey);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Build the query that returns the latest row per key.
     */
    private static function latestPerKeyQuery(): Builder
    {
        $ranked = static::query()->selectRaw(
            'key_value_entries.*, row_number() over (partition by key_value_entries.key order by version desc, timestamp desc, id desc) as rn'
        );

        return static::query()
            ->fromSub($ranked, 'ranked')
            ->where('rn', 1)
            ->orderBy('key')
            ->select(['ranked.*']);
    }
}
