<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Mail;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Jmap\Method\Mail\EmailGetMethod;
use App\Jmap\Method\Mail\EmailSubmissionGetMethod;
use App\Jmap\Method\Mail\EmailSubmissionSetMethod;
use App\Jmap\Method\Mail\IdentityGetMethod;
use App\Tests\Jmap\JmapTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Email/get's `replyFrom`, and sending as what it names.
 *
 * The browser answers mail from the address it came in for; this is how a
 * phone gets the same answer without a copy of the rule. The round trip is the
 * subject: an id that Email/get hands out has to be one EmailSubmission/set
 * accepts, and the mail has to leave from that address under the name the
 * account sends it with.
 *
 * Two things are pinned as hard as the feature itself, because each is what an
 * app already installed depends on. Nothing new appears unless it is asked for
 * by name — not in a get with no `properties`, and not in Identity/get. And an
 * address identity is a spelling of an address, never a permission: the same
 * id for a domain the account does not catch is refused exactly as an unknown
 * number is.
 */
final class EmailReplyFromTest extends JmapTestCase
{
    private EmailGetMethod $get;
    private EmailSubmissionSetMethod $submit;
    private EmailSubmissionGetMethod $submissions;
    private IdentityGetMethod $identities;
    private InMemoryTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();
        $this->get         = $container->get(EmailGetMethod::class);
        $this->submit      = $container->get(EmailSubmissionSetMethod::class);
        $this->submissions = $container->get(EmailSubmissionGetMethod::class);
        $this->identities  = $container->get(IdentityGetMethod::class);

        $transport = $container->get('messenger.transport.export');

        self::assertInstanceOf(InMemoryTransport::class, $transport, 'the test transport must not really send mail');

        $this->transport = $transport;
        $this->transport->reset();
    }

    // ── which identity answers ────────────────────────────────────────────

    /** The alias the mail was sent to, as the identity Identity/get lists for it. */
    public function testMailForAnAliasIsAnsweredFromThatAliasesIdentity(): void
    {
        $this->alias((string) $this->account->email, EmailAliasStatus::Primary);
        $sales = $this->alias('sales@example.test', name: 'Sales Desk');

        $replyFrom = $this->replyFrom($this->receivedFor('sales@example.test'));

        self::assertNotNull($replyFrom);
        self::assertSame((string) $sales->id, $replyFrom['id']);
        self::assertSame('sales@example.test', $replyFrom['email']);
        self::assertSame('Sales Desk', $replyFrom['name']);
        self::assertContains($replyFrom['id'], $this->publishedIdentityIds(), 'an alias is an identity the client already holds');
    }

    /**
     * The catch-all case: no alias row, so no listed identity, and the object
     * is the only place the client can learn what to submit with.
     */
    public function testMailForACatchAllAddressIsAnsweredFromAnAddressIdentity(): void
    {
        $this->account->catchAllDomains = ['shop.test'];
        $this->account->setSetting(Account::SETTING_SIGNATURE, '<p>Kind regards</p>');
        $this->em->flush();

        $replyFrom = $this->replyFrom($this->receivedFor('Yo@Shop.test'));

        self::assertNotNull($replyFrom);
        self::assertSame('yo@shop.test', $replyFrom['email']);
        self::assertSame('Jmap Fixture', $replyFrom['name'], 'no alias row, so the account\'s name');
        self::assertSame('<p>Kind regards</p>', $replyFrom['htmlSignature'], 'and the account\'s signature');
        self::assertFalse($replyFrom['mayDelete']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,255}$/', $replyFrom['id'], 'RFC 8620 §1.2');
        self::assertNotContains($replyFrom['id'], $this->publishedIdentityIds(), 'Identity/get cannot list every local part');
    }

    /** Null is "stay on the primary identity", which is what every client did before. */
    public function testMailThatNamesNoneOfTheAccountsAddressesHasNoReplyFrom(): void
    {
        $this->account->catchAllDomains = ['shop.test'];
        $this->em->flush();

        $email = $this->email($this->receivedFor('list@elsewhere.test'), ['subject', 'replyFrom']);

        self::assertArrayHasKey('replyFrom', $email);
        self::assertNull($email['replyFrom']);
    }

    /**
     * A draft answers with the address it is written from, so a client
     * reopening one puts the sender back where it was left.
     */
    public function testADraftNamesTheAddressItIsAlreadyWrittenFrom(): void
    {
        $this->account->catchAllDomains = ['shop.test'];

        $draft = $this->draftMessage();
        $draft->fromAddress = 'yo@shop.test';
        $this->em->flush();

        self::assertSame('yo@shop.test', $this->replyFrom($draft)['email'] ?? null);
    }

    // ── what an installed app sees ────────────────────────────────────────

    /**
     * Not a default property. An app written before it existed asks for
     * everything and must not be sent a signature per message it never reads.
     */
    public function testItIsLeftOutUnlessAskedForByName(): void
    {
        $this->account->catchAllDomains = ['shop.test'];
        $this->em->flush();

        $message = $this->receivedFor('yo@shop.test');

        self::assertArrayNotHasKey('replyFrom', $this->email($message, null));
        self::assertArrayNotHasKey('replyFrom', $this->email($message, ['subject', 'from']));
    }

    /** A catch-all domain adds nothing to the list an old From picker is drawn from. */
    public function testACatchAllDomainAddsNoIdentityToTheList(): void
    {
        $before = $this->publishedIdentityIds();

        $this->account->catchAllDomains = ['shop.test'];
        $this->em->flush();

        self::assertSame($before, $this->publishedIdentityIds());
    }

    // ── sending as it ─────────────────────────────────────────────────────

    /** The round trip: the id handed out is accepted, and the row the worker reads carries the address and name. */
    public function testASubmissionWithTheAddressIdentityLeavesFromThatAddress(): void
    {
        $this->account->catchAllDomains = ['shop.test'];
        $this->em->flush();

        $replyFrom = $this->replyFrom($this->receivedFor('yo@shop.test'));
        $draft     = $this->addressedDraft();

        $result = $this->submit($draft, (string) $replyFrom['id']);

        self::assertArrayHasKey('s1', (array) $result['created']);
        self::assertSame('yo@shop.test', $draft->fromAddress);
        self::assertSame('Jmap Fixture', $draft->fromName);
        self::assertSame([(int) $draft->id], $this->queuedMessageIds());
    }

    /** And EmailSubmission/get reports that id back, not the primary one. */
    public function testTheSubmissionReportsTheAddressIdentityItWasSentAs(): void
    {
        $this->account->catchAllDomains = ['shop.test'];
        $this->em->flush();

        $replyFrom = $this->replyFrom($this->receivedFor('yo@shop.test'));
        $draft     = $this->addressedDraft();

        $this->submit($draft, (string) $replyFrom['id']);

        $result = $this->submissions->handle(
            ['accountId' => $this->accountId(), 'ids' => [(string) $draft->id]],
            $this->context(),
        );

        self::assertSame($replyFrom['id'], $result['list'][0]['identityId']);
    }

    /**
     * The forged sender. The id is well-formed and names a real-looking
     * address; the account does not catch that domain, so it is nothing.
     */
    public function testAnAddressIdentityForADomainTheAccountDoesNotCatchIsRefused(): void
    {
        $this->account->catchAllDomains = ['shop.test'];
        $this->em->flush();

        $draft = $this->addressedDraft();
        $draft->fromAddress = (string) $this->account->email;
        $this->em->flush();

        $result = $this->submit($draft, $this->addressId('ceo@bank.test'));

        self::assertSame('forbiddenFrom', ((array) $result['notCreated'])['s1']['type']);
        self::assertSame($this->account->email, $draft->fromAddress, 'a refused submission must not rewrite the From');
        self::assertSame([], $this->queuedMessageIds(), 'and must not reach the bus');
    }

    /** Checked when the mail is sent, not when the id was handed out. */
    public function testAnAddressIdentityStopsResolvingWhenTheDomainIsRemoved(): void
    {
        $this->account->catchAllDomains = ['shop.test'];
        $this->em->flush();

        $replyFrom = $this->replyFrom($this->receivedFor('yo@shop.test'));

        $this->account->catchAllDomains = [];
        $this->em->flush();

        $result = $this->submit($this->addressedDraft(), (string) $replyFrom['id']);

        self::assertSame('forbiddenFrom', ((array) $result['notCreated'])['s1']['type']);
        self::assertSame([], $this->queuedMessageIds());
    }

    /** Another account's catch-all is not this account's. */
    public function testAnAddressIdentityOfAnotherAccountsCatchAllIsRefused(): void
    {
        $other = $this->secondAccount();
        $other->catchAllDomains = ['shop.test'];
        $this->em->flush();

        $result = $this->submit($this->addressedDraft(), $this->addressId('yo@shop.test'));

        self::assertSame('forbiddenFrom', ((array) $result['notCreated'])['s1']['type']);
    }

    /** Anything after the prefix that is not an address is still just an unknown id. */
    public function testAMalformedAddressIdentityIsRefused(): void
    {
        $this->account->catchAllDomains = ['shop.test'];
        $this->em->flush();

        foreach (['addr-', 'addr-!!!', 'addr-' . rtrim(strtr(base64_encode('shop.test'), '+/', '-_'), '=')] as $id) {
            $result = $this->submit($this->addressedDraft(), $id);

            self::assertSame('forbiddenFrom', ((array) $result['notCreated'])['s1']['type'], $id);
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>|null
     */
    private function replyFrom(Message $message): ?array
    {
        return $this->email($message, ['replyFrom'])['replyFrom'];
    }

    /**
     * @param list<string>|null $properties
     *
     * @return array<string,mixed>
     */
    private function email(Message $message, ?array $properties): array
    {
        $result = $this->get->handle(
            ['accountId' => $this->accountId(), 'ids' => [(string) $message->id], 'properties' => $properties],
            $this->context(),
        );

        self::assertCount(1, $result['list']);

        return $result['list'][0];
    }

    /**
     * @return array<string,mixed>
     */
    private function submit(Message $draft, string $identityId): array
    {
        return $this->submit->handle(
            [
                'accountId' => $this->accountId(),
                'create'    => ['s1' => ['emailId' => (string) $draft->id, 'identityId' => $identityId]],
            ],
            $this->context(),
        );
    }

    /** @return list<string> */
    private function publishedIdentityIds(): array
    {
        $result = $this->identities->handle(['accountId' => $this->accountId(), 'ids' => null], $this->context());

        return array_map(static fn (array $identity): string => (string) $identity['id'], $result['list']);
    }

    /** @return list<int> */
    private function queuedMessageIds(): array
    {
        $ids = [];

        foreach ($this->transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof SendMessageMessage) {
                $ids[] = $message->messageId;
            }
        }

        return $ids;
    }

    /**
     * The id an address would have, built here rather than read from the
     * server — which is the point of the tests that use it: a client can
     * compute one for any address it likes.
     */
    private function addressId(string $address): string
    {
        return 'addr-' . rtrim(strtr(base64_encode($address), '+/', '-_'), '=');
    }

    private function receivedFor(string $address): Message
    {
        $message = $this->receivedMessage();
        $message->toAddresses = [['name' => null, 'address' => $address]];
        $this->em->flush();

        return $message;
    }

    private function addressedDraft(): Message
    {
        $draft = $this->draftMessage();
        $draft->toAddresses = [['name' => null, 'address' => 'recipient@example.test']];
        $this->em->flush();

        return $draft;
    }

    private function alias(
        string $address,
        EmailAliasStatus $status = EmailAliasStatus::Active,
        ?string $name = null,
    ): EmailAlias {
        $alias = new EmailAlias($this->account, $address, EmailAliasSource::Manual, $status);
        $alias->displayName = $name;
        $this->account->addAlias($alias);
        $this->em->persist($alias);
        $this->em->flush();

        return $alias;
    }
}
