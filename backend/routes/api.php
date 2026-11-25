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
        $redisStatus = 'not_used';

        try {
            DB::connection()->getPdo();
            $dbStatus = 'healthy';
        } catch (\Throwable $e) {
            // Never echo the driver message: it can contain host and credential details.
            $dbStatus = 'unreachable';
        }

        // Redis counts only when cache, queue or sessions are configured to use it (the Vercel setup uses Postgres instead).
        $usesRedis = in_array('redis', [config('cache.default'), config('queue.default'), config('session.driver')], true);
        if ($usesRedis) {
            try {
                Redis::connection()->ping();
                $redisStatus = 'healthy';
            } catch (\Throwable $e) {
                $redisStatus = 'unreachable';
            }
        }

        $isHealthy = $dbStatus === 'healthy' && in_array($redisStatus, ['healthy', 'not_used'], true);

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
            Route::post('/{provider}/credential-discovery', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'discoverCredentialAccounts']);
            Route::get('/oauth/{provider}/redirect', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'getRedirectUrl']);
            Route::get('/oauth/{provider}/callback', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'handleCallback']);
            Route::post('/{provider}/connect', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'connectAccounts']);
        });

        Route::prefix('ad-accounts')->group(function () {
            Route::get('/{id}/canonical-audiences', [\App\Http\Controllers\Api\V1\CanonicalAudienceController::class,'index']);
            Route::get('/{id}/audience-targeting-observations', [\App\Http\Controllers\Api\V1\CanonicalAudienceController::class,'observations']);
            Route::get('/{id}/canonical-creatives', [\App\Http\Controllers\Api\V1\CanonicalCreativeController::class,'index']);
            Route::get('/{id}/canonical-creatives/{creative}/observations', [\App\Http\Controllers\Api\V1\CanonicalCreativeController::class,'observations']);
            Route::get('/{id}/canonical-integrity', [\App\Http\Controllers\Api\V1\CanonicalIntegrityController::class,'show']);
            Route::post('/{id}/conversion-definitions/{definition}/events/{event}/journey', [\App\Http\Controllers\Api\V1\FirstPartyJourneyController::class,'store']);
            Route::post('/{id}/conversion-definitions/{definition}/stored-attribution', [\App\Http\Controllers\Api\V1\FirstPartyJourneyController::class,'attribute']);
            Route::get('/{id}/conversion-definitions/{definition}/events', [\App\Http\Controllers\Api\V1\FirstPartyEventController::class,'index']);
            Route::post('/{id}/conversion-definitions/{definition}/events', [\App\Http\Controllers\Api\V1\FirstPartyEventController::class,'store']);
            Route::get('/{id}/conversion-definitions', [\App\Http\Controllers\Api\V1\ConversionSourceController::class,'index']);
            Route::post('/{id}/conversion-definitions', [\App\Http\Controllers\Api\V1\ConversionSourceController::class,'store']);
            Route::post('/{id}/conversion-definitions/{definition}/daily-import', [\App\Http\Controllers\Api\V1\ConversionSourceController::class,'import']);
            Route::get('/', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'listAccounts']);
            Route::post('/{id}/amazon-reports', [\App\Http\Controllers\Api\V1\AmazonReportController::class, 'submit']);
            Route::get('/{id}/amazon-reports/{job}', [\App\Http\Controllers\Api\V1\AmazonReportController::class, 'status']);
            Route::post('/{id}/microsoft-reports', [\App\Http\Controllers\Api\V1\MicrosoftReportController::class, 'submit']);
            Route::get('/{id}/microsoft-reports/{job}', [\App\Http\Controllers\Api\V1\MicrosoftReportController::class, 'status']);
            Route::get('/{id}/x-delivery-report', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'xDeliveryReport']);
            Route::get('/{id}/apple-delivery-report', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'appleDeliveryReport']);
            Route::delete('/{id}', [\App\Http\Controllers\Api\V1\IntegrationController::class, 'disconnectAccount']);

            // Synchronization endpoints
            Route::post('/{id}/sync', [\App\Http\Controllers\Api\V1\SyncController::class, 'triggerSync']);
            Route::get('/{id}/sync-status', [\App\Http\Controllers\Api\V1\SyncController::class, 'getStatus']);
            Route::get('/{id}/sync-history', [\App\Http\Controllers\Api\V1\SyncController::class, 'getHistory']);
        });

        // Analytics & Reporting Endpoints
        Route::prefix('analytics')->group(function () {
            Route::get('/decision-evidence', [\App\Http\Controllers\Api\V1\DecisionEvidenceController::class,'index']);
            Route::post('/decision-evidence', [\App\Http\Controllers\Api\V1\DecisionEvidenceController::class,'preview']);
            Route::get('/decision-evidence/{id}', [\App\Http\Controllers\Api\V1\DecisionEvidenceController::class,'show'])->whereNumber('id');
            Route::get('/decision-evidence/{id}/question', [\App\Http\Controllers\Api\V1\DecisionEvidenceController::class,'question'])->whereNumber('id');
            Route::post('/decision-evidence/{id}/assist', [\App\Http\Controllers\Api\V1\DecisionEvidenceController::class,'assist'])->whereNumber('id');
            Route::post('/decision-evidence/{id}/custom-scenario', [\App\Http\Controllers\Api\V1\DecisionEvidenceController::class,'custom'])->whereNumber('id');
            Route::post('/decision-evidence/{id}/replay', [\App\Http\Controllers\Api\V1\DecisionEvidenceController::class,'replay'])->whereNumber('id');
            Route::post('/entity-optimization', [\App\Http\Controllers\Api\V1\EntityOptimizationController::class, 'run']);
            Route::get('/optimization-runs', [\App\Http\Controllers\Api\V1\OptimizationRunController::class, 'index']);
            Route::post('/optimization-runs/{id}/custom-leads', [\App\Http\Controllers\Api\V1\OptimizationRunController::class, 'customLead']);
            Route::post('/optimization-runs/{id}/compare', [\App\Http\Controllers\Api\V1\OptimizationRunController::class, 'compare']);
            Route::get('/optimization-runs/{id}/report', [\App\Http\Controllers\Api\V1\OptimizationRunController::class, 'report']);
            Route::get('/optimization-runs/{id}', [\App\Http\Controllers\Api\V1\OptimizationRunController::class, 'show']);
            Route::post('/optimization-runs/{id}/explain', [\App\Http\Controllers\Api\V1\OptimizationRunController::class, 'explain']);
            Route::post('/optimization-runs/{id}/replay', [\App\Http\Controllers\Api\V1\OptimizationRunController::class, 'replay']);
            Route::post('/attribution', [\App\Http\Controllers\Api\V1\AnalyticsDeepController::class, 'attribution']);
            Route::get('/overview', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'overview']);
            Route::get('/data-coverage', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'dataCoverage']);
            Route::get('/suggestions', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'suggestions']);
            Route::get('/campaigns', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'campaigns']);
            Route::get('/campaigns/{id}', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'campaignDetails']);
            Route::get('/operations-research', [\App\Http\Controllers\Api\V1\AnalyticsController::class, 'operationsResearch']);
            Route::get('/audience-matrix', [\App\Http\Controllers\Api\V1\AudienceMatrixController::class, 'show']);
            Route::get('/platform-comparison', [\App\Http\Controllers\Api\V1\PlatformComparisonController::class, 'show']);
            Route::get('/audience-saturation', [\App\Http\Controllers\Api\V1\AudienceSaturationController::class, 'show']);
            Route::get('/audience', [\App\Http\Controllers\Api\V1\AnalyticsDeepController::class, 'audience']);
            Route::get('/data-quality', [\App\Http\Controllers\Api\V1\AnalyticsDeepController::class, 'dataQuality']);
            Route::get('/campaign-health', [\App\Http\Controllers\Api\V1\CampaignHealthController::class, 'show']);
            Route::get('/creative-matrix', [\App\Http\Controllers\Api\V1\CreativeMatrixController::class, 'show']);
            Route::get('/creative-fatigue', [\App\Http\Controllers\Api\V1\CreativeFatigueController::class, 'show']);
            Route::get('/observed-series', [\App\Http\Controllers\Api\V1\ObservedSeriesController::class, 'show']);
            Route::get('/kpis', [\App\Http\Controllers\Api\V1\AnalyticsDeepController::class, 'kpis']);
            Route::get('/forecast', [\App\Http\Controllers\Api\V1\AnalyticsDeepController::class, 'forecast']);
            Route::get('/anomalies', [\App\Http\Controllers\Api\V1\AnalyticsDeepController::class, 'anomalies']);
            Route::get('/diagnostics', [\App\Http\Controllers\Api\V1\AnalyticsDeepController::class, 'diagnostics']);
        });

        // Business profile (per-user inputs for scenarios; never shared across users)
        Route::get('/business-profile', [\App\Http\Controllers\Api\V1\BusinessProfileController::class, 'show']);
        Route::put('/business-profile', [\App\Http\Controllers\Api\V1\BusinessProfileController::class, 'update']);

        // AI model connection (BYOK & custom models). The configured model has one bounded job:
        // it ORDERS the deterministic OR suggestions and attaches fixed server-written notes.
        // No free-text generation endpoints exist by design.
        Route::prefix('ai')->group(function () {
            Route::get('/config', [\App\Http\Controllers\Api\V1\AiController::class, 'getConfig']);
            Route::put('/config/{provider}', [\App\Http\Controllers\Api\V1\AiController::class, 'updateConfig']);
            Route::delete('/config/{provider}', [\App\Http\Controllers\Api\V1\AiController::class, 'deleteConfig']);
            Route::post('/config/{provider}/test', [\App\Http\Controllers\Api\V1\AiController::class, 'testConnection']);
        });

        // Looker Studio BI Community Connector Endpoint
        Route::get('/reporting/looker-studio', [\App\Http\Controllers\Api\V1\LookerStudioController::class, 'handle']);
    });
});
