<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Domain\Enum\AppLocale;
use App\Domain\Helper\TimezoneHelper;
use App\Entity\User\User;
use App\Form\ApiTokenType;
use App\Service\User\ProfileSectionViewData;
use App\Service\User\UserTimezoneResolver;
use App\Service\User\TwoFactor\SecuritySectionViewData;
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
    private const array SECTIONS = ['accounts', 'profile', 'security', 'labels', 'filters', 'integrations', 'appearance', 'aliases', 'app-passwords', 'notifications', 'general'];

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
        private readonly ProfileSectionViewData $profileSection,
        private readonly SecuritySectionViewData $securitySection,
        private readonly UserTimezoneResolver $timezones,
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
            ...$this->profileSection->build($this->getUser(), $request),
            ...$this->securitySectionData($section, $request),
            'aliasForms'         => $this->aliasAddForms->forAccounts($manageableAccounts),
            'vapidPublicKey'     => $this->vapidPublicKey,
            'locales'            => AppLocale::cases(),
            'activeLocale'       => AppLocale::tryFromRequest($this->getUser()->getLocale())
                ?? AppLocale::tryFromRequest($this->defaultLocale)
                ?? AppLocale::English,
            ...$this->timezoneSectionData($section),
        ]);
    }

    /**
     * Only when the General section is on screen: grouped() walks every IANA
     * identifier, which is not work to do for whoever opened the Labels tab.
     *
     * @return array<string, mixed>
     */
    private function timezoneSectionData(string $section): array
    {
        $user = $this->getUser();

        if ('general' !== $section || false === $user instanceof User) {
            return [];
        }

        $zone = $this->timezones->resolve($user);

        return [
            'timezoneGroups'  => TimezoneHelper::grouped(),
            'activeTimezone'  => $user->getTimezone(),
            'defaultTimezone' => $this->timezones->defaultZone()->getName(),
            // Rendered here rather than in the template so the sample is
            // unambiguously the picked zone, not whatever Twig is currently set
            // to — the point of the line is to let someone check their choice.
            'currentTime'     => new \DateTimeImmutable('now', $zone)->format('H:i'),
        ];
    }

    /**
     * The Security section's parameters, and only when that section is on
     * screen.
     *
     * Building it is not free of consequence: opening the enrolment panel
     * stages a fresh TOTP secret, and it renders the QR. Neither should happen
     * because somebody opened Settings on the Labels tab.
     *
     * @return array<string, mixed>
     */
    private function securitySectionData(string $section, Request $request): array
    {
        if ('security' !== $section) {
            return [];
        }

        // Read once, straight out of the flash bag — see the note on
        // TwoFactorController about why recovery codes travel this way.
        $codes = $request->getSession()->getFlashBag()->get(TwoFactorController::FLASH_BACKUP_CODES);

        return $this->securitySection->build(
            $this->getUser(),
            $request,
            [] === $codes ? null : array_values((array) $codes),
        );
    }
}
