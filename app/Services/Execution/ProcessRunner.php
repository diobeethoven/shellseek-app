<?php

namespace App\Services\Execution;

/**
 * Runs an argument vector WITHOUT a shell.
 */
interface ProcessRunner
{
    /**
     * @param  list<string>  $argv  absolute binary path followed by arguments
     * @param  array<string, string>  $env  the complete environment of the child
     */
    public function run(array $argv, float $timeout, int $maxOutputBytes, array $env, string $cwd): ProcessOutcome;
}
