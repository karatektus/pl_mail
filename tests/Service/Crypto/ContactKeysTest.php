<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Domain\Enum\Crypto\ContactKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyOutcome;
use App\Domain\Enum\Crypto\ContactKeyStatus;
use App\Domain\Enum\Crypto\MessageCrypto;
use App\Domain\Enum\Crypto\PgpScheme;
use App\Domain\Enum\Crypto\RecipientPolicy;
use App\Domain\Exception\ContactKeyRefused;
use App\Entity\Crypto\ContactKey;
use App\Entity\Mail\Account;
use App\Infrastructure\Messaging\Message\VerifySignatureMessage;
use App\Repository\Crypto\ContactKeyRepository;
use App\Service\Crypto\ContactKeys;
use App\Service\Crypto\PublicKeyReader;
use App\Tests\Command\MailFixtures;
use App\Tests\Support\Crypto\Gpg;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Trust on first use, and nothing that arrives later gets to undo it.
 *
 * The first key for an address is used. A different one turning up later —
 * from an Autocrypt header, an attachment, WKD or a paste — is recorded and
 * waits; it never replaces the known key by itself, and never a pinned one.
 * That is the one property that makes collecting keys from incoming mail
 * safe at all: without it, any stranger's message would rewrite whom the
 * person believes.
 *
 * Real keys from gpg and the real reader, because "usable for this address"
 * is gpg's verdict on the key's self-signatures, expiry and revocation.
 */
final class ContactKeysTest extends KernelTestCase
{
    private const string ANNA = 'anna@contacts.test';

    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;
    private ContactKeys $contactKeys;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em          = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection  = static::getContainer()->get(Connection::class);
        $this->contactKeys = static::getContainer()->get(ContactKeys::class);
        $this->connection->beginTransaction();

        $this->account = MailFixtures::account($this->em, MailFixtures::user($this->em, 'tofu'));
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheFirstKeyForAnAddressIsUsed(): void
    {
        $key = new Gpg(self::ANNA);

        self::assertSame(ContactKeyOutcome::Added, $this->offer($key));

        $current = $this->repository()->findCurrent($this->account->usr, self::ANNA);
        self::assertSame($key->fingerprint, $current?->fingerprint);
        self::assertFalse($current->pinned);
    }

    public function testTheSameKeyAgainIsOnlySeenAgain(): void
    {
        $key = new Gpg(self::ANNA);

        $this->offer($key);

        self::assertSame(ContactKeyOutcome::Seen, $this->offer($key, ContactKeyOrigin::Wkd));
        self::assertCount(1, $this->repository()->findForAddress($this->account->usr, self::ANNA));
    }

    /** The heart of TOFU: a new key is news, not a replacement. */
    public function testADifferentKeyLaterIsRecordedAsChangedAndTheKnownOneStaysInUse(): void
    {
        $first  = new Gpg(self::ANNA);
        $second = new Gpg(self::ANNA);

        $this->offer($first);

        self::assertSame(ContactKeyOutcome::Changed, $this->offer($second));
        self::assertSame($first->fingerprint, $this->repository()->findCurrent($this->account->usr, self::ANNA)?->fingerprint);
        self::assertSame(ContactKeyStatus::Changed, $this->row($second)->status);
    }

    public function testAPinnedKeyIsNeverReplacedByOneThatArrivesLater(): void
    {
        $first  = new Gpg(self::ANNA);
        $second = new Gpg(self::ANNA);

        $this->offer($first);
        $this->contactKeys->pin($this->account->usr, (int) $this->row($first)->id);

        $this->offer($second, ContactKeyOrigin::Wkd);
        $this->contactKeys->import($this->account->usr, $second->armored);

        $current = $this->repository()->findCurrent($this->account->usr, self::ANNA);
        self::assertSame($first->fingerprint, $current?->fingerprint);
        self::assertTrue($current->pinned);
    }

    /**
     * An address whose known key was removed while a changed one waits still
     * has a decision pending; a third key does not get to make it by arriving.
     */
    public function testANewKeyDoesNotBecomeCurrentWhileAChangedOneWaits(): void
    {
        $first  = new Gpg(self::ANNA);
        $second = new Gpg(self::ANNA);
        $third  = new Gpg(self::ANNA);

        $this->offer($first);
        $this->offer($second);
        $this->contactKeys->remove($this->account->usr, (int) $this->row($first)->id);

        self::assertSame(ContactKeyOutcome::Changed, $this->offer($third));
        self::assertNull($this->repository()->findCurrent($this->account->usr, self::ANNA));
    }

    public function testAcceptingAChangedKeyReplacesTheKnownOneAndKeepsThePreferences(): void
    {
        $first  = new Gpg(self::ANNA);
        $second = new Gpg(self::ANNA);

        $this->offer($first);
        $this->contactKeys->pin($this->account->usr, (int) $this->row($first)->id);
        $this->contactKeys->setPreferences($this->account->usr, (int) $this->row($first)->id, RecipientPolicy::Always, RecipientPolicy::Never, PgpScheme::Inline);
        $this->offer($second);

        $accepted = $this->contactKeys->accept($this->account->usr, (int) $this->row($second)->id);

        self::assertTrue($accepted->isCurrent());
        self::assertFalse($accepted->pinned, 'the confirmation was about the other key');
        self::assertSame(RecipientPolicy::Always, $accepted->encrypt);
        self::assertSame(PgpScheme::Inline, $accepted->scheme);
        self::assertCount(1, $this->repository()->findForAddress($this->account->usr, self::ANNA), 'the old key is gone');
    }

    public function testAKeyWithoutAUserIdForTheAddressIsNotKept(): void
    {
        self::assertSame(ContactKeyOutcome::Unusable, $this->offer(new Gpg('someone-else@contacts.test')));
        self::assertSame([], $this->repository()->findForAddress($this->account->usr, self::ANNA));
    }

    public function testAnExpiredKeyIsNotKept(): void
    {
        self::assertSame(ContactKeyOutcome::Unusable, $this->offer(new Gpg(self::ANNA, expired: true)));
    }

    public function testARevokedKeyIsNotKept(): void
    {
        $key  = new Gpg(self::ANNA);
        $info = static::getContainer()->get(PublicKeyReader::class)->read($key->revoked())[0];

        self::assertTrue($info->revoked);
        self::assertSame(ContactKeyOutcome::Unusable, $this->contactKeys->record($this->account->usr, self::ANNA, $info, ContactKeyOrigin::Manual));
    }

    public function testAnImportKeepsTheKeyForEveryAddressItNames(): void
    {
        $key = new Gpg(self::ANNA, ['anna.work@contacts.test']);

        $counts = $this->contactKeys->import($this->account->usr, $key->armored);

        self::assertSame(2, $counts[ContactKeyOutcome::Added->value]);
        self::assertNotNull($this->repository()->findCurrent($this->account->usr, 'anna.work@contacts.test'));
    }

    public function testAPrivateKeyIsRefusedBeforeAnythingReadsIt(): void
    {
        $this->expectException(ContactKeyRefused::class);

        $this->contactKeys->import($this->account->usr, "-----BEGIN PGP PRIVATE KEY BLOCK-----\n\nxx\n-----END PGP PRIVATE KEY BLOCK-----");
    }

    /** Once a key is known, the sender's earlier signed mail is checked again. */
    public function testANewKeyQueuesTheSendersSignedMailForAnotherCheck(): void
    {
        $message = MailFixtures::message($this->em, $this->account);
        $message->fromAddress = self::ANNA;
        $message->crypto      = MessageCrypto::PgpSigned;
        $this->em->flush();

        $transport = static::getContainer()->get('messenger.transport.enrich');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $transport->reset();

        $this->offer(new Gpg(self::ANNA));

        $queued = array_map(static fn ($envelope): mixed => $envelope->getMessage(), $transport->getSent());

        self::assertEquals([new VerifySignatureMessage((int) $message->id)], $queued);
    }

    public function testAnotherPersonsKeyCannotBeTouched(): void
    {
        $this->offer(new Gpg(self::ANNA));
        $other = MailFixtures::user($this->em, 'stranger');

        $this->expectException(ContactKeyRefused::class);

        $this->contactKeys->pin($other, (int) $this->repository()->findCurrent($this->account->usr, self::ANNA)?->id);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function offer(Gpg $key, ContactKeyOrigin $origin = ContactKeyOrigin::Autocrypt): ContactKeyOutcome
    {
        $info = static::getContainer()->get(PublicKeyReader::class)->read($key->binary)[0];

        return $this->contactKeys->record($this->account->usr, self::ANNA, $info, $origin);
    }

    private function row(Gpg $key): ContactKey
    {
        $row = $this->repository()->findOneBy(['usr' => $this->account->usr, 'fingerprint' => $key->fingerprint]);
        self::assertNotNull($row);

        return $row;
    }

    private function repository(): ContactKeyRepository
    {
        return static::getContainer()->get(ContactKeyRepository::class);
    }
}
