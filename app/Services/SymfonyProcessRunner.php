<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\ProcessRunnerInterface;
use App\DTOs\ProcessResult;
use Symfony\Component\Process\Process;
use Throwable;

class SymfonyProcessRunner implements ProcessRunnerInterface
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
}
