<?php

use App\Http\Controllers\OpenApiController;
use App\Http\Controllers\ObjectController;
use Illuminate\Support\Facades\Route;

/**
 * Key-Value Store API Routes
 * 
 * IMPORTANT: The order of routes matters!
 * - get_all_records must come BEFORE {key} wildcard
 * - Otherwise Laravel will treat "get_all_records" as a {key} value
 */

Route::prefix('v1')->group(function () {
    Route::get('/docs', [OpenApiController::class, 'ui'])->name('api.docs');
    Route::get('/openapi.json', [OpenApiController::class, 'spec'])->name('api.openapi.json');

    Route::prefix('object')->group(function () {
        // Store a new key-value pair
        Route::post('/', [ObjectController::class, 'store']);

        // Get all records (latest value per key)
        Route::get('/get_all_records', [ObjectController::class, 'getAllRecords']);

        // Get a specific key (with optional timestamp for time-travel)
        Route::get('/{key}', [ObjectController::class, 'show'])
            ->where('key', '[^/]+');
    });
});
