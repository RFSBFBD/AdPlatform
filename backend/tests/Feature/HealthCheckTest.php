<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    /**
     * Test the API health check endpoint returns 200 and healthy services.
     */
    public function test_health_check_endpoint_returns_healthy(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
                 ->assertJsonPath('status', 'healthy')
                 ->assertJsonPath('services.database', 'healthy');
        // Redis is reported only when cache, queue or sessions use it; otherwise it is not_used.
        $this->assertContains($response->json('services.redis'), ['healthy', 'not_used']);
    }
}
