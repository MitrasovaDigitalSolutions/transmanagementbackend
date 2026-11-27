<?php

namespace Tests\Feature;

use Tests\TestCase;

class DocsTest extends TestCase
{
    public function test_docs_and_openapi_are_served(): void
    {
        $this->get('/docs')
            ->assertOk()
            ->assertSee('TransManagement API Documentation');

        $response = $this->get('/openapi.json');

        $response->assertOk();
        $this->assertIsArray(json_decode(
            file_get_contents(public_path('openapi.json')),
            true,
            512,
            JSON_THROW_ON_ERROR
        ));
    }
}
