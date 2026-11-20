<?php

namespace App\Services\Execution;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * The ONLY place in the application that starts processes.
 * Uses Symfony Process with an argument array: no shell is involved, so
 * metacharacters in arguments are passed literally.
 */
final class SymfonyProcessRunner implements ProcessRunner
{
    private const POLL_INTERVAL_US = 20_000;

    public function run(array $argv, float $timeout, int $maxOutputBytes, array $env, string $cwd): ProcessOutcome
    {
        $process = new Process($argv, $cwd, self::isolatedEnv($env), null, $timeout);

        $stdout = '';
        $stderr = '';
        $timedOut = false;
        $exceeded = false;
        $started = hrtime(true);

        $process->start();

        try {
            while ($process->isRunning()) {
                $process->checkTimeout();
                self::collect($process, $stdout, $stderr, $maxOutputBytes, $exceeded);
                if ($exceeded) {
                    $process->stop(0);
                    break;
                }
                usleep(self::POLL_INTERVAL_US);
            }
            if (! $exceeded) {
                self::collect($process, $stdout, $stderr, $maxOutputBytes, $exceeded);
            }
        } catch (ProcessTimedOutException) {
            $timedOut = true;
            self::collect($process, $stdout, $stderr, $maxOutputBytes, $exceeded);
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
        }

        return new ProcessOutcome(
            ($timedOut || $exceeded) ? null : $process->getExitCode(),
            $stdout,
            $stderr,
            (int) round((hrtime(true) - $started) / 1_000_000),
            $timedOut,
            $exceeded,
        );
    }

    private static function collect(Process $process, string &$stdout, string &$stderr, int $max, bool &$exceeded): void
    {
        $out = $process->getIncrementalOutput();
        $err = $process->getIncrementalErrorOutput();
        $process->clearOutput();
        $process->clearErrorOutput();

        self::append($stdout, $out, $max - strlen($stdout) - strlen($stderr), $exceeded);
        self::append($stderr, $err, $max - strlen($stdout) - strlen($stderr), $exceeded);
    }

    private static function append(string &$buffer, string $chunk, int $room, bool &$exceeded): void
    {
        if ($chunk === '' || $exceeded) {
            return;
        }
        if (strlen($chunk) > $room) {
            $buffer .= substr($chunk, 0, max(0, $room));
            $exceeded = true;

            return;
        }
        $buffer .= $chunk;
    }

    /**
     * Symfony Process inherits the parent environment ($_ENV + getenv())
     * unless each inherited variable is explicitly set to false.
     *
     * @param  array<string, string>  $env
     * @return array<string, string|false>
     */
    private static function isolatedEnv(array $env): array
    {
        $inherited = array_keys(getenv() + $_ENV + array_filter($_SERVER, 'is_string'));
        $blocked = array_fill_keys(array_map('strval', $inherited), false);

        return $env + $blocked;
    }
}
