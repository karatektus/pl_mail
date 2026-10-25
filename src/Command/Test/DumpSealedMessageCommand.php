<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Domain\Helper\SealedMessageStorage;
use App\Entity\Mail\Message;
use App\Repository\Mail\AccountRepository;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What the server holds of an end-to-end test user's message, as JSON: the
 * body columns, whether the draft is sealed, the seal of the message the
 * browser built, and the sealed files themselves.
 *
 * For encryption-sending.spec.ts, which has to show that what the server
 * keeps of an encrypted draft is ciphertext and nothing else — something no
 * page will say, because no page is allowed to see it. Refuses to run in prod.
 */
#[AsCommand(
    name: 'app:test:dump-sealed-message',
    description: 'Print what the server holds of an E2E user\'s message, by subject, as JSON',
)]
final class DumpSealedMessageCommand extends Command
{
    public function __construct(
        private readonly UserRepository         $users,
        private readonly AccountRepository      $accounts,
        private readonly EntityManagerInterface $entityManager,
        private readonly SealedMessageStorage   $storage,
        #[Autowire('%kernel.environment%')]
        private readonly string                 $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('email', null, InputOption::VALUE_REQUIRED, 'The E2E user')
            ->addOption('subject', null, InputOption::VALUE_REQUIRED, 'Subject of the message');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ('prod' === $this->environment) {
            $output->writeln('app:test:dump-sealed-message must not run in the prod environment.');

            return Command::FAILURE;
        }

        $user     = $this->users->findOneBy(['email' => (string) $input->getOption('email')]);
        $accounts = null === $user ? [] : $this->accounts->findBy(['usr' => $user]);

        /** @var Message|null $message */
        $message = [] === $accounts ? null : $this->entityManager->getRepository(Message::class)->findOneBy(
            ['account' => $accounts, 'subject' => (string) $input->getOption('subject')],
            ['id' => 'DESC'],
        );

        if (null === $message || null === $message->id) {
            $output->writeln('null');

            return Command::SUCCESS;
        }

        $output->writeln((string) json_encode([
            'bodyText'    => $message->bodyText,
            'bodyHtml'    => $message->bodyHtml,
            'sealedDraft' => $message->sealedDraft,
            'crypto'      => $message->crypto?->value,
            'seal'        => $message->outgoingSeal,
            'draft'       => $this->storage->read($message->id, SealedMessageStorage::DRAFT),
            'entity'      => $this->storage->read($message->id, SealedMessageStorage::OUTGOING),
            'parts'       => array_map(static fn ($part): array => ['name' => $part->filename, 'sealed' => $part->sealed], $message->messageParts->toArray()),
        ], JSON_THROW_ON_ERROR));

        return Command::SUCCESS;
    }
}
