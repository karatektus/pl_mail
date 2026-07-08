<?php

namespace App\Command;

use App\Repository\MessageRepository;
use App\Service\Imap\MessageSendService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:mail:send-draft',
    description: 'Pick a draft and send it via MessageSendService',
)]
class SendDraftCommand extends Command
{
    public function __construct(
        private readonly MessageRepository  $messageRepository,
        private readonly MessageSendService $sendService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('message-id', InputArgument::OPTIONAL, 'Skip the picker and send this message ID directly');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Send Draft');

        $messageId = $input->getArgument('message-id');

        if ($messageId !== null) {
            $message = $this->messageRepository->find((int) $messageId);

            if ($message === null) {
                $io->error(sprintf('No message found with ID %d.', $messageId));
                return Command::FAILURE;
            }
        } else {
            // Load all draft messages
            $drafts = $this->messageRepository->findDrafts();

            if (count($drafts) === 0) {
                $io->warning('No drafts found.');
                return Command::SUCCESS;
            }

            // Build choice list: "ID — Subject (account@email.com)"
            $choices = [];
            foreach ($drafts as $draft) {
                $account = $draft->getMailbox()->getAccount();
                $label = sprintf(
                    '[%d] %s — %s',
                    $draft->getId(),
                    $draft->getSubject() ?: '(no subject)',
                    $account->getEmail() ?? $account->getUsername(),
                );
                $choices[$draft->getId()] = $label;
            }

            $choice = $io->choice('Pick a draft to send', $choices);

            // $choice is the label string — find the matching draft by ID
            $selectedId = array_search($choice, $choices);
            $message    = $this->messageRepository->find($selectedId);

            if ($message === null) {
                $io->error('Could not load the selected draft.');
                return Command::FAILURE;
            }
        }

        $account = $message->getMailbox()->getAccount();
        $io->section(sprintf(
            'Sending message %d — "%s" via %s',
            $message->getId(),
            $message->getSubject() ?: '(no subject)',
            $account->getEmail() ?? $account->getUsername(),
        ));

        try {
            $this->sendService->send($message);
            $io->success('Message sent successfully.');
        } catch (\Throwable $e) {
            $io->error('Send failed: ' . $e->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
