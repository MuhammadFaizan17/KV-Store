<?php

namespace Tests\Feature;

use Tests\TestCase;

class SwaggerDocsTest extends TestCase
{
    public function test_openapi_json_is_available(): void
    {
        $this->getJson('/api/v1/openapi.json')
            ->assertOk()
            ->assertJsonFragment([
                'openapi' => '3.0.3',
            ])
            ->assertJsonPath('info.title', 'Secretlab KV Store API');
    }

    public function test_swagger_ui_page_is_available(): void
    {
        $this->get('/api/v1/docs')
            ->assertOk()
            ->assertSee('Secretlab KV Store API Docs')
            ->assertSee('SwaggerUIBundle');
    }
}
