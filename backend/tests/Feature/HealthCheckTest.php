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
                 ->assertJson([
                     'status' => 'healthy',
                     'services' => [
                         'database' => 'healthy',
                         'redis' => 'healthy',
                     ],
                 ]);
    }
}
