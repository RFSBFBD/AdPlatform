<?php

namespace Tests\Feature;

use Tests\TestCase;

final class UnauthenticatedApiTest extends TestCase
{
    public function test_protected_api_routes_answer_401_even_without_an_accept_header(): void
    {
        $this->call('GET', '/api/v1/analytics/audience-saturation')->assertStatus(401);
        $this->call('GET', '/api/v1/analytics/platform-comparison')->assertStatus(401);
    }
}
