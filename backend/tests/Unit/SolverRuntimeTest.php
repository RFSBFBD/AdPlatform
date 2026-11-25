<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\SolverRuntime;

final class SolverRuntimeTest extends TestCase
{
    private string|false $saved;

    protected function setUp(): void
    {
        $this->saved = getenv('TEST_SOLVER_PYTHON');
        SolverRuntime::reset();
    }

    protected function tearDown(): void
    {
        $this->saved === false ? putenv('TEST_SOLVER_PYTHON') : putenv('TEST_SOLVER_PYTHON='.$this->saved);
        SolverRuntime::reset();
    }

    public function test_invalid_explicit_override_fails_loudly_and_is_not_replaced_or_skipped(): void
    {
        putenv('TEST_SOLVER_PYTHON=/definitely/not/a/python');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TEST_SOLVER_PYTHON=/definitely/not/a/python is not usable');
        SolverRuntime::python($this);
    }

    public function test_override_without_numpy_scipy_is_rejected_with_reason(): void
    {
        if (! is_executable('/bin/false')) {
            $this->markTestSkipped('Needs /bin/false (POSIX).');
        }
        putenv('TEST_SOLVER_PYTHON=/bin/false'); // a real executable that cannot import numpy/scipy
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not usable');
        SolverRuntime::python($this);
    }
}
