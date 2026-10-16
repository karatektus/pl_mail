<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Domain\Enum\Crypto\AddressKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyOutcome;
use App\Domain\Enum\Crypto\ContactKeyStatus;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Crypto\AddressKey;
use App\Entity\Crypto\ContactKey;
use App\Entity\Crypto\UserKey;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\Mail\MessageThread;
use App\Repository\Crypto\ContactKeyRepository;
use App\Service\Crypto\Autocrypt;
use App\Service\Crypto\ContactKeyCollector;
use App\Service\Mail\RawMessageResolver;
use App\Tests\Command\MailFixtures;
use App\Tests\Support\Crypto\Gpg;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A message may vouch for its sender's own key, and for nobody else's.
 *
 * An Autocrypt header counts only when its `addr=` is the From address; an
 * attached key only with a user ID for the sender. Either rule missing, and
 * any message could plant a key for a third person — the person's colleague,
 * their bank — which plMail would then use as that person's on first use.
 * Mail the person sent themselves brings no contact key at all.
 */
final class ContactKeyCollectorTest extends KernelTestCase
{
    private const string SENDER = 'sender@collect.test';

    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        $this->account = MailFixtures::account($this->em, MailFixtures::user($this->em, 'collect'));
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── Autocrypt ─────────────────────────────────────────────────────────────

    public function testAnAutocryptHeaderForTheSenderIsRead(): void
    {
        $key    = new Gpg(self::SENDER);
        $parsed = Autocrypt::parse(sprintf("addr=%s; prefer-encrypt=mutual; keydata=%s", strtoupper(self::SENDER), chunk_split(base64_encode($key->binary), 76, ' ')));

        self::assertSame(self::SENDER, $parsed['addr'] ?? null);
        self::assertSame($key->binary, $parsed['keydata'] ?? null);
    }

    public function testAnAutocryptHeaderNamingSomebodyElseIsIgnored(): void
    {
        $header = 'addr=victim@collect.test; keydata=' . base64_encode('x');

        self::assertNull(Autocrypt::keyFor(['autocrypt' => $header], self::SENDER));
    }

    public function testAnAutocryptHeaderWithAnUnknownCriticalAttributeIsIgnored(): void
    {
        self::assertNull(Autocrypt::parse('addr=' . self::SENDER . '; future=1; keydata=' . base64_encode('x')));
        self::assertNotNull(Autocrypt::parse('addr=' . self::SENDER . '; _future=1; keydata=' . base64_encode('x')));
    }

    public function testTwoAutocryptHeadersForTheSenderAreBothIgnored(): void
    {
        $header = 'addr=' . self::SENDER . '; keydata=' . base64_encode('x');

        self::assertNull(Autocrypt::keyFor(['autocrypt' => [$header, $header]], self::SENDER));
    }

    public function testTheSendersAutocryptKeyIsCollected(): void
    {
        $key     = new Gpg(self::SENDER);
        $message = $this->message(['autocrypt' => 'addr=' . self::SENDER . '; keydata=' . base64_encode($key->binary)]);

        self::assertSame([ContactKeyOutcome::Added], $this->collector()->collect($message));

        $row = $this->contactKeys()->findCurrent($this->account->usr, self::SENDER);
        self::assertSame($key->fingerprint, $row?->fingerprint);
        self::assertSame(ContactKeyOrigin::Autocrypt, $row->origin);
    }

    // ── Attached keys ─────────────────────────────────────────────────────────

    public function testAKeyAttachedToTheMessageIsCollectedFromTheRawSource(): void
    {
        $key     = new Gpg(self::SENDER);
        $message = $this->messageWithAttachment($key->armored);

        self::assertTrue(ContactKeyCollector::mayCarryKeys($message));
        self::assertSame([ContactKeyOutcome::Added], $this->collector()->collect($message));
        self::assertSame(ContactKeyOrigin::Attached, $this->contactKeys()->findCurrent($this->account->usr, self::SENDER)?->origin);
    }

    /** A forwarded colleague's key is the colleague's to vouch for, not the sender's. */
    public function testAnAttachedKeyForSomebodyElseIsNotKept(): void
    {
        $key     = new Gpg('colleague@collect.test');
        $message = $this->messageWithAttachment($key->armored);

        self::assertSame([ContactKeyOutcome::Unusable], $this->collector()->collect($message));
        self::assertSame([], $this->contactKeys()->findForUser($this->account->usr));
    }

    public function testMailThePersonSentThemselvesBringsNoContactKey(): void
    {
        $key     = new Gpg($this->account->username);
        $message = $this->message(['autocrypt' => 'addr=' . $this->account->username . '; keydata=' . base64_encode($key->binary)]);
        $message->fromAddress = $this->account->username;

        self::assertSame([], $this->collector()->collect($message));
    }

    // ── The sender's key, not an attachment (phase 6g) ───────────────────────

    /**
     * The sender's own key — a user ID for From — is marked, and with it the
     * message no longer counts as carrying an attachment, nor its thread
     * (#28, comment 6216816288).
     */
    public function testTheSendersAttachedKeyIsMarkedAndNoLongerAnAttachment(): void
    {
        $key     = new Gpg(self::SENDER);
        $message = $this->messageWithAttachment($key->armored);

        $this->collector()->collect($message);

        self::assertSame($key->fingerprint, $message->messageParts->first()->senderKeyFingerprint);
        self::assertFalse($message->messageParts->first()->isListed());
        self::assertFalse($message->hasAttachments);
        self::assertSame(0, $message->thread->attachmentCount);
    }

    /** A forwarded colleague's key stays a normal attachment. */
    public function testAColleaguesAttachedKeyStaysAnAttachment(): void
    {
        $message = $this->messageWithAttachment(new Gpg('colleague@collect.test')->armored);

        $this->collector()->collect($message);

        self::assertNull($message->messageParts->first()->senderKeyFingerprint);
        self::assertTrue($message->messageParts->first()->keyChecked);
        self::assertTrue($message->messageParts->first()->isListed());
        self::assertTrue($message->hasAttachments);
    }

    /**
     * A key plMail already holds for the sender counts as theirs even without
     * a user ID for the address — one they sent before, say, with only their
     * other address on it.
     */
    public function testAKeyPlMailHoldsForTheSenderIsTheirsWithoutAUserIdForTheAddress(): void
    {
        $key = new Gpg('other-address@collect.test');
        $this->em->persist(new ContactKey($this->account->usr, self::SENDER, $key->fingerprint, $key->armored, ContactKeyOrigin::Manual, ContactKeyStatus::Current, null, new DateTimeImmutable()));
        $this->em->flush();

        $message = $this->messageWithAttachment($key->armored);
        $this->collector()->markAttachedKeys($message);

        self::assertSame($key->fingerprint, $message->messageParts->first()->senderKeyFingerprint);
    }

    /** Beside a real attachment, the message still has one. */
    public function testAnotherAttachmentKeepsTheMessageCarryingOne(): void
    {
        $key     = new Gpg(self::SENDER);
        $message = $this->messageWithAttachment($key->armored, withPdf: true);

        $this->collector()->collect($message);

        self::assertTrue($message->hasAttachments);
        self::assertSame(1, $message->thread->attachmentCount);
        self::assertSame(['report.pdf'], array_values(array_map(static fn (MessagePart $part): ?string => $part->filename, array_filter($message->messageParts->toArray(), static fn (MessagePart $part): bool => $part->isListed()))));
    }

    /**
     * The person's own Sent mail, where "Attach your public key" put their
     * key: marked as theirs, and nothing recorded as a contact key.
     */
    public function testThePersonsOwnKeyOnTheirSentMailIsMarked(): void
    {
        $own     = new Gpg($this->account->username);
        $userKey = new UserKey($this->account->usr, 'v1.' . base64_encode(random_bytes(12)) . '.' . base64_encode(random_bytes(48)));
        $this->em->persist($userKey);
        $addressKey = new AddressKey($this->account->usr, $userKey, $own->fingerprint, $own->armored, "-----BEGIN PGP PRIVATE KEY BLOCK-----\nx\n-----END PGP PRIVATE KEY BLOCK-----", 'token', AddressKeyOrigin::Generated, null, null);
        $addressKey->bind($this->account->username, true);
        $this->em->persist($addressKey);
        $this->em->flush();

        $message = $this->messageWithAttachment($own->armored, from: $this->account->username);

        self::assertSame([], $this->collector()->collect($message));
        self::assertSame($own->fingerprint, $message->messageParts->first()->senderKeyFingerprint);
        self::assertFalse($message->hasAttachments);
        self::assertSame([], $this->contactKeys()->findForUser($this->account->usr));
    }

        public function testAPlainMessageIsNotWorthAJob(): void
    {
        self::assertFalse(ContactKeyCollector::mayCarryKeys($this->message([])));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, string|list<string>> $headers
     */
    private function message(array $headers): Message
    {
        $message = MailFixtures::message($this->em, $this->account);
        $message->fromAddress = self::SENDER;
        $message->headers     = $headers;
        $this->em->flush();

        return $message;
    }

    private function messageWithAttachment(string $armored, bool $withPdf = false, string $from = self::SENDER): Message
    {
        $message = $this->message([]);
        $message->fromAddress = $from;

        $part              = new MessagePart();
        $part->contentType = 'application/pgp-keys';
        $part->filename    = 'key.asc';
        $part->disposition = 'attachment';
        $message->addMessagePart($part);
        $this->em->persist($part);

        if (true === $withPdf) {
            $pdf              = new MessagePart();
            $pdf->contentType = 'application/pdf';
            $pdf->filename    = 'report.pdf';
            $pdf->disposition = 'attachment';
            $message->addMessagePart($pdf);
            $this->em->persist($pdf);
        }

        // As the syncer and the threader leave it: an attachment, counted.
        $message->hasAttachments = true;
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'My key';
        $thread->normalizedSubject = 'my key';
        $thread->lastMessageAt     = new DateTimeImmutable();
        $thread->threadingMethod   = ThreadingMethod::References;
        $this->em->persist($thread);
        $thread->addMessage($message);
        $thread->attachmentCount = 1;

        $raw = 'From: ' . $from . "\r\nSubject: My key\r\nMIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"M\"\r\n\r\n"
            . "--M\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nHere is my key.\r\n"
            . "--M\r\nContent-Type: application/pgp-keys; name=\"key.asc\"\r\nContent-Disposition: attachment; filename=\"key.asc\"\r\n\r\n"
            . str_replace("\n", "\r\n", trim($armored)) . "\r\n"
            . ($withPdf ? "--M\r\nContent-Type: application/pdf; name=\"report.pdf\"\r\nContent-Disposition: attachment; filename=\"report.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\nJVBERi0xLjQK\r\n" : '')
            . "--M--\r\n";

        static::getContainer()->get(RawMessageResolver::class)->store($message, $raw);
        $this->em->flush();

        return $message;
    }

    private function collector(): ContactKeyCollector
    {
        return static::getContainer()->get(ContactKeyCollector::class);
    }

    private function contactKeys(): ContactKeyRepository
    {
        return static::getContainer()->get(ContactKeyRepository::class);
    }
}
