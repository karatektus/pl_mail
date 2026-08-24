<?php

declare(strict_types=1);

namespace App\Command\Push;

use App\Repository\Mail\AccountRepository;
use App\Service\Mail\GraphApiClient;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Cancel a Graph subscription by id, using an account's credentials.
 *
 * For the registration Microsoft is still delivering and plMail no longer
 * recognises — the one behind "GraphNotification: unknown subscription". Those
 * lapse on their own within three days, and until now waiting was the only
 * option: the notification carries an id, but the endpoint that receives it has
 * no account to match it to and therefore no token to cancel it with.
 *
 * The account is named here instead, which is what makes this possible at all.
 * Any account in the same mailbox can revoke it; the id in the log line is
 * enough.
 *
 *   php bin/console app:push:cancel-subscription --account=10 \
 *       --subscription=701dc0d3-b4ed-4ad8-94f2-a263a90a30bb
 *
 * Renewal no longer creates these — a failure that is not a 404 hands the old
 * registration back before building a replacement, see
 * GraphSubscriptionManager::renew(). This is for the ones already out there,
 * and for the orphans nothing else can reach: push switched off by hand, an
 * account removed and recreated.
 *
 * It deliberately does NOT touch the account's own subscription state. If the
 * id given happens to be the live one, the next run of app:push:renew builds a
 * new subscription in the ordinary way.
 */
#[AsCommand(
    name: 'app:push:cancel-subscription',
    description: 'Cancel a Graph subscription by id, for registrations Microsoft still holds and plMail does not',
)]
final class GraphCancelSubscriptionCommand extends Command
{
    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly GraphApiClient    $apiClient,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('account', null, InputOption::VALUE_REQUIRED, 'Id of an account in the mailbox the subscription belongs to')
            ->addOption('subscription', null, InputOption::VALUE_REQUIRED, 'The subscription id from the log line');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $accountId      = (string) ($input->getOption('account') ?? '');
        $subscriptionId = trim((string) ($input->getOption('subscription') ?? ''));

        if ('' === $accountId || '' === $subscriptionId) {
            $io->error('Both --account and --subscription are required.');

            return Command::INVALID;
        }

        $account = $this->accountRepository->find((int) $accountId);

        if (null === $account) {
            $io->error(sprintf('No account with id %s.', $accountId));

            return Command::FAILURE;
        }

        if (false === $account->isMicrosoft()) {
            $io->error(sprintf('Account %s is not a Microsoft account, so it has no Graph subscriptions.', $accountId));

            return Command::FAILURE;
        }

        try {
            $this->apiClient->deleteSubscription($account, $subscriptionId);
        } catch (\Throwable $e) {
            // A 404 here is the good outcome as often as not: it means the
            // registration has already lapsed and the notifications have
            // stopped. Reported rather than dressed up as success, because the
            // caller is diagnosing something and deserves to know which it was.
            $io->warning(sprintf('Graph refused the cancellation: %s', $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf('Cancelled subscription %s.', $subscriptionId));

        return Command::SUCCESS;
    }
}
