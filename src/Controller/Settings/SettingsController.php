<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Domain\Enum\AppLocale;
use App\Domain\Enum\User\ClockFormat;
use App\Domain\Helper\TimezoneHelper;
use App\Entity\User\User;
use App\Form\ApiTokenType;
use App\Service\User\ClockFormatResolver;
use App\Service\User\ProfileSectionViewData;
use App\Service\User\UserTimezoneResolver;
use App\Service\User\TwoFactor\SecuritySectionViewData;
use App\Form\Factory\AliasAddFormFactory;
use App\Repository\Calendar\BookingPageRepository;
use App\Repository\Calendar\CalendarRepository;
use App\Repository\Calendar\CalendarShareLinkRepository;
use App\Repository\Mail\AccountRepository;
use App\Repository\Push\PushDeliveryRepository;
use App\Repository\User\ApiTokenRepository;
use App\Repository\User\PushSubscriptionRepository;
use App\Repository\Label\LabelRepository;
use App\Repository\Rule\MailRuleRepository;
use App\Service\Calendar\Subscription\CalendarSourceLister;
use App\Service\Health\AccountHealthInspector;
use App\Service\Insight\InsightExtractorInterface;
use App\Service\Insight\InsightExtractorRegistry;
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
    /**
     * `health` leads deliberately.
     *
     * It is not a settings pane — nothing on it is a preference — but it is
     * where the topbar indicator points and where the OAuth reconnect returns
     * to, so it needs a stable section of its own. It gets one rather than a
     * block on `accounts` for two reasons: the problems it lists are not all
     * account problems (calendars, file-store connections and abandoned
     * background work all appear there), and a variable-height alert stack at
     * the top of the accounts pane would push the actual account controls below
     * the fold on exactly the installs that have something wrong.
     */
    private const array SECTIONS = ['health', 'accounts', 'profile', 'security', 'labels', 'calendars', 'sharing', 'filters', 'insights', 'integrations', 'appearance', 'aliases', 'signature', 'app-passwords', 'notifications', 'general'];

    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly LabelRepository   $labelRepository,
        private readonly CalendarRepository $calendarRepository,
        private readonly CalendarShareLinkRepository $shareLinkRepository,
        private readonly BookingPageRepository $bookingPageRepository,
        private readonly MailRuleRepository $mailRuleRepository,
        private readonly PushSubscriptionRegistry $pushSubscriptionRegistry,
        private readonly PushSubscriptionRepository $pushSubscriptions,
        private readonly PushDeliveryRepository $pushDeliveries,
        private readonly ApiTokenRepository $apiTokenRepository,
        private readonly AliasAddFormFactory $aliasAddForms,
        #[Autowire('%env(VAPID_PUBLIC_KEY)%')]
        private readonly string $vapidPublicKey,
        #[Autowire('%kernel.default_locale%')]
        private readonly string $defaultLocale,
        private readonly CalendarSourceLister $calendarSources,
        private readonly ProfileSectionViewData $profileSection,
        private readonly SecuritySectionViewData $securitySection,
        private readonly UserTimezoneResolver $timezones,
        private readonly ClockFormatResolver $clocks,
        private readonly AccountHealthInspector $healthInspector,
        private readonly InsightExtractorRegistry $insightExtractors,
    ) {
    }

    #[Route('', name: 'index')]
    public function index(Request $request): Response
    {
        $section = (string) $request->query->get('section', 'accounts');

        if (false === in_array($section, self::SECTIONS, true)) {
            $section = 'accounts';
        }

        // The user's own arrangement, not the alphabet.
        //
        // This list is drag-reorderable, and AccountController::reorder()
        // re-renders it with findForUserOrdered() — so dragging a row produced
        // a list in sortOrder that snapped back to alphabetical on the next
        // load. The order is also what "primary" means (position 0, see
        // AccountCreator::resequence()), which made the first row and the
        // primary account two different rows on any install where the two
        // orderings disagree.
        $manageableAccounts = $this->accountRepository->findForUserOrdered($this->getUser());

        return $this->render('settings/index.html.twig', [
            'section'            => $section,
            'manageableAccounts' => $manageableAccounts,
            'labels'             => $this->labelRepository->findForUserTreeOrdered($this->getUser()),
            'calendars'          => $this->calendarRepository->findForUser($this->getUser()),
            'rules'              => $this->mailRuleRepository->findForUserOrdered($this->getUser()),
            'apiTokens'          => $this->apiTokenRepository->findForUser($this->getUser()),
            'apiTokenForm'       => $this->createForm(ApiTokenType::class)->createView(),
            ...$this->profileSection->build($this->getUser(), $request),
            ...$this->securitySectionData($section, $request),
            ...$this->calendarSectionData($section),
            ...$this->sharingSectionData($section),
            ...$this->notificationsSectionData($section),
            'aliasForms'         => $this->aliasAddForms->forAccounts($manageableAccounts),
            'vapidPublicKey'     => $this->vapidPublicKey,
            'locales'            => AppLocale::cases(),
            'activeLocale'       => AppLocale::tryFromRequest($this->getUser()->locale)
                ?? AppLocale::tryFromRequest($this->defaultLocale)
                ?? AppLocale::English,
            ...$this->timezoneSectionData($section),
            ...$this->healthSectionData($section),
            ...$this->insightsSectionData($section),
        ]);
    }

    /**
     * The health report, and only when that section is on screen.
     *
     * The same rule the section methods below follow, and it earns its keep
     * more than most: this is the one call on the page that reads the failure
     * transport, which means deserialising envelopes rather than counting rows.
     * Paying that on the labels tab would be paying it on every settings visit.
     *
     * The infrastructure half is asked for only for an admin — see
     * AccountHealthInspector::abandonedWork() on why the queue is not a
     * per-user question — so a non-admin never triggers that read at all.
     *
     * @return array<string, mixed>
     */
    private function healthSectionData(string $section): array
    {
        $user = $this->getUser();

        if ('health' !== $section || false === $user instanceof User) {
            return [];
        }

        return [
            'healthReport' => $this->healthInspector->inspect($user, $this->isGranted('ROLE_ADMIN')),
        ];
    }

    /**
     * Where calendars can be subscribed from, and only when that section is on
     * screen — asking the driver registry about every account is cheap but not
     * free, and nothing else on this page shows it.
     *
     * Built by CalendarSourceLister rather than by a rule here, so this page
     * and CalendarSettingsController's Turbo Stream cannot disagree about which
     * accounts carry calendars.
     *
     * @return array<string, mixed>
     */
    private function calendarSectionData(string $section): array
    {
        $user = $this->getUser();

        if ('calendars' !== $section || false === $user instanceof User) {
            return [];
        }

        return [
            'calendarAccounts'    => $this->calendarSources->accountsFor($user),
            'calendarConnections' => $this->calendarSources->connectionsFor($user),
        ];
    }

    /**
     * The two public-URL lists, and only when that section is on screen.
     *
     * Both are fetch-joined reads over rows almost every install has none of,
     * so the cost is small — but it is the same rule the two methods around
     * this one follow, and one exception is how a settings page ends up making
     * eleven queries to render one tab.
     *
     * @return array<string, mixed>
     */
    private function sharingSectionData(string $section): array
    {
        $user = $this->getUser();

        if ('sharing' !== $section || false === $user instanceof User) {
            return [];
        }

        return [
            'shareLinks'   => $this->shareLinkRepository->findForUser($user),
            'bookingPages' => $this->bookingPageRepository->findForUser($user),
        ];
    }

    /**
     * The user's registered devices and what last happened to each, and only
     * when the Notifications section is on screen.
     *
     * Two queries rather than one join, deliberately. A subscription with no
     * delivery yet — every device between registering and its first
     * PushVerification — has to appear in the list saying so, and a join would
     * either drop it or need an outer join whose null half means the same
     * thing the missing key already means. The device ids come back keyed, so
     * the template looks each one up rather than searching a list.
     *
     * This is the user's own view of the same table /admin/push reads, scoped
     * to them: the admin sees who could not be reached across the install, the
     * user sees whether their own phone is being reached, which is the question
     * that gets asked as "notifications stopped working".
     *
     * @return array<string, mixed>
     */
    private function notificationsSectionData(string $section): array
    {
        $user = $this->getUser();

        if ('notifications' !== $section || false === $user instanceof User) {
            return [];
        }

        return [
            'pushDevices'    => $this->pushSubscriptions->findForUser($user),
            'lastDeliveries' => $this->pushDeliveries->lastDeliveryPerDevice((int) $user->id),
        ];
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
        $now  = new \DateTimeImmutable('now', $zone);

        return [
            'timezoneGroups'  => TimezoneHelper::grouped(),
            'activeTimezone'  => $user->timezone,
            'defaultTimezone' => $this->timezones->defaultZone()->getName(),
            // Rendered here rather than in the template so the sample is
            // unambiguously the picked zone, not whatever Twig is currently set
            // to — the point of the line is to let someone check their choice.
            'currentTime'     => $now->format('H:i'),

            // The clock picker, which belongs beside the zone: the two together
            // are "what time is it, and how is it written". Its samples are
            // rendered here for the same reason the line above is — a sample
            // formatted by the template would be formatted by the setting it is
            // supposed to be demonstrating, so every option would read the same.
            'clockOptions'    => [
                ClockFormat::Twelve->value     => $now->format(ClockFormat::Twelve->time()),
                ClockFormat::TwentyFour->value => $now->format(ClockFormat::TwentyFour->time()),
            ],
            'activeClock'     => $this->clocks->chosen($user)?->value,
            'defaultClock'    => $now->format(
                ClockFormat::forLocale(AppLocale::tryFromRequest($user->locale)
                    ?? AppLocale::tryFromRequest($this->defaultLocale))->time(),
            ),

            // Folded is the default, so anything but a stored false reads as
            // true — the same absent-key convention the setting itself keeps.
            'forwardQuoteCollapsed' => false !== $user->getSetting(User::SETTING_COMPOSE_FORWARD_QUOTE_COLLAPSED, true),
        ];
    }

    /**
     * The insight extractors as the settings card renders them, and only when
     * that section is on screen — the rule every method around this one
     * follows.
     *
     * Rendered FROM THE REGISTRY, which is the promise
     * InsightExtractorInterface makes: an extractor added next release appears
     * here without this method, the template, or anything else changing. The
     * rows are plain arrays rather than the extractor objects so the template
     * touches presentation only — key, icon, and whether this user has left it
     * on.
     *
     * @return array<string, mixed>
     */
    private function insightsSectionData(string $section): array
    {
        $user = $this->getUser();

        if ('insights' !== $section || false === $user instanceof User) {
            return [];
        }

        return [
            'insightExtractors' => array_map(
                fn (InsightExtractorInterface $extractor): array => [
                    'key'     => $extractor::key(),
                    'icon'    => $extractor->icon(),
                    'enabled' => $this->insightExtractors->isEnabledFor($user, $extractor::key()),
                ],
                $this->insightExtractors->all(),
            ),
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
