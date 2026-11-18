<?php

declare(strict_types=1);

namespace App\Commands\System;

use App\Commands\Concerns\InteractsWithLocale;
use App\Contracts\SystemDriverInterface;
use LaravelZero\Framework\Commands\Command;

use function Laravel\Prompts\info;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\warning;

class UsbDevicesCommand extends Command
{
    use InteractsWithLocale;

    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'system:usb {--lang= : Interface language (en or pt_BR)}';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'List connected USB controllers and peripheral devices';

    /**
     * Execute the console command.
     */
    public function handle(SystemDriverInterface $driver): int
    {
        $this->resolveLocale();

        info((string) __('messages.system.usb_section'));

        /** @var array<int, array{name: string, manufacturer: string, status: string, device_id: string}> $devices */
        $devices = spin(
            callback: fn (): array => $driver->getUsbDevices(),
            message: (string) __('messages.spinners.system_usb'),
        );

        if ($devices === []) {
            warning((string) __('messages.system.no_usb_devices'));

            return self::SUCCESS;
        }

        table(
            headers: [
                (string) __('messages.system.usb_headers.name'),
                (string) __('messages.system.usb_headers.manufacturer'),
                (string) __('messages.system.usb_headers.status'),
                (string) __('messages.system.usb_headers.device_id'),
            ],
            rows: array_map(
                fn (array $dev): array => [
                    $dev['name'] ?? '-',
                    $dev['manufacturer'] ?? '-',
                    $dev['status'] ?? 'OK',
                    $dev['device_id'] ?? '-',
                ],
                $devices
            ),
        );

        return self::SUCCESS;
    }
}
