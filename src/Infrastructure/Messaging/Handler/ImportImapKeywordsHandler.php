<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\ImportImapKeywordsMessage;
use App\Repository\Mail\Account\AccountRepository;
use App\Repository\Mail\Message\MessageRepository;
use App\Service\Label\KeywordLabelFollower;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Turns the keywords already in an account's flag mirror into labels, a page
 * at a time, after the switch went on. See ImportImapKeywordsMessage.
 *
 * The switch is read again here, not trusted from the dispatch: somebody who
 * turned it on and straight back off meant the second, and labels made from
 * keywords would then be labels nobody asked for. Off midway, the walk stops
 * at the next page.
 *
 * No IMAP: the flag mirror is what the folder listing last said, and that is
 * what the follower reads anyway. A keyword that reaches the server after the
 * mirror was taken arrives through follow() as the change it is.
 *
 * Safe to run twice (importExisting() only adds), and a walk cut short leaves
 * nothing half-done: each page is flushed before the next is queued.
 */
#[AsMessageHandler]
final readonly class ImportImapKeywordsHandler
{
    /** Small enough that one page is a short transaction. */
    public const int PAGE = 200;

    public function __construct(
        private AccountRepository      $accounts,
        private MessageRepository      $messages,
        private KeywordLabelFollower   $follower,
        private EntityManagerInterface $em,
        private MessageBusInterface    $bus,
    ) {
    }

    public function __invoke(ImportImapKeywordsMessage $job): void
    {
        $account = $this->accounts->find($job->accountId);

        if (null === $account || false === $account->labelsAsKeywords) {
            return;
        }

        $ids = $this->messages->idsInFoldersForAccountAfter($job->accountId, $job->afterId, self::PAGE);

        if ([] === $ids) {
            return;
        }

        /** @var array<int, array{0: Mailbox, 1: list<Message>}> $byFolder */
        $byFolder = [];

        foreach ($this->messages->findByIds($ids) as $message) {
            $mailbox = $message->mailbox;

            if (null === $mailbox) {
                continue;
            }

            $byFolder[(int) $mailbox->id] ??= [$mailbox, []];
            $byFolder[(int) $mailbox->id][1][] = $message;
        }

        foreach ($byFolder as [$mailbox, $inFolder]) {
            $this->follower->importExisting($mailbox, $inFolder);
        }

        $this->em->flush();
        $this->em->clear();

        if (self::PAGE === count($ids)) {
            $this->bus->dispatch(new ImportImapKeywordsMessage($job->accountId, $ids[count($ids) - 1]));
        }
    }
}
