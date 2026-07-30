<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Domain\Enum\AppLocale;
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
    private const array SECTIONS = ['accounts', 'labels', 'filters', 'integrations', 'appearance', 'aliases', 'app-passwords', 'notifications', 'general'];

    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly LabelRepository   $labelRepository,
        private readonly MailRuleRepository $mailRuleRepository,
        private readonly PushSubscriptionRegistry $pushSubscriptionRegistry,
        private readonly ApiTokenRepository $apiTokenRepository,
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')]
        private readonly string $vapidPublicKey,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $defaultLocale,
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
            'vapidPublicKey'     => $this->vapidPublicKey,
            'locales'            => AppLocale::cases(),
            'activeLocale'       => AppLocale::tryFromRequest($this->getUser()->getLocale())
                ?? AppLocale::tryFromRequest($this->defaultLocale)
                ?? AppLocale::English,
        ]);
    }
}
