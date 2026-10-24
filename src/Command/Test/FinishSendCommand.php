<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Handler\SendMessageHandler;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Repository\Mail\AccountRepository;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Does for one message what the send worker does ten seconds after Send: the
 * real SendMessageHandler, handed the mail's real SendMessageMessage, and then
 * the event the worker dispatches once a message is handled — which is what
 * drains the change log to the browsers (JmapPushSubscriber) and so moves the
 * sidebar and the conversation.
 *
 * For the browser suite, which has no worker on the send queue and so never
 * saw a send finish. Picking the one message by subject, rather than draining
 * the queue, keeps it from sending what another worker's spec is still
 * holding. The provider is App\Tests\Support\Mail\RecordingMailSender, which
 * claims only the accounts the suite seeds and writes the mail to
 * var/test-sent; this prints that file, so a spec can read what went out.
 *
 * Refuses to run in prod.
 */
#[AsCommand(
    name: 'app:test:finish-send',
    description: 'Run the send job for an E2E user\'s message, by subject, and print what went out',
)]
final class FinishSendCommand extends Command
{
    public function __construct(
        private readonly UserRepository           $users,
        private readonly AccountRepository        $accounts,
        private readonly EntityManagerInterface   $entityManager,
        private readonly SendMessageHandler       $handler,
        private readonly EventDispatcherInterface $dispatcher,
        #[Autowire('%kernel.project_dir%/var/test-sent')]
        private readonly string                   $directory,
        #[Autowire('%kernel.environment%')]
        private readonly string                   $environment,
    ) {
        parent::__construct();
    }

    /** Where RecordingMailSender writes the mail with this Message-ID. */
    public static function sentPath(string $directory, string $messageId): string
    {
        return sprintf('%s/%s.eml', $directory, preg_replace('/[^A-Za-z0-9._@-]/', '_', trim($messageId, '<> ')));
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
            $output->writeln('app:test:finish-send must not run in the prod environment.');

            return Command::FAILURE;
        }

        $user     = $this->users->findOneBy(['email' => (string) $input->getOption('email')]);
        $accounts = null === $user ? [] : $this->accounts->findBy(['usr' => $user]);

        /** @var Message|null $message */
        $message = [] === $accounts ? null : $this->entityManager->getRepository(Message::class)->findOneBy(
            ['account' => $accounts, 'subject' => (string) $input->getOption('subject'), 'sentAt' => null],
            ['id' => 'DESC'],
        );

        if (null === $message || null === $message->id) {
            $output->writeln('No unsent message with that subject.');

            return Command::FAILURE;
        }

        $id   = $message->id;
        $send = new SendMessageMessage($id);

        // As the worker finds it: nothing of this command's own reading in
        // the identity map, so the handler claims and loads the row itself.
        $this->entityManager->clear();
        ($this->handler)($send);
        $this->dispatcher->dispatch(new WorkerMessageHandledEvent(new Envelope($send), 'export'));

        $sent = $this->entityManager->getRepository(Message::class)->find($id);

        if (null === $sent?->sentAt || null === $sent->messageId) {
            $output->writeln('The send job did not send it.');

            return Command::FAILURE;
        }

        $output->write((string) file_get_contents(self::sentPath($this->directory, $sent->messageId)));

        return Command::SUCCESS;
    }
}
