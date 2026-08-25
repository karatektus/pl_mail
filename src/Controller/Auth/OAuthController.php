<?php

declare(strict_types=1);

namespace App\Controller\Auth;

use App\Domain\Enum\Account\MailProvider;
use App\Domain\Exception\AccountIdentityMismatch;
use App\Entity\User\User;
use App\Repository\Mail\AccountRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\OAuth\MicrosoftOAuthErrorTranslator;
use App\Service\OAuth\OAuthAccountLinker;
use App\Service\OAuth\OAuthProviderFactory;
use App\Service\OAuth\OAuthStateStore;
use App\Service\Onboarding\OnboardingFlow;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('ROLE_USER')]
#[Route('/oauth', name: 'app_oauth_')]
class OAuthController extends AbstractController
{
    private const string SESSION_NAMESPACE = 'oauth2';

    public function __construct(
        private readonly OAuthProviderFactory   $providerFactory,
        private readonly OAuthStateStore        $stateStore,
        private readonly OAuthAccountLinker     $accountLinker,
        private readonly OnboardingFlow $onboarding,
        private readonly MicrosoftOAuthErrorTranslator $microsoftErrorTranslator,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly AccountRepository $accounts,
    ) {}

    /**
     * The session key holding "this handshake is a repair, not a new account".
     *
     * Kept beside the state rather than passed through the provider's redirect:
     * anything sent to the provider comes back under the user's control, and an
     * account id that could be edited in the address bar is an invitation to
     * write somebody else's tokens onto a row of your choosing. The id never
     * leaves the server, and ownership is re-checked when it is read back.
     */
    private const string SESSION_RECONNECT = 'oauth2_reconnect_account';

    #[Route('/{provider}/connect', name: 'connect', methods: ['GET'])]
    public function connect(string $provider, Request $request): RedirectResponse
    {
        $mailProvider = $this->resolveProvider($provider);
        $client       = $this->providerFactory->create($mailProvider);

        $options = array_merge(
            ['scope' => $mailProvider->scopes()],
            $mailProvider->authorizationUrlOptions(),
        );

        $authUrl = $client->getAuthorizationUrl($options);

        $this->stateStore->remember($request->getSession(), self::SESSION_NAMESPACE, $client->getState());

        // Reconnect mode. The health page sends the account it wants repaired;
        // everything else omits it and gets the original find-or-create
        // behaviour unchanged.
        //
        // The id is stored only after ownership has been proven, so the value
        // read back in the callback is known to be this user's — the callback
        // still re-checks, because a session outlives a login in ways that are
        // not worth reasoning about at a distance.
        $session = $request->getSession();
        $session->remove(self::SESSION_RECONNECT);

        $reconnect = $request->query->getInt('reconnect');

        if (0 !== $reconnect) {
            $account = $this->accounts->find($reconnect);

            if (null === $account) {
                throw $this->createAccessDeniedException();
            }

            $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);

            $session->set(self::SESSION_RECONNECT, (int) $account->id);
        }

        return new RedirectResponse($authUrl);
    }

    /**
     * Where to land after the round trip through the provider.
     *
     * Settings, normally. But a user still in setup started this from the
     * wizard, and dropping them on the settings page means the wizard reopens
     * over a page they never asked for. The mail shell is where they were.
     */
    private function landingRoute(): string
    {
        $user = $this->getUser();

        if ($user instanceof User && true === $this->onboarding->isPending($user)) {
            return 'app_default_index';
        }

        return 'app_settings_index';
    }

    #[Route('/{provider}/callback', name: 'callback', methods: ['GET'])]
    public function callback(string $provider, Request $request): Response
    {
        $mailProvider = $this->resolveProvider($provider);

        $state         = $request->query->get('state');
        $expectedState = $this->stateStore->consume($request->getSession(), self::SESSION_NAMESPACE)['state'];
        $error = $request->query->get('error');

        if (null !== $error) {
            $description = (string) $request->query->get('error_description', '');
            $translated  = $this->microsoftErrorTranslator->translate($description);

            $this->logger->warning('OAuth callback returned an error', [
                'provider'    => $provider,
                'error'       => $error,
                'aadstsCode'  => $translated['code'],
                'description' => $description,
            ]);

            $this->addFlash('error', $this->translator->trans($translated['key']));

            return $this->redirectToRoute($this->landingRoute());
        }

        if (null === $state || $state !== $expectedState) {
            throw $this->createAccessDeniedException('Invalid OAuth state.');
        }

        $code = $request->query->get('code');

        if (null === $code) {
            throw $this->createAccessDeniedException('Missing authorization code.');
        }

        $client = $this->providerFactory->create($mailProvider);

        try {
            $token = $client->getAccessToken('authorization_code', ['code' => $code]);
        } catch (IdentityProviderException $e) {
            $raw        = $e->getMessage() . ' ' . json_encode($e->getResponseBody());
            $translated = $this->microsoftErrorTranslator->translate($raw);

            $this->logger->warning('OAuth token exchange failed', [
                'provider'   => $provider,
                'error'      => $e->getMessage(),
                'aadstsCode' => $translated['code'],
            ]);

            $this->addFlash('error', $this->translator->trans($translated['key']));

            return $this->redirectToRoute($this->landingRoute());
        }

        $ownerData = $client->getResourceOwner($token)->toArray();
        $email     = $this->accountLinker->mailboxAddress($ownerData);

        if (null === $email) {
            throw $this->createAccessDeniedException(
                'Could not determine the account email from the provider.'
            );
        }

        /** @var User $user */
        $user = $this->getUser();

        $session   = $request->getSession();
        $reconnect = $session->get(self::SESSION_RECONNECT);

        // Single-use, like the state itself: a repair intent left in the
        // session would be picked up by the next ordinary "add an account"
        // handshake and silently turn it into an overwrite.
        $session->remove(self::SESSION_RECONNECT);

        if (false === is_int($reconnect)) {
            $this->accountLinker->link($user, $mailProvider, $email, $token);
            $this->warnIfCalendarWasDeclined($mailProvider, $token);

            return $this->redirectToRoute($this->landingRoute());
        }

        $account = $this->accounts->find($reconnect);

        if (null === $account || $account->usr !== $user) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->accountLinker->relink($account, $mailProvider, $email, $token);
        } catch (AccountIdentityMismatch $e) {
            // Refused, not resolved. Falling back to link() here would create a
            // second account for the address they actually signed in as, which
            // is a surprising thing to do to somebody who pressed "Reconnect"
            // — and it would leave the broken one broken.
            $this->logger->warning('Reconnect refused: the authorised mailbox is not this account', [
                'accountId' => $account->id,
                'provider'  => $provider,
            ]);

            $this->addFlash('error', $this->translator->trans('settings.health.reconnect.wrong_account', [
                '%expected%' => $e->expected,
                '%actual%'   => $e->actual,
            ]));

            return $this->redirectToRoute('app_settings_index', ['section' => 'health']);
        }

        $this->addFlash('success', $this->translator->trans('settings.health.reconnect.done', [
            '%account%' => $account->email,
        ]));

        $this->warnIfCalendarWasDeclined($mailProvider, $token);

        return $this->redirectToRoute('app_settings_index', ['section' => 'health']);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Say so NOW when the calendar permission was asked for and not given.
     *
     * The durable half of this is the health card built from
     * Account::$oauthGrantedScopes — a flash is gone on the next click, and the
     * shortfall lasts until somebody fixes it. This is the immediate half, said
     * at the one moment the user remembers the consent screen they just saw.
     *
     * Google's consent screen offers sensitive scopes as individual tick boxes,
     * so a user can grant mail and decline calendar and still come back with a
     * perfectly valid token. Nothing about the handshake fails. The account
     * connects, the app says so, and the missing permission surfaces days later
     * as three calendars that "stopped syncing" with a 403 — by which time the
     * consent screen is a distant memory and the message reads like a fault in
     * plMail.
     *
     * The token response is where the truth is: OAuth 2.0 requires the
     * authorization server to return the granted `scope` whenever it differs
     * from the requested one, and Google returns it every time. It was being
     * discarded.
     *
     * A warning rather than a refusal, and the account is linked either way:
     * mail works, that is most of what people connect an account for, and
     * throwing the whole connection away over a permission somebody may not
     * want to give would be worse than telling them what they will be missing.
     *
     * Google only. Microsoft consents to the requested set as a whole — see
     * MailProvider::scopes(), which says so — and its token response spells
     * scopes differently enough that comparing them would invent a warning
     * where there is no problem.
     */
    private function warnIfCalendarWasDeclined(MailProvider $provider, AccessTokenInterface $token): void
    {
        $granted = $token->getValues()['scope'] ?? null;

        if (false !== $provider->grantsCalendarAccess(is_string($granted) ? $granted : null)) {
            return;
        }

        $this->logger->warning('Account connected without calendar access', [
            'provider' => $provider->value,
            'granted'  => $granted,
        ]);

        $this->addFlash('warning', $this->translator->trans('account.oauth.calendar_declined'));
    }

    private function resolveProvider(string $provider): MailProvider
    {
        $mailProvider = MailProvider::tryFrom($provider);

        if (null === $mailProvider) {
            throw $this->createNotFoundException(
                sprintf('Unknown OAuth provider "%s".', $provider)
            );
        }

        return $mailProvider;
    }
}
