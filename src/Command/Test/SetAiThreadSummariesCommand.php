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
 * Switches the reading pane's summaries on or off for the suite.
 *
 * The sibling of app:test:ai-writing-help, and separate from it for the reason
 * AiSettings keeps the four flags separate: the two specs are about different
 * surfaces, and a spec that switched both on would put an AI button in every
 * composer in the suite as a side effect of asking about a thread.
 *
 * AiSettings is a SINGLETON with no user column — install-wide state, like
 * IntegrationProviderConfig. Turning summaries on does not stay inside the spec
 * that asked for it: it puts an offer beside the subject of every conversation
 * of more than one message. That is why the spec that uses this belongs in the
 * `chromium-exclusive` project, and why this command always has an `off` to put
 * back.
 *
 * The host is deliberately an address nothing answers on. The spec this exists
 * for is about what the pane DOES when the button is pressed — the card that is
 * not there until then — and about stopping a run, and neither of those needs a
 * language model or a GPU in CI.
 *
 * Idempotent. Refuses to run in prod.
 */
#[AsCommand(
    name: 'app:test:ai-summaries',
    description: 'Switch the reading pane summaries on or off (on|off)',
)]
final class SetAiThreadSummariesCommand extends Command
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

        $settings->isEnabled      = $on;
        $settings->summaryEnabled = $on;
        $settings->baseUrl        = $on ? self::DEAD_HOST : null;
        $settings->chatModel      = $on ? 'e2e-summary-model' : null;

        if (null === $settings->id) {
            $this->entityManager->persist($settings);
        }

        $this->entityManager->flush();

        $io->success(sprintf('Thread summaries are now %s.', $state));

        return Command::SUCCESS;
    }
}
