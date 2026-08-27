<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Ai\ReplyContext;
use App\Entity\Ai\AiFeature;
use App\Entity\Ai\AiSettings;
use App\Entity\Embeddable\AiPreferences;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The three writes behind Settings → Assistant: who may make them, what they
 * store, and what the page refuses.
 *
 * THE ONE THAT MATTERS MOST is the ceiling: a POST asking to switch ON a
 * feature the administrator has switched off is refused and writes nothing. The
 * rendered form is not the guarantee — an administrator can flip that switch
 * while somebody has the page open — so the endpoint has to hold it.
 *
 * Everything is read back through a cleared EntityManager, so the assertions
 * are about what the DATABASE holds rather than what the last request left in
 * memory.
 *
 * The install-wide AI settings are a singleton row the whole test database
 * shares. It is written by signedIn() rather than by setUp, because half these
 * cases need a feature switched off at that level, and it is deleted again in
 * tearDown along with the user's own preferences — neither has a fixture of its
 * own, so both are put back rather than left for the next suite.
 */
final class AiPreferencesTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';
    private const string TOGGLE = '/settings/ai/toggle';
    private const string NOTES  = '/settings/ai/notes';
    private const string CONTEXT = '/settings/ai/context';

    protected function tearDown(): void
    {
        $container = static::getContainer();

        $container->get(Connection::class)->executeStatement('DELETE FROM ai_settings');

        if (null !== $user = $this->find()) {
            $user->aiPreferences->applyArray((new AiPreferences())->toArray());
            $container->get(EntityManagerInterface::class)->flush();
        }

        parent::tearDown();
    }

    public function testSwitchingAFeatureOffPersists(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::TOGGLE, [
            'key'     => AiFeature::Search->value,
            'enabled' => '0',
            '_token'  => $this->token($client),
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertTrue($this->stored()->searchOff);
    }

    public function testSwitchingAFeatureBackOnPersists(): void
    {
        [$client, $user] = $this->signedIn();

        $user->aiPreferences->searchOff = true;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('POST', self::TOGGLE, [
            'key'     => AiFeature::Search->value,
            'enabled' => '1',
            '_token'  => $this->token($client),
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertFalse($this->stored()->searchOff);
    }

    /**
     * The fourth feature goes through the same door as the other three.
     *
     * Worth its own case rather than trusting the enum loop above: the toggle
     * writes through a statement-match in AiPreferencesController, and a case
     * missing an arm there is an UnhandledMatchError on the POST rather than on
     * the render — so the census test that counts rows would still pass while
     * the switch did nothing but 500.
     */
    public function testSwitchingSummariesOffPersists(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::TOGGLE, [
            'key'     => AiFeature::Summary->value,
            'enabled' => '0',
            '_token'  => $this->token($client),
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertTrue($this->stored()->summaryOff);
    }

    public function testSwitchingSummariesBackOnPersists(): void
    {
        [$client, $user] = $this->signedIn();

        $user->aiPreferences->summaryOff = true;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('POST', self::TOGGLE, [
            'key'     => AiFeature::Summary->value,
            'enabled' => '1',
            '_token'  => $this->token($client),
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertFalse($this->stored()->summaryOff);
    }

    /**
     * The ceiling, from the endpoint's side.
     *
     * A person cannot switch on for themselves something the installation has
     * switched off — and the refusal writes nothing, so a stale page cannot
     * leave a row that says yes to a feature nobody may use.
     */
    public function testTurningOnWhatTheAdministratorHasOffIsRefusedAndWritesNothing(): void
    {
        [$client, $user] = $this->signedIn(writingHelp: false);

        // The person is opted out as well, so a successful write would be
        // visible as a change rather than as a no-op.
        $user->aiPreferences->writingHelpOff = true;
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('POST', self::TOGGLE, [
            'key'     => AiFeature::WritingHelp->value,
            'enabled' => '1',
            '_token'  => $this->token($client),
        ]);

        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertTrue($this->stored()->writingHelpOff, 'a user switched on what the installation has off');
    }

    /**
     * Switching OFF a feature the administrator has off is still allowed.
     *
     * It writes a refusal, which no ceiling has any reason to prevent — and
     * refusing it would mean a person's stored answer depended on the order the
     * two switches were flipped in.
     */
    public function testTurningOffIsAllowedEvenWhenTheAdministratorHasItOff(): void
    {
        [$client] = $this->signedIn(writingHelp: false);

        $client->request('POST', self::TOGGLE, [
            'key'     => AiFeature::WritingHelp->value,
            'enabled' => '0',
            '_token'  => $this->token($client),
        ]);

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertTrue($this->stored()->writingHelpOff);
    }

    public function testAnUnknownFeatureIsA400AndWritesNothing(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::TOGGLE, [
            'key'     => 'no-such-feature',
            'enabled' => '0',
            '_token'  => $this->token($client),
        ]);

        self::assertSame(400, $client->getResponse()->getStatusCode());

        $stored = $this->stored();

        self::assertFalse($stored->searchOff);
        self::assertFalse($stored->categoriseOff);
        self::assertFalse($stored->writingHelpOff);
    }

    public function testATokenlessToggleIsRefusedAndWritesNothing(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::TOGGLE, ['key' => AiFeature::Search->value, 'enabled' => '0']);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertFalse($this->stored()->searchOff, 'a tokenless POST flipped a switch');
    }

    public function testAForgedTokenIsRefused(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::TOGGLE, [
            'key'     => AiFeature::Search->value,
            'enabled' => '0',
            '_token'  => 'nonsense',
        ]);

        self::assertSame(403, $client->getResponse()->getStatusCode());
        self::assertFalse($this->stored()->searchOff);
    }

    /** Signed out, there is nothing to configure and nothing to attack. */
    public function testItIsBehindTheLogin(): void
    {
        $client = static::createClient();
        $client->request('POST', self::TOGGLE, ['key' => AiFeature::Search->value, 'enabled' => '0']);

        self::assertContains(
            $client->getResponse()->getStatusCode(),
            [302, 401, 403],
            'the endpoint answered an anonymous POST',
        );
    }

    /** The notes are stored as typed. */
    public function testTheNotesAreStored(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::NOTES, [
            'aboutMe'      => 'I run a bicycle repair shop in Leipzig.',
            'systemPrompt' => 'Keep it to three sentences.',
            '_token'       => $this->formToken($client, self::NOTES),
        ]);

        self::assertResponseRedirects();

        $stored = $this->stored();

        self::assertSame('I run a bicycle repair shop in Leipzig.', $stored->aboutMe);
        self::assertSame('Keep it to three sentences.', $stored->systemPrompt);
    }

    /**
     * An over-long paste loses its tail rather than the whole of it.
     *
     * The browser's maxlength stops this on the page; the clamp is what stops
     * anything else, and it is the same number the template publishes — two
     * copies of a limit is how a form reports success while the server stores
     * something shorter.
     */
    public function testAnOverlongNoteIsTruncatedRatherThanRefused(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::NOTES, [
            'aboutMe'      => str_repeat('a', AiPreferences::MAX_ABOUT_ME + 250),
            'systemPrompt' => str_repeat('b', AiPreferences::MAX_SYSTEM_PROMPT + 250),
            '_token'       => $this->formToken($client, self::NOTES),
        ]);

        self::assertResponseRedirects();

        $stored = $this->stored();

        self::assertSame(AiPreferences::MAX_ABOUT_ME, mb_strlen($stored->aboutMe));
        self::assertSame(AiPreferences::MAX_SYSTEM_PROMPT, mb_strlen($stored->systemPrompt));
    }

    public function testTheContextDepthIsStored(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::CONTEXT, [
            'depth'  => ReplyContext::Thread->value,
            '_token' => $this->formToken($client, self::CONTEXT),
        ]);

        self::assertResponseRedirects();
        self::assertSame(ReplyContext::Thread, $this->stored()->replyContext);
    }

    /** A depth outside the enum is a bug in our own form, and says so. */
    public function testAnUnknownContextDepthIsRefusedAndWritesNothing(): void
    {
        [$client] = $this->signedIn();

        $client->request('POST', self::CONTEXT, [
            'depth'  => 'everything-i-have-ever-written',
            '_token' => $this->formToken($client, self::CONTEXT),
        ]);

        self::assertSame(404, $client->getResponse()->getStatusCode());
        self::assertSame(ReplyContext::Message, $this->stored()->replyContext);
    }

    /**
     * The page renders one row per AiFeature — a census, counted by the
     * `data-ai-feature` attribute rather than by translated text, so neither a
     * rename in the catalogue nor a missing translation can fail it.
     */
    public function testTheSectionRendersOneRowPerFeature(): void
    {
        [$client] = $this->signedIn();

        $crawler = $client->request('GET', '/settings?section=ai');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertCount(count(AiFeature::cases()), $crawler->filter('[data-ai-feature]'));

        foreach (AiFeature::cases() as $feature) {
            self::assertCount(
                1,
                $crawler->filter(sprintf('[data-ai-feature="%s"]', $feature->value)),
                sprintf('no settings row for the "%s" feature', $feature->value),
            );
        }
    }

    /**
     * A feature the ADMINISTRATOR has off renders as a row with no control.
     *
     * The row stays, because somebody looking for the feature would otherwise
     * conclude plMail does not have it — but a switch that silently does
     * nothing is worse than no switch, so there is a sentence instead.
     */
    public function testAFeatureTheAdministratorHasOffRendersWithoutAControl(): void
    {
        [$client] = $this->signedIn(writingHelp: false);

        $crawler = $client->request('GET', '/settings?section=ai');

        $row = $crawler->filter(sprintf('[data-ai-feature="%s"]', AiFeature::WritingHelp->value));

        self::assertCount(1, $row);
        self::assertCount(1, $row->filter('[data-ai-feature-admin-off]'));
        self::assertCount(0, $row->filter('input[type=radio]'), 'a switch was rendered for a feature nobody may use');
    }

    /**
     * The preferences as the DATABASE now has them, not as the last request
     * left them in memory.
     */
    private function stored(): AiPreferences
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();

        $user = $this->find();

        self::assertInstanceOf(User::class, $user);

        return $user->aiPreferences;
    }

    /**
     * A token minted for this session, read off the page that renders the
     * switches — which is also the only place the real one comes from.
     */
    private function token(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/settings?section=ai');

        return (string) $crawler
            ->filter('[data-settings--toggle-token-value]')
            ->last()
            ->attr('data-settings--toggle-token-value');
    }

    /**
     * The same, for the two ordinary forms, whose tokens are hidden inputs.
     *
     * Found by the form's own action rather than by position, so adding a
     * fourth card above them does not silently start reading the wrong token.
     */
    private function formToken(KernelBrowser $client, string $action): string
    {
        $crawler = $client->request('GET', '/settings?section=ai');

        return (string) $crawler
            ->filter(sprintf('form[action="%s"] input[name="_token"]', $action))
            ->first()
            ->attr('value');
    }

    /**
     * Signed in, on an installation with the AI switched on.
     *
     * The install-wide row is written here rather than in setUp because half
     * these cases need a feature switched OFF at that level, and that is the
     * whole subject of two of them.
     *
     * @return array{KernelBrowser, User}
     */
    private function signedIn(bool $writingHelp = true, bool $summary = true): array
    {
        $client = static::createClient();

        $container = static::getContainer();
        $em        = $container->get(EntityManagerInterface::class);

        $container->get(Connection::class)->executeStatement('DELETE FROM ai_settings');
        $em->clear();

        $settings = new AiSettings();
        $settings->isEnabled             = true;
        $settings->baseUrl               = 'http://model-host.invalid:11434';
        $settings->chatModel             = 'llama3.1:8b';
        $settings->embeddingModel        = 'nomic-embed-text';
        $settings->searchEnabled         = true;
        $settings->categorisationEnabled = true;
        $settings->writingHelpEnabled    = $writingHelp;
        $settings->summaryEnabled        = $summary;

        $em->persist($settings);
        $em->flush();

        $user = $this->find();

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);

        return [$client, $user];
    }

    private function find(): ?User
    {
        $user = static::getContainer()->get(UserRepository::class)
            ->findOneBy(['email' => self::ADMIN_EMAIL]);

        return $user instanceof User ? $user : null;
    }
}
