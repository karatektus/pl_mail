<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Settings;

use App\Domain\Enum\Theme\Density;
use App\Domain\Enum\Theme\Layout;
use App\Domain\Enum\Theme\LogoStyle;
use App\Domain\Enum\Theme\Theme;
use App\Entity\Embeddable\Appearance;
use App\Entity\User\User;
use App\Jmap\Mapper\AppearanceMapper;
use App\Jmap\Method\Settings\AppearanceGetMethod;
use App\Jmap\Method\Settings\AppearanceSetMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Jmap\Protocol\JmapContext;
use App\Tests\Jmap\JmapTestCase;

/**
 * Appearance/set writes the theme back, and refuses what it cannot store.
 *
 * The refusals are most of this class, and they are here because
 * `Appearance`'s setters are deliberately forgiving: an unknown theme keeps the
 * old one, a malformed hex resets the accent to plMail's default, and an
 * out-of-range slider is pulled to the nearest end. That is right for the web
 * pane — a closed form that cannot send anything else — and wrong for a phone,
 * which would be told it succeeded and then show the old theme with nothing
 * anywhere saying why. The Mailbox.color precedent: a closed vocabulary that
 * accepts what it cannot store is worse than no vocabulary at all.
 *
 * Clamping stays, because a slider is a continuum and 1.4 is a sloppy client
 * rather than a client meaning something unrepresentable — but it is reported
 * in the `updated` map (RFC 8620 §5.3) rather than applied behind the client's
 * back.
 */
final class AppearanceSetMethodTest extends JmapTestCase
{
    private AppearanceSetMethod $method;
    private AppearanceGetMethod $get;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();
        $this->method = $container->get(AppearanceSetMethod::class);
        $this->get = $container->get(AppearanceGetMethod::class);
    }

    /**
     * Every settable property, written and read back. The round trip is the
     * point: a property that is accepted and then not stored is the exact
     * failure this surface exists to avoid.
     */
    public function testEveryPropertyRoundTrips(): void
    {
        $patch = [
            'theme' => 'nord',
            'layout' => 'boxed',
            'accent' => '#0a1b2c',
            'paneAlpha' => 0.42,
            'paneBlur' => 18,
            'radius' => 1.25,
            'density' => 'compact',
            'backgroundKind' => 'preset',
            'backgroundPreset' => 'harbour',
            'backgroundSolid' => '#334455',
            'scrimAlpha' => 0.3,
            'inkColor' => '#111111',
            'inkMuted' => '#222222',
            'inkFaint' => '#333333',
            'mainTint' => '#444444',
            'mainAlpha' => 0.55,
        ];

        $result = $this->update($patch);

        self::assertSame([], (array) $result['notUpdated']);

        $object = $this->read();

        foreach ($patch as $property => $value) {
            self::assertSame($value, $object[$property], sprintf('"%s" did not round trip', $property));
        }
    }

    /** Nulling the optional properties is a real value, not a missing one. */
    public function testTheOptionalColoursClearToNull(): void
    {
        $this->update(['inkColor' => '#111111', 'backgroundPreset' => 'pine', 'mainAlpha' => 0.5]);
        $this->update(['inkColor' => null, 'backgroundPreset' => null, 'mainAlpha' => null]);

        $object = $this->read();

        self::assertNull($object['inkColor']);
        self::assertNull($object['backgroundPreset']);
        self::assertNull($object['mainAlpha']);
    }

    public function testAnUnknownThemeIsRefusedNamingTheAcceptedValues(): void
    {
        $error = $this->updateError(['theme' => 'midnight']);

        self::assertSame('invalidProperties', $error['type']);
        self::assertStringContainsString('midnight', $error['description']);

        foreach (array_column(Theme::cases(), 'value') as $accepted) {
            self::assertStringContainsString($accepted, $error['description']);
        }

        self::assertSame(Theme::Paper->value, $this->read()['theme'], 'the refused value must not have been applied');
    }

    public function testAnUnknownDensityIsRefusedNamingTheAcceptedValues(): void
    {
        $error = $this->updateError(['density' => 'roomy']);

        self::assertSame('invalidProperties', $error['type']);
        self::assertStringContainsString('roomy', $error['description']);

        foreach (array_column(Density::cases(), 'value') as $accepted) {
            self::assertStringContainsString($accepted, $error['description']);
        }
    }

    /**
     * The setter's fallback is DEFAULT_ACCENT, which is a value the client
     * never asked for — accepting the write would repaint the account clay and
     * report success.
     */
    public function testAMalformedColourIsRefusedRatherThanReset(): void
    {
        $this->update(['accent' => '#0a1b2c']);

        $error = $this->updateError(['accent' => 'rebeccapurple']);

        self::assertSame('invalidProperties', $error['type']);
        self::assertSame('#0a1b2c', $this->read()['accent']);
        self::assertNotSame(Appearance::DEFAULT_ACCENT, $this->read()['accent']);
    }

    public function testAnUnknownPropertyIsRefusedNamingWhatIsAccepted(): void
    {
        $error = $this->updateError(['wallpaper' => 'nice']);

        self::assertSame('invalidProperties', $error['type']);
        self::assertStringContainsString('wallpaper', $error['description']);
        self::assertStringContainsString('scrimAlpha', $error['description']);
    }

    /**
     * Seventeen properties land on one embeddable, so a patch that is refused
     * half way through would flush the half that had already been written —
     * a theme the client was told it did not get.
     */
    public function testARefusedPatchAppliesNoneOfItself(): void
    {
        $this->updateError(['theme' => 'dark', 'density' => 'roomy']);

        self::assertSame(Theme::Paper->value, $this->read()['theme']);
    }

    /**
     * Out of range is clamped, and the clamp is reported. A client that reads
     * the `updated` map sees the number it will actually get.
     *
     * paneAlpha is the awkward half: 1.4 clamps to 1.0, which is the value it
     * already held. Reporting only what *changed* would answer null here and
     * leave the client believing 1.4 stuck.
     */
    public function testAClampedValueIsReportedRatherThanSilentlyAdjusted(): void
    {
        $result = $this->update(['paneAlpha' => 1.4, 'paneBlur' => 900]);

        $changes = $result['updated'][AppearanceMapper::SINGLETON_ID];

        self::assertSame(Appearance::RANGE_PANE_ALPHA['max'], $changes['paneAlpha']);
        self::assertSame(Appearance::RANGE_PANE_BLUR['max'], $changes['paneBlur']);
        self::assertSame(Appearance::RANGE_PANE_BLUR['max'], $this->read()['paneBlur']);
    }

    /** Nothing beyond the patch changed, so there is nothing to report. */
    public function testAnExactlyAppliedPatchReportsNoServerSideChanges(): void
    {
        $result = $this->update(['theme' => 'dark']);

        self::assertNull($result['updated'][AppearanceMapper::SINGLETON_ID]);
    }

    /**
     * Picking a layout means its knob preset — that is what the enum says a
     * layout *is*, and what the web pane does client-side. A client that sent
     * only `layout` would otherwise get the new structure wearing the old
     * layout's numbers, which looks like nothing happened.
     */
    public function testPickingALayoutSeedsItsKnobsAndSaysSo(): void
    {
        $result = $this->update(['layout' => 'boxed']);

        $object = $this->read();
        $defaults = Layout::Boxed->defaults();

        self::assertSame($defaults['paneAlpha'], $object['paneAlpha']);
        self::assertSame($defaults['paneBlur'], $object['paneBlur']);
        self::assertSame($defaults['radius'], $object['radius']);
        self::assertArrayHasKey('paneAlpha', (array) $result['updated'][AppearanceMapper::SINGLETON_ID]);
    }

    /** An explicit knob in the same patch beats the preset it arrived with. */
    public function testAnExplicitKnobBeatsTheLayoutPreset(): void
    {
        $this->update(['layout' => 'boxed', 'paneAlpha' => 0.95]);

        self::assertSame(0.95, $this->read()['paneAlpha']);
    }

    /**
     * get → change one field → set is how a client is supposed to work, so the
     * server-set properties it read back have to be accepted unchanged. They
     * are still not settable: a *different* value is refused.
     */
    public function testTheObjectFromGetCanBeSentStraightBack(): void
    {
        $object = $this->read();
        $object['theme'] = 'dusk';

        $result = $this->update($object);

        self::assertSame([], (array) $result['notUpdated']);
        self::assertSame('dusk', $this->read()['theme']);

        $error = $this->updateError(['backgroundFile' => 'somebody-elses.jpg']);

        self::assertSame('invalidProperties', $error['type']);
        self::assertStringContainsString('backgroundFile', $error['description']);
    }

    /**
     * The mark is read-only, and asking for one is refused rather than
     * accepted-and-ignored — the same bargain every other closed vocabulary
     * here strikes. A client told "ok" and then shown the old mark forever has
     * nothing to debug against.
     */
    public function testTheLogoStyleIsNotSettable(): void
    {
        $error = $this->updateError(['logoStyle' => 'tricolore']);

        self::assertSame('invalidProperties', $error['type']);
        self::assertStringContainsString('logoStyle', $error['description']);
        self::assertSame(LogoStyle::DEFAULT->value, $this->read()['logoStyle']);
    }

    /**
     * The one that would have bitten somebody. An echo of `logoStyle` is
     * accepted, because get → edit → set has to work — but it must not be
     * WRITTEN, because the value reported is the effective mark and the column
     * behind it may be a different, deliberately unlinked choice. Assigning
     * the echo would replace that choice with a copy of the theme's name, from
     * an edit the user never made to a field no client can see.
     */
    public function testEchoingTheEffectiveMarkBackDoesNotOverwriteAnUnlinkedChoice(): void
    {
        $this->user->appearance->theme = Theme::Ocean;
        $this->user->appearance->logoLinked = false;
        $this->user->appearance->logoStyle = LogoStyle::Tricolore;
        $this->em->flush();

        $object = $this->read();

        self::assertSame('tricolore', $object['logoStyle'], 'unlinked: the stored choice is what is reported');

        // The whole object straight back, one unrelated field changed — the
        // flow the echo allowance exists for.
        $object['paneBlur'] = 12;

        $result = $this->update($object);

        self::assertSame([], (array) $result['notUpdated']);
        self::assertSame(LogoStyle::Tricolore, $this->user->appearance->logoStyle);
        self::assertFalse($this->user->appearance->logoLinked);
        self::assertSame('tricolore', $this->read()['logoStyle']);
    }

    /**
     * Changing the theme changes the mark, and the `updated` map says so. This
     * is the only way to move it over the wire, and RFC 8620 §5.3 asks for
     * exactly this: a property the server changed beyond what was asked for.
     */
    public function testChangingTheThemeMovesTheMarkAndReportsIt(): void
    {
        $result = $this->update(['theme' => 'petrol-copper']);

        $changes = (array) $result['updated'][AppearanceMapper::SINGLETON_ID];

        self::assertSame('petrol-copper', $changes['logoStyle']);
        self::assertSame('petrol-copper', $this->read()['logoStyle']);
    }

    public function testCreateAndDestroyAreRefusedAsASingleton(): void
    {
        $result = $this->method->handle(
            ['create' => ['c1' => ['theme' => 'dark']], 'destroy' => [AppearanceMapper::SINGLETON_ID]],
            $this->context(),
        );

        self::assertSame('singleton', $result['notCreated']['c1']['type']);
        self::assertSame('singleton', $result['notDestroyed'][AppearanceMapper::SINGLETON_ID]['type']);
        self::assertSame(Theme::Paper->value, $this->read()['theme']);
    }

    public function testAnyOtherIdIsNotFound(): void
    {
        $result = $this->method->handle(['update' => ['1' => ['theme' => 'dark']]], $this->context());

        self::assertSame('notFound', $result['notUpdated']['1']['type']);
    }

    /**
     * Two devices editing the same singleton: the second one writes over the
     * first unless it says which state it was looking at.
     */
    public function testAStaleIfInStateIsRefused(): void
    {
        $this->expectException(MethodException::class);

        $this->method->handle(
            ['ifInState' => 'not-the-current-state', 'update' => [AppearanceMapper::SINGLETON_ID => ['theme' => 'dark']]],
            $this->context(),
        );
    }

    /**
     * The object is resolved off the authenticated user and nowhere else, so
     * there is no id on the wire that could name somebody else's theme. This
     * asserts it stays that way.
     */
    public function testSettingOneUsersAppearanceLeavesAnotherAlone(): void
    {
        $stranger = $this->otherUser();

        $this->method->handle(
            ['update' => [AppearanceMapper::SINGLETON_ID => ['theme' => 'dark', 'accent' => '#0a1b2c']]],
            $this->context(),
        );
        $this->em->flush();

        self::assertSame(Theme::Dark, $this->user->appearance->theme);
        self::assertSame(Theme::Paper, $stranger->appearance->theme);
        self::assertSame(Appearance::DEFAULT_ACCENT, $stranger->appearance->accent);

        $result = $this->method->handle(
            ['update' => [AppearanceMapper::SINGLETON_ID => ['theme' => 'nord']]],
            new JmapContext($stranger),
        );
        $this->em->flush();

        self::assertSame([], (array) $result['notUpdated']);
        self::assertSame(Theme::Nord, $stranger->appearance->theme);
        self::assertSame(Theme::Dark, $this->user->appearance->theme, 'the stranger wrote over the fixture user');
    }

    private function otherUser(): User
    {
        $user = new User();
        $user->email = 'jmap-stranger-'.uniqid('', true).'@example.test';
        $user->nameFirst = 'Jmap';
        $user->nameLast = 'Stranger';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    /**
     * @param array<string,mixed> $patch
     *
     * @return array<string,mixed>
     */
    private function update(array $patch): array
    {
        return $this->method->handle(
            ['update' => [AppearanceMapper::SINGLETON_ID => $patch]],
            $this->context(),
        );
    }

    /**
     * @param array<string,mixed> $patch
     *
     * @return array<string,mixed> the SetError the patch was refused with
     */
    private function updateError(array $patch): array
    {
        $result = $this->update($patch);

        self::assertArrayHasKey(
            AppearanceMapper::SINGLETON_ID,
            (array) $result['notUpdated'],
            'the patch was expected to be refused',
        );

        return $result['notUpdated'][AppearanceMapper::SINGLETON_ID];
    }

    /**
     * @return array<string,mixed>
     */
    private function read(): array
    {
        return $this->get->handle([], $this->context())['list'][0];
    }
}
