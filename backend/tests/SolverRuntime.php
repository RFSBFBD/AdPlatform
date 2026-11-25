<?php

namespace Tests;

use PHPUnit\Framework\TestCase as PhpUnitTestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Resolves the Python interpreter used by the optional OR solver tests (numpy + scipy, see
 * backend/solver/requirements.txt). Test-only. Production reads OR_SOLVER_PYTHON and is unchanged.
 *
 * Rules:
 *  - TEST_SOLVER_PYTHON set: it must be a working interpreter with numpy and scipy. If it is not, the
 *    test run FAILS with a diagnostic. An explicit override is never ignored or replaced.
 *  - Not set: look for python3, then python, on PATH (Windows .exe/.cmd aware). If one imports
 *    numpy and scipy it is used. If none does, the test is SKIPPED with a visible reason, so missing
 *    solver coverage shows in the phpunit summary and is never counted as a pass.
 */
final class SolverRuntime
{
    public static function reset(): void
    {
        self::$resolved = null;
    }

    /** @var array{path:?string,reason:string}|null */
    private static ?array $resolved = null;

    public static function python(PhpUnitTestCase $test): string
    {
        $r = self::$resolved ??= self::resolve();
        if ($r['path'] === null) {
            if ($r['reason'] !== '' && str_starts_with($r['reason'], 'INVALID_OVERRIDE:')) {
                throw new RuntimeException(substr($r['reason'], strlen('INVALID_OVERRIDE:')));
            }
            $test->markTestSkipped('Solver runtime unavailable, solver coverage NOT executed: '.$r['reason']);
        }

        return $r['path'];
    }

    /** @return array{path:?string,reason:string} */
    private static function resolve(): array
    {
        $override = getenv('TEST_SOLVER_PYTHON');
        if ($override !== false && $override !== '') {
            $why = self::check($override);

            return $why === null
                ? ['path' => $override, 'reason' => '']
                : ['path' => null, 'reason' => 'INVALID_OVERRIDE:TEST_SOLVER_PYTHON='.$override.' is not usable: '.$why];
        }
        $notes = [];
        foreach (['python3', 'python'] as $name) {
            $path = self::onPath($name);
            if ($path === null) {
                $notes[] = "$name not found on PATH";
                continue;
            }
            $why = self::check($path);
            if ($why === null) {
                return ['path' => $path, 'reason' => ''];
            }
            $notes[] = "$path: $why";
        }

        return ['path' => null, 'reason' => implode('; ', $notes).'. Install Python with numpy and scipy (backend/solver/requirements.txt) or set TEST_SOLVER_PYTHON to its full path.'];
    }

    private static function onPath(string $name): ?string
    {
        $exts = DIRECTORY_SEPARATOR === '\\' ? ['.exe', '.cmd', '.bat', ''] : [''];
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            foreach ($exts as $ext) {
                $f = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.$name.$ext;
                if ($dir !== '' && is_file($f) && (DIRECTORY_SEPARATOR === '\\' || is_executable($f))) {
                    return $f;
                }
            }
        }

        return null;
    }

    private static function check(string $python): ?string
    {
        if (! is_file($python) || (DIRECTORY_SEPARATOR !== '\\' && ! is_executable($python))) {
            return 'file does not exist or is not executable';
        }
        try {
            $p = new Process([$python, '-c', 'import numpy, scipy']);
            $p->setTimeout(60);
            $p->run();
        } catch (\Throwable $e) {
            return 'could not start: '.$e->getMessage();
        }

        return $p->isSuccessful() ? null : 'cannot import numpy and scipy ('.trim(substr($p->getErrorOutput(), -200)).')';
    }
}
