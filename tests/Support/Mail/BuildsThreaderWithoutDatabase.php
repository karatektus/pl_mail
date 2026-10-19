<?php

declare(strict_types=1);

namespace App\Tests\Support\Mail;

use App\Jmap\State\ChangeLogRepository;
use App\Jmap\State\StateManager;
use App\Repository\Insight\MailInsightRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Imap\MessageThreader;
use App\Service\Imap\ThreadMerger;
use App\Service\Mail\MailChangeRecorder;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A MessageThreader for tests that never let it reach the database.
 *
 * Four test classes build one by hand: the rule tests because a collaborator
 * needs it and never calls the part that queries, MessageThreaderTest because
 * its cases run on the provider-key path, which queries nothing but the stub it
 * is handed. ThreadMerger is final and so are its own collaborators, so they
 * cannot be doubled and are built for real over stubs — once, here, rather
 * than as the same eight lines in every one of those classes.
 */
trait BuildsThreaderWithoutDatabase
{
    private function threaderWithoutDatabase(
        ?EntityManagerInterface  $entityManager = null,
        ?MessageThreadRepository $threadRepository = null,
    ): MessageThreader {
        $entityManager     ??= $this->createStub(EntityManagerInterface::class);
        $messageRepository = $this->createStub(MessageRepository::class);

        return new MessageThreader(
            $entityManager,
            $messageRepository,
            $threadRepository ?? $this->createStub(MessageThreadRepository::class),
            new ThreadMerger(
                $entityManager,
                $messageRepository,
                $this->createStub(MailInsightRepository::class),
                new MailChangeRecorder(
                    new StateManager(
                        $entityManager,
                        new ChangeLogRepository($this->createStub(ManagerRegistry::class)),
                    ),
                ),
            ),
        );
    }
}
