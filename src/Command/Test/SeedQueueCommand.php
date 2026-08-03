<?php

declare(strict_types=1);

namespace App\Command\Test;

use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Puts messages in the transport so the admin queue panel has something to be
 * about.
 *
 * Written straight into `messenger_messages` rather than dispatched, because
 * the panel's whole job is to distinguish states the bus cannot produce on
 * demand: a message a worker is holding right now, and one backing off until
 * later. Dispatching gives only the third.
 *
 * Real serialised envelopes, through the transport's own serializer — the
 * panel decodes them to name the handler, so a hand-written body would test
 * the reading of a format nothing writes.
 */
#[AsCommand(
    name: 'app:test:seed-queue',
    description: 'Seed the messenger transport for the admin queue panel tests',
)]
final class SeedQueueCommand extends Command
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire(service: 'messenger.transport.native_php_serializer')]
        private readonly SerializerInterface $serializer,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('waiting', null, InputOption::VALUE_REQUIRED, 'How many waiting messages', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ('prod' === $this->environment) {
            $io->error('app:test:seed-queue must not run in the prod environment.');

            return Command::FAILURE;
        }

        // Only what this command wrote before: the failure queue belongs to
        // another panel, and a test that cleared it would be changing the
        // subject of a different spec.
        $this->connection->executeStatement(
            "DELETE FROM messenger_messages WHERE queue_name IN ('ingest', 'export')",
        );

        $waiting = max(1, (int) $input->getOption('waiting'));
        $now     = new DateTimeImmutable();

        // Enough to page: the panel fetches 25 at a time, and a backlog that
        // fits in one page never exercises the second fetch.
        for ($i = 0; $i < $waiting; $i++) {
            $this->insert('ingest', new SyncAccountMessage($i + 1), $now->modify(sprintf('-%d minutes', $i + 2)));
        }

        // One a worker is holding, and one waiting on a clock — the two states
        // the panel exists to tell apart.
        $this->insert('ingest', new SyncAccountMessage(900), $now->modify('-20 minutes'), deliveredAt: $now->modify('-3 minutes'));
        $this->insert('export', new SyncAccountMessage(901), $now->modify('-15 minutes'), availableAt: $now->modify('+30 minutes'));

        $io->success(sprintf('Seeded %d waiting, 1 running and 1 scheduled message.', $waiting));

        return Command::SUCCESS;
    }

    private function insert(
        string $queue,
        object $message,
        DateTimeImmutable $createdAt,
        ?DateTimeImmutable $availableAt = null,
        ?DateTimeImmutable $deliveredAt = null,
    ): void {
        $encoded = $this->serializer->encode(new Envelope($message));

        $this->connection->insert('messenger_messages', [
            'body'         => $encoded['body'],
            'headers'      => json_encode($encoded['headers'] ?? [], JSON_THROW_ON_ERROR),
            'queue_name'   => $queue,
            'created_at'   => $createdAt,
            'available_at' => $availableAt ?? $createdAt,
            'delivered_at' => $deliveredAt,
        ], [
            'created_at'   => 'datetime_immutable',
            'available_at' => 'datetime_immutable',
            'delivered_at' => 'datetime_immutable',
        ]);
    }
}
