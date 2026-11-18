<?php

declare(strict_types=1);

use App\Services\SelfUpdateService;

it('compares semantic version strings accurately via version_compare', function (): void {
    $service = new SelfUpdateService();

    expect($service->isNewerVersion('1.0.0', 'v1.2.0'))->toBeTrue()
        ->and($service->isNewerVersion('v1.2.0', 'v1.2.0'))->toBeFalse()
        ->and($service->isNewerVersion('v1.2.0', 'v1.1.9'))->toBeFalse();
});

it('selects the correct GitHub Release artifact by platform and execution mode', function (): void {
    $service = new SelfUpdateService();

    expect($service->resolveTargetAssetName('', 'Linux'))->toBeNull()
        ->and($service->resolveTargetAssetName('C:\\Tools\\it-toolbox-windows-x64.exe', 'Windows'))->toBe('it-toolbox-windows-x64.exe')
        ->and($service->resolveTargetAssetName('/usr/local/bin/it-toolbox-linux-x64', 'Linux'))->toBe('it-toolbox-linux-x64')
        ->and($service->resolveTargetAssetName('/usr/local/bin/it-toolbox.phar', 'Linux'))->toBe('it-toolbox')
        ->and($service->resolveTargetAssetName('/usr/local/bin/it-toolbox', 'Linux'))->toBe('it-toolbox');
});

it('returns a friendly git pull warning when attempting self-update in unpacked source mode', function (): void {
    $service = new SelfUpdateService(
        currentVersion: '1.0.0',
        runningBinaryPath: '',
    );

    $result = $service->performUpdate();

    expect($result->success)->toBeFalse()
        ->and($result->message)->toBe('messages.update.source_mode_warning');
});

it('downloads and atomically replaces the executable (with .old backup on Windows)', function (): void {
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'it_toolbox_update_' . uniqid();
    mkdir($tempDir, 0777, true);
    $fakeExePath = $tempDir . DIRECTORY_SEPARATOR . 'it-toolbox-windows-x64.exe';
    file_put_contents($fakeExePath, 'OLD_BINARY_PAYLOAD');

    $fakeReleaseJson = (string) json_encode([
        'tag_name' => 'v1.3.0',
        'html_url' => 'https://github.com/Dev-lib4n/it-toolbox-cli/releases/tag/v1.3.0',
        'assets' => [
            [
                'name' => 'it-toolbox-windows-x64.exe',
                'browser_download_url' => 'https://github.com/Dev-lib4n/it-toolbox-cli/releases/download/v1.3.0/it-toolbox-windows-x64.exe',
            ],
        ],
    ]);

    $service = new SelfUpdateService(
        httpFetcher: fn (string $url): string => str_contains($url, 'api.github.com')
            ? $fakeReleaseJson
            : 'NEW_BINARY_PAYLOAD_V1_3_0',
        currentVersion: '1.2.0',
        runningBinaryPath: $fakeExePath,
        osFamily: 'Windows',
    );

    try {
        $check = $service->checkForUpdates();
        expect($check['success'])->toBeTrue()
            ->and($check['update_available'])->toBeTrue()
            ->and($check['latest_version'])->toBe('1.3.0')
            ->and($check['asset_name'])->toBe('it-toolbox-windows-x64.exe');

        $updateResult = $service->performUpdate();
        expect($updateResult->success)->toBeTrue()
            ->and($updateResult->message)->toBe('messages.update.updated_success')
            ->and(file_get_contents($fakeExePath))->toBe('NEW_BINARY_PAYLOAD_V1_3_0')
            ->and(file_exists($fakeExePath . '.old'))->toBeTrue()
            ->and(file_get_contents($fakeExePath . '.old'))->toBe('OLD_BINARY_PAYLOAD');
    } finally {
        @unlink($fakeExePath);
        @unlink($fakeExePath . '.old');
        @unlink($fakeExePath . '.download.tmp');
        @rmdir($tempDir);
    }
});
