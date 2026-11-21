<?php

declare(strict_types=1);

namespace App\Tests\Controller\Crypto;

use App\Domain\Enum\Crypto\AddressKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyStatus;
use App\Domain\Enum\Crypto\RecipientPolicy;
use App\Entity\Crypto\AddressKey;
use App\Entity\Crypto\ContactKey;
use App\Entity\Crypto\UserKey;
use App\Entity\User\User;
use App\Tests\Command\MailFixtures;
use App\Tests\Support\Mail\SendsAjaxCsrf;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The composer's lock panel remembers, per address, whether mail to it is
 * encrypted (#703) — and the next composer is told so.
 *
 * The case that asked for it is the person's own Gmail address: plMail made a
 * key for it, so RecipientKeys answered `own` with `encrypt: auto` and every
 * message to it went out encrypted, unreadable in Gmail's own app. Own
 * addresses have no ContactKey to keep the choice on, so before this there was
 * nowhere to say "never" for them at all; these tests pin that the choice is
 * kept for them, read back by the same answer the composer gets, and that a
 * contact's lands on their key as Settings → Contacts' keys always kept it.
 */
final class RecipientPolicyTest extends WebTestCase
{
    use SendsAjaxCsrf;

    private const string OWN     = 'me@gmail.test';
    private const string CONTACT = 'friend@policy.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->user = MailFixtures::user($this->em, 'recipient-policy');
        MailFixtures::account($this->em, $this->user);
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testOffForAnOwnAddressIsKeptAndTheComposerIsToldSo(): void
    {
        $this->ownKey(self::OWN);

        self::assertSame('auto', $this->recipient('Me@Gmail.test')['encrypt'], 'nothing set yet');

        $answer = $this->set('Me@Gmail.test', 'never');

        self::assertResponseIsSuccessful();
        self::assertSame(['email' => self::OWN, 'encrypt' => 'never'], $answer);
        self::assertSame([self::OWN => 'never'], $this->reloadedUser()->ownAddressEncrypt);

        $recipient = $this->recipient(self::OWN);
        self::assertTrue($recipient['own']);
        self::assertSame('never', $recipient['encrypt']);
    }

    /** Auto is the absence of a choice, so going back to it keeps nothing. */
    public function testAutoForAnOwnAddressLeavesNothingBehindRatherThanStoringAuto(): void
    {
        $this->ownKey(self::OWN);
        $this->set(self::OWN, 'never');
        $this->set(self::OWN, 'auto');

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->reloadedUser()->ownAddressEncrypt);
        self::assertNull($this->reloadedUser()->getSetting(User::SETTING_CRYPTO_OWN_ENCRYPT));
        self::assertSame('auto', $this->recipient(self::OWN)['encrypt']);
    }

    public function testAContactsChoiceLandsOnTheirCurrentKey(): void
    {
        $key = $this->contactKey(self::CONTACT);

        $this->set(self::CONTACT, 'always');

        self::assertResponseIsSuccessful();
        self::assertSame(RecipientPolicy::Always, $this->reloaded($key)->encrypt);
        self::assertSame([], $this->reloadedUser()->ownAddressEncrypt, 'a contact is not one of the person\'s own addresses');
        self::assertSame('always', $this->recipient(self::CONTACT)['encrypt']);
    }

    /** Nothing to encrypt to, so nothing to remember — and no row somewhere else either. */
    public function testAnAddressWithoutAKeyIsRefusedRatherThanRemembered(): void
    {
        $this->set('stranger@policy.test', 'never');

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->reloadedUser()->ownAddressEncrypt);
    }

    public function testAnotherPersonsContactKeyIsNotTouched(): void
    {
        $theirs = $this->contactKey(self::CONTACT, MailFixtures::user($this->em, 'someone-else'));

        $this->set(self::CONTACT, 'never');

        self::assertResponseStatusCodeSame(422);
        self::assertSame(RecipientPolicy::Auto, $this->reloaded($theirs)->encrypt);
    }

    public function testWithoutTheTokenNothingIsWritten(): void
    {
        $this->ownKey(self::OWN);

        $this->client->request('POST', '/contact-keys/recipients/policy', server: ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'email'   => self::OWN,
            'encrypt' => 'never',
        ]));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->reloadedUser()->ownAddressEncrypt);
    }

    public function testAPolicyThatIsNotOneIsRefused(): void
    {
        $this->ownKey(self::OWN);

        $this->set(self::OWN, 'sometimes');

        self::assertResponseStatusCodeSame(400);
    }

    /**
     * @return array<string, mixed>
     */
    private function set(string $email, string $encrypt): array
    {
        $this->client->request('POST', '/contact-keys/recipients/policy', server: $this->ajaxCsrf($this->client) + ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'email'   => $email,
            'encrypt' => $encrypt,
        ]));

        return (array) json_decode((string) $this->client->getResponse()->getContent(), true);
    }

    /**
     * @return array<string, mixed>
     */
    private function recipient(string $email): array
    {
        $this->client->request('POST', '/contact-keys/recipients', server: $this->ajaxCsrf($this->client) + ['CONTENT_TYPE' => 'application/json'], content: (string) json_encode([
            'emails' => [$email],
            'lookup' => false,
        ]));

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR)['recipients'][0];
    }

    private function reloadedUser(): User
    {
        $this->em->clear();

        return $this->em->find(User::class, $this->user->id) ?? self::fail('the user is gone');
    }

    /** Fetched again: the request in between reset the entity manager. */
    private function reloaded(ContactKey $key): ContactKey
    {
        $this->em->clear();

        return $this->em->find(ContactKey::class, $key->id) ?? self::fail('the key is gone');
    }

    private function ownKey(string $email): void
    {
        $userKey = new UserKey($this->user, 'v1.' . base64_encode(random_bytes(12)) . '.' . base64_encode(random_bytes(48)));
        $address = new AddressKey($this->user, $userKey, str_repeat('c', 40), "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\nx\n-----END PGP PUBLIC KEY BLOCK-----", "-----BEGIN PGP PRIVATE KEY BLOCK-----\n\nx\n", 'v1.x.y', AddressKeyOrigin::Imported, null, null);

        $this->em->persist($userKey);
        $this->em->persist($address);
        $this->em->persist($address->bind($email, true));
        $this->em->flush();
    }

    private function contactKey(string $email, ?User $user = null): ContactKey
    {
        $key = new ContactKey($user ?? $this->user, $email, str_repeat('d', 40), "-----BEGIN PGP PUBLIC KEY BLOCK-----\n\nx\n-----END PGP PUBLIC KEY BLOCK-----", ContactKeyOrigin::Manual, ContactKeyStatus::Current, null, new DateTimeImmutable());
        $this->em->persist($key);
        $this->em->flush();

        return $key;
    }
}
