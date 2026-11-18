<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class ProcessResult
{
    /**
     * @param  int  $exitCode  Process exit status code.
     * @param  string  $output  Standard output (stdout).
     * @param  string  $errorOutput  Standard error output (stderr).
     * @param  bool  $successful  Whether the process exited with code 0.
     */
    public function __construct(
        public int $exitCode,
        public string $output,
        public string $errorOutput = '',
        public bool $successful = true,
    ) {}
}
