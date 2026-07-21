<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

class OpenApiController extends Controller
{
    public function ui(): View
    {
        return view('swagger-ui', [
            'specUrl' => route('api.openapi.json'),
        ]);
    }

    public function spec(): JsonResponse
    {
        return response()->json($this->document());
    }

    private function document(): array
    {
        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Secretlab KV Store API',
                'version' => '1.0.0',
                'description' => 'Swagger/OpenAPI documentation for the version-controlled key-value store API.',
            ],
            'servers' => [
                [
                    'url' => '/api/v1',
                    'description' => 'Version 1 API prefix',
                ],
            ],
            'paths' => [
                '/object' => [
                    'post' => [
                        'summary' => 'Store one or more key-value pairs',
                        'description' => 'Accepts a JSON object and appends a new immutable version for each key.',
                        'parameters' => [
                            [
                                'name' => 'Idempotency-Key',
                                'in' => 'header',
                                'required' => false,
                                'schema' => ['type' => 'string', 'maxLength' => 255],
                                'description' => 'Optional key to make POST retries safe. Replaying same key+payload returns original response.',
                            ],
                            [
                                'name' => 'X-Request-Id',
                                'in' => 'header',
                                'required' => false,
                                'schema' => ['type' => 'string', 'maxLength' => 255],
                                'description' => 'Optional request correlation id. If omitted, server generates one.',
                            ],
                        ],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'additionalProperties' => true,
                                        'minProperties' => 1,
                                    ],
                                    'examples' => [
                                        'single' => [
                                            'summary' => 'Single key',
                                            'value' => [
                                                'mykey' => 'value1',
                                            ],
                                        ],
                                        'multiple' => [
                                            'summary' => 'Multiple keys',
                                            'value' => [
                                                'alpha' => 'one',
                                                'beta' => ['nested' => true],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => [
                            '201' => [
                                'description' => 'Stored successfully',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'required' => ['message', 'stored'],
                                            'properties' => [
                                                'message' => ['type' => 'string'],
                                                'stored' => [
                                                    'type' => 'array',
                                                    'items' => [
                                                        'type' => 'object',
                                                        'required' => ['key', 'version', 'timestamp'],
                                                        'properties' => [
                                                            'key' => ['type' => 'string'],
                                                            'version' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 1],
                                                            'timestamp' => ['type' => 'integer', 'format' => 'int64'],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '422' => [
                                'description' => 'Validation error',
                            ],
                            '409' => [
                                'description' => 'Idempotency conflict (same key used with different payload)',
                            ],
                        ],
                    ],
                ],
                '/object/{key}' => [
                    'get' => [
                        'summary' => 'Get the latest or historical value for a key',
                        'parameters' => [
                            [
                                'name' => 'key',
                                'in' => 'path',
                                'required' => true,
                                'schema' => ['type' => 'string'],
                            ],
                            [
                                'name' => 'timestamp',
                                'in' => 'query',
                                'required' => false,
                                'schema' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 0],
                                'description' => 'UTC UNIX timestamp used for floor lookup.',
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Value found',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'required' => ['key', 'version', 'value', 'timestamp'],
                                            'properties' => [
                                                'key' => ['type' => 'string'],
                                                'version' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 1],
                                                'value' => [],
                                                'timestamp' => ['type' => 'integer', 'format' => 'int64'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '404' => [
                                'description' => 'Key not found',
                            ],
                            '422' => [
                                'description' => 'Invalid timestamp',
                            ],
                        ],
                    ],
                ],
                '/object/get_all_records' => [
                    'get' => [
                        'summary' => 'Get the latest record for every key',
                        'parameters' => [
                            [
                                'name' => 'limit',
                                'in' => 'query',
                                'required' => false,
                                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'default' => 100],
                                'description' => 'Page size for latest-per-key records.',
                            ],
                            [
                                'name' => 'after_key',
                                'in' => 'query',
                                'required' => false,
                                'schema' => ['type' => 'string', 'maxLength' => 255],
                                'description' => 'Cursor for keyset pagination. Returns keys lexicographically greater than this value.',
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Latest record per key',
                                'headers' => [
                                    'X-Next-After-Key' => [
                                        'description' => 'Pagination cursor for the next page. Present when a subsequent page may exist.',
                                        'schema' => ['type' => 'string'],
                                    ],
                                ],
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'required' => ['key', 'version', 'value', 'timestamp'],
                                                'properties' => [
                                                    'key' => ['type' => 'string'],
                                                    'version' => ['type' => 'integer', 'format' => 'int64', 'minimum' => 1],
                                                    'value' => [],
                                                    'timestamp' => ['type' => 'integer', 'format' => 'int64'],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                            '422' => [
                                'description' => 'Invalid pagination input',
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}