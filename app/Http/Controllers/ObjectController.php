<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreObjectRequest;
use App\Models\IdempotencyKey;
use App\Models\KeyValueEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ObjectController extends Controller
{
    /**
     * POST /object
     * Store one or more key-value pairs from the JSON body.
     * All entries get the same UNIX timestamp (current UTC time).
     *
     * @param StoreObjectRequest $request
     * @return JsonResponse
     */
    public function store(StoreObjectRequest $request): JsonResponse
    {
        $requestId = (string) $request->header('X-Request-Id', (string) Str::uuid());
        $idempotencyKey = $request->header('Idempotency-Key');
        $payload = $request->all();
        $payloadHash = hash('sha256', (string) $request->getContent());

        if ($idempotencyKey !== null && trim($idempotencyKey) === '') {
            return response()->json([
                'error' => 'Idempotency-Key header must not be empty',
            ], 422)->header('X-Request-Id', $requestId);
        }

        if ($idempotencyKey !== null && strlen($idempotencyKey) > 255) {
            return response()->json([
                'error' => 'Idempotency-Key header exceeds 255 characters',
            ], 422)->header('X-Request-Id', $requestId);
        }

        Log::info('kvstore.store.request', [
            'request_id' => $requestId,
            'key_count' => count($payload),
            'idempotency_key_present' => $idempotencyKey !== null,
        ]);

        $result = DB::transaction(function () use ($idempotencyKey, $payloadHash, $payload) {
            if ($idempotencyKey !== null) {
                $existing = IdempotencyKey::query()
                    ->where('key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if (!hash_equals($existing->request_hash, $payloadHash)) {
                        return [
                            'conflict' => true,
                        ];
                    }

                    return [
                        'conflict' => false,
                        'replayed' => true,
                        'status_code' => $existing->status_code,
                        'response_body' => $existing->response_body,
                    ];
                }
            }

            $timestamp = now()->utc()->unix();
            $stored = [];

            foreach ($payload as $key => $value) {
                $latestVersion = KeyValueEntry::query()
                    ->where('key', $key)
                    ->lockForUpdate()
                    ->max('version');

                $nextVersion = ((int) $latestVersion) + 1;

                KeyValueEntry::create([
                    'key' => $key,
                    'version' => $nextVersion,
                    'value' => $value,
                    'timestamp' => $timestamp,
                ]);

                $stored[] = [
                    'key' => $key,
                    'version' => $nextVersion,
                    'timestamp' => $timestamp,
                ];
            }

            $responseBody = [
                'message' => 'Stored successfully',
                'stored' => $stored,
            ];

            if ($idempotencyKey !== null) {
                IdempotencyKey::create([
                    'key' => $idempotencyKey,
                    'request_hash' => $payloadHash,
                    'status_code' => 201,
                    'response_body' => $responseBody,
                ]);
            }

            return [
                'conflict' => false,
                'replayed' => false,
                'status_code' => 201,
                'response_body' => $responseBody,
            ];
        }, 3);

        if ($result['conflict']) {
            Log::warning('kvstore.store.idempotency_conflict', [
                'request_id' => $requestId,
                'idempotency_key' => $idempotencyKey,
            ]);

            return response()->json([
                'error' => 'Idempotency-Key has already been used with a different payload',
            ], 409)->header('X-Request-Id', $requestId);
        }

        Log::info('kvstore.store.response', [
            'request_id' => $requestId,
            'status_code' => $result['status_code'],
            'replayed' => $result['replayed'],
        ]);

        return response()->json($result['response_body'], $result['status_code'])
            ->header('X-Request-Id', $requestId)
            ->header('Idempotent-Replayed', $result['replayed'] ? 'true' : 'false');
    }

    /**
     * GET /object/get_all_records
     * Return the latest value for every key.
     *
     * IMPORTANT: This route must be registered BEFORE /object/{key}
     * in routes/api.php or Laravel will treat "get_all_records" as a {key} param.
     *
     * @return JsonResponse
     */
    public function getAllRecords(): JsonResponse
    {
        $limitQuery = request()->query('limit');
        $afterKey = request()->query('after_key');

        if ($limitQuery !== null && (!ctype_digit((string) $limitQuery) || (int) $limitQuery < 1 || (int) $limitQuery > 1000)) {
            return response()->json([
                'error' => 'limit must be an integer between 1 and 1000',
            ], 422);
        }

        if ($afterKey !== null && strlen((string) $afterKey) > 255) {
            return response()->json([
                'error' => 'after_key must not exceed 255 characters',
            ], 422);
        }

        $limit = $limitQuery === null ? 100 : (int) $limitQuery;
        $records = KeyValueEntry::allLatest($limit, $afterKey)
            ->map(fn ($e) => [
                'key'       => $e->key,
                'version'   => $e->version,
                'value'     => $e->value,
                'timestamp' => $e->timestamp,
            ]);

        $response = response()->json($records);

        if ($records->count() === $limit) {
            $lastKey = $records->last()['key'] ?? null;
            if ($lastKey !== null) {
                $response->header('X-Next-After-Key', $lastKey);
            }
        }

        return $response;
    }

    /**
     * GET /object/{key}
     * GET /object/{key}?timestamp=<unix>
     *
     * Without timestamp: return latest value.
     * With timestamp: return value as it was AT OR BEFORE that UTC UNIX timestamp.
     *
     * @param Request $request
     * @param string $key
     * @return JsonResponse
     */
    public function show(Request $request, string $key): JsonResponse
    {
        $key = rawurldecode($key);
        $ts = $request->query('timestamp');

        // Validate timestamp query param if present
        if ($ts !== null) {
            if (!ctype_digit((string) $ts) || (int) $ts < 0) {
                return response()->json([
                    'error' => 'timestamp must be a non-negative integer',
                ], 422);
            }
            $entry = KeyValueEntry::atTimestamp($key, (int) $ts);
        } else {
            $entry = KeyValueEntry::latest($key);
        }

        if ($entry === null) {
            return response()->json([
                'error' => "No value found for key '{$key}'" .
                           ($ts !== null ? " at or before timestamp {$ts}." : '.'),
            ], 404);
        }

        return response()->json([
            'key'       => $entry->key,
            'version'   => $entry->version,
            'value'     => $entry->value,
            'timestamp' => $entry->timestamp,
        ]);
    }
}
