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

    $newBinaryPayload = 'NEW_BINARY_PAYLOAD_V1_3_0' . str_repeat('X', 520 * 1024);
    $expectedSize = strlen($newBinaryPayload);

    $fakeReleaseJson = (string) json_encode([
        'tag_name' => 'v1.3.0',
        'html_url' => 'https://github.com/Dev-lib4n/it-toolbox-cli/releases/tag/v1.3.0',
        'assets' => [
            [
                'name' => 'it-toolbox-windows-x64.exe',
                'size' => $expectedSize,
                'browser_download_url' => 'https://github.com/Dev-lib4n/it-toolbox-cli/releases/download/v1.3.0/it-toolbox-windows-x64.exe',
            ],
        ],
    ]);

    $service = new SelfUpdateService(
        httpFetcher: fn (string $url): string => str_contains($url, 'api.github.com')
            ? $fakeReleaseJson
            : $newBinaryPayload,
        currentVersion: '1.2.0',
        runningBinaryPath: $fakeExePath,
        osFamily: 'Windows',
    );

    try {
        $check = $service->checkForUpdates();
        expect($check['success'])->toBeTrue()
            ->and($check['update_available'])->toBeTrue()
            ->and($check['latest_version'])->toBe('1.3.0')
            ->and($check['asset_name'])->toBe('it-toolbox-windows-x64.exe')
            ->and($check['asset_size'])->toBe($expectedSize);

        $updateResult = $service->performUpdate();
        expect($updateResult->success)->toBeTrue()
            ->and($updateResult->message)->toBe('messages.update.updated_success')
            ->and($service->wasBinaryReplaced())->toBeTrue()
            ->and(file_get_contents($fakeExePath))->toBe($newBinaryPayload)
            ->and(file_exists($fakeExePath . '.old'))->toBeTrue()
            ->and(file_get_contents($fakeExePath . '.old'))->toBe('OLD_BINARY_PAYLOAD');
    } finally {
        @unlink($fakeExePath);
        @unlink($fakeExePath . '.old');
        @unlink($fakeExePath . '.download.tmp');
        @rmdir($tempDir);
    }
});

it('rejects downloaded binaries whose byte size differs from the GitHub asset metadata', function (): void {
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'it_toolbox_truncated_' . uniqid();
    mkdir($tempDir, 0777, true);
    $fakeExePath = $tempDir . DIRECTORY_SEPARATOR . 'it-toolbox-windows-x64.exe';
    file_put_contents($fakeExePath, 'ORIGINAL_HEALTHY_BINARY');

    $truncatedPayload = str_repeat('Z', 530 * 1024);
    $expectedAssetSize = 650 * 1024;

    $fakeReleaseJson = (string) json_encode([
        'tag_name' => 'v1.4.0',
        'assets' => [
            [
                'name' => 'it-toolbox-windows-x64.exe',
                'size' => $expectedAssetSize,
                'browser_download_url' => 'https://github.com/Dev-lib4n/it-toolbox-cli/releases/download/v1.4.0/it-toolbox-windows-x64.exe',
            ],
        ],
    ]);

    $service = new SelfUpdateService(
        httpFetcher: fn (string $url): string => str_contains($url, 'api.github.com')
            ? $fakeReleaseJson
            : $truncatedPayload,
        currentVersion: '1.3.0',
        runningBinaryPath: $fakeExePath,
        osFamily: 'Windows',
    );

    try {
        expect(fn () => $service->performUpdate())->toThrow(RuntimeException::class)
            ->and($service->wasBinaryReplaced())->toBeFalse()
            ->and(file_get_contents($fakeExePath))->toBe('ORIGINAL_HEALTHY_BINARY')
            ->and(file_exists($fakeExePath . '.download.tmp'))->toBeFalse();
    } finally {
        @unlink($fakeExePath);
        @unlink($fakeExePath . '.old');
        @unlink($fakeExePath . '.download.tmp');
        @rmdir($tempDir);
    }
});

it('rejects suspiciously small downloaded payloads below 500 KB and removes temp artifacts', function (): void {
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'it_toolbox_small_' . uniqid();
    mkdir($tempDir, 0777, true);
    $fakeExePath = $tempDir . DIRECTORY_SEPARATOR . 'it-toolbox-linux-x64';
    file_put_contents($fakeExePath, 'ORIGINAL_ELF_BINARY');

    $fakeReleaseJson = (string) json_encode([
        'tag_name' => 'v1.4.0',
        'assets' => [
            [
                'name' => 'it-toolbox-linux-x64',
                'browser_download_url' => 'https://github.com/Dev-lib4n/it-toolbox-cli/releases/download/v1.4.0/it-toolbox-linux-x64',
            ],
        ],
    ]);

    $service = new SelfUpdateService(
        httpFetcher: fn (string $url): string => str_contains($url, 'api.github.com')
            ? $fakeReleaseJson
            : 'HTML_ERROR_OR_TRUNCATED_BODY',
        currentVersion: '1.3.0',
        runningBinaryPath: $fakeExePath,
        osFamily: 'Linux',
    );

    try {
        expect(fn () => $service->performUpdate())->toThrow(RuntimeException::class)
            ->and($service->wasBinaryReplaced())->toBeFalse()
            ->and(file_get_contents($fakeExePath))->toBe('ORIGINAL_ELF_BINARY')
            ->and(file_exists($fakeExePath . '.download.tmp'))->toBeFalse();
    } finally {
        @unlink($fakeExePath);
        @unlink($fakeExePath . '.download.tmp');
        @rmdir($tempDir);
    }
});

it('cleans up stale .old backup binaries without deleting the active executable or unrelated files', function (): void {
    $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'it_toolbox_cleanup_' . uniqid();
    mkdir($tempDir, 0777, true);

    $activeExe = $tempDir . DIRECTORY_SEPARATOR . 'it-toolbox-windows-x64.exe';
    $oldExe1 = $tempDir . DIRECTORY_SEPARATOR . 'it-toolbox-windows-x64.exe.old';
    $oldExe2 = $tempDir . DIRECTORY_SEPARATOR . 'it-toolbox-1.9.0.exe.old';
    $oldPhar = $tempDir . DIRECTORY_SEPARATOR . 'it-toolbox.old';
    $unrelatedOld = $tempDir . DIRECTORY_SEPARATOR . 'other-utility.exe.old';
    $notesFile = $tempDir . DIRECTORY_SEPARATOR . 'notes.txt';

    file_put_contents($activeExe, 'ACTIVE_BINARY');
    file_put_contents($oldExe1, 'OLD_BACKUP_1');
    file_put_contents($oldExe2, 'OLD_BACKUP_2');
    file_put_contents($oldPhar, 'OLD_BACKUP_3');
    file_put_contents($unrelatedOld, 'KEEP_UNRELATED');
    file_put_contents($notesFile, 'KEEP_NOTES');

    $service = new SelfUpdateService(runningBinaryPath: $activeExe);

    try {
        expect($service->cleanupOldBinaries())->toBe(3)
            ->and(file_exists($activeExe))->toBeTrue()
            ->and(file_exists($oldExe1))->toBeFalse()
            ->and(file_exists($oldExe2))->toBeFalse()
            ->and(file_exists($oldPhar))->toBeFalse()
            ->and(file_exists($unrelatedOld))->toBeTrue()
            ->and(file_exists($notesFile))->toBeTrue()
            ->and($service->cleanupOldBinaries(''))->toBe(0);
    } finally {
        @unlink($activeExe);
        @unlink($oldExe1);
        @unlink($oldExe2);
        @unlink($oldPhar);
        @unlink($unrelatedOld);
        @unlink($notesFile);
        @rmdir($tempDir);
    }
});
