<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Account\AccountService;
use App\Domain\Enum\Job\JobKind;
use App\Entity\Job\BackgroundJob;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\DeleteAccountMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Removing a mail account: the half that happens when Remove is pressed, and
 * the question every page asks afterwards — "is this account on its way out?"
 * (#672). DeleteAccountHandler is the other half.
 *
 * WHY IT LEFT THE REQUEST
 * ───────────────────────
 * Removing used to be `$em->remove($account)` in the controller, with the
 * account's mail going by ON DELETE CASCADE in the same statement. Every
 * message that cascade removes fires the thread-bin trigger, and a Gmail
 * mailbox of a few tens of thousands of messages took longer than PHP's thirty
 * seconds. The request died mid-transaction, everything rolled back — and so did
 * the log row of the error, written on the same connection — so the person was
 * told "something went wrong" and the only line left in the log was an
 * unrelated, already-handled warning about Gmail's watch. Always a job now,
 * whatever the size: a small account finishes in a second, and one code path
 * is one thing to trust.
 *
 * WHAT HAPPENS STRAIGHT AWAY
 * ──────────────────────────
 * Every service is switched off, through AccountServiceSwitch, before the job
 * is queued. That is the existing way to make an account go quiet, and it does
 * the rest with it: syncing stops, Gmail's watch and Graph's subscription are
 * handed back, the account stops being offered as a sender, and the primary
 * moves to one that can send. Without it the account would go on syncing — and
 * filling up again — while the job emptied it.
 *
 * IF IT FAILS the account is left with its services off and part of its mail
 * gone, and the indicator says why. Pressing Remove again picks up where it
 * stopped: the job deletes whatever is there, not a list made earlier.
 *
 * Whether an account is being removed is AccountRemovalState's to answer.
 */
final readonly class AccountRemoval
{
    public function __construct(
        private AccountServiceSwitch    $services,
        private AccountRemovalState     $state,
        private EntityManagerInterface  $em,
        private MessageBusInterface     $bus,
    ) {
    }

    /**
     * Switch the account off and queue its removal. Returns the job already
     * running for it when Remove is pressed twice, or from two tabs.
     */
    public function start(Account $account, User $user): BackgroundJob
    {
        $running = $this->state->activeFor($user)[(int) $account->id] ?? null;

        if (null !== $running) {
            return $running;
        }

        foreach (AccountService::cases() as $service) {
            $this->services->set($account, $service, false);
        }

        $job = new BackgroundJob($user, JobKind::DeleteAccount);

        // The scope and value every job carries, as DeleteLabel does. The
        // address is kept because the indicator names the account, and after
        // the last batch there is no row left to read it from.
        $job->view = [
            'scope'          => 'account',
            'value'          => (string) $account->id,
            'unreadOnly'     => false,
            'accountId'      => $account->id,
            'accountAddress' => $account->displayAddress,
        ];

        $this->em->persist($job);
        $this->em->flush();

        $this->bus->dispatch(new DeleteAccountMessage((int) $job->id));

        return $job;
    }
}
