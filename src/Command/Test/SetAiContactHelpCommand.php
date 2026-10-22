<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Repository\Ai\AiSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Switches contact help on or off for the suite: the "Improve with AI"
 * buttons on the contact editor and on quick add.
 *
 * app:test:ai-writing-help's twin, for the same reasons: AiSettings is one
 * row for the whole installation, so the spec that uses this runs in
 * `chromium-exclusive` and always switches it back off; and the host is an
 * address nothing answers on. contacts-editor.spec.ts answers the page's own
 * AI routes in the browser, so no model is needed — what it checks is what
 * the page does with an answer, not what a model would say.
 *
 * Idempotent. Refuses to run in prod.
 */
#[AsCommand(
    name: 'app:test:ai-contact-help',
    description: 'Switch contact help (Improve with AI on contacts) on or off (on|off)',
)]
final class SetAiContactHelpCommand extends Command
{
    /** Reserved by RFC 5737 for documentation, so it is never somebody's machine. */
    private const string DEAD_HOST = 'http://192.0.2.1:11434';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AiSettingsRepository   $settings,
        #[Autowire('%kernel.environment%')]
        private readonly string                 $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('state', InputArgument::REQUIRED, '"on" or "off"');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('Fixture commands do not run in prod.');

            return Command::FAILURE;
        }

        $state = (string) $input->getArgument('state');

        if (false === in_array($state, ['on', 'off'], true)) {
            $io->error(sprintf('Unknown state "%s".', $state));

            return Command::FAILURE;
        }

        $on       = 'on' === $state;
        $settings = $this->settings->currentOrDefault();

        $settings->isEnabled          = $on;
        $settings->contactHelpEnabled = $on;
        $settings->baseUrl            = $on ? self::DEAD_HOST : null;
        $settings->chatModel          = $on ? 'e2e-contact-model' : null;

        if (null === $settings->id) {
            $this->entityManager->persist($settings);
        }

        $this->entityManager->flush();

        $io->success(sprintf('Contact help is now %s.', $state));

        return Command::SUCCESS;
    }
}
