<?php

declare(strict_types=1);

namespace App\Command\Test;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Puts a public key on the stand-in key server of the test environment, as if
 * its owner had published it on keys.openpgp.org or keys.mailvelope.com and
 * confirmed the address.
 *
 * For the browser specs of phase 6f of issue #28, which look a key up on a
 * key server without the internet. In the test environment the key servers'
 * requests go to App\Tests\Support\Crypto\KeyServerStub (config/services.yaml,
 * `when@test`), which answers from the files this writes — in each server's
 * own API shape, so the real KeyServerInterface implementations read them.
 * Nothing of this is wired anywhere else, and the command refuses to run
 * outside the test environment, like app:test:remember-me.
 */
#[AsCommand(
    name: 'app:test:seed-key-server',
    description: 'Publish a public key on the test environment\'s stand-in key server (test environment only)',
)]
final class SeedKeyServerCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/test-key-servers')]
        private readonly string $directory,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    /**
     * Where the stand-in keeps the key for one address on one server. Shared
     * with KeyServerStub, so the two cannot disagree about the layout.
     */
    public static function path(string $directory, string $host, string $email): string
    {
        return sprintf('%s/%s/%s.asc', $directory, strtolower($host), sha1(strtolower(trim($email))));
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'The key server, e.g. keys.openpgp.org')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'The address the key is published for')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'The armored public key, base64-encoded');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('test' !== $this->environment) {
            $io->error('app:test:seed-key-server runs in the test environment only.');

            return Command::FAILURE;
        }

        $host  = (string) $input->getOption('host');
        $email = (string) $input->getOption('email');
        $key   = base64_decode((string) $input->getOption('key'), true);

        if (1 !== preg_match('/^[a-z0-9.-]+$/', $host) || false === filter_var($email, FILTER_VALIDATE_EMAIL) || false === $key || '' === $key) {
            $io->error('Give --host, --email and a base64-encoded --key.');

            return Command::FAILURE;
        }

        $path = self::path($this->directory, $host, $email);

        if (false === is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }

        file_put_contents($path, $key);
        $io->writeln($path);

        return Command::SUCCESS;
    }
}
