<?php

declare(strict_types=1);

namespace App\Tests\Controller\Crypto;

use App\Domain\Enum\Crypto\ContactKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyStatus;
use App\Domain\Enum\Crypto\MessageCrypto;
use App\Domain\Enum\Crypto\SignatureState;
use App\Entity\Crypto\ContactKey;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Crypto\ContactKeyRepository;
use App\Service\Mail\RawMessageResolver;
use App\Tests\Command\MailFixtures;
use App\Tests\Support\Crypto\Gpg;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Contacts' keys are one person's, and every write proves it came from their
 * own page.
 *
 * The rows hold public keys, so what is at stake is not secrecy but whom a
 * person believes: a cross-site POST that pins a key, or a request that
 * reaches another person's row, would change which signatures somebody's
 * mail calls valid. Each endpoint is held to the CSRF token of its own
 * action and to rows of the signed-in person only.
 */
final class ContactKeyControllerTest extends WebTestCase
{
    private const string CONTACT = 'contact@keys.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->user    = MailFixtures::user($this->em, 'contact-keys');
        $this->account = MailFixtures::account($this->em, $this->user);
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testTheListShowsOnlyThePersonsOwnKeys(): void
    {
        $mine   = $this->seedKey($this->user, 'a');
        $theirs = $this->seedKey(MailFixtures::user($this->em, 'other'), 'b');

        $crawler = $this->client->request('GET', '/contact-keys');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#contact-keys'));
        self::assertStringContainsString($this->grouped($mine->fingerprint), $crawler->text());
        self::assertStringNotContainsString($this->grouped($theirs->fingerprint), $crawler->text());
    }

    public function testAnImportedKeyIsKeptAndShown(): void
    {
        $key = new Gpg(self::CONTACT);

        $this->client->request('POST', '/contact-keys/import', ['_token' => $this->token('contact-keys'), 'key' => $key->armored]);

        self::assertResponseRedirects('/contact-keys');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString($this->grouped($key->fingerprint), $crawler->text());
        self::assertSame(ContactKeyOrigin::Manual, $this->repository()->findCurrent($this->user, self::CONTACT)?->origin);
    }

    /**
     * The file half of the import form, which the tests above never reached:
     * ContactKeyController::import() reads an uploaded `file` in place of the
     * textarea when there is one (#28, comment 6216185683).
     */
    public function testAnUploadedKeyFileIsKeptAndShown(): void
    {
        $key  = new Gpg(self::CONTACT);
        $path = tempnam(sys_get_temp_dir(), 'contact-key');
        file_put_contents($path, $key->armored);

        $this->client->request(
            'POST',
            '/contact-keys/import',
            ['_token' => $this->token('contact-keys'), 'key' => ''],
            ['file' => new UploadedFile($path, 'OpenPGP_0x' . strtoupper(substr($key->fingerprint, -16)) . '.asc', 'application/pgp-keys', null, true)],
        );

        self::assertResponseRedirects('/contact-keys');
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString($this->grouped($key->fingerprint), $crawler->text());
        self::assertSame(ContactKeyOrigin::Manual, $this->repository()->findCurrent($this->user, self::CONTACT)?->origin);
    }

    /** A file wins over whatever the textarea held, as the form says. */
    public function testAnUploadedFileIsTakenInsteadOfThePastedText(): void
    {
        $uploaded = new Gpg(self::CONTACT);
        $pasted   = new Gpg('somebody-else@keys.test');
        $path     = tempnam(sys_get_temp_dir(), 'contact-key');
        file_put_contents($path, $uploaded->armored);

        $this->client->request(
            'POST',
            '/contact-keys/import',
            ['_token' => $this->token('contact-keys'), 'key' => $pasted->armored],
            ['file' => new UploadedFile($path, 'key.asc', 'application/pgp-keys', null, true)],
        );

        self::assertNotNull($this->repository()->findCurrent($this->user, self::CONTACT));
        self::assertNull($this->repository()->findCurrent($this->user, 'somebody-else@keys.test'));
    }

        public function testAnImportWithoutTheTokenIsRefusedAndKeepsNothing(): void
    {
        $this->client->request('POST', '/contact-keys/import', ['key' => new Gpg(self::CONTACT)->armored]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->repository()->findForUser($this->user));
    }

    public function testPinningNeedsTheTokenOfThatKey(): void
    {
        $key   = $this->seedKey($this->user, 'a');
        $other = $this->seedKey($this->user, 'b', 'other@keys.test');

        // A token minted for another row of the same person does not do.
        $this->client->request('POST', sprintf('/contact-keys/%d/pin', $key->id), ['_token' => $this->token('contact-key-' . $other->id)]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', sprintf('/contact-keys/%d/pin', $key->id), ['_token' => $this->token('contact-key-' . $key->id)]);
        self::assertResponseRedirects('/contact-keys');

        self::assertTrue($this->fresh(ContactKey::class, $key->id)?->pinned);
    }

    /** Another person's row is not there, as far as this person is concerned. */
    public function testAnotherPersonsKeyCannotBePinnedOrRemoved(): void
    {
        $theirs = $this->seedKey(MailFixtures::user($this->em, 'other'), 'b');

        $this->client->request('POST', sprintf('/contact-keys/%d/pin', $theirs->id), ['_token' => $this->token('contact-key-' . $theirs->id)]);
        self::assertResponseStatusCodeSame(404);

        $this->client->request('POST', sprintf('/contact-keys/%d/remove', $theirs->id), ['_token' => $this->token('contact-key-' . $theirs->id)]);
        self::assertResponseStatusCodeSame(404);

        self::assertFalse($this->fresh(ContactKey::class, $theirs->id)?->pinned);
    }

    public function testTheSenderKeysAnswerHoldsOnlyThePersonsOwnRows(): void
    {
        $this->seedKey(MailFixtures::user($this->em, 'other'), 'b');
        $mine = $this->seedKey($this->user, 'a');

        $this->client->request('GET', '/contact-keys/sender', ['email' => strtoupper(self::CONTACT)]);

        self::assertResponseIsSuccessful();
        $keys = json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame([$mine->fingerprint], array_column($keys, 'fingerprint'));
    }

    /**
     * Trust from the banner: the key that made an Untrusted signature is
     * pinned, the message checked again on the spot, and the banner says so.
     */
    public function testTrustingFromTheBannerPinsTheKeyAndTheBannerTurnsValid(): void
    {
        $key     = new Gpg(self::CONTACT);
        $message = $this->signedMessage($key);
        $row     = new ContactKey($this->user, self::CONTACT, $key->fingerprint, $key->armored, ContactKeyOrigin::Autocrypt, ContactKeyStatus::Current, null, new DateTimeImmutable());
        $this->em->persist($row);
        $message->signatureState = SignatureState::Untrusted;
        $message->signer         = $key->fingerprint;
        $this->em->flush();

        $this->client->request('POST', sprintf('/contact-keys/message/%d/trust', $message->id), ['_token' => $this->token('message-key-' . $message->id)], server: [
            'HTTP_ACCEPT' => 'text/vnd.turbo-stream.html, text/html',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('crypto-banner-' . $message->id, (string) $this->client->getResponse()->getContent());

        self::assertTrue($this->fresh(ContactKey::class, $row->id)?->pinned);
        self::assertSame(SignatureState::Valid, $this->fresh(\App\Entity\Mail\Message::class, $message->id)?->signatureState);
    }

    public function testAnotherPersonsMessageCannotBeActedOnFromABanner(): void
    {
        $stranger = MailFixtures::account($this->em, MailFixtures::user($this->em, 'stranger'));
        $message  = MailFixtures::message($this->em, $stranger);

        $this->client->request('POST', sprintf('/contact-keys/message/%d/trust', $message->id), ['_token' => $this->token('message-key-' . $message->id)]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testABannerActionWithoutTheTokenIsRefused(): void
    {
        $message = MailFixtures::message($this->em, $this->account);

        $this->client->request('POST', sprintf('/contact-keys/message/%d/lookup', $message->id));

        self::assertResponseStatusCodeSame(403);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function token(string $id): string
    {
        // Minted in the browser's session, pushed back onto the request stack
        // the way AdminUserManagementTest does it: tokens are per session and
        // read off the current request, and between requests there is none.
        $this->client->request('GET', '/contact-keys');

        $stack   = static::getContainer()->get('request_stack');
        $carrier = new Request();
        $carrier->setSession($this->client->getRequest()->getSession());
        $stack->push($carrier);

        try {
            return (string) static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
        } finally {
            $stack->pop();
        }
    }

    private function seedKey(User $user, string $seed, string $email = self::CONTACT): ContactKey
    {
        $key = new ContactKey($user, $email, str_repeat($seed, 40), "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\nx\n-----END PGP PUBLIC KEY BLOCK-----", ContactKeyOrigin::Manual, ContactKeyStatus::Current, null, new DateTimeImmutable());
        $this->em->persist($key);
        $this->em->flush();

        return $key;
    }

    private function signedMessage(Gpg $key): \App\Entity\Mail\Message
    {
        $message = MailFixtures::message($this->em, $this->account);
        $message->fromAddress = self::CONTACT;
        $message->crypto      = MessageCrypto::PgpSigned;
        static::getContainer()->get(RawMessageResolver::class)->store($message, $key->signedMessage('Hello.'));
        $this->em->flush();

        return $message;
    }

    /**
     * The row as the database now holds it. Each request resets the entity
     * manager, so what a test holds afterwards is detached.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function fresh(string $class, ?int $id): ?object
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->find($class, $id);
    }

    private function grouped(string $fingerprint): string
    {
        return implode(' ', str_split(strtoupper($fingerprint), 4));
    }

    private function repository(): ContactKeyRepository
    {
        return static::getContainer()->get(ContactKeyRepository::class);
    }
}
