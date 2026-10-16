<?php

declare(strict_types=1);

namespace App\Command\Test;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\Crypto\WkdLookup;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Puts a public key on the stand-in key server of the test environment, as if
 * its owner had published it on keys.openpgp.org or keys.mailvelope.com and
 * confirmed the address — or in a domain's stand-in Web Key Directory
 * (`--wkd=advanced|direct`), or confirms an address that was published
 * through plMail (`--confirm`), as if its owner had clicked the link
 * keys.openpgp.org mailed them.
 *
 * For the browser specs of phases 6f and 6g of issue #28, which look a key up on a
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
        private readonly CacheInterface $cache,
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

    /** Where the stand-in keeps one host's Web Key Directory: one file per hash. */
    public static function wkdDirectory(string $directory, string $host): string
    {
        return sprintf('%s/wkd/%s', $directory, strtolower($host));
    }

    /**
     * Takes both WKD hosts of an address's domain down again: a test that
     * seeded one leaves the stand-in as it found it, since the files are
     * shared by every test that runs after it.
     */
    public static function forgetWkd(string $directory, string $email): void
    {
        foreach (WkdLookup::urls($email) ?? [] as $url) {
            $host = self::wkdDirectory($directory, (string) parse_url($url, PHP_URL_HOST));

            foreach (glob($host . '/*') ?: [] as $file) {
                unlink($file);
            }

            if (true === is_dir($host)) {
                rmdir($host);
            }
        }
    }

    /**
     * Drops what earlier lookups of an address left in the cache — WKD's and
     * every key server's answer — so a test seeding a key for an address it
     * shares with earlier runs is asked afresh.
     */
    public static function forgetLookups(CacheInterface $cache, string $email): void
    {
        $email = strtolower(trim($email));

        $cache->delete('wkd.' . sha1($email));

        foreach (['keys.openpgp.org', 'keys.mailvelope.com'] as $host) {
            $cache->delete(sprintf('keyserver.%s.%s', $host, sha1($email)));
        }
    }

    /** Where the stand-in upload API keeps what it was told. */
    public static function vksDirectory(string $directory): string
    {
        return $directory . '/vks';
    }

    /**
     * Each address's state on the stand-in upload API for one key.
     *
     * @return array<string, string>
     */
    public static function vksState(string $directory, string $fingerprint): array
    {
        $file = sprintf('%s/%s.json', self::vksDirectory($directory), strtolower($fingerprint));

        if (false === is_file($file)) {
            return [];
        }

        $state = json_decode((string) file_get_contents($file), true);

        return is_array($state['addresses'] ?? null) ? $state['addresses'] : [];
    }

    /**
     * Keeps a key's state, and the latest armored copy uploaded. An address
     * already confirmed is handed out by address, the latest copy — so a
     * revocation uploaded later is what a lookup then gets, as on the real
     * server.
     *
     * @param array<string, string> $addresses
     */
    public static function saveVks(string $directory, string $fingerprint, array $addresses, ?string $armored): void
    {
        $file = sprintf('%s/%s.json', self::vksDirectory($directory), strtolower($fingerprint));
        $old  = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        $key  = $armored ?? (is_array($old) && is_string($old['key'] ?? null) ? $old['key'] : '');

        if (false === is_dir(dirname($file))) {
            mkdir(dirname($file), 0o777, true);
        }

        file_put_contents($file, (string) json_encode(['addresses' => $addresses, 'key' => $key]));

        foreach ($addresses as $email => $state) {
            if ('published' === $state || 'revoked' === $state) {
                self::write(self::path($directory, 'keys.openpgp.org', $email), $key);
            }
        }
    }

    protected function configure(): void
    {
        $this
            ->addOption('host', null, InputOption::VALUE_REQUIRED, 'The key server, e.g. keys.openpgp.org')
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'The address the key is published for')
            ->addOption('key', null, InputOption::VALUE_REQUIRED, 'The armored public key, base64-encoded')
            ->addOption('wkd', null, InputOption::VALUE_REQUIRED, 'Put the key in the address\'s Web Key Directory instead: advanced or direct')
            ->addOption('confirm', null, InputOption::VALUE_NONE, 'Confirm the address on keys.openpgp.org for every key uploaded for it, as its owner clicking the link would')
            ->addOption('forget-wkd', null, InputOption::VALUE_NONE, 'Take down both Web Key Directory hosts of the address\'s domain again, so they are unreachable');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('test' !== $this->environment) {
            $io->error('app:test:seed-key-server runs in the test environment only.');

            return Command::FAILURE;
        }

        $email = strtolower(trim((string) $input->getOption('email')));

        if (true === $input->getOption('confirm')) {
            return $this->confirm($io, $email);
        }

        if (true === $input->getOption('forget-wkd')) {
            self::forgetWkd($this->directory, $email);

            return Command::SUCCESS;
        }

        $host = (string) $input->getOption('host');
        $key  = base64_decode((string) $input->getOption('key'), true);
        $wkd  = $input->getOption('wkd');

        if (null !== $wkd) {
            return $this->wkd($io, (string) $wkd, $email, false === $key ? '' : $key);
        }

        if (1 !== preg_match('/^[a-z0-9.-]+$/', $host) || false === filter_var($email, FILTER_VALIDATE_EMAIL) || false === $key || '' === $key) {
            $io->error('Give --host, --email and a base64-encoded --key.');

            return Command::FAILURE;
        }

        $path = self::path($this->directory, $host, $email);

        self::write($path, $key);
        self::forgetLookups($this->cache, $email);
        $io->writeln($path);

        return Command::SUCCESS;
    }

    /**
     * The key as the domain's directory would serve it, under one method's
     * host. An empty --key makes the host answer without the key — reachable,
     * nothing there.
     */
    private function wkd(SymfonyStyle $io, string $method, string $email, string $key): int
    {
        $urls = WkdLookup::urls($email);

        if (null === $urls || false === in_array($method, ['advanced', 'direct'], true)) {
            $io->error('Give --wkd=advanced or --wkd=direct and an --email WKD can be asked about.');

            return Command::FAILURE;
        }

        $host      = (string) parse_url($urls[$method], PHP_URL_HOST);
        $directory = self::wkdDirectory($this->directory, $host);

        if (false === is_dir($directory)) {
            mkdir($directory, 0o777, true);
        }

        if ('' !== $key) {
            $path = $directory . '/' . WkdLookup::hash((string) strstr($email, '@', true));
            self::write($path, $key);
            $io->writeln($path);
        }

        return Command::SUCCESS;
    }

    private function confirm(SymfonyStyle $io, string $email): int
    {
        $confirmed = 0;

        foreach (glob(self::vksDirectory($this->directory) . '/*.json') ?: [] as $file) {
            $fingerprint = basename($file, '.json');
            $state       = self::vksState($this->directory, $fingerprint);

            if ('pending' === ($state[$email] ?? null) || 'unpublished' === ($state[$email] ?? null)) {
                $state[$email] = 'published';
                self::saveVks($this->directory, $fingerprint, $state, null);
                ++$confirmed;
            }
        }

        $io->writeln(sprintf('Confirmed %s for %d key(s).', $email, $confirmed));

        return 0 < $confirmed ? Command::SUCCESS : Command::FAILURE;
    }

    private static function write(string $path, string $content): void
    {
        if (false === is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }

        file_put_contents($path, $content);
    }
}
