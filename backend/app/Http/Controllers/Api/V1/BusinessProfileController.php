<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BusinessProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BusinessProfileController extends Controller
{
    /** Fields the user fills in. Nothing here is inferred or defaulted. */
    private const FIELDS = [
        'business_name', 'business_model', 'currency', 'primary_goal', 'gross_margin_percent',
        'average_order_value', 'budget_cap', 'budget_cap_horizon', 'target_roas', 'conversion_definition', 'notes', 'custom_fields',
    ];

    public function show(Request $request): JsonResponse
    {
        $profile = BusinessProfile::where('user_id', $request->user()->id)->first();

        return response()->json([
            'success' => true,
            'data' => $profile ? $profile->only(self::FIELDS) : null,
            'missing_fields' => $this->missing($profile),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'business_name' => ['nullable', 'string', 'max:255'],
            'business_model' => ['nullable', 'in:ecommerce,lead_gen,app,other'],
            'currency' => ['nullable', 'string', 'size:3', 'alpha'],
            'primary_goal' => ['nullable', 'in:revenue,roas,leads,profit'],
            'gross_margin_percent' => ['nullable', 'numeric', 'between:0,100'],
            'average_order_value' => ['nullable', 'numeric', 'gt:0', 'max:999999999999'],
            'budget_cap' => ['nullable', 'numeric', 'gt:0', 'max:999999999999', 'required_with:budget_cap_horizon'],
            'budget_cap_horizon' => ['nullable', 'in:daily,per_30_days', 'required_with:budget_cap'],
            'target_roas' => ['nullable', 'numeric', 'gt:0', 'max:999999'],
            'conversion_definition' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'custom_fields' => ['nullable', 'array', 'max:20'],
            'custom_fields.*' => ['nullable', 'string', 'max:200'],
        ]);

        // custom_fields are free-form user notes: untrusted, never used by the OR engine, and must not
        // carry secrets. Keys are restricted and secret-looking keys/values are refused.
        foreach (($validated['custom_fields'] ?? []) as $key => $value) {
            if (!is_string($key) || !preg_match('/^[a-z][a-z0-9_]{0,39}$/', $key)) {
                return $this->rejectCustomField("Custom field names must be lowercase letters, digits or underscores (max 40).");
            }
            if (preg_match('/pass|secret|token|api_?key|apikey|card|cvv|cvc|ssn|iban|private/i', $key)
                || preg_match('/\b(?:\d[ -]?){13,19}\b/', (string) $value)
                || preg_match('/(sk|pk|ey)[A-Za-z0-9_\-]{20,}/', (string) $value)) {
                return $this->rejectCustomField('Custom fields cannot store passwords, tokens, keys or card numbers.');
            }
        }

        if (isset($validated['currency'])) {
            $validated['currency'] = strtoupper($validated['currency']);
        }

        // user_id always comes from the authenticated user, never from the request.
        $profile = BusinessProfile::updateOrCreate(['user_id' => $request->user()->id], $validated);

        return response()->json([
            'success' => true,
            'data' => $profile->only(self::FIELDS),
            'missing_fields' => $this->missing($profile),
        ]);
    }

    private function rejectCustomField(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'errors' => ['custom_fields' => [$message]],
        ], 422);
    }

    /** Which required inputs for a meaningful scenario are still empty. */
    private function missing(?BusinessProfile $profile): array
    {
        $required = ['business_model', 'currency', 'primary_goal', 'conversion_definition'];
        return array_values(array_filter($required, fn($f) => !$profile || $profile->{$f} === null || $profile->{$f} === ''));
    }
}
