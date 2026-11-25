<?php

declare(strict_types=1);

namespace App\Services\Decision;

/** Fingerprint local decision implementation/dependencies, not a reproducible environment image or signature. */
final class DecisionExecutionManifest
{
    public function capture(): array
    {
        $base = dirname(__DIR__, 3);
        $roots = ['app/Services/Decision', 'app/Services/Analytics/Curves'];
        $files = ['app/Services/Analytics/Statistics/ConfidenceIntervals.php', 'app/Services/Analytics/Statistics/Quantiles.php', 'app/Services/Analytics/AnomalyDetector.php', 'app/Services/Analytics/ResponseCurveFitter.php', 'app/Services/Analytics/RecordedKpiArithmetic.php', 'composer.lock', 'solver/typed_quadratic_risk.py', 'solver/typed_finite_milp.py', 'solver/typed_linear_lp.py', 'solver/typed_concave_proposal.py'];
        foreach ($roots as $root) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base.'/'.$root, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($base) + 1));
                }
            }
        }
        sort($files, SORT_STRING);
        $hashes = [];
        foreach ($files as $file) {
            $hashes[$file] = is_file($base.'/'.$file) ? hash_file('sha256', $base.'/'.$file) : null;
        }

        return ['version' => 'local-decision-execution-manifest-v1', 'implementation_files_sha256' => $hashes,
            'php_version' => PHP_VERSION, 'integer_size' => PHP_INT_SIZE,
            'scope' => 'Listed local implementation and dependency-lock bytes only. Not a full interpreter, operating-system or solver-library environment image.'];
    }
}
