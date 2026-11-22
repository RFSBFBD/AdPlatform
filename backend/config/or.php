<?php

/*
 * Operations Research engine settings.
 * Data-quality gates are UNCALIBRATED engineering defaults, not statistical sufficiency or platform policy.
 * Tune per deployment via env; the values in effect are returned with every OR response (`gates`).
 */
return [
    'min_days_with_data' => (int) env('OR_MIN_DAYS_WITH_DATA', 14),
    'max_staleness_days' => (int) env('OR_MAX_STALENESS_DAYS', 3),
    // Presentation label only (MAINTAIN vs SCALE): relative change band in percent. Not a statistical threshold.
    'maintain_band_percent' => (float) env('OR_MAINTAIN_BAND_PERCENT', 5),
    // Off by default: channels with fewer than 30 data days are excluded, not extrapolated.
    'allow_partial_window_scaling' => (bool) env('OR_ALLOW_PARTIAL_WINDOW_SCALING', false),
];
