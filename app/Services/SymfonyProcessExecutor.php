<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ProcessExecutorInterface;
use App\DTOs\ProcessResult;
use Symfony\Component\Process\Process;
use Throwable;

class SymfonyProcessExecutor implements ProcessExecutorInterface
{
    /**
     * {@inheritDoc}
     */
    public function run(array $command, int $timeout = 30): ProcessResult
    {
        try {
            $process = new Process($command, null, null, null, $timeout);
            $process->run();

            return new ProcessResult(
                exitCode: $process->getExitCode() ?? 1,
                output: trim($process->getOutput()),
                errorOutput: trim($process->getErrorOutput()),
                successful: $process->isSuccessful(),
            );
        } catch (Throwable $exception) {
            return new ProcessResult(
                exitCode: 1,
                output: '',
                errorOutput: $exception->getMessage(),
                successful: false,
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function readFile(string $path): ?string
    {
        if (! is_readable($path)) {
            return null;
        }

        $content = @file_get_contents($path);

        return $content === false ? null : $content;
    }

    /**
     * {@inheritDoc}
     */
    public function getEffectiveUid(): int
    {
        if (function_exists('posix_geteuid')) {
            return posix_geteuid();
        }

        $result = $this->run(['id', '-u']);

        return $result->successful && is_numeric(trim($result->output))
            ? (int) trim($result->output)
            : 1000;
    }
}
