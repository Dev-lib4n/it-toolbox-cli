#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Combine a static-php-cli micro.sfx binary with a compiled PHAR archive,
 * stripping any leading Unix shebang and recalculating the PHAR cryptographic
 * signature (SHA512 / SHA256 / SHA1 / MD5) so ext-phar verification succeeds.
 *
 * Usage:
 *   php scripts/combine-micro.php <micro.sfx> <input.phar> <output-binary>
 */

if ($argc < 4) {
    fwrite(STDERR, "Usage: php scripts/combine-micro.php <micro.sfx> <input.phar> <output-binary>\n");
    exit(1);
}

[, $microSfxPath, $pharPath, $outputPath] = $argv;

if (! is_file($microSfxPath)) {
    fwrite(STDERR, sprintf("Error: micro.sfx file not found at '%s'.\n", $microSfxPath));
    exit(1);
}

if (! is_file($pharPath)) {
    fwrite(STDERR, sprintf("Error: PHAR file not found at '%s'.\n", $pharPath));
    exit(1);
}

$microBytes = file_get_contents($microSfxPath);
$pharBytes = file_get_contents($pharPath);

if ($microBytes === false || $pharBytes === false) {
    fwrite(STDERR, "Error: Failed to read input binary files.\n");
    exit(1);
}

// 1. Strip leading Unix shebang (e.g. #!/usr/bin/env php\n) from the PHAR if present
if (str_starts_with($pharBytes, '#!')) {
    $newlinePos = strpos($pharBytes, "\n");
    if ($newlinePos !== false) {
        $pharBytes = substr($pharBytes, $newlinePos + 1);
    }
}

// 2. Inspect the trailing 8-byte PHAR signature footer: [4-byte flags][4-byte "GBMB"]
$magic = substr($pharBytes, -4);
if ($magic !== 'GBMB') {
    fwrite(STDERR, "Error: Input PHAR does not contain a valid 'GBMB' signature footer.\n");
    exit(1);
}

$footer = substr($pharBytes, -8);
$unpacked = unpack('Vflags', substr($footer, 0, 4));
$sigFlags = (int) ($unpacked['flags'] ?? 0);

[$hashAlgo, $hashLen] = match ($sigFlags) {
    0x0001 => ['md5', 16],
    0x0002 => ['sha1', 20],
    0x0003 => ['sha256', 32],
    0x0004 => ['sha512', 64],
    default => [null, 0],
};

if ($hashAlgo === null || $hashLen === 0) {
    fwrite(STDERR, sprintf("Error: Unsupported PHAR signature flag 0x%04X.\n", $sigFlags));
    exit(1);
}

// 3. Extract the PHAR payload without the trailing signature block ([hash][flags][GBMB])
$pharWithoutSignature = substr($pharBytes, 0, -($hashLen + 8));
$combinedWithoutSignature = $microBytes . $pharWithoutSignature;

// 4. Determine whether micro.sfx hashes the combined binary from offset 0 or the PHAR payload from sfx_filesize
$modeCacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'it_toolbox_spc_sig_mode.txt';
$preferredMode = is_file($modeCacheFile) ? trim((string) file_get_contents($modeCacheFile)) : 'combined';

$writeWithMode = static function (string $mode) use (
    $outputPath,
    $hashAlgo,
    $combinedWithoutSignature,
    $microBytes,
    $pharWithoutSignature,
    $footer
): void {
    $newSignature = $mode === 'phar_only'
        ? hash($hashAlgo, $pharWithoutSignature, true)
        : hash($hashAlgo, $combinedWithoutSignature, true);

    $finalBinary = $combinedWithoutSignature . $newSignature . $footer;

    if (file_put_contents($outputPath, $finalBinary) === false) {
        fwrite(STDERR, sprintf("Error: Failed to write output binary '%s'.\n", $outputPath));
        exit(1);
    }

    @chmod($outputPath, 0755);
};

$writeWithMode($preferredMode);

// 5. On Linux, if the output is an ELF binary, self-test signature verification and auto-switch if needed
if (PHP_OS_FAMILY === 'Linux' && str_starts_with($microBytes, "\x7fELF")) {
    $cmd = escapeshellarg(realpath($outputPath) ?: $outputPath) . ' --version 2>&1';
    $testOutput = [];
    $exitCode = 0;
    exec($cmd, $testOutput, $exitCode);
    $joinedOutput = implode("\n", $testOutput);

    if ($exitCode !== 0 && str_contains(strtolower($joinedOutput), 'signature')) {
        $fallbackMode = $preferredMode === 'combined' ? 'phar_only' : 'combined';
        fwrite(STDOUT, sprintf("Notice: Switching PHAR signature mode from '%s' to '%s' for micro.sfx compatibility.\n", $preferredMode, $fallbackMode));
        $writeWithMode($fallbackMode);
        @file_put_contents($modeCacheFile, $fallbackMode);
    } else {
        @file_put_contents($modeCacheFile, $preferredMode);
    }
}

fwrite(
    STDOUT,
    sprintf(
        "Successfully combined '%s' + '%s' -> '%s' (Signature: %s, Size: %.2f MB)\n",
        basename($microSfxPath),
        basename($pharPath),
        $outputPath,
        strtoupper($hashAlgo),
        filesize($outputPath) / 1048576
    )
);
