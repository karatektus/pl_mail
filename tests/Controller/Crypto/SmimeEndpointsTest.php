<?php

declare(strict_types=1);

namespace App\Tests\Controller\Crypto;

use App\Domain\Enum\Crypto\ContactKeyStatus;
use App\Domain\Enum\Crypto\SmimeCertificateStatus;
use App\Entity\Crypto\ContactCertificate;
use App\Entity\Crypto\SmimeCertificate;
use App\Entity\Crypto\UserKey;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Security\PasswordKdf;
use App\Tests\Command\MailFixtures;
use App\Tests\Support\Crypto\Smime;
use App\Tests\Support\Mail\SendsAjaxCsrf;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * The S/MIME endpoints: a person's own certificates on the keyring, contacts'
 * certificates in Settings → Encryption, and the reading pane's question
 * about a signature inside encrypted mail.
 *
 * The browser's half — opening the PKCS#12 file, wrapping the key — is
 * encryption-smime.spec.ts. What is pinned here is the server's: it reads the
 * certificate itself and refuses one that is not the person's to use, every
 * write needs its token, and nothing of one person's is reachable by another
 * — not a certificate to remove, not a message to ask about.
 */
final class SmimeEndpointsTest extends WebTestCase
{
    use SendsAjaxCsrf;

    private const string ADDRESS = 'smime-owner@example.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private UserKey $userKey;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        [$this->user, $this->userKey] = $this->seedUser(self::ADDRESS);
        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        Smime::untrust();

        parent::tearDown();
    }

    public function testACertificateIsStoredWithWhatTheServerReadsFromIt(): void
    {
        $certificate = Smime::issue(self::ADDRESS);
        $authority   = Smime::authority();

        $state = $this->post('/keyring/smime', $this->import($certificate, $authority->certificate));

        self::assertResponseIsSuccessful();
        self::assertCount(1, $state['smimeCertificates']);
        self::assertSame($certificate->fingerprint, $state['smimeCertificates'][0]['fingerprint']);
        self::assertSame([self::ADDRESS], $state['smimeCertificates'][0]['addresses']);
        self::assertSame($this->userKey->id, $state['smimeCertificates'][0]['userKeyId']);
        self::assertStringContainsString('BEGIN CERTIFICATE', $state['smimeCertificates'][0]['chain']);
        self::assertSame('active', $state['smimeCertificates'][0]['status']);
    }

    public function testAnImportWithoutTheKeyringTokenIsRefused(): void
    {
        $this->client->request('POST', '/keyring/smime', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($this->import(Smime::issue(self::ADDRESS)), JSON_THROW_ON_ERROR));

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->ownCertificates());
    }

    /** A certificate for somebody else's address would sign mail as them. */
    public function testACertificateForAnAddressThePersonDoesNotOwnIsRefused(): void
    {
        $this->post('/keyring/smime', $this->import(Smime::issue('somebody-else@example.test')));

        self::assertResponseStatusCodeSame(422);
        self::assertSame([], $this->ownCertificates());
    }

    public function testAKeyThatIsNotRsaOrNotWrappedIsRefused(): void
    {
        $this->post('/keyring/smime', $this->import(Smime::issue(self::ADDRESS, ec: true)));
        self::assertResponseStatusCodeSame(422);

        $this->post('/keyring/smime', ['privateKey' => 'the key in the clear'] + $this->import(Smime::issue(self::ADDRESS)));
        self::assertResponseStatusCodeSame(422);

        self::assertSame([], $this->ownCertificates());
    }

    /** The yearly renewal: the old certificate stays, to open old mail, but retires. */
    public function testANewerCertificateForTheSameAddressRetiresTheOlder(): void
    {
        $this->post('/keyring/smime', $this->import(Smime::issue(self::ADDRESS)));
        sleep(1);
        $this->post('/keyring/smime', $this->import(Smime::issue(self::ADDRESS)));

        $statuses = array_map(static fn (SmimeCertificate $certificate): SmimeCertificateStatus => $certificate->status, $this->ownCertificates());

        self::assertSame([SmimeCertificateStatus::Active, SmimeCertificateStatus::Retired], $statuses);
    }

    public function testNobodyElseCanRemoveACertificate(): void
    {
        $state = $this->post('/keyring/smime', $this->import(Smime::issue(self::ADDRESS)));
        $id    = $state['smimeCertificates'][0]['id'];

        [$other] = $this->seedUser('somebody-else@example.test');
        $this->client->loginUser($other);

        $this->post('/keyring/smime/' . $id . '/remove', []);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(1, $this->ownCertificates());

        $this->client->loginUser($this->user);
        $this->post('/keyring/smime/' . $id . '/remove', []);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->ownCertificates());
    }

    public function testTheSignerOfASignatureInsideEncryptedMailIsJudgedForTheMessagesOwnerOnly(): void
    {
        $authority = Smime::authority();
        $authority->trustAuthority();
        $sender  = Smime::issue('smime-sender@example.test', $authority);
        $message = $this->message($this->user);
        $body    = json_encode(['certificate' => $sender->certificate, 'chain' => ''], JSON_THROW_ON_ERROR);

        $this->client->request('POST', '/contact-keys/message/' . $message->id . '/smime-signer', server: ['CONTENT_TYPE' => 'application/json'], content: $body);
        self::assertResponseStatusCodeSame(403, 'without the ajax token');

        $this->client->request('POST', '/contact-keys/message/' . $message->id . '/smime-signer', server: $this->ajaxCsrf($this->client) + ['CONTENT_TYPE' => 'application/json'], content: $body);
        self::assertResponseIsSuccessful();

        $answer = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame('valid', $answer['state']);
        self::assertSame($sender->fingerprint, $answer['signer']);
        self::assertCount(1, $this->em->getRepository(ContactCertificate::class)->findBy(['usr' => $this->user]), 'kept for the sender');

        [$other]      = $this->seedUser('somebody-else@example.test');
        $otherMessage = $this->message($other);

        $this->client->request('POST', '/contact-keys/message/' . $otherMessage->id . '/smime-signer', server: $this->ajaxCsrf($this->client) + ['CONTENT_TYPE' => 'application/json'], content: $body);
        self::assertResponseStatusCodeSame(403, 'somebody else\'s message');
        self::assertSame([], $this->em->getRepository(ContactCertificate::class)->findBy(['usr' => $other]));
    }

    public function testAContactsCertificateIsAcceptedAndRemovedByItsOwnerOnly(): void
    {
        $waiting = $this->contactCertificate($this->user, ContactKeyStatus::Changed);

        $this->client->request('POST', '/contact-keys/certificates/' . $waiting->id . '/accept', ['_token' => 'wrong']);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/contact-keys/certificates/' . $waiting->id . '/accept', ['_token' => $this->frameToken('contact-certificate-' . $waiting->id)]);
        self::assertResponseRedirects();
        self::assertSame(ContactKeyStatus::Current, $this->reloadContactCertificate((int) $waiting->id)?->status);

        [$other] = $this->seedUser('somebody-else@example.test');
        $this->client->loginUser($other);

        $this->client->request('POST', '/contact-keys/certificates/' . $waiting->id . '/remove', ['_token' => $this->frameToken('contact-certificate-' . $waiting->id)]);
        self::assertResponseStatusCodeSame(404);
        self::assertNotNull($this->reloadContactCertificate((int) $waiting->id));
    }

    public function testTheSettingsListShowsContactsCertificates(): void
    {
        $this->contactCertificate($this->user, ContactKeyStatus::Current);

        $crawler = $this->client->request('GET', '/contact-keys');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('[data-testid=contact-certificate]'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function import(Smime $certificate, string $chain = ''): array
    {
        return [
            'userKeyId'   => $this->userKey->id,
            'certificate' => $certificate->certificate,
            'chain'       => $chain,
            'privateKey'  => 'v1.' . base64_encode(random_bytes(12)) . '.' . base64_encode(random_bytes(1200)),
        ];
    }

    /**
     * @param array<mixed> $body
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $body): array
    {
        $this->client->request('POST', $path, server: [
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->keyringCsrf(),
        ], content: json_encode($body, JSON_THROW_ON_ERROR));

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function keyringCsrf(): string
    {
        $crawler = $this->client->request('GET', '/settings?section=encryption');

        return (string) $crawler->filter('[data-crypto--keyring-csrf-value]')->attr('data-crypto--keyring-csrf-value');
    }

    /**
     * A token minted in the signed-in person's session, the way
     * ContactKeyControllerTest::token() does it: between requests there is
     * no request on the stack to read the session from.
     */
    private function frameToken(string $id): string
    {
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

    /** @return list<SmimeCertificate> */
    private function ownCertificates(): array
    {
        $this->em->clear();

        return $this->em->getRepository(SmimeCertificate::class)->findBy(['usr' => $this->user->id], ['id' => 'DESC']);
    }

    private function reloadContactCertificate(int $id): ?ContactCertificate
    {
        $this->em->clear();

        return $this->em->find(ContactCertificate::class, $id);
    }

    private function contactCertificate(User $user, ContactKeyStatus $status): ContactCertificate
    {
        $user        = $this->em->find(User::class, $user->id) ?? $user;
        $certificate = new ContactCertificate($user, 'contact@example.test', str_repeat('c', 64), "-----BEGIN CERTIFICATE-----\nx\n-----END CERTIFICATE-----\n", false, $status, 'Contact', 'Contact', new DateTimeImmutable('-1 day'), new DateTimeImmutable('+1 year'), new DateTimeImmutable());

        $this->em->persist($certificate);
        $this->em->flush();

        return $certificate;
    }

    private function message(User $user): \App\Entity\Mail\Message
    {
        $user    = $this->em->find(User::class, $user->id) ?? $user;
        $account = $this->em->getRepository(Account::class)->findOneBy(['usr' => $user]);
        self::assertNotNull($account);

        $message              = MailFixtures::message($this->em, $account);
        $message->fromAddress = 'smime-sender@example.test';
        $this->em->flush();

        return $message;
    }

    /** @return array{0: User, 1: UserKey} */
    private function seedUser(string $address): array
    {
        $hasher     = static::getContainer()->get(UserPasswordHasherInterface::class);
        $iterations = static::getContainer()->get(PasswordKdf::class)->iterations;
        $salt       = base64_encode(random_bytes(PasswordKdf::SALT_BYTES));

        $user = new User();
        $user->email     = 'smime-' . bin2hex(random_bytes(6)) . '@plmail.test';
        $user->nameFirst = 'Ess';
        $user->nameLast  = 'Mime';
        $user->roles     = [User::ROLE_USER];
        $user->useClientDerivedPassword($hasher->hashPassword($user, PasswordKdf::derive('a-password', $salt, $iterations)), $salt, $iterations);
        $this->em->persist($user);

        $account           = new Account();
        $account->usr      = $user;
        $account->email    = $address;
        $account->username = $address;
        $account->authType = 'password';
        $account->isActive = true;
        $account->imapHost = 'imap.example.test';
        $this->em->persist($account);

        $userKey = new UserKey($user, 'v1.' . base64_encode(random_bytes(12)) . '.' . base64_encode(random_bytes(48)));
        $this->em->persist($userKey);

        $this->em->flush();

        return [$user, $userKey];
    }
}
