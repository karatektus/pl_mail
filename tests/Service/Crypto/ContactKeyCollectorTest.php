<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Domain\Enum\Crypto\ContactKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyOutcome;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Repository\Crypto\ContactKeyRepository;
use App\Service\Crypto\Autocrypt;
use App\Service\Crypto\ContactKeyCollector;
use App\Service\Mail\RawMessageResolver;
use App\Tests\Command\MailFixtures;
use App\Tests\Support\Crypto\Gpg;
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

    private function messageWithAttachment(string $armored): Message
    {
        $message = $this->message([]);

        $part              = new MessagePart();
        $part->contentType = 'application/pgp-keys';
        $part->filename    = 'key.asc';
        $part->disposition = 'attachment';
        $message->addMessagePart($part);
        $this->em->persist($part);

        $raw = 'From: ' . self::SENDER . "\r\nSubject: My key\r\nMIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"M\"\r\n\r\n"
            . "--M\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nHere is my key.\r\n"
            . "--M\r\nContent-Type: application/pgp-keys; name=\"key.asc\"\r\nContent-Disposition: attachment; filename=\"key.asc\"\r\n\r\n"
            . str_replace("\n", "\r\n", trim($armored)) . "\r\n"
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
