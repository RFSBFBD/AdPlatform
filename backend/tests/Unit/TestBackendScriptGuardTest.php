<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class TestBackendScriptGuardTest extends TestCase
{
    private function run_script(array $env): array
    {
        $script = str_replace('\\', '/', dirname(__DIR__, 3).'/scripts/test-backend.sh');
        [$bash, $why] = $this->usableBash($script);
        if ($bash === null) {
            $this->markTestSkipped('No usable bash, scripts/test-backend.sh guard coverage NOT executed. '.$why.' Use Git Bash (a WSL bash.exe shim is not enough).');
        }
        // Array command and env array: no shell quoting or POSIX "VAR=x cmd" syntax, so it is platform safe.
        $p = new Process([$bash, $script], null, $env);
        $p->setTimeout(60);
        $p->run();

        return [$p->getExitCode(), $p->getOutput().$p->getErrorOutput()];
    }

    /**
     * A bash file on PATH is not necessarily a working shell (Windows ships a WSL launcher named bash.exe
     * that cannot open Windows-style paths or has no distro). Each candidate must start and open the script.
     *
     * @return array{0:?string,1:string}
     */
    private function usableBash(string $script): array
    {
        $cands = [];
        $exts = DIRECTORY_SEPARATOR === '\\' ? ['.exe', ''] : [''];
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            foreach ($exts as $ext) {
                $f = rtrim($dir, '/\\').DIRECTORY_SEPARATOR.'bash'.$ext;
                if ($dir !== '' && is_file($f)) {
                    $cands[] = $f;
                }
            }
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            foreach ([getenv('ProgramFiles'), getenv('ProgramFiles(x86)'), getenv('LOCALAPPDATA').'/Programs'] as $root) {
                if ($root) {
                    $cands[] = $root.'/Git/bin/bash.exe';
                }
            }
        }
        $notes = [];
        foreach (array_unique($cands) as $c) {
            if (! is_file($c)) {
                continue;
            }
            try {
                $probe = new Process([$c, '-c', 'test -f "$1" && echo guard-probe-ok', '_', $script]);
                $probe->setTimeout(20);
                $probe->run();
            } catch (\Throwable $e) {
                $notes[] = "$c could not start";
                continue;
            }
            if ($probe->isSuccessful() && str_contains($probe->getOutput(), 'guard-probe-ok')) {
                return [$c, ''];
            }
            $notes[] = "$c rejected (exit ".$probe->getExitCode().')';
        }

        return [null, $notes === [] ? 'bash not found on PATH.' : 'Candidates: '.implode('; ', $notes).'.'];
    }

    public function test_refuses_non_test_database_name_before_any_migration(): void
    {
        [$code, $out] = $this->run_script(['DB_DATABASE' => 'advertising_platform', 'PHP_BIN' => 'false']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('must end in _test', $out);
    }

    public function test_refuses_non_local_host(): void
    {
        [$code, $out] = $this->run_script(['DB_HOST' => 'db.example.com', 'PHP_BIN' => 'false']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('non-local DB_HOST', $out);
    }
}
