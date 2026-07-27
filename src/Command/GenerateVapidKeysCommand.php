<?php

declare(strict_types=1);

namespace App\Command;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mint a VAPID keypair for Web Push (RFC 8292).
 *
 * Prints rather than writes: the private key belongs in .env.local, which is
 * untracked, and silently editing env files is the kind of helpfulness that
 * ends with a secret in git.
 *
 * Rotating the keypair invalidates every existing PushSubscription — browsers
 * bind a subscription to the applicationServerKey it was created with — so
 * every device has to re-register afterwards.
 */
#[AsCommand(
    name: 'app:push:generate-vapid-keys',
    description: 'Generate a VAPID keypair for Web Push and print the env lines',
)]
final class GenerateVapidKeysCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $keys = VAPID::createVapidKeys();

        $io->section('Add to .env.local (never to .env — it is tracked)');
        $io->writeln('###> plmail/web-push ###');
        $io->writeln('VAPID_SUBJECT=mailto:you@example.com');
        $io->writeln('VAPID_PUBLIC_KEY='.$keys['publicKey']);
        $io->writeln('VAPID_PRIVATE_KEY='.$keys['privateKey']);
        $io->writeln('###< plmail/web-push ###');
        $io->newLine();

        $io->warning([
            'Rotating these invalidates every existing PushSubscription.',
            'Devices bind to the public key at registration and must re-register.',
        ]);

        $io->note('VAPID_SUBJECT must be a mailto: or https: URL identifying you, per RFC 8292.');

        return Command::SUCCESS;
    }
}
