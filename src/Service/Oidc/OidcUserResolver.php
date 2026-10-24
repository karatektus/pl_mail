<?php

declare(strict_types=1);

namespace App\Service\Oidc;

use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Which account a provider identity signs into — and whether it may.
 *
 * In order, stopping at the first that applies:
 *
 *  1. The account already linked to this issuer and subject. The normal case
 *     after the first sign-in, and the only one that ignores the address: it
 *     can change at the provider without anything here noticing or caring.
 *  2. The one account holding the address the provider reports, linked now —
 *     but only if the provider says it verified that address. Otherwise
 *     anybody who could type an administrator's address into their own
 *     profile at the provider would be signed in as that administrator.
 *  3. A new account, if OIDC_AUTO_CREATE allows it.
 *
 * Anything else is refused with a reason the login form can show. What is
 * deliberately not done is linking an account that is already linked to a
 * DIFFERENT subject of the same provider: that is two people at the provider
 * claiming one mailbox here, and picking the newer one would let an address
 * change at the provider take an account over.
 *
 * Suspended and removed accounts are not this class's concern. Removed rows
 * are never found (every query is undeleted), and suspension is refused by
 * App\Security\UserChecker after the identity is established, exactly as it is
 * for a password.
 */
final readonly class OidcUserResolver
{
    public function __construct(
        private OidcSettings $settings,
        private UserRepository $users,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws OidcLoginFailed
     */
    public function resolve(OidcIdentity $identity): User
    {
        $linked = $this->users->findOneByOidcIdentity($identity->issuer, $identity->subject);

        if (null !== $linked) {
            return $linked;
        }

        if (null === $identity->email) {
            throw new OidcLoginFailed('oidc.no_email', sprintf(
                'Subject "%s" is linked to no account and the provider sent no address — is the "email" scope allowed?',
                $identity->subject,
            ));
        }

        if (true === $this->settings->current()->requireVerifiedEmail && false === $identity->emailVerified) {
            throw new OidcLoginFailed('oidc.email_unverified', sprintf(
                'Subject "%s" reports %s without email_verified=true; not linking by address',
                $identity->subject,
                $identity->email,
            ));
        }

        $matches = $this->users->findUndeletedByEmailIgnoringCase($identity->email);

        if (count($matches) > 1) {
            throw new OidcLoginFailed('oidc.ambiguous', sprintf(
                'Several accounts hold %s in different case; link one by hand',
                $identity->email,
            ));
        }

        if (1 === count($matches)) {
            return $this->link($matches[0], $identity);
        }

        if (false === $this->settings->current()->autoCreate) {
            throw new OidcLoginFailed('oidc.no_account', sprintf(
                'No account holds %s and OIDC_AUTO_CREATE is off',
                $identity->email,
            ));
        }

        return $this->create($identity);
    }

    private function link(User $user, OidcIdentity $identity): User
    {
        if ($identity->issuer === $user->oidcIssuer && null !== $user->oidcSubject) {
            throw new OidcLoginFailed('oidc.linked_elsewhere', sprintf(
                'Account %d is already linked to subject "%s"; subject "%s" claims it by address %s',
                (int) $user->id,
                $user->oidcSubject,
                $identity->subject,
                $identity->email,
            ));
        }

        $user->oidcIssuer  = $identity->issuer;
        $user->oidcSubject = $identity->subject;
        $this->entityManager->flush();

        $this->logger->info('Linked an account to a single sign-on identity', [
            'user'    => $user->id,
            'issuer'  => $identity->issuer,
            'subject' => $identity->subject,
        ]);

        return $user;
    }

    private function create(OidcIdentity $identity): User
    {
        // The columns are 255 long, and a provider's display name is
        // whatever somebody typed into it.
        [$first, $last] = array_map(
            static fn (string $part): string => mb_substr($part, 0, 255),
            $this->names($identity),
        );

        $user = new User();
        $user->email       = (string) $identity->email;
        $user->nameFirst   = $first;
        $user->nameLast    = $last;
        // No password, in the form the admin delete action already uses for
        // "cannot sign in with one": a hash no password produces. The person
        // signs in through the provider; `app:user:password` gives them one
        // as well if they want both.
        $user->password    = '';
        $user->oidcIssuer  = $identity->issuer;
        $user->oidcSubject = $identity->subject;

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $this->logger->info('Created an account for a single sign-on identity', [
            'user'    => $user->id,
            'issuer'  => $identity->issuer,
            'subject' => $identity->subject,
        ]);

        return $user;
    }

    /**
     * First and last name, both required by the table.
     *
     * The provider's own split when it sends one; otherwise its display name,
     * split at the last space; otherwise the address's local part. A name that
     * is wrong is fixed in Settings in a second, and a refusal over it would
     * be absurd.
     *
     * @return array{string, string}
     */
    private function names(OidcIdentity $identity): array
    {
        if (null !== $identity->givenName || null !== $identity->familyName) {
            return [$identity->givenName ?? '', $identity->familyName ?? ''];
        }

        if (null !== $identity->name) {
            $space = strrpos($identity->name, ' ');

            return false === $space
                ? [$identity->name, '']
                : [substr($identity->name, 0, $space), substr($identity->name, $space + 1)];
        }

        return [strstr((string) $identity->email, '@', true) ?: (string) $identity->email, ''];
    }
}
