<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Session;

use App\Domain\Enum\Theme\Layout;
use App\Domain\Enum\Theme\LogoStyle;
use App\Domain\Enum\Theme\Theme;
use App\Entity\Embeddable\Appearance;
use App\Jmap\Method\MethodRegistry;
use App\Jmap\Protocol\Capability;
use App\Jmap\Session\SessionBuilder;
use App\Tests\Jmap\JmapTestCase;

/**
 * The Session carries the user's theme and the vocabulary it comes from.
 *
 * Per user, not per account, so it sits in the top-level capabilities rather
 * than beside the mail capability in each account's entry: a user has one theme
 * and three mailboxes, and publishing it per account would have a client
 * reconcile three copies of one object.
 *
 * The values here are a hint — the Session's `state` is a hash of account ids
 * and does not move when a theme changes, so Appearance/get stays the
 * authoritative read. What this buys is the first frame: the chrome painted in
 * the user's palette instead of the wrong one flashing while the first API call
 * is in flight.
 *
 * The vocabularies are published because Appearance/set refuses everything
 * outside them, and a closed vocabulary a client can only discover by being
 * refused is a client shipping a broken theme picker.
 */
final class AppearanceCapabilityTest extends JmapTestCase
{
    private SessionBuilder $sessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessions = self::getContainer()->get(SessionBuilder::class);
    }

    public function testTheCompactReadTracksTheUsersOwnAppearance(): void
    {
        $this->user->appearance->theme = Theme::Solar;
        $this->user->appearance->accent = '#0a1b2c';
        $this->em->flush();

        $appearance = $this->capability()['appearance'];

        self::assertSame('solar', $appearance['theme']);
        self::assertSame('#0a1b2c', $appearance['accent']);
        self::assertArrayHasKey('layout', $appearance);
        self::assertArrayHasKey('density', $appearance);
    }

    /** Per user: it is not repeated under every connected account. */
    public function testItIsNotPublishedPerAccount(): void
    {
        $this->secondAccount();

        $session = $this->sessions->build($this->user);

        foreach ($session['accounts'] as $account) {
            self::assertArrayNotHasKey(Capability::APPEARANCE, $account['accountCapabilities']);
        }
    }

    public function testTheClosedVocabulariesArePublished(): void
    {
        $capability = $this->capability();

        self::assertSame(array_column(Theme::cases(), 'value'), $capability['themes']);
        self::assertSame(array_column(Layout::cases(), 'value'), $capability['layouts']);
        self::assertContains('comfortable', $capability['densities']);
        self::assertContains('preset', $capability['backgroundKinds']);
        self::assertContains('dunes', $capability['backgroundPresets']);
    }

    /**
     * The colourways are published even though `Appearance.logoStyle` is
     * read-only, and *because* it is: a settable vocabulary can be discovered
     * by being refused, a read-only one cannot be discovered at all. A client
     * mapping the thirty-two onto assets of its own needs the list to know
     * when it is holding a word it has nothing for.
     */
    public function testTheColourwaysArePublishedEvenThoughTheMarkIsReadOnly(): void
    {
        self::assertSame(array_column(LogoStyle::cases(), 'value'), $this->capability()['logoStyles']);
    }

    /**
     * The mark itself is NOT in the compact hint, and that is the decision
     * worth pinning rather than a property nobody got round to adding.
     *
     * The Session's `state` is a hash of account ids and does not move when an
     * appearance changes, so everything in this hint can be stale for as long
     * as a client keeps its Session. That is the right trade for a palette,
     * which is repainted the moment Appearance/get lands; it is the wrong
     * trade for the mark, which the client this was added for turns into a
     * launcher icon — something committed to outside the app, where being
     * wrong is not one frame but somebody's home screen. The vocabulary above
     * is early because it cannot go stale. This value is not, because it can.
     */
    public function testTheMarkIsNotInTheHintBecauseTheHintCanBeStale(): void
    {
        self::assertArrayNotHasKey('logoStyle', $this->capability()['appearance']);
    }

    /**
     * The clamp bounds come off the setters' own constants. Two copies of these
     * numbers is how a phone offers a blur of 80, reports 80, and the server
     * stores 60.
     */
    public function testTheClampRangesComeFromTheSettersThemselves(): void
    {
        self::assertSame(Appearance::RANGE_PANE_BLUR, $this->capability()['ranges']['paneBlur']);
        self::assertSame(Appearance::RANGE_SCRIM_ALPHA, $this->capability()['ranges']['scrimAlpha']);
    }

    /** So a client's sliders can sit where the web pane's do. */
    public function testEachLayoutPublishesTheKnobsItSeeds(): void
    {
        self::assertSame(Layout::Boxed->defaults(), $this->capability()['layoutDefaults']['boxed']);
    }

    /**
     * A capability nothing can serve is worse than none, because a client
     * discovers it by calling. Methods reach the registry by DI tag, so a class
     * that exists and is fully tested is still unreachable if autoconfiguration
     * stops applying to its directory — `Method/Settings/` is new here.
     */
    public function testTheAdvertisedMethodsAreRegisteredAndDeclarable(): void
    {
        $registry = self::getContainer()->get(MethodRegistry::class);

        foreach (['Appearance/get', 'Appearance/set'] as $name) {
            self::assertNotNull($registry->get($name), sprintf('"%s" is advertised but not registered', $name));
        }

        self::assertContains(Capability::APPEARANCE, Capability::SUPPORTED);
    }

    /**
     * @return array<string,mixed>
     */
    private function capability(): array
    {
        return $this->sessions->build($this->user)['capabilities'][Capability::APPEARANCE];
    }
}
