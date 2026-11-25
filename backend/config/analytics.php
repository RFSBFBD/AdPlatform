<?php

/*
 * Analytics engines settings (Master Spec implementation).
 * Every threshold here is an ENGINEERING DEFAULT, not a statistical or platform
 * guarantee. They are tunable per deployment via env, and the values in effect
 * are returned with the responses that used them.
 */
return [
    'data_quality' => [
        // Scoring rubric version; bump when the rubric changes (recorded on every score).
        'rubric_version' => '1.0',
        // Below this portfolio score the system says DO NOT OPTIMIZE (spec section 67).
        'optimize_threshold' => (float) env('ANALYTICS_DQ_OPTIMIZE_THRESHOLD', 50),
        // Deductions (deterministic rubric, starting from 100).
        'missing_day_deduction' => (float) env('ANALYTICS_DQ_MISSING_DAY_DEDUCTION', 2),
        'missing_day_deduction_cap' => (float) env('ANALYTICS_DQ_MISSING_DAY_CAP', 30),
        'stale_day_deduction' => (float) env('ANALYTICS_DQ_STALE_DAY_DEDUCTION', 10),
        'stale_day_deduction_cap' => (float) env('ANALYTICS_DQ_STALE_DAY_CAP', 20),
        'unknown_currency_deduction' => (float) env('ANALYTICS_DQ_UNKNOWN_CURRENCY', 20),
        'mixed_currency_deduction' => (float) env('ANALYTICS_DQ_MIXED_CURRENCY', 25),
        'no_conversion_value_deduction' => (float) env('ANALYTICS_DQ_NO_CONV_VALUE', 15),
        'duplicate_rows_deduction' => (float) env('ANALYTICS_DQ_DUPLICATE_ROWS', 20),
    ],
    'diagnostics' => [
        // |change %%| inside this band counts as "normal" (spec section 34 examples use 40-55%%).
        'change_band_percent' => (float) env('ANALYTICS_DIAG_CHANGE_BAND', 20),
        // Minimum complete days in BOTH windows before any classification is attempted.
        'min_days' => (int) env('ANALYTICS_DIAG_MIN_DAYS', 7),
    ],
    'forecast' => [
        // Minimum complete days of history before any forecast is produced (spec: abstain when data is short).
        'min_days' => (int) env('ANALYTICS_FORECAST_MIN_DAYS', 14),
        'default_level' => (float) env('ANALYTICS_FORECAST_LEVEL', 0.95),
    ],
    'curve_fit' => [
        'min_days' => (int) env('ANALYTICS_CURVE_MIN_DAYS', 14),
        'min_spend_levels' => (int) env('ANALYTICS_CURVE_MIN_SPEND_LEVELS', 5),
        // Chronological holdout share for validation (last part of the window).
        'validation_share' => (float) env('ANALYTICS_CURVE_VALIDATION_SHARE', 0.3),
        'min_validation_points' => (int) env('ANALYTICS_CURVE_MIN_VALIDATION', 4),
        // A model whose validation RMSE exceeds this share of mean revenue is rejected.
        'max_validation_rmse_rel' => (float) env('ANALYTICS_CURVE_MAX_RMSE_REL', 0.5),
        // Complexity penalty per parameter beyond the first (added to relative validation RMSE).
        'complexity_penalty' => (float) env('ANALYTICS_CURVE_COMPLEXITY_PENALTY', 0.01),
        // Instability penalty weight: max relative curve deviation when refit on a reduced window.
        'instability_weight' => (float) env('ANALYTICS_CURVE_INSTABILITY_WEIGHT', 0.5),
    ],
    'scenarios' => [
        // Sensitivity perturbation applied to each channel's curve output scale (deterministic what-if).
        'perturbation_percent' => (float) env('ANALYTICS_SENS_PERTURB', 10),
        // Stability classification on the largest change in any channel's budget SHARE (percentage points).
        'stable_below_pp' => (float) env('ANALYTICS_STABLE_BELOW_PP', 10),
        'moderate_below_pp' => (float) env('ANALYTICS_MODERATE_BELOW_PP', 30),
    ],
    'anomaly' => [
        'min_points' => (int) env('ANALYTICS_ANOMALY_MIN_POINTS', 8),
        'iqr_k' => (float) env('ANALYTICS_ANOMALY_IQR_K', 1.5),
        'robust_z_threshold' => (float) env('ANALYTICS_ANOMALY_RZ', 3.5),
        'ewma_lambda' => (float) env('ANALYTICS_ANOMALY_EWMA_LAMBDA', 0.3),
        'ewma_limit' => (float) env('ANALYTICS_ANOMALY_EWMA_L', 3.0),
        'change_point_min_points' => (int) env('ANALYTICS_ANOMALY_CP_MIN', 10),
        'change_point_min_z' => (float) env('ANALYTICS_ANOMALY_CP_Z', 3.0),
    ],
];
