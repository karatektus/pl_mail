<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Mail;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\EmailAlias;
use App\Entity\Mail\Message;
use App\Jmap\Method\Mail\EmailGetMethod;
use App\Jmap\Method\Mail\EmailSetMethod;
use App\Jmap\Session\SessionBuilder;
use App\Tests\Jmap\JmapTestCase;

/**
 * A draft keeps the sender it was saved with.
 *
 * RFC 8621 names the identity on the submission only, so until `identityId`
 * was accepted on Email/set a draft had no sender of its own: every one an app
 * saved was stored from the account's address, and a reply written from an
 * alias and reopened from Drafts came back on the primary one — to be sent, by
 * whoever did not notice, as the wrong person.
 *
 * The round trip is what is pinned: saved with an identity, read back through
 * `replyFrom`, which is where a client reopening the draft looks.
 */
final class EmailDraftIdentityTest extends JmapTestCase
{
    private EmailSetMethod $set;
    private EmailGetMethod $get;

    protected function setUp(): void
    {
        parent::setUp();

        $this->set = self::getContainer()->get(EmailSetMethod::class);
        $this->get = self::getContainer()->get(EmailGetMethod::class);
    }

    public function testADraftCreatedWithAnIdentityIsWrittenFromThatAddress(): void
    {
        $this->alias((string) $this->account->email, EmailAliasStatus::Primary);
        $sales = $this->alias('sales@example.test', name: 'Sales Desk');

        $draft = $this->create(['identityId' => (string) $sales->id]);

        self::assertSame('sales@example.test', $draft->fromAddress);
        self::assertSame('Sales Desk', $draft->fromName);
        self::assertSame((string) $sales->id, $this->replyFrom($draft)['id'] ?? null, 'and a client reopening it is told so');
    }

    /** What every installed app sends, and what it has always got. */
    public function testADraftCreatedWithoutAnIdentityIsWrittenFromTheAccount(): void
    {
        $this->alias((string) $this->account->email, EmailAliasStatus::Primary);
        $this->alias('sales@example.test');

        self::assertSame($this->account->email, $this->create([])->fromAddress);
    }

    /** The From menu changed on a draft already saved: the next autosave carries it. */
    public function testAnAutosaveMovesTheDraftToTheIdentityItNames(): void
    {
        $this->alias((string) $this->account->email, EmailAliasStatus::Primary);
        $sales = $this->alias('sales@example.test', name: 'Sales Desk');

        $draft = $this->create([]);

        $this->update($draft, ['identityId' => (string) $sales->id, 'subject' => 'Changed']);

        self::assertSame('sales@example.test', $draft->fromAddress);
        self::assertSame('Sales Desk', $draft->fromName);
        self::assertSame('Changed', $draft->subject);
    }

    /** An autosave that says nothing about the sender leaves the sender alone. */
    public function testAnAutosaveWithoutAnIdentityKeepsTheSender(): void
    {
        $this->alias((string) $this->account->email, EmailAliasStatus::Primary);
        $sales = $this->alias('sales@example.test');

        $draft = $this->create(['identityId' => (string) $sales->id]);

        $this->update($draft, ['subject' => 'Changed']);

        self::assertSame('sales@example.test', $draft->fromAddress);
    }

    public function testADraftCanBeWrittenFromACatchAllAddress(): void
    {
        $this->account->catchAllDomains = ['shop.test'];
        $this->em->flush();

        $draft = $this->create(['identityId' => $this->addressId('yo@shop.test')]);

        self::assertSame('yo@shop.test', $draft->fromAddress);
        self::assertSame('yo@shop.test', $this->replyFrom($draft)['email'] ?? null);
    }

    /**
     * Ignored, not refused — the one place this differs from a submission. A
     * save that failed over the sender would lose the text; the submission is
     * where sending as it is turned down.
     */
    public function testAnIdentityTheAccountMayNotSendAsIsIgnoredAndTheDraftStillSaved(): void
    {
        $this->alias((string) $this->account->email, EmailAliasStatus::Primary);
        $sales = $this->alias('sales@example.test');

        $draft = $this->create(['identityId' => (string) $sales->id]);

        foreach (['999999', $this->addressId('ceo@bank.test'), 'addr-!!!'] as $forged) {
            $result = $this->set->handle(
                [
                    'accountId' => $this->accountId(),
                    'update'    => [(string) $draft->id => ['identityId' => $forged, 'subject' => 'Still saved']],
                ],
                $this->context(),
            );

            self::assertArrayHasKey((string) $draft->id, $result['updated'] ?? [], $forged);
            self::assertSame('sales@example.test', $draft->fromAddress, $forged);
            self::assertSame('Still saved', $draft->subject);
        }
    }

    /** A sender is a property of a draft; mail that has arrived does not get a new one. */
    public function testTheIdentityOfAReceivedMessageCannotBeChanged(): void
    {
        $this->alias((string) $this->account->email, EmailAliasStatus::Primary);
        $sales   = $this->alias('sales@example.test');
        $message = $this->receivedMessage();
        $before  = $message->fromAddress;

        $result = $this->set->handle(
            [
                'accountId' => $this->accountId(),
                'update'    => [(string) $message->id => ['identityId' => (string) $sales->id]],
            ],
            $this->context(),
        );

        self::assertSame('invalidPatch', $result['notUpdated'][(string) $message->id]['type'] ?? null);
        self::assertSame($before, $message->fromAddress);
    }

    /** A client asks this before it names an identity on a draft; an older server refuses the patch. */
    public function testTheSessionSaysDraftsKeepTheirIdentity(): void
    {
        $session = json_encode(self::getContainer()->get(SessionBuilder::class)->build($this->user), JSON_THROW_ON_ERROR);

        self::assertStringContainsString('"draftIdentity":true', $session);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $extra
     */
    private function create(array $extra): Message
    {
        $result = $this->set->handle(
            [
                'accountId' => $this->accountId(),
                'create'    => ['d1' => $extra + [
                    'subject'    => 'A reply',
                    'to'         => [['name' => null, 'email' => 'recipient@example.test']],
                    'htmlBody'   => [['partId' => 'b', 'type' => 'text/html']],
                    'bodyValues' => ['b' => ['value' => '<p>Hello</p>']],
                ]],
            ],
            $this->context(),
        );

        self::assertArrayHasKey('d1', $result['created'] ?? [], json_encode($result['notCreated'] ?? null) ?: '');

        $draft = $this->em->find(Message::class, (int) $result['created']['d1']['id']);

        self::assertNotNull($draft);

        return $draft;
    }

    /**
     * @param array<string,mixed> $patch
     */
    private function update(Message $draft, array $patch): void
    {
        $result = $this->set->handle(
            ['accountId' => $this->accountId(), 'update' => [(string) $draft->id => $patch]],
            $this->context(),
        );

        self::assertArrayHasKey((string) $draft->id, $result['updated'] ?? [], json_encode($result['notUpdated'] ?? null) ?: '');
    }

    /**
     * @return array<string,mixed>|null
     */
    private function replyFrom(Message $message): ?array
    {
        $result = $this->get->handle(
            ['accountId' => $this->accountId(), 'ids' => [(string) $message->id], 'properties' => ['replyFrom']],
            $this->context(),
        );

        return $result['list'][0]['replyFrom'] ?? null;
    }

    private function addressId(string $address): string
    {
        return 'addr-' . rtrim(strtr(base64_encode($address), '+/', '-_'), '=');
    }

    private function alias(
        string $address,
        EmailAliasStatus $status = EmailAliasStatus::Active,
        ?string $name = null,
    ): EmailAlias {
        $alias = new EmailAlias($this->account, $address, EmailAliasSource::Manual, $status, $name);

        $this->account->addAlias($alias);
        $this->em->persist($alias);
        $this->em->flush();

        return $alias;
    }
}
