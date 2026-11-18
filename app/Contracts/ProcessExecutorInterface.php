<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\ProcessResult;

interface ProcessExecutorInterface extends ProcessRunnerInterface
{
    /**
     * Execute an external OS command safely using an array of arguments.
     *
     * @param  array<int, string>  $command
     * @param  int  $timeout
     * @return ProcessResult
     */
    public function run(array $command, int $timeout = 30): ProcessResult;

    /**
     * Read the contents of a system file (e.g. /proc/meminfo, /etc/os-release).
     *
     * @param  string  $path
     * @return string|null
     */
    public function readFile(string $path): ?string;

    /**
     * Return the effective POSIX user ID (0 for root) on Unix-like systems.
     *
     * @return int
     */
    public function getEffectiveUid(): int;
}
