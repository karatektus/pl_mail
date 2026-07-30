<?php

declare(strict_types=1);

namespace App\Command\Setup;

use App\Infrastructure\Setup\DefaultSecretsGuard;
use App\Infrastructure\Setup\EncryptionKeyProbe;
use App\Infrastructure\Setup\GeneratedSecretsFile;
use Minishlink\WebPush\VAPID;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Process\Process;

/**
 * The half of first-run secret generation that needs PHP.
 *
 * `frankenphp/docker-entrypoint.sh` runs this on every container start, after
 * migrations. It is idempotent: everything here generates only what is absent,
 * so the second and every later start is a few file_exists calls.
 *
 * APP_SECRET and APP_ENCRYPTION_KEY are deliberately NOT here — the kernel
 * needs both before a console command could run, so the entrypoint mints them
 * in shell. This adds the rest to the same file, so there is one thing to back
 * up rather than several.
 */
#[AsCommand(
    name: 'app:secrets:init',
    description: 'Generate the per-install secrets that are missing, and verify the encryption key',
)]
final class InitSecretsCommand extends Command
{
    public function __construct(
        private readonly GeneratedSecretsFile $secrets,
        private readonly EncryptionKeyProbe $probe,
        private readonly DefaultSecretsGuard $guard,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        // resolve:, because these carry %kernel.project_dir% the way lexik's
        // own configuration does.
        #[Autowire('%env(resolve:JWT_SECRET_KEY)%')]
        private readonly string $jwtSecretKey,
        #[Autowire('%env(resolve:JWT_PUBLIC_KEY)%')]
        private readonly string $jwtPublicKey,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Before anything else: a container that cannot read the existing data
        // must not go on to write any.
        $this->probe->verify();

        if (null !== $shipped = $this->guard->describe()) {
            $io->error($shipped);

            return Command::FAILURE;
        }

        $this->ensureVapidKeys($io);
        $this->ensureJwtKeypair($io);

        return Command::SUCCESS;
    }

    /**
     * Web Push needs a keypair that is stable for the life of the install:
     * browsers bind a subscription to the public key it was created with, so
     * regenerating silently would unsubscribe every device. Generated once,
     * never rotated here — `app:push:generate-vapid-keys` stays the way to
     * rotate deliberately.
     */
    private function ensureVapidKeys(SymfonyStyle $io): void
    {
        if ('' !== $this->env('VAPID_PUBLIC_KEY') && '' !== $this->env('VAPID_PRIVATE_KEY')) {
            return;
        }

        if ($this->secrets->has('VAPID_PUBLIC_KEY') && $this->secrets->has('VAPID_PRIVATE_KEY')) {
            return;
        }

        $keys = VAPID::createVapidKeys();

        $this->secrets->ensure('VAPID_PUBLIC_KEY', static fn (): string => $keys['publicKey']);
        $this->secrets->ensure('VAPID_PRIVATE_KEY', static fn (): string => $keys['privateKey']);

        $io->writeln('Generated a VAPID keypair for Web Push.');
    }

    /**
     * The JMAP firewall accepts JWTs, and lexik needs a keypair on disk for
     * that. It lives beside the generated secrets rather than in config/jwt,
     * because every service has to verify tokens the others signed.
     */
    private function ensureJwtKeypair(SymfonyStyle $io): void
    {
        if (is_file($this->jwtSecretKey) && is_file($this->jwtPublicKey)) {
            return;
        }

        $dir = \dirname($this->jwtSecretKey);

        if (false === is_dir($dir) && false === @mkdir($dir, 0700, true) && false === is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create the JWT key directory %s.', $dir));
        }

        $process = new Process(
            ['php', 'bin/console', 'lexik:jwt:generate-keypair', '--skip-if-exists', '--no-interaction'],
            $this->projectDir,
        );

        $process->run();

        if (false === $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                "Could not generate the JWT keypair at %s:\n%s",
                \dirname($this->jwtSecretKey),
                $process->getErrorOutput() ?: $process->getOutput(),
            ));
        }

        $io->writeln('Generated the JWT keypair.');
    }

    private function env(string $name): string
    {
        return trim((string) ($_SERVER[$name] ?? $_ENV[$name] ?? ''));
    }
}
