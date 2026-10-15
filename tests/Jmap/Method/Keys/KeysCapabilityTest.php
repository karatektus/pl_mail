<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Keys;

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

    // ── Fixtures ──────────────────────────────────────────────────────────────

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
