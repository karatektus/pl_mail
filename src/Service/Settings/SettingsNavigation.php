<?php

declare(strict_types=1);

namespace App\Service\Settings;

use App\Domain\DTO\Settings\SettingsArea;
use App\Domain\DTO\Settings\SettingsCard;
use App\Domain\DTO\Settings\SettingsPage;

/**
 * The shape of Settings: areas, the pages in each, and the cards on each page
 * (#569).
 *
 * ── Why three levels ───────────────────────────────────────────────────────
 * The navigation used to be five groups over twenty-nine pages. "Mail" alone
 * listed sixteen, "General" was whatever had no better home (language, time
 * zone, clock, compose behaviour, the sidebar, what's new), and "Features" held
 * two. People scan a list like that for a word they already have in mind, and
 * the word was often on a card inside a page named something else. Eight areas
 * of at most five pages each, with every card indexed for search, is the fix
 * for both: the tree is short enough to read, and search finds the card when
 * reading does not.
 *
 * ── Why the address is /settings/{page}, with no area in it ─────────────────
 * A page's slug is stable and an area is navigation. With the area in the path
 * every move between areas would break every link to the page, and every
 * caller of the route would have to know which area a page lives in to build a
 * URL to it. Slugs are unique across areas, so the area adds nothing the slug
 * does not already identify.
 *
 * ── Why a class and not a YAML file or a Twig array ─────────────────────────
 * Three things read it: the controller (which page is this, does it exist), the
 * template (the tree) and the search index (every card). A Twig array was the
 * old home, and it could not be asked by the other two. The route requirement
 * is the one copy that has to be a literal — attributes take constants only —
 * and SettingsNavigationTest fails when PAGE_REQUIREMENT and the table below
 * disagree.
 *
 * Which pages are SHOWN is not decided here. The Assistant and Account health
 * entries depend on the request (an administrator's switches, the health
 * report), and the template already has both answers; this class lists every
 * page that can exist, and the template leaves out the two that should not be
 * drawn right now.
 */
final class SettingsNavigation
{
    /** Where /settings opens: the first page in the tree. */
    public const string DEFAULT_PAGE = 'profile';

    /**
     * Every slug in the table, for the route attribute.
     *
     * A literal because an attribute cannot call a method. SettingsNavigationTest
     * compares it with the table, so a page added below and forgotten here fails
     * a test instead of answering 404.
     */
    public const string PAGE_REQUIREMENT = 'profile|security|app-passwords|encryption'
        . '|region|appearance|layout|notifications|whats-new'
        . '|accounts|aliases|health'
        . '|inboxes|categories|labels|filters|disposal'
        . '|reading|compose|signature|templates'
        . '|calendars|calendar-behaviour|sharing|contacts'
        . '|ai|insights'
        . '|integrations';

    /**
     * The table: area key => [icon, [page slug => [cards]]], each card
     * [anchor, heading key, keywords key or null].
     *
     * Page labels are `settings.nav.<slug with _>` and area labels
     * `settings.nav.area.<key>`, derived rather than listed, so a page cannot be
     * given a label that is not in the catalogue's settings.nav block.
     *
     * Order within an area is the order someone meets the pages, not the
     * alphabet: Profile before Security, the inbox's own setup (unified inboxes,
     * tabs) before what files mail away (labels, filters, disposal).
     */
    private const array TABLE = [
        'account' => ['fa-solid fa-user', [
            'profile' => [
                ['card-profile', 'settings.profile.heading', 'settings.search.keywords.profile'],
                ['card-handwriting', 'settings.handwriting.heading', 'settings.search.keywords.handwriting'],
            ],
            'security' => [
                ['card-password', 'settings.password.heading', 'settings.search.keywords.password'],
                ['card-two-factor', 'settings.security.heading', 'settings.search.keywords.two_factor'],
            ],
            'app-passwords' => [
                ['card-app-passwords', 'settings.app_passwords.heading', 'settings.search.keywords.app_passwords'],
                ['card-app-setup', 'settings.app_passwords.setup_heading', null],
            ],
            'encryption' => [
                ['card-encryption-status', 'encryption.status.heading', 'settings.search.keywords.encryption'],
                ['card-encryption-keys', 'encryption.keys.heading', null],
                ['card-smime', 'encryption.smime.heading', null],
                ['card-contact-keys', 'contact_keys.heading', null],
                ['card-decrypted-mail', 'encryption.decrypted.heading', null],
                ['card-encrypted-sending', 'encryption.sending.heading', null],
                ['card-wkd', 'encryption.wkd.heading', 'settings.search.keywords.wkd'],
            ],
        ]],
        'general' => ['fa-solid fa-sliders', [
            'region' => [
                ['card-language', 'settings.language.heading', 'settings.search.keywords.language'],
                ['card-timezone', 'settings.timezone.heading', null],
                ['card-clock', 'settings.clock.heading', 'settings.search.keywords.clock'],
            ],
            'appearance' => [
                ['card-theme', 'settings.appearance.theme.heading', 'settings.search.keywords.theme'],
                ['card-accent', 'settings.appearance.accent.heading', 'settings.search.keywords.accent'],
                ['card-background', 'settings.appearance.background.heading', null],
                ['card-glass', 'settings.appearance.glass.heading', null],
                ['card-density', 'settings.appearance.density.heading', null],
                ['card-typography', 'settings.appearance.typography.heading', 'settings.search.keywords.typography'],
                ['card-motion', 'settings.appearance.motion.heading', null],
                ['card-logo', 'settings.appearance.logo.heading', null],
            ],
            'layout' => [
                ['card-sidebar', 'settings.sidebar.heading', null],
                ['card-swipe-actions', 'settings.swipe_actions.heading', 'settings.search.keywords.swipe_actions'],
                ['card-shortcuts', 'settings.shortcuts.heading', 'settings.search.keywords.shortcuts'],
            ],
            'notifications' => [
                ['card-notifications', 'settings.notifications.heading', 'settings.search.keywords.notifications'],
            ],
            'whats-new' => [
                ['card-whats-new', 'settings.whats_new.heading', 'settings.search.keywords.whats_new'],
            ],
        ]],
        'mail_accounts' => ['fa-solid fa-at', [
            'accounts' => [
                ['card-accounts', 'settings.accounts.heading', 'settings.search.keywords.accounts'],
            ],
            'aliases' => [
                ['card-aliases', 'settings.aliases.heading', 'settings.search.keywords.aliases'],
            ],
            'health' => [
                ['card-health', 'settings.health.heading', null],
            ],
        ]],
        'inbox' => ['fa-solid fa-inbox', [
            'inboxes' => [
                ['card-unified-inboxes', 'settings.unified_inboxes.heading', null],
                ['card-unified-inbox-accounts', 'settings.unified_inboxes.accounts_heading', null],
            ],
            'categories' => [
                ['card-sorting', 'settings.sorting.heading', null],
                ['card-inbox-tabs', 'settings.categories.heading', 'settings.search.keywords.inbox_tabs'],
            ],
            'labels' => [
                ['card-labels', 'settings.labels.heading', 'settings.search.keywords.labels'],
            ],
            'filters' => [
                ['card-filters', 'settings.filters.heading', 'settings.search.keywords.filters'],
            ],
            'disposal' => [
                ['card-disposal', 'settings.disposal.heading', 'settings.search.keywords.disposal'],
            ],
        ]],
        'reading_writing' => ['fa-solid fa-pen', [
            'reading' => [
                ['card-reading-pane', 'settings.reading_pane.heading', 'settings.search.keywords.reading_pane'],
                ['card-images', 'settings.images.heading', 'settings.search.keywords.images'],
            ],
            'compose' => [
                ['card-compose-behaviour', 'settings.compose_behavior.heading', 'settings.search.keywords.compose_behaviour'],
                ['card-read-receipts', 'settings.compose_defaults.heading', 'settings.search.keywords.read_receipts'],
            ],
            'signature' => [
                ['card-signature', 'settings.signature.heading', null],
            ],
            'templates' => [
                ['card-templates', 'settings.templates.heading', 'settings.search.keywords.templates'],
            ],
        ]],
        'calendar_contacts' => ['fa-regular fa-calendar', [
            'calendars' => [
                ['card-calendars', 'settings.calendars.heading', 'settings.search.keywords.calendars'],
            ],
            'calendar-behaviour' => [
                ['card-calendar-behaviour', 'settings.calendar_behaviour.heading', null],
            ],
            'sharing' => [
                ['card-sharing', 'settings.sharing.heading', 'settings.search.keywords.sharing'],
            ],
            'contacts' => [
                ['card-contacts', 'settings.contacts.heading', 'settings.search.keywords.contacts'],
                ['card-contacts-behaviour', 'settings.contacts_behaviour.heading', null],
            ],
        ]],
        'assistant' => ['fa-solid fa-wand-magic-sparkles', [
            'ai' => [
                ['card-ai-features', 'settings.ai.features.heading', 'settings.search.keywords.ai'],
                ['card-ai-notes', 'settings.ai.notes.heading', null],
                ['card-ai-context', 'settings.ai.context.heading', null],
            ],
            'insights' => [
                ['card-insights', 'settings.insights.heading', 'settings.search.keywords.insights'],
            ],
        ]],
        'integrations' => ['fa-solid fa-plug', [
            'integrations' => [
                ['card-integrations', 'settings.integrations.heading', 'settings.search.keywords.integrations'],
            ],
        ]],
    ];

    /** @var list<SettingsArea>|null */
    private ?array $areas = null;

    /**
     * Every area with every page, in tree order.
     *
     * @return list<SettingsArea>
     */
    public function areas(): array
    {
        if (null !== $this->areas) {
            return $this->areas;
        }

        $areas = [];

        foreach (self::TABLE as $areaKey => [$icon, $pages]) {
            $built = [];

            foreach ($pages as $slug => $cards) {
                $built[] = new SettingsPage(
                    slug:     $slug,
                    areaKey:  $areaKey,
                    labelKey: sprintf('settings.nav.%s', str_replace('-', '_', $slug)),
                    cards:    array_map(
                        static fn (array $card): SettingsCard => new SettingsCard($card[0], $card[1], $card[2]),
                        $cards,
                    ),
                );
            }

            $areas[] = new SettingsArea(
                key:      $areaKey,
                labelKey: sprintf('settings.nav.area.%s', $areaKey),
                icon:     $icon,
                pages:    $built,
            );
        }

        return $this->areas = $areas;
    }

    /**
     * The page behind a slug, or null for one that does not exist.
     */
    public function page(string $slug): ?SettingsPage
    {
        foreach ($this->areas() as $area) {
            foreach ($area->pages as $page) {
                if ($slug === $page->slug) {
                    return $page;
                }
            }
        }

        return null;
    }

    /**
     * The area a page belongs to. Only asked with a slug page() has accepted, so
     * a miss is a bug in the caller and says so.
     */
    public function areaOf(SettingsPage $page): SettingsArea
    {
        foreach ($this->areas() as $area) {
            if ($page->areaKey === $area->key) {
                return $area;
            }
        }

        throw new \LogicException(sprintf('Settings page "%s" names area "%s", which the table does not have.', $page->slug, $page->areaKey));
    }
}
