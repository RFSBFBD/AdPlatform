<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

// Prefix all API routes with /v1
Route::prefix('v1')->group(function () {

    // 1. System Health Check Endpoint
    Route::get('/health', function () {
        $dbStatus = 'unhealthy';
        $redisStatus = 'unhealthy';

        try {
            DB::connection()->getPdo();
            $dbStatus = 'healthy';
        } catch (\Throwable $e) {
            $dbStatus = 'error: ' . $e->getMessage();
        }

        try {
            Redis::connection()->ping();
            $redisStatus = 'healthy';
        } catch (\Throwable $e) {
            $redisStatus = 'error: ' . $e->getMessage();
        }

        $isHealthy = ($dbStatus === 'healthy' && $redisStatus === 'healthy');

        return response()->json([
            'status' => $isHealthy ? 'healthy' : 'degraded',
            'timestamp' => now()->toIso8601String(),
            'services' => [
                'database' => $dbStatus,
                'redis' => $redisStatus,
            ],
            'environment' => app()->environment(),
        ], $isHealthy ? 200 : 503);
    });

    // 2. Authentication Endpoints (Public)
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login']);
    });

    // 3. Protected Authenticated Endpoints
    Route::middleware('auth:sanctum')->group(function () {
        // Current user
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // User & Team Management (Restricted to manage-users permission)
        Route::middleware(['permission:manage-users'])->prefix('users')->group(function () {
            Route::get('/', [UserController::class, 'index']);
            Route::post('/', [UserController::class, 'store']);
            Route::post('/invite', [UserController::class, 'invite']);
            Route::put('/{id}/role', [UserController::class, 'updateRole']);
            Route::delete('/{id}', [UserController::class, 'destroy']);
        });

        // Developer API Settings Center (Restricted to manage-developers permission)
        Route::middleware(['permission:manage-developers'])->prefix('admin/developer-configs')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\DeveloperConfigController::class, 'index']);
            Route::put('/{provider}', [\App\Http\Controllers\Api\V1\DeveloperConfigController::class, 'update']);
            Route::post('/{provider}/test', [\App\Http\Controllers\Api\V1\DeveloperConfigController::class, 'testConnection']);
        });

        // Official Advertising Integrations & Connected Accounts
        Route::prefix('integrations')->group(function () {
            Route::get('/oauth/{provider}/redirect', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'getRedirectUrl']);
            Route::get('/oauth/{provider}/callback', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'handleCallback']);
            Route::post('/{provider}/connect', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'connectAccounts']);
        });

        Route::prefix('ad-accounts')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'listAccounts']);
            Route::delete('/{id}', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'disconnectAccount']);

            // Synchronization endpoints
            Route::post('/{id}/sync', [\App\Http\Controllers\Api\V1\SyncController::class, 'triggerSync']);
            Route::get('/{id}/sync-status', [\App\Http\Controllers\Api\V1\SyncController::class, 'getStatus']);
            Route::get('/{id}/sync-history', [\App\Http\Controllers\Api\V1\SyncController::class, 'getHistory']);
        });

        // Analytics & Reporting Endpoints
        Route::prefix('analytics')->group(function () {
            Route::get('/overview', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'overview']);
            Route::get('/data-coverage', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'dataCoverage']);
            Route::get('/suggestions', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'suggestions']);
            Route::get('/campaigns', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'campaigns']);
            Route::get('/campaigns/{id}', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'campaignDetails']);
            Route::get('/operations-research', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'operationsResearch']);
        });

        // Business profile (per-user inputs for scenarios; never shared across users)
        Route::get('/business-profile', [\App\Http\Controllers\Api\V1\BusinessProfileController::class, 'show']);
        Route::put('/business-profile', [\App\Http\Controllers\Api\V1\BusinessProfileController::class, 'update']);

        // Billing & Payment Information
        Route::prefix('billing')->group(function () {
            Route::get('/summary', [\App\Http\Controllers\Api\V1\BillingController::class, 'summary']);
            Route::get('/invoices', [\App\Http\Controllers\Api\V1\BillingController::class, 'invoices']);
        });

        // AI Integrations Center (BYOK & Custom Models)
        Route::prefix('ai')->group(function () {
            Route::get('/config', [\App\Http\Controllers\Api\V1\AiController::class, 'getConfig']);
            Route::put('/config/{provider}', [\App\Http\Controllers\Api\V1\AiController::class, 'updateConfig']);
            Route::delete('/config/{provider}', [\App\Http\Controllers\Api\V1\AiController::class, 'deleteConfig']);
            Route::post('/config/{provider}/test', [\App\Http\Controllers\Api\V1\AiController::class, 'testConnection']);
            Route::post('/chat', [\App\Http\Controllers\Api\V1\AiController::class, 'chat']);
            Route::post('/generate-copy', [\App\Http\Controllers\Api\V1\AiController::class, 'generateCopy']);
            Route::post('/analyze-performance', [\App\Http\Controllers\Api\V1\AiController::class, 'analyzePerformance']);
        });

        // Looker Studio BI Community Connector Endpoint
        Route::get('/reporting/looker-studio', [\App\Http\Controllers\Api\V1\LookerStudioController::class, 'handle']);
        Route::get('/looker', [\App\Http\Controllers\Api\V1\LookerStudioController::class, 'handle']);
    });
});
