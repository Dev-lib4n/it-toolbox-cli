<?php

declare(strict_types=1);

use App\Commands\System\SelfUpdateCommand;
use App\DTOs\ActionResult;
use App\Services\SelfUpdateService;
use Illuminate\Contracts\Console\Kernel;
use Laravel\Prompts\Prompt;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Create a stubbed SelfUpdateService simulating an available update and successful binary replacement.
 */
function makeUpdatedSelfUpdateService(string $binaryPath): SelfUpdateService
{
    return new class($binaryPath) extends SelfUpdateService
    {
        public function __construct(private readonly string $targetBinary)
        {
            parent::__construct();
        }

        public function checkForUpdates(): array
        {
            return [
                'success' => true,
                'update_available' => true,
                'current_version' => '2.0.0',
                'latest_version' => '2.1.0',
                'asset_name' => 'it-toolbox-linux-x64',
                'asset_size' => 1024000,
                'download_url' => 'https://example.test/it-toolbox-linux-x64',
                'release_url' => 'https://example.test/release',
                'error' => null,
            ];
        }

        public function performUpdate(): ActionResult
        {
            return new ActionResult(
                success: true,
                message: 'messages.update.updated_success',
                details: [
                    'previous_version' => '2.0.0',
                    'new_version' => '2.1.0',
                    'asset_name' => 'it-toolbox-linux-x64',
                    'paths' => [$this->targetBinary],
                ],
            );
        }
    };
}

it('exposes the --no-restart flag and restart translations in both en and pt_BR', function (): void {
    /** @var Kernel $kernel */
    $kernel = $this->app->make(Kernel::class);
    $command = $kernel->all()['self:update'];
    $definition = $command->getDefinition();

    expect($definition->hasOption('no-restart'))->toBeTrue()
        ->and($definition->hasOption('check'))->toBeTrue()
        ->and($definition->hasOption('lang'))->toBeTrue();

    foreach (['en', 'pt_BR'] as $locale) {
        app()->setLocale($locale);
        app('translator')->setLocale($locale);

        $confirmRestart = (string) __('messages.update.confirm_restart', ['version' => '2.1.0']);
        $restartingMsg = (string) __('messages.update.restarting_new_version', ['version' => '2.1.0']);
        $restartNotice = (string) __('messages.update.restart_notice', ['version' => '2.1.0']);

        expect($confirmRestart)->toContain('2.1.0')
            ->and($restartingMsg)->toContain('2.1.0')
            ->and($restartNotice)->toContain('2.1.0');
    }
});

it('automatically relaunches the updated binary preserving --lang when --no-restart is omitted', function (): void {
    $fakeBinaryPath = '/tmp/it-toolbox-linux-x64';
    $this->app->instance(SelfUpdateService::class, makeUpdatedSelfUpdateService($fakeBinaryPath));

    $command = new class($fakeBinaryPath) extends SelfUpdateCommand
    {
        /** @var array{0: string, 1: string}|null */
        public ?array $relaunchedWith = null;

        public function __construct(private readonly string $fakeBinary)
        {
            parent::__construct();
        }

        protected function resolveRunningBinaryPath(): string
        {
            return $this->fakeBinary;
        }

        protected function relaunchBinary(string $binaryPath, string $locale): never
        {
            $this->relaunchedWith = [$binaryPath, $locale];

            throw new RuntimeException('TEST_RELAUNCH_INTERCEPTED');
        }
    };

    $command->setLaravel($this->app);
    $tester = new CommandTester($command);

    Prompt::fallbackWhen(true);

    try {
        expect(fn (): int => $tester->execute(['--lang' => 'pt_BR'], ['interactive' => false]))
            ->toThrow(RuntimeException::class, 'TEST_RELAUNCH_INTERCEPTED');

        expect($command->relaunchedWith)->toBe([$fakeBinaryPath, 'pt_BR'])
            ->and($tester->getDisplay())->toContain('v2.0.0 -> v2.1.0');
    } finally {
        Prompt::fallbackWhen(false);
    }
});

it('skips relaunching and prints restart notice when --no-restart is provided', function (): void {
    $fakeBinaryPath = '/tmp/it-toolbox-linux-x64';
    $this->app->instance(SelfUpdateService::class, makeUpdatedSelfUpdateService($fakeBinaryPath));

    $command = new class($fakeBinaryPath) extends SelfUpdateCommand
    {
        public bool $relaunchCalled = false;

        public function __construct(private readonly string $fakeBinary)
        {
            parent::__construct();
        }

        protected function resolveRunningBinaryPath(): string
        {
            return $this->fakeBinary;
        }

        protected function relaunchBinary(string $binaryPath, string $locale): never
        {
            $this->relaunchCalled = true;

            throw new RuntimeException('TEST_RELAUNCH_SHOULD_NOT_HAPPEN');
        }
    };

    $command->setLaravel($this->app);
    $tester = new CommandTester($command);

    Prompt::fallbackWhen(true);

    try {
        $exitCode = $tester->execute([
            '--lang' => 'en',
            '--no-restart' => true,
        ], ['interactive' => false]);

        expect($exitCode)->toBe(0)
            ->and($command->relaunchCalled)->toBeFalse()
            ->and($tester->getDisplay())->toContain('v2.0.0 -> v2.1.0')
            ->and($tester->getDisplay())->toContain('Please restart it-toolbox to use the new version (v2.1.0).');
    } finally {
        Prompt::fallbackWhen(false);
    }
});
