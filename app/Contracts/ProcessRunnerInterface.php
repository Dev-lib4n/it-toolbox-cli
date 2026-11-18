<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\ProcessResult;

interface ProcessRunnerInterface
{
    /**
     * Execute an external OS command safely using an array of arguments.
     *
     * @param  array<int, string>  $command
     * @param  int  $timeout
     * @return ProcessResult
     */
    public function run(array $command, int $timeout = 30): ProcessResult;
}
