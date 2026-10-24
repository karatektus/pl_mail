<?php

declare(strict_types=1);

namespace App\Tests\Service\Oidc;

use App\Entity\User\User;
use App\Repository\Oidc\OidcConfigRepository;
use App\Repository\User\UserRepository;
use App\Service\Oidc\OidcIdentity;
use App\Service\Oidc\OidcLoginFailed;
use App\Service\Oidc\OidcSettings;
use App\Service\Oidc\OidcUserResolver;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A provider identity signs into the account it belongs to, and into no other.
 *
 * Against the real database, because the claims are about which ROW is found —
 * undeleted, case-insensitive, linked to this issuer and not another — and a
 * mocked repository would only repeat the queries back to themselves.
 *
 * The tests that matter most are the refusals. Linking by address is the one
 * moment the provider's word about an address decides whose mail somebody
 * reads, so an unverified address, an account already linked to a different
 * person, and two accounts that differ only in case are each refused rather
 * than resolved cleverly.
 */
final class OidcUserResolverTest extends KernelTestCase
{
    private const string ISSUER = 'https://auth.example.com/application/o/plmail/';

    private EntityManagerInterface $em;
    private UserRepository $users;

    protected function setUp(): void
    {
        self::bootKernel();

        $container   = static::getContainer();
        $this->em    = $container->get(EntityManagerInterface::class);
        $this->users = $container->get(UserRepository::class);

        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === isset($this->em) && true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testALinkedIdentitySignsIntoItsAccountWhateverAddressItNowReports(): void
    {
        $user = $this->seedUser('oidc-linked@plmail.test', 'subject-linked');

        $resolved = $this->resolver()->resolve($this->identity('subject-linked', 'renamed@elsewhere.test', false));

        self::assertSame($user->id, $resolved->id);
    }

    public function testAVerifiedAddressLinksTheAccountHoldingItIgnoringCase(): void
    {
        $user = $this->seedUser('oidc-first@plmail.test');

        $resolved = $this->resolver()->resolve($this->identity('subject-first', 'OIDC-First@plmail.test', true));

        self::assertSame($user->id, $resolved->id);
        self::assertSame(self::ISSUER, $resolved->oidcIssuer);
        self::assertSame('subject-first', $resolved->oidcSubject);
    }

    public function testAnUnverifiedAddressLinksNothing(): void
    {
        $user = $this->seedUser('oidc-unverified@plmail.test');

        $this->assertRefused('oidc.email_unverified', fn () => $this->resolver()->resolve(
            $this->identity('subject-unverified', 'oidc-unverified@plmail.test', false),
        ));

        self::assertNull($user->oidcSubject);
    }

    public function testAnUnverifiedAddressLinksWhenTheOperatorSaidToTrustIt(): void
    {
        $user = $this->seedUser('oidc-trusted@plmail.test');

        $resolved = $this->resolver(requireVerifiedEmail: false)->resolve(
            $this->identity('subject-trusted', 'oidc-trusted@plmail.test', false),
        );

        self::assertSame($user->id, $resolved->id);
    }

    /**
     * Two people at the provider, one address here. The second must not take
     * the account over just because the provider now reports the address for
     * them — that is the takeover an address change at the provider would be.
     */
    public function testAnAccountLinkedToAnotherSubjectOfTheSameProviderIsRefused(): void
    {
        $this->seedUser('oidc-taken@plmail.test', 'subject-original');

        $this->assertRefused('oidc.linked_elsewhere', fn () => $this->resolver()->resolve(
            $this->identity('subject-newcomer', 'oidc-taken@plmail.test', true),
        ));
    }

    public function testAnAccountLinkedToAnotherProviderIsRelinked(): void
    {
        $user = $this->seedUser('oidc-moved@plmail.test', 'subject-old', 'https://old-provider.example.com/');

        $resolved = $this->resolver()->resolve($this->identity('subject-new', 'oidc-moved@plmail.test', true));

        self::assertSame($user->id, $resolved->id);
        self::assertSame(self::ISSUER, $resolved->oidcIssuer);
        self::assertSame('subject-new', $resolved->oidcSubject);
    }

    public function testTwoAccountsDifferingOnlyInCaseAreNotGuessedBetween(): void
    {
        $this->seedUser('oidc-twice@plmail.test');
        $this->seedUser('OIDC-Twice@plmail.test');

        $this->assertRefused('oidc.ambiguous', fn () => $this->resolver()->resolve(
            $this->identity('subject-twice', 'oidc-twice@plmail.test', true),
        ));
    }

    public function testARemovedAccountIsNeitherMatchedNorLinked(): void
    {
        $user = $this->seedUser('oidc-removed@plmail.test', 'subject-removed');
        $user->deletedAt = new DateTimeImmutable();
        $this->em->flush();

        $this->assertRefused('oidc.no_account', fn () => $this->resolver()->resolve(
            $this->identity('subject-removed', 'oidc-removed@plmail.test', true),
        ));
    }

    public function testNobodyIsCreatedUnlessTheOperatorAllowedIt(): void
    {
        $this->assertRefused('oidc.no_account', fn () => $this->resolver()->resolve(
            $this->identity('subject-stranger', 'oidc-stranger@plmail.test', true),
        ));

        self::assertSame([], $this->users->findUndeletedByEmailIgnoringCase('oidc-stranger@plmail.test'));
    }

    public function testACreatedAccountIsOrdinaryLinkedAndHasNoUsablePassword(): void
    {
        $created = $this->resolver(autoCreate: true)->resolve(
            $this->identity('subject-created', 'oidc-created@plmail.test', true, 'Ada', 'Lovelace'),
        );

        self::assertNotNull($created->id);
        self::assertSame('oidc-created@plmail.test', $created->email);
        self::assertSame(['Ada', 'Lovelace'], [$created->nameFirst, $created->nameLast]);
        self::assertSame(['ROLE_USER'], $created->getRoles());
        self::assertSame('', $created->password);
        self::assertSame('subject-created', $created->oidcSubject);
    }

    private function resolver(bool $autoCreate = false, bool $requireVerifiedEmail = true): OidcUserResolver
    {
        return new OidcUserResolver(
            // The environment's side of the settings: no row is saved here, so
            // these are what is in force.
            new OidcSettings(
                static::getContainer()->get(OidcConfigRepository::class),
                self::ISSUER,
                'client',
                'secret',
                '',
                $autoCreate,
                true === $requireVerifiedEmail ? 'true' : 'false',
                'openid email',
            ),
            $this->users,
            $this->em,
            new NullLogger(),
        );
    }

    private function identity(
        string $subject,
        ?string $email,
        bool $verified,
        ?string $givenName = null,
        ?string $familyName = null,
    ): OidcIdentity {
        return new OidcIdentity(self::ISSUER, $subject, $email, $verified, $givenName, $familyName, null);
    }

    private function seedUser(string $email, ?string $subject = null, string $issuer = self::ISSUER): User
    {
        $user = new User();
        $user->email     = $email;
        $user->nameFirst = 'Oidc';
        $user->nameLast  = 'Test';
        $user->password  = '';

        if (null !== $subject) {
            $user->oidcIssuer  = $issuer;
            $user->oidcSubject = $subject;
        }

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function assertRefused(string $messageKey, callable $resolve): void
    {
        try {
            $resolve();
        } catch (OidcLoginFailed $e) {
            self::assertSame($messageKey, $e->messageKey);

            return;
        }

        self::fail(sprintf('expected a refusal with "%s"', $messageKey));
    }
}
