<?php

declare(strict_types=1);

namespace App\Service\Demo;

use App\Entity\User\User;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Hands a demo visitor a mailbox of their own.
 *
 * One throwaway user per visitor, rather than one shared demo account
 * everybody signs into. A shared account is cheaper by every measure except the
 * one that matters: a demo is a thing people click, and the first two visitors
 * to arrive at once would be marking each other's mail read, deleting each
 * other's threads and reading whatever the previous visitor typed into a draft.
 * The product would look broken while working perfectly.
 *
 * The users are disposable by construction — a reserved domain nobody can
 * receive at, a published password, a stamped expiry — and app:demo:reap
 * removes them on a timer. Nothing here is meant to survive.
 */
final readonly class DemoProvisioner
{
    /** Stamped at provision time; app:demo:reap deletes on it. */
    public const string SETTING_EXPIRES_AT = 'demo.expires_at';

    public function __construct(
        private EntityManagerInterface      $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private DemoMailbox                 $demoMailbox,
        private DemoMode                    $demoMode,
    ) {
    }

    /**
     * Creates a user, their demo account and a seeded mailbox, and hands the
     * user back for the caller to log in.
     *
     * The address is random rather than sequential. It is visible on the
     * profile page and in the From of anything they send, and a demo that
     * tells every visitor they are number 412 is telling them something about
     * the deployment that is none of their business.
     */
    public function provision(): User
    {
        $now = new DateTimeImmutable();

        $user            = new User();
        $user->email     = sprintf('%s%s@%s', DemoMode::USER_PREFIX, bin2hex(random_bytes(6)), DemoMode::USER_DOMAIN);
        $user->nameFirst = 'Demo';
        $user->nameLast  = 'Besucher';
        $user->roles     = [User::ROLE_USER];
        $user->password  = $this->passwordHasher->hashPassword($user, DemoMode::PASSWORD);

        // Onboarding is marked done before they ever see a page. The setup
        // wizard opens over a backdrop that swallows every click and asks for
        // IMAP credentials as its first step — on a demo that is a locked door
        // in front of the thing they came to look at, and the account it would
        // ask them to add is one demo mode refuses to create anyway.
        $user->setSetting(
            User::SETTING_ONBOARDING_COMPLETED_AT,
            $now->format(DateTimeInterface::ATOM),
        );

        $user->setSetting(
            self::SETTING_EXPIRES_AT,
            $this->demoMode->expiryFrom($now)->format(DateTimeInterface::ATOM),
        );

        // Before the account, which needs a managed owner, and before the seed,
        // which needs ids.
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $account = $this->demoMailbox->account($user, []);

        $this->demoMailbox->seed($user, $account);

        return $user;
    }
}
