<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Mail;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Entity\Mail\EmailAlias;
use App\Jmap\Method\Mail\IdentityGetMethod;
use App\Jmap\Method\Mail\IdentitySetMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Tests\Jmap\JmapTestCase;

/**
 * "Identity/set" — the addresses a client may send from.
 *
 * An Identity is a plMail EmailAlias, and RFC 8621's Identity object is much
 * larger than an alias: replyTo, bcc, a text signature and an HTML signature.
 * plMail stores none of those, and the decision here is to REFUSE them rather
 * than accept and drop them. That is the interesting behaviour and most of
 * what follows pins it: a client told it saved a signature, which then sends
 * mail without one, has no way to discover the difference — and the person
 * finding out is the recipient.
 *
 * The destroy rules are the other half. An alias the provider discovered is
 * not the client's to remove — it returns on the next sync, so honouring the
 * delete would produce a mailbox that resurrects a deleted identity and looks
 * broken.
 */
final class IdentitySetMethodTest extends JmapTestCase
{
    private IdentitySetMethod $method;
    private IdentityGetMethod $get;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();
        $this->method = $container->get(IdentitySetMethod::class);
        $this->get    = $container->get(IdentityGetMethod::class);
    }

    public function testItIsNamedIdentitySet(): void
    {
        self::assertSame('Identity/set', $this->method->name());
    }

    // ── create ────────────────────────────────────────────────────────────

    public function testCreateAddsASendableIdentity(): void
    {
        $result = $this->handle([
            'create' => ['i1' => ['email' => 'Second@Example.Test', 'name' => 'Second Me']],
        ]);

        $created = ((array) $result['created'])['i1'];

        self::assertTrue($created['mayDelete'], 'a hand-added identity is the user\'s to remove');

        $identity = $this->identityById($created['id']);

        self::assertSame('second@example.test', $identity['email'], 'the address is normalised');
        self::assertSame('Second Me', $identity['name']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unstorableProperties(): iterable
    {
        yield 'replyTo' => ['replyTo'];
        yield 'bcc' => ['bcc'];
        yield 'textSignature' => ['textSignature'];
        yield 'htmlSignature' => ['htmlSignature'];
    }

    /**
     * Refused, not dropped. The refusal even names the property, because
     * "invalidProperties" alone leaves a client guessing which of four fields
     * it may not send.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unstorableProperties')]
    public function testAPropertyPlmailCannotStoreIsRefusedByName(string $property): void
    {
        $result = $this->handle([
            'create' => ['i1' => ['email' => 'new@example.test', $property => 'something']],
        ]);

        $error = ((array) $result['notCreated'])['i1'];

        self::assertSame('invalidProperties', $error['type']);
        self::assertStringContainsString($property, $error['description']);
        self::assertCount(0, $this->account->aliases);
    }

    public function testAnAddressThatIsNotAMailboxIsRefused(): void
    {
        $result = $this->handle(['create' => ['i1' => ['email' => 'not an address']]]);

        self::assertSame('invalidProperties', ((array) $result['notCreated'])['i1']['type']);
    }

    public function testANonStringAddressIsRefused(): void
    {
        $result = $this->handle(['create' => ['i1' => ['email' => ['a@example.test']]]]);

        self::assertSame('invalidProperties', ((array) $result['notCreated'])['i1']['type']);
    }

    /**
     * Uniqueness is enforced here rather than left to the unique index, so the
     * client gets a SetError for that one id instead of the whole request
     * dying on a database constraint.
     */
    public function testADuplicateAddressIsRefused(): void
    {
        $this->alias('taken@example.test');

        $result = $this->handle(['create' => ['i1' => ['email' => 'TAKEN@example.test']]]);

        $error = ((array) $result['notCreated'])['i1'];

        self::assertSame('invalidProperties', $error['type']);
        self::assertStringContainsString('already an identity', $error['description']);
    }

    public function testANonObjectCreateArgumentFailsTheWholeCall(): void
    {
        $this->expectException(MethodException::class);

        $this->handle(['create' => 'not a map']);
    }

    // ── update ────────────────────────────────────────────────────────────

    public function testUpdateChangesTheDisplayName(): void
    {
        $alias = $this->alias('renamed@example.test', displayName: 'Before');

        $result = $this->handle(['update' => [(string) $alias->id => ['name' => 'After']]]);

        self::assertSame([(string) $alias->id => null], (array) $result['updated']);
        self::assertSame('After', $alias->displayName);
    }

    /**
     * "email" is create-only in the spec, and for good reason: repointing an
     * identity at another address would silently change who a client that
     * remembered the id sends as.
     */
    public function testTheAddressCannotBeChangedByAnUpdate(): void
    {
        $alias = $this->alias('fixed@example.test');

        $result = $this->handle(['update' => [(string) $alias->id => ['email' => 'moved@example.test']]]);

        self::assertSame('invalidProperties', ((array) $result['notUpdated'])[(string) $alias->id]['type']);
        self::assertSame('fixed@example.test', $alias->address);
    }

    public function testAnUnknownIdentityIsNotUpdated(): void
    {
        $result = $this->handle(['update' => ['999999' => ['name' => 'Nobody']]]);

        self::assertSame('notFound', ((array) $result['notUpdated'])['999999']['type']);
    }

    /** A creation id from earlier in the same request resolves to the new row. */
    public function testAnIdentityCreatedInThisRequestCanBeUpdatedByCreationId(): void
    {
        $context = $this->context();

        $result = $this->method->handle(
            [
                'accountId' => $this->accountId(),
                'create' => ['i1' => ['email' => 'fresh@example.test']],
                'update' => ['#i1' => ['name' => 'Named after the fact']],
            ],
            $context,
        );

        self::assertArrayHasKey('#i1', (array) $result['updated']);
        self::assertSame('Named after the fact', $this->identityById($context->resolveId('#i1') ?? '')['name']);
    }

    // ── destroy ───────────────────────────────────────────────────────────

    public function testDestroyRemovesAHandAddedIdentity(): void
    {
        $alias = $this->alias('temporary@example.test');
        $id = (string) $alias->id;

        $result = $this->handle(['destroy' => [$id]]);

        self::assertSame([$id], $result['destroyed']);
        self::assertCount(0, $this->account->aliases);
    }

    /**
     * The primary address is what the account sends as. Destroying it would
     * leave the account with nothing to put in a From header.
     */
    public function testThePrimaryIdentityCannotBeDestroyed(): void
    {
        $alias = $this->alias('primary@example.test', status: EmailAliasStatus::Primary);

        $result = $this->handle(['destroy' => [(string) $alias->id]]);

        self::assertSame('forbidden', ((array) $result['notDestroyed'])[(string) $alias->id]['type']);
        self::assertCount(1, $this->account->aliases);
    }

    /**
     * A provider-discovered alias comes back on the next sync, so deleting it
     * would be a change that undoes itself — the client would show a row it
     * had removed, with no event to explain the reappearance.
     */
    public function testAProviderDiscoveredIdentityCannotBeDestroyed(): void
    {
        $alias = $this->alias('sendas@example.test', source: EmailAliasSource::Provider);

        $result = $this->handle(['destroy' => [(string) $alias->id]]);

        $error = ((array) $result['notDestroyed'])[(string) $alias->id];

        self::assertSame('forbidden', $error['type']);
        self::assertStringContainsString('next sync', $error['description']);
    }

    public function testAnUnknownIdentityIsNotDestroyed(): void
    {
        $result = $this->handle(['destroy' => ['999999']]);

        self::assertSame('notFound', ((array) $result['notDestroyed'])['999999']['type']);
    }

    // ── state ─────────────────────────────────────────────────────────────

    public function testAStaleIfInStateFailsTheWholeCall(): void
    {
        $this->expectException(MethodException::class);

        $this->handle(['ifInState' => 'not-the-current-state', 'update' => []]);
    }

    public function testACreateAdvancesTheIdentityState(): void
    {
        $result = $this->handle(['create' => ['i1' => ['email' => 'moves-state@example.test']]]);

        self::assertNotSame($result['oldState'], $result['newState']);
    }

    // ── what Identity/get then shows ──────────────────────────────────────

    /**
     * An account with no alias rows still has an address to send from, so
     * Identity/get degrades to a synthetic identity for the account itself
     * rather than reporting that the user may send from nowhere.
     */
    public function testAnAccountWithNoAliasesReportsOneSyntheticIdentity(): void
    {
        $result = $this->get->handle(['accountId' => $this->accountId()], $this->context());

        self::assertCount(1, $result['list']);
        self::assertSame((string) $this->account->id, $result['list'][0]['id']);
        self::assertSame($this->account->email, $result['list'][0]['email']);
        self::assertFalse($result['list'][0]['mayDelete'], 'the account address is not deletable');
    }

    /**
     * mayDelete has to agree with what Identity/set will actually do, or a
     * client draws a delete button that always fails.
     */
    public function testMayDeleteMatchesWhatDestroyWillAllow(): void
    {
        $manual   = $this->alias('manual@example.test');
        $primary  = $this->alias('primary@example.test', status: EmailAliasStatus::Primary);
        $provider = $this->alias('provider@example.test', source: EmailAliasSource::Provider);

        $byId = [];

        foreach ($this->get->handle(['accountId' => $this->accountId()], $this->context())['list'] as $identity) {
            $byId[$identity['id']] = $identity['mayDelete'];
        }

        self::assertTrue($byId[(string) $manual->id]);
        self::assertFalse($byId[(string) $primary->id]);
        self::assertFalse($byId[(string) $provider->id]);
    }

    public function testIdentityGetRefusesANonArrayIdsArgument(): void
    {
        $this->expectException(MethodException::class);

        $this->get->handle(['accountId' => $this->accountId(), 'ids' => 'one'], $this->context());
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    private function handle(array $arguments): array
    {
        return $this->method->handle(
            $arguments + ['accountId' => $this->accountId()],
            $this->context(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function identityById(string $id): array
    {
        foreach ($this->get->handle(['accountId' => $this->accountId()], $this->context())['list'] as $identity) {
            if ($identity['id'] === $id) {
                return $identity;
            }
        }

        self::fail(sprintf('Identity/get does not list "%s"', $id));
    }

    private function alias(
        string $address,
        EmailAliasSource $source = EmailAliasSource::Manual,
        EmailAliasStatus $status = EmailAliasStatus::Active,
        ?string $displayName = null,
    ): EmailAlias {
        $alias = new EmailAlias($this->account, $address, $source, $status, $displayName);

        $this->account->addAlias($alias);
        $this->em->persist($alias);
        $this->em->flush();

        return $alias;
    }
}
