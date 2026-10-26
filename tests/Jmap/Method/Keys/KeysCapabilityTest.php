<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Keys;

use App\Command\Test\SeedKeyServerCommand;
use App\Domain\Enum\Crypto\AddressKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyStatus;
use App\Domain\Enum\Crypto\MessageCrypto;
use App\Domain\Enum\Crypto\PlaintextSource;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Helper\SealedMessageStorage;
use App\Domain\Helper\UploadStorage;
use App\Domain\Interface\MailSenderInterface;
use App\Entity\Crypto\AddressKey;
use App\Entity\Crypto\ContactCertificate;
use App\Entity\Crypto\ContactKey;
use App\Entity\Crypto\UserKey;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\UploadedBlob;
use App\Jmap\Blob\BlobId;
use App\Jmap\Mapper\EmailMapper;
use App\Jmap\Method\JmapMethod;
use App\Jmap\Method\Keys\ContactKeyGetMethod;
use App\Jmap\Method\Keys\ContactKeyLookupMethod;
use App\Jmap\Method\Keys\ContactKeySmimeSignerMethod;
use App\Jmap\Method\Keys\DecryptedMailPendingMethod;
use App\Jmap\Method\Keys\DecryptedMailStoreMethod;
use App\Jmap\Method\Keys\KeyringGetMethod;
use App\Jmap\Method\Mail\EmailImportMethod;
use App\Jmap\Protocol\Capability;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Session\SessionBuilder;
use App\Service\Imap\MessageSendService;
use App\Service\Mail\MailSenderRegistry;
use App\Tests\Jmap\JmapTestCase;
use App\Tests\Support\Crypto\Gpg;
use App\Tests\Support\Crypto\Smime;
use DateTimeImmutable;
use Symfony\Component\Mime\Email;

/**
 * What a native client gets under `urn:plmail:params:jmap:keys` (issue #28,
 * phase 6): the keyring as the browser keeps it, contacts' keys and the
 * composer's lookup, the queue of mail to decrypt and the hand-over, the
 * encryption fields on Email — and Email/import, through which a message the
 * client built and encrypted itself is sent byte for byte.
 */
final class KeysCapabilityTest extends JmapTestCase
{
    protected function tearDown(): void
    {
        Smime::untrust();

        parent::tearDown();
    }

    public function testTheSessionAdvertisesTheCapabilityWithItsConstants(): void
    {
        $session = self::getContainer()->get(SessionBuilder::class)->build($this->user);

        self::assertSame('plmail/user-key', $session['capabilities'][Capability::KEYS]['purposes']['userKey']);
        self::assertSame('plmail/pairing-transfer', $session['capabilities'][Capability::KEYS]['purposes']['pairingTransfer']);
        self::assertSame('plmail/smime-key', $session['capabilities'][Capability::KEYS]['purposes']['smimeKey']);
        self::assertFalse($session['accounts'][$this->accountId()]['accountCapabilities'][Capability::KEYS]['keepsDecryptedMail']);
        self::assertContains(Capability::KEYS, Capability::SUPPORTED);
    }

    public function testKeyringGetHandsOverTheWrappedKeyringWithoutTheRecoveryCopies(): void
    {
        $this->ownKey();

        $result = $this->method(KeyringGetMethod::class)->handle([], $this->context());

        self::assertSame('singleton', $result['list'][0]['id']);
        self::assertArrayNotHasKey('copies', $result['list'][0]);
        self::assertCount(1, $result['list'][0]['addressKeys']);
        self::assertStringStartsWith('v1.', $result['list'][0]['userKeys'][0]['wrapped']);
        // S/MIME certificates ride along, their keys wrapped under the user
        // key (`plmail/smime-key`); none here.
        self::assertSame([], $result['list'][0]['smimeCertificates']);
        // Which addresses plMail's Web Key Directory publishes; none, since
        // example.test is not a domain the test installation serves.
        self::assertSame([], $result['list'][0]['wkd']);

        $this->expectException(MethodException::class);
        $this->method(KeyringGetMethod::class)->handle(['accountId' => $this->accountId()], $this->context());
    }

    /**
     * The app has to know which secret to ask for (phase 6e): the password,
     * or — on an account single sign-on made — the encryption passphrase,
     * with the salt and count to stretch it with.
     */
    public function testKeyringGetNamesTheRootAndHowToStretchIt(): void
    {
        $keyring = $this->method(KeyringGetMethod::class)->handle([], $this->context())['list'][0];

        self::assertSame('password', $keyring['root']);
        self::assertSame($keyring['kdf'], $keyring['rootKdf']);

        $salt = base64_encode(random_bytes(16));
        $this->user->password = '';
        $this->user->usePassphraseRoot($salt, 700000);
        $this->em->flush();

        $keyring = $this->method(KeyringGetMethod::class)->handle([], $this->context())['list'][0];

        self::assertSame('passphrase', $keyring['root']);
        self::assertSame(['salt' => $salt, 'iterations' => 700000], $keyring['rootKdf']);
    }

    public function testContactKeysAreListedAndLookedUp(): void
    {
        $contact = new Gpg('friend@example.test');
        $this->em->persist(new ContactKey($this->user, 'friend@example.test', $contact->fingerprint, $contact->armored, ContactKeyOrigin::Manual, ContactKeyStatus::Current, null, new DateTimeImmutable()));
        $this->em->flush();

        $list = $this->method(ContactKeyGetMethod::class)->handle([], $this->context())['list'];

        self::assertSame(['friend@example.test'], array_column($list, 'email'));
        self::assertSame('current', $list[0]['status']);

        $lookup = $this->method(ContactKeyLookupMethod::class)->handle(['emails' => ['Friend@Example.test', 'stranger@example.test']], $this->context());

        self::assertSame($contact->fingerprint, $lookup['recipients'][0]['key']['fingerprint']);
        self::assertNull($lookup['recipients'][1]['key']);
    }

    /**
     * The lookup a native composer makes finds a key only a key server has —
     * the test environment's stand-in for it (KeyServerStub) — and both
     * methods say where it came from. `.test` resolves nowhere, so WKD, which
     * is asked first, finds nothing.
     */
    public function testContactKeyLookupFindsAKeyOnAKeyServerAndSaysWhich(): void
    {
        $address = sprintf('jo-%s@keyserver-only.test', bin2hex(random_bytes(6)));
        $key     = new Gpg($address);
        $path    = SeedKeyServerCommand::path(self::getContainer()->getParameter('kernel.project_dir') . '/var/test-key-servers', 'keys.openpgp.org', $address);

        if (false === is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }

        file_put_contents($path, $key->armored);

        try {
            $lookup = $this->method(ContactKeyLookupMethod::class)->handle(['emails' => [$address], 'lookup' => true], $this->context());
        } finally {
            unlink($path);
        }

        self::assertSame($key->fingerprint, $lookup['recipients'][0]['key']['fingerprint']);
        self::assertSame('keyserver', $lookup['recipients'][0]['key']['origin']);
        self::assertSame('keys.openpgp.org', $lookup['recipients'][0]['key']['originHost']);

        $list = $this->method(ContactKeyGetMethod::class)->handle([], $this->context())['list'];

        self::assertSame(['keyserver'], array_column($list, 'origin'));
        self::assertSame(['keys.openpgp.org'], array_column($list, 'originHost'));
    }

    /**
     * The address that was reported as not found (#28, comment 6216185683):
     * no WKD — its hosts resolve to nothing on the test environment's
     * stand-in — and a confirmed key on keys.openpgp.org, which a native
     * client's lookup finds without asking for key servers by name.
     */
    public function testContactKeyLookupFindsTheReportedAddressOnKeysOpenpgpOrg(): void
    {
        $address = 'test@pluetzner.de';
        $key     = new Gpg($address);
        $path    = SeedKeyServerCommand::path(self::getContainer()->getParameter('kernel.project_dir') . '/var/test-key-servers', 'keys.openpgp.org', $address);

        SeedKeyServerCommand::forgetLookups(self::getContainer()->get('cache.app'), $address);

        if (false === is_dir(dirname($path))) {
            mkdir(dirname($path), 0o777, true);
        }

        file_put_contents($path, $key->armored);

        try {
            $lookup = $this->method(ContactKeyLookupMethod::class)->handle(['emails' => [$address], 'lookup' => true], $this->context());
        } finally {
            unlink($path);
        }

        self::assertSame($key->fingerprint, $lookup['recipients'][0]['key']['fingerprint']);
        self::assertSame('keys.openpgp.org', $lookup['recipients'][0]['key']['originHost']);
    }

    public function testEncryptedMailWaitsForItsDecryptedTextOnlyForAnAccountThatKeepsIt(): void
    {
        $message = $this->receivedMessage();
        $message->crypto   = MessageCrypto::PgpEncrypted;
        $message->bodyText = '';
        $this->em->flush();

        $pending = $this->method(DecryptedMailPendingMethod::class);

        self::assertSame([], $pending->handle(['accountId' => $this->accountId()], $this->context())['ids']);

        $this->account->keepDecrypted = true;
        $this->em->flush();

        self::assertSame([(string) $message->id], $pending->handle(['accountId' => $this->accountId()], $this->context())['ids']);

        $result = $this->method(DecryptedMailStoreMethod::class)->handle([
            'accountId' => $this->accountId(),
            'emails'    => [(string) $message->id => $this->upload("Content-Type: text/plain; charset=utf-8\r\n\r\nThe kiwi is ripe.")],
        ], $this->context());

        self::assertSame([(string) $message->id], $result['stored']);
        self::assertStringContainsString('kiwi', (string) $this->em->find(Message::class, $message->id)->bodyText);
        self::assertSame([], $pending->handle(['accountId' => $this->accountId()], $this->context())['ids']);
    }

    public function testEmailCarriesWhatItIsWrappedIn(): void
    {
        $message = $this->receivedMessage();
        $message->crypto          = MessageCrypto::PgpEncrypted;
        $message->plaintextSource = PlaintextSource::Client;

        $email = self::getContainer()->get(EmailMapper::class)->toJmap($message, ['cryptoType', 'encrypted', 'decryptedOnServer']);

        self::assertSame(['id' => (string) $message->id, 'cryptoType' => 'pgp_encrypted', 'encrypted' => true, 'decryptedOnServer' => true], $email);
    }

    public function testAnImportedMessageBecomesAnEmailInTheNamedMailbox(): void
    {
        $result = $this->import([
            'blobId'     => $this->upload("From: Ada <ada@example.test>\r\nTo: {$this->account->email}\r\nSubject: Imported\r\nMessage-ID: <imp-1@example.test>\r\nDate: Mon, 12 Oct 2026 09:00:00 +0000\r\n\r\nHello from an archive."),
            'mailboxIds' => [$this->mailboxIdFor(LabelRole::Inbox) => true],
            'keywords'   => ['$seen' => true],
        ]);

        $message = $this->em->find(Message::class, (int) $result['created']['a']['id']);

        self::assertSame('Imported', $message->subject);
        self::assertSame('ada@example.test', $message->fromAddress);
        self::assertStringContainsString('archive', (string) $message->bodyText);
        self::assertContains(LabelRole::Inbox->value, $this->rolesOn($message));
        self::assertNotNull($message->rawPath);
    }

    /**
     * A Date header with an offset keeps its instant through the database.
     *
     * The columns have no zone, so a value is stored as the wall time of the
     * zone it carries. "14:53 +0100" kept as it stands was read back as 14:53
     * UTC — an hour in the future in Berlin, two in summer — which is how a
     * mail sent from the phone came to sit above the answer to it. The same
     * for a `receivedAt` the client gives with an offset.
     */
    public function testAnImportedDateWithAnOffsetKeepsItsInstant(): void
    {
        $result = $this->import([
            'blobId'     => $this->upload("From: Ada <ada@example.test>\r\nTo: {$this->account->email}\r\nSubject: Offset\r\nMessage-ID: <imp-offset@example.test>\r\nDate: Sun, 25 Oct 2026 14:53:00 +0100\r\n\r\nSent in Berlin."),
            'mailboxIds' => [$this->mailboxIdFor(LabelRole::Inbox) => true],
        ]);

        $given = $this->import([
            'blobId'     => $this->upload("From: Ada <ada@example.test>\r\nTo: {$this->account->email}\r\nSubject: Offset given\r\nMessage-ID: <imp-offset-2@example.test>\r\nDate: Sun, 25 Oct 2026 14:53:00 +0100\r\n\r\nSent in Berlin."),
            'mailboxIds' => [$this->mailboxIdFor(LabelRole::Inbox) => true],
            'receivedAt' => '2026-10-25T14:54:00+01:00',
        ]);

        $this->em->clear();

        $message = $this->em->find(Message::class, (int) $result['created']['a']['id']);
        self::assertSame('2026-10-25 13:53:00', $message->sentAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-25 13:53:00', $message->receivedAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));

        $message = $this->em->find(Message::class, (int) $given['created']['a']['id']);
        self::assertSame('2026-10-25 13:54:00', $message->receivedAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    /**
     * A message from an archive whose Date line is there three times is
     * imported, not refused as "not an RFC 5322 message" (#357).
     */
    public function testAnImportedMessageWithARepeatedDateIsTaken(): void
    {
        $dates  = "Date: Mon, 12 Oct 2026 09:00:00 +0000\r\nDate: Mon, 12 Oct 2026 10:00:00 +0000\r\nDate: Mon, 12 Oct 2026 11:00:00 +0000\r\n";
        $result = $this->import([
            'blobId'     => $this->upload("From: Ada <ada@example.test>\r\nTo: {$this->account->email}\r\nSubject: Dated thrice\r\nMessage-ID: <imp-3@example.test>\r\n{$dates}\r\nHello from an archive."),
            'mailboxIds' => [$this->mailboxIdFor(LabelRole::Inbox) => true],
        ]);

        self::assertArrayHasKey('a', $result['created'] ?? [], json_encode($result['notCreated'] ?? []) ?: '');

        $message = $this->em->find(Message::class, (int) $result['created']['a']['id']);

        self::assertSame('Dated thrice', $message->subject);
        self::assertSame('2026-10-12 09:00:00', $message->sentAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'));
    }

    public function testAnImportedDraftIsSentByteForByte(): void
    {
        $body   = "Content-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: 7bit\r\n\r\nBuilt on a phone, not here.\r\n";
        $result = $this->import([
            'blobId'     => $this->upload("From: {$this->account->email}\r\nTo: friend@example.test\r\nSubject: From the app\r\nMIME-Version: 1.0\r\n{$body}"),
            'mailboxIds' => [$this->mailboxIdFor(LabelRole::Drafts) => true],
            'keywords'   => ['$draft' => true],
        ]);

        $message = $this->em->find(Message::class, (int) $result['created']['a']['id']);

        self::assertNull($message->sentAt, 'a draft has not been sent');
        self::assertSame('plain', $message->outgoingSeal['kind'] ?? null);
        self::assertSame($body, self::getContainer()->get(SealedMessageStorage::class)->read((int) $message->id, SealedMessageStorage::OUTGOING));

        $recorder = $this->recorder();
        self::getContainer()->set(MailSenderRegistry::class, new MailSenderRegistry([$recorder]));
        self::assertTrue(self::getContainer()->get(MessageSendService::class)->send($message));

        self::assertStringContainsString($body, $recorder->sent[0]->toString());
    }

    public function testAnEncryptedDraftMustNameTheRecipientsCurrentKeys(): void
    {
        $friend = new Gpg('friend@example.test');
        $this->em->persist(new ContactKey($this->user, 'friend@example.test', $friend->fingerprint, $friend->armored, ContactKeyOrigin::Manual, ContactKeyStatus::Current, null, new DateTimeImmutable()));
        $this->em->flush();

        $raw = "From: {$this->account->email}\r\nTo: friend@example.test\r\nSubject: Sealed\r\nMIME-Version: 1.0\r\n"
            . "Content-Type: multipart/encrypted; protocol=\"application/pgp-encrypted\"; boundary=\"b\"\r\n\r\n"
            . "--b\r\nContent-Type: application/pgp-encrypted\r\n\r\nVersion: 1\r\n"
            . "--b\r\nContent-Type: application/octet-stream\r\n\r\n-----BEGIN PGP MESSAGE-----\r\n\r\nwV4D\r\n-----END PGP MESSAGE-----\r\n--b--\r\n";
        $draft = ['mailboxIds' => [$this->mailboxIdFor(LabelRole::Drafts) => true], 'keywords' => ['$draft' => true]];

        $refused = $this->import(['blobId' => $this->upload($raw)] + $draft);

        self::assertSame('forbidden', $refused['notCreated']['a']['type']);

        $taken = $this->import(['blobId' => $this->upload($raw), 'fingerprints' => [$friend->fingerprint]] + $draft);
        $message = $this->em->find(Message::class, (int) $taken['created']['a']['id']);

        self::assertSame(MessageCrypto::PgpEncrypted, $message->crypto);
        self::assertSame('', (string) $message->bodyText, 'an encrypted message keeps no body');
        self::assertSame('encrypted', $message->outgoingSeal['kind'] ?? null);
    }

    public function testAnUnknownBlobIsNotImported(): void
    {
        $result = $this->import(['blobId' => 'u-999999999', 'mailboxIds' => [$this->mailboxIdFor(LabelRole::Inbox) => true]]);

        self::assertSame('blobNotFound', $result['notCreated']['a']['type']);
    }

    /**
     * The signer of a signature inside S/MIME-encrypted mail, judged by the
     * server and kept for the sender (#320) — what the web's smime-signer
     * route does, for a client an app password signs in.
     */
    public function testTheSignerInsideSmimeEncryptedMailIsJudgedAndKeptForTheSender(): void
    {
        $authority = Smime::authority();
        $authority->trustAuthority();
        $sender  = Smime::issue('smime-sender@example.test', $authority);
        $message = $this->smimeEncryptedFrom('smime-sender@example.test');

        $answer = $this->smimeSigner($message, $sender->certificate);

        self::assertSame('valid', $answer['state']);
        self::assertSame($sender->fingerprint, $answer['signer']);
        self::assertSame((string) $message->id, $answer['emailId']);
        self::assertCount(1, $this->contactCertificates(), 'kept for the sender, so a reply can be encrypted');
    }

    /** Nobody vouches for it: untrusted, and a different one after it has changed. */
    public function testAnUnvouchedForSignerIsUntrustedAndADifferentOneHasChanged(): void
    {
        $message = $this->smimeEncryptedFrom('smime-sender@example.test');

        $first = Smime::issue('smime-sender@example.test');
        self::assertSame('untrusted', $this->smimeSigner($message, $first->certificate)['state']);

        $second = Smime::issue('smime-sender@example.test');
        self::assertSame('key_changed', $this->smimeSigner($message, $second->certificate)['state']);
    }

    /** The sender is the message's From, not whatever the certificate claims. */
    public function testASignerThatDoesNotNameTheSenderIsInvalidAndNotKept(): void
    {
        $message = $this->smimeEncryptedFrom('smime-sender@example.test');

        $answer = $this->smimeSigner($message, Smime::issue('somebody-else@example.test')->certificate);

        self::assertSame('invalid', $answer['state']);
        self::assertSame([], $this->contactCertificates());
    }

    public function testOnlyAnSmimeEncryptedEmailOfTheAccountIsAccepted(): void
    {
        $certificate = Smime::issue('smime-sender@example.test')->certificate;

        $plain = $this->receivedMessage();
        $plain->fromAddress = 'smime-sender@example.test';
        $this->em->flush();

        try {
            $this->smimeSigner($plain, $certificate);
            self::fail('a message that is not S/MIME-encrypted was accepted');
        } catch (MethodException $refused) {
            self::assertSame('forbidden', $refused->errorType);
        }

        try {
            $this->method(ContactKeySmimeSignerMethod::class)->handle([
                'accountId'   => $this->accountId(),
                'emailId'     => '999999999',
                'certificate' => $certificate,
            ], $this->context());
            self::fail('an Email that is not in the account was accepted');
        } catch (MethodException $refused) {
            self::assertSame('notFound', $refused->errorType);
        }

        self::assertSame([], $this->contactCertificates());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function smimeEncryptedFrom(string $from): Message
    {
        $message              = $this->receivedMessage();
        $message->crypto      = MessageCrypto::SmimeEncrypted;
        $message->fromAddress = $from;
        $this->em->flush();

        return $message;
    }

    /** @return array<string, mixed> */
    private function smimeSigner(Message $message, string $certificate): array
    {
        return $this->method(ContactKeySmimeSignerMethod::class)->handle([
            'accountId'   => $this->accountId(),
            'emailId'     => (string) $message->id,
            'certificate' => $certificate,
        ], $this->context());
    }

    /** @return list<ContactCertificate> */
    private function contactCertificates(): array
    {
        return $this->em->getRepository(ContactCertificate::class)->findBy(['usr' => $this->user]);
    }

    /**
     * @param array<string, mixed> $import
     *
     * @return array<string, mixed>
     */
    private function import(array $import): array
    {
        return $this->method(EmailImportMethod::class)->handle(['accountId' => $this->accountId(), 'emails' => ['a' => $import]], $this->context());
    }

    private function upload(string $content, ?Account $account = null): string
    {
        $account ??= $this->account;
        $blob = new UploadedBlob($account, self::getContainer()->get(UploadStorage::class)->store((int) $account->id, $content), 'message/rfc822', strlen($content));

        $this->em->persist($blob);
        $this->em->flush();

        return (string) BlobId::forUpload((int) $blob->id);
    }

    private function ownKey(): void
    {
        $key     = new Gpg((string) $this->account->email);
        $userKey = new UserKey($this->user, 'v1.' . base64_encode(random_bytes(12)) . '.' . base64_encode(random_bytes(48)));
        $address = new AddressKey($this->user, $userKey, $key->fingerprint, $key->armored, "-----BEGIN PGP PRIVATE KEY BLOCK-----\n\nx\n", 'v1.x.y', AddressKeyOrigin::Imported, null, null);

        $this->em->persist($userKey);
        $this->em->persist($address);
        $this->em->persist($address->bind((string) $this->account->email, true));
        $this->em->flush();
    }

    /**
     * @template T of JmapMethod
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function method(string $class): JmapMethod
    {
        return self::getContainer()->get($class);
    }

    /**
     * A sender that keeps what it is handed, in `sent`.
     *
     * @return MailSenderInterface&object{sent: list<Email>}
     */
    private function recorder(): MailSenderInterface
    {
        return new class () implements MailSenderInterface {
            /** @var list<Email> */
            public array $sent = [];

            public function supports(Account $account): bool
            {
                return true;
            }

            public function filesSentCopy(): bool
            {
                return true;
            }

            public function send(Email $email, Account $account): bool
            {
                $this->sent[] = $email;

                return true;
            }
        };
    }
}
