<?php

declare(strict_types=1);

namespace App\DTOs;

readonly class ActionResult
{
    /**
     * @param  bool  $success  Whether the system action succeeded.
     * @param  string  $message  Translation key or descriptive result message.
     * @param  array<string, mixed>  $details  Additional structured details from the operation.
     */
    public function __construct(
        public bool $success,
        public string $message,
        public array $details = [],
    ) {}
}
