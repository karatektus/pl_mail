<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Helper\OwnAddressHelper;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\Mail\Message;
use PHPUnit\Framework\TestCase;

/**
 * Which addresses are the account's own in one message.
 *
 * The cases that matter are the ones where a catch-all domain is shared with
 * people who have mailboxes of their own — the rule is "an address this
 * message is evidence for", not "anything at the domain", and each test below
 * is one kind of evidence or the lack of it.
 */
final class OwnAddressHelperTest extends TestCase
{
    public function testWithoutACatchAllDomainTheAnswerIsTheAccountsAliases(): void
    {
        $message = $this->message($this->account(catchAll: false), to: ['yo@shop.test', 'me@example.test']);

        self::assertSame(['me@example.test'], OwnAddressHelper::forMessage($message));
        self::assertSame([], OwnAddressHelper::caughtRecipients($message));
    }

    public function testACatchAllAddressTheMailWasSentToIsOwn(): void
    {
        $message = $this->message($this->account(), to: ['Yo@Shop.test'], cc: ['someone@elsewhere.test']);

        self::assertSame(['me@example.test', 'yo@shop.test'], OwnAddressHelper::forMessage($message));
    }

    public function testTheDeliveryHeaderSettlesWhichCatchAllAddressWasOurs(): void
    {
        $message          = $this->message($this->account(), to: ['anna@shop.test', 'yo@shop.test']);
        $message->headers = ['Delivered-To' => '<yo@shop.test>'];

        self::assertSame(['yo@shop.test'], OwnAddressHelper::caughtRecipients($message));
    }

    /** The one place it still guesses, written down so a change to it is a decision. */
    public function testWithoutADeliveryHeaderEveryVisibleCatchAllRecipientIsClaimed(): void
    {
        $message = $this->message($this->account(), to: ['anna@shop.test', 'yo@shop.test']);

        self::assertSame(['anna@shop.test', 'yo@shop.test'], OwnAddressHelper::caughtRecipients($message));
    }

    /** A delivery header naming an address elsewhere says nothing about the catch-all. */
    public function testADeliveryHeaderForAnAliasLeavesTheVisibleRecipientsToAnswer(): void
    {
        $message          = $this->message($this->account(), to: ['yo@shop.test']);
        $message->headers = ['Delivered-To' => 'me@example.test'];

        self::assertSame(['yo@shop.test'], OwnAddressHelper::caughtRecipients($message));
    }

    // ── sent by the account ──────────────────────────────────────────────────

    public function testMailFromAnAliasIsTheAccountsOwn(): void
    {
        $message              = $this->message($this->account(), to: ['k@elsewhere.test']);
        $message->fromAddress = 'Me@Example.test';

        self::assertTrue(OwnAddressHelper::sentByAccount($message));
    }

    public function testMailFromTheCatchAllDomainIsOnlyOursWhenItIsFiledAsSent(): void
    {
        $message              = $this->message($this->account(), to: ['me@example.test']);
        $message->fromAddress = 'anna@shop.test';

        self::assertFalse(OwnAddressHelper::sentByAccount($message), 'in the inbox it is a colleague writing');

        $sent       = new Label();
        $sent->role = LabelRole::Sent;
        $message->addLabel($sent);

        self::assertTrue(OwnAddressHelper::sentByAccount($message));
    }

    public function testMailFromAStrangerIsNotOursWhereverItIsFiled(): void
    {
        $message              = $this->message($this->account(), to: ['me@example.test']);
        $message->fromAddress = 'k@elsewhere.test';

        $sent       = new Label();
        $sent->role = LabelRole::Sent;
        $message->addLabel($sent);

        self::assertFalse(OwnAddressHelper::sentByAccount($message));
    }

    /**
     * @param list<string> $to
     * @param list<string> $cc
     */
    private function message(Account $account, array $to, array $cc = []): Message
    {
        $wrap = static fn (string $address): array => ['name' => '', 'address' => $address];

        $message              = new Message();
        $message->account     = $account;
        $message->fromAddress = 'k@elsewhere.test';
        $message->toAddresses = array_map($wrap, $to);
        $message->ccAddresses = array_map($wrap, $cc);

        return $message;
    }

    private function account(bool $catchAll = true): Account
    {
        $account        = new Account();
        $account->email = 'me@example.test';

        $account->addAlias(new EmailAlias($account, 'me@example.test', EmailAliasSource::Manual, EmailAliasStatus::Primary));

        if (true === $catchAll) {
            $account->catchAllDomains = ['shop.test'];
        }

        return $account;
    }
}
