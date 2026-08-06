<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Settings;

use App\Domain\Enum\Theme\BackgroundKind;
use App\Domain\Enum\Theme\BackgroundPreset;
use App\Domain\Enum\Theme\Density;
use App\Domain\Enum\Theme\Layout;
use App\Domain\Enum\Theme\Theme;
use App\Jmap\Mapper\AppearanceMapper;
use App\Jmap\Method\Settings\AppearanceGetMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Tests\Jmap\JmapTestCase;

/**
 * Appearance/get publishes the theme the user chose in the browser, so a phone
 * can paint the same thing.
 *
 * Until this existed the embeddable was reachable only from
 * `Settings\AppearanceController`, which speaks HTML and Turbo: a client that
 * was not the web UI could not read the user's own theme at all, and the two
 * surfaces disagreed by construction.
 *
 * The claims are that it reports what is *stored* (not a default), that it is a
 * singleton — one object, id "singleton", no enumeration — and that it belongs
 * to the user rather than to a mail account, which is why an accountId is
 * refused instead of quietly ignored.
 */
final class AppearanceGetMethodTest extends JmapTestCase
{
    private AppearanceGetMethod $method;

    protected function setUp(): void
    {
        parent::setUp();

        $this->method = self::getContainer()->get(AppearanceGetMethod::class);
    }

    public function testItReturnsTheStoredAppearanceRatherThanTheDefaults(): void
    {
        $appearance = $this->user->appearance;
        $appearance->theme = Theme::Nord;
        $appearance->layout = Layout::Boxed;
        $appearance->accent = '#123456';
        $appearance->density = Density::Compact;
        $appearance->paneBlur = 24;
        $appearance->backgroundKind = BackgroundKind::Preset;
        $appearance->backgroundPreset = BackgroundPreset::Dunes;
        $appearance->inkMuted = '#abcdef';
        $this->em->flush();

        $object = $this->get()['list'][0];

        self::assertSame(AppearanceMapper::SINGLETON_ID, $object['id']);
        self::assertSame('nord', $object['theme']);
        self::assertSame('boxed', $object['layout']);
        self::assertSame('#123456', $object['accent']);
        self::assertSame('compact', $object['density']);
        self::assertSame(24, $object['paneBlur']);
        self::assertSame('preset', $object['backgroundKind']);
        self::assertSame('dunes', $object['backgroundPreset']);
        self::assertSame('#abcdef', $object['inkMuted']);
    }

    /** Every property the object claims to have is actually in the response. */
    public function testItReturnsTheWholeObject(): void
    {
        $object = $this->get()['list'][0];

        foreach (AppearanceMapper::PROPERTIES as $property) {
            self::assertArrayHasKey($property, $object);
        }
    }

    public function testTheSingletonIdResolvesAndAnyOtherIsNotFound(): void
    {
        $found = $this->get(['ids' => [AppearanceMapper::SINGLETON_ID]]);

        self::assertCount(1, $found['list']);
        self::assertSame([], $found['notFound']);

        $missing = $this->get(['ids' => ['42']]);

        self::assertSame([], $missing['list']);
        self::assertSame(['42'], $missing['notFound']);
    }

    /**
     * The state token moves when the object does — that is the whole contract
     * it carries, since Appearance is not in the change log and there is no
     * Appearance/changes to make it monotonic.
     */
    public function testTheStateTokenTracksTheObject(): void
    {
        $before = $this->get()['state'];

        $this->user->appearance->theme = Theme::Dusk;
        $this->em->flush();

        self::assertNotSame($before, $this->get()['state']);
    }

    public function testAskingForSomePropertiesStillReturnsTheId(): void
    {
        $object = $this->get(['properties' => ['theme']])['list'][0];

        self::assertSame(['id', 'theme'], array_keys($object));
    }

    /**
     * A property this server does not have is a client working from a different
     * idea of the object. Answering with the rest would hide that behind a
     * response that looks complete.
     */
    public function testAnUnknownPropertyIsRefusedNamingWhatIsAccepted(): void
    {
        $exception = $this->refusal(['properties' => ['wallpaper']]);

        self::assertSame('invalidArguments', $exception->errorType);
        self::assertStringContainsString('wallpaper', $exception->getMessage());
        self::assertStringContainsString('backgroundKind', $exception->getMessage());
    }

    /**
     * Appearance is the user's, not an account's. A client that sent an
     * accountId believes something false about the object it is reading, and
     * ignoring the argument would let it keep believing it.
     */
    public function testAnAccountIdIsRefusedRatherThanIgnored(): void
    {
        $exception = $this->refusal(['accountId' => $this->accountId()]);

        self::assertSame('invalidArguments', $exception->errorType);
        self::assertStringContainsString('per user', $exception->getMessage());
    }

    /**
     * @param array<string,mixed> $arguments
     *
     * @return array<string,mixed>
     */
    private function get(array $arguments = []): array
    {
        return $this->method->handle($arguments, $this->context());
    }

    /**
     * @param array<string,mixed> $arguments
     */
    private function refusal(array $arguments): MethodException
    {
        try {
            $this->get($arguments);
        } catch (MethodException $exception) {
            return $exception;
        }

        self::fail('the call was expected to be refused');
    }
}
