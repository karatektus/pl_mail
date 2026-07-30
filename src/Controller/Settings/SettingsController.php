<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Domain\Enum\AppLocale;
use App\Form\ApiTokenType;
use App\Entity\User\User;
use App\Form\User\ProfileType;
use App\Service\User\AvatarFromIntegration;
use App\Form\Factory\AliasAddFormFactory;
use App\Repository\Mail\AccountRepository;
use App\Repository\User\ApiTokenRepository;
use App\Repository\Label\LabelRepository;
use App\Repository\Rule\MailRuleRepository;
use App\Service\Push\PushSubscriptionRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/settings', name: 'app_settings_')]
#[IsGranted('ROLE_USER')]
final class SettingsController extends AbstractController
{
    private const array SECTIONS = ['accounts', 'profile', 'labels', 'filters', 'integrations', 'appearance', 'aliases', 'app-passwords', 'notifications', 'general'];

    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly LabelRepository   $labelRepository,
        private readonly MailRuleRepository $mailRuleRepository,
        private readonly PushSubscriptionRegistry $pushSubscriptionRegistry,
        private readonly ApiTokenRepository $apiTokenRepository,
        private readonly AliasAddFormFactory $aliasAddForms,
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')]
        private readonly string $vapidPublicKey,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $defaultLocale,
        private readonly AvatarFromIntegration $avatarSources,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $section = (string) $request->query->get('section', 'accounts');

        if (false === in_array($section, self::SECTIONS, true)) {
            $section = 'accounts';
        }

        $manageableAccounts = $this->accountRepository->findForUserOrderedByName($this->getUser());

        return $this->render('settings/index.html.twig', [
            'section'            => $section,
            'manageableAccounts' => $manageableAccounts,
            'labels'             => $this->labelRepository->findForUserTreeOrdered($this->getUser()),
            'rules'              => $this->mailRuleRepository->findForUserOrdered($this->getUser()),
            'apiTokens'          => $this->apiTokenRepository->findForUser($this->getUser()),
            'apiTokenForm'       => $this->createForm(ApiTokenType::class)->createView(),
            ...$this->profileSection($request),
            'aliasForms'         => $this->aliasAddForms->forAccounts($manageableAccounts),
            'vapidPublicKey'     => $this->vapidPublicKey,
            'locales'            => AppLocale::cases(),
            'activeLocale'       => AppLocale::tryFromRequest($this->getUser()->getLocale())
                ?? AppLocale::tryFromRequest($this->defaultLocale)
                ?? AppLocale::English,
        ]);
    }

    /**
     * The profile section's own parameters.
     *
     * The picture can be taken from a service the user has connected, exactly
     * as it can during setup — the wizard is not the only place a profile is
     * edited, and a feature reachable only from a one-time flow is a feature
     * most people never find.
     *
     * @return array<string, mixed>
     */
    private function profileSection(Request $request): array
    {
        /** @var User $user */
        $user = $this->getUser();

        $sources = $this->avatarSources->availableFor($user);
        $picking = null;

        foreach ($sources as $source) {
            // Matched against the user's own connections rather than looked up
            // by id, so a borrowed id cannot browse somebody else's photos.
            if ((string) $source->id === (string) $request->query->get('pick', '')) {
                $picking = $source;
            }
        }

        $pickUrls = [];

        foreach ($sources as $source) {
            $pickUrls[(string) $source->id] = $this->generateUrl('app_settings_index', [
                'section' => 'profile',
                'pick'    => $source->id,
            ]);
        }

        return [
            'profileForm' => $this->createForm(ProfileType::class, $user, [
                'action'        => $this->generateUrl('app_settings_profile_save'),
                'avatar_source' => null === $picking ? null : (string) $picking->id,
            ])->createView(),
            'avatarSources' => $sources,
            'avatarPicking' => $picking,
            'avatarEntries' => null === $picking ? [] : $this->avatarSources->browse($picking),
            'avatarPickUrls' => $pickUrls,
            'avatarCloseUrl' => $this->generateUrl('app_settings_index', ['section' => 'profile']),
        ];
    }

}
