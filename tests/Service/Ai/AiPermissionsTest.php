<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\Ai\AiFeature;
use App\Entity\Ai\AiSettings;
use App\Entity\User\User;
use App\Repository\Ai\AiSettingsRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\AiCallRecorder;
use App\Service\Ai\AiPermissions;
use App\Service\Ai\OllamaClient;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * The AND, and which half wins when they disagree.
 *
 * There are two switches per AI feature and they are not peers. The
 * installation's — an administrator's, in AiSettings — is a CEILING; a person's
 * is a floor underneath it. The one assertion this file exists for is that no
 * user value can get past a `false` from the administrator, because the day
 * that stops being true is the day a preference page starts switching on
 * features an operator deliberately switched off.
 *
 * The second is that a MISSING user is a refusal rather than a default. The
 * callers that can arrive without one are workers running unattended over
 * somebody's mail — an envelope serialised by a previous build, a mailbox
 * deleted mid-run — and "we could not work out whose mail this is" is never a
 * reason to send it to a model.
 *
 * Everything happens inside one transaction that is rolled back, so the shared
 * test database is left as it was found. The ai_settings row is deleted first
 * because it is a singleton the whole installation shares.
 */
final class AiPermissionsTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private AiSettingsRepository $settings;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->settings   = $container->get(AiSettingsRepository::class);

        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM ai_settings');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * THE HEADLINE. An administrator's no beats everybody's yes.
     *
     * The user's own preference defaults to allowing every feature, so this is
     * the natural state of a mailbox on an installation that has switched the
     * feature off — and it must still be a refusal.
     */
    public function testAnAdministratorsRefusalIsNotOverridableByAUser(): void
    {
        $permissions = $this->permissions(search: false, categorise: false, writingHelp: false, summary: false);
        $user        = new User();

        foreach (AiFeature::cases() as $feature) {
            self::assertTrue($user->aiPreferences->allows($feature), 'the fixture is not a permissive user');
            self::assertFalse($permissions->allows($user, $feature), $feature->value . ' got past the ceiling');
        }
    }

    /** With both switches open, the answer is yes — which is the point of having them. */
    public function testBothSwitchesOpenIsAYes(): void
    {
        $permissions = $this->permissions();

        foreach (AiFeature::cases() as $feature) {
            self::assertTrue($permissions->allows(new User(), $feature));
        }
    }

    /** And a person's own no is honoured on an installation that allows it. */
    public function testAPersonsOwnRefusalIsHonoured(): void
    {
        $permissions = $this->permissions();
        $user        = new User();

        $user->aiPreferences->searchOff      = true;
        $user->aiPreferences->categoriseOff  = true;
        $user->aiPreferences->writingHelpOff = true;
        $user->aiPreferences->summaryOff     = true;

        foreach (AiFeature::cases() as $feature) {
            self::assertFalse($permissions->allows($user, $feature));
        }
    }

    /** One feature off does not take the others with it. */
    public function testTheThreeFeaturesAreDecidedSeparately(): void
    {
        $permissions = $this->permissions();
        $user        = new User();

        $user->aiPreferences->searchOff = true;

        self::assertFalse($permissions->allows($user, AiFeature::Search));
        self::assertTrue($permissions->allows($user, AiFeature::Categorise));
        self::assertTrue($permissions->allows($user, AiFeature::WritingHelp));
    }

    /**
     * No user, no consent — for every feature, however open the installation
     * is.
     */
    public function testNoUserIsARefusalAndNotADefault(): void
    {
        $permissions = $this->permissions();

        foreach (AiFeature::cases() as $feature) {
            self::assertFalse($permissions->allows(null, $feature), $feature->value . ' ran without a user');
        }
    }

    /**
     * An installation that is switched on but half-configured is still a no.
     *
     * A host with no model named for the job answers nothing, and this is the
     * ceiling being AiSettings::enabledFor() rather than a re-implementation of
     * it: three conditions, not one, and two of them are the ones people
     * actually get wrong.
     */
    public function testAHalfConfiguredInstallationIsStillARefusal(): void
    {
        $settings = $this->settings->current() ?? new AiSettings();

        $settings->isEnabled             = true;
        $settings->baseUrl               = 'http://10.0.0.5:11434';
        $settings->searchEnabled         = true;
        $settings->categorisationEnabled = true;
        $settings->writingHelpEnabled    = true;
        // No chatModel and no embeddingModel — the commonest half-finished
        // state, and the one that produces a feature which appears to exist.

        $this->em->persist($settings);
        $this->em->flush();

        $permissions = new AiPermissions($this->assistant());

        foreach (AiFeature::cases() as $feature) {
            self::assertFalse($permissions->allows(new User(), $feature));
        }

        self::assertFalse($permissions->anyAdminEnabled());
    }

    /**
     * anyAdminEnabled() asks the ADMINISTRATOR'S switches only.
     *
     * It decides whether the settings section exists at all, so folding the
     * user's own answers into it would take the page away from anybody who had
     * switched all three of theirs off — a setting that can be turned off and
     * never on again.
     */
    public function testTheSectionStaysVisibleForSomebodyWhoTurnedEverythingOff(): void
    {
        $permissions = $this->permissions();
        $user        = new User();

        $user->aiPreferences->searchOff      = true;
        $user->aiPreferences->categoriseOff  = true;
        $user->aiPreferences->writingHelpOff = true;
        $user->aiPreferences->summaryOff     = true;

        self::assertTrue($permissions->anyAdminEnabled());
    }

    /** One feature is enough for the section to be worth showing. */
    public function testOneAdminFeatureIsEnoughForTheSectionToExist(): void
    {
        self::assertTrue($this->permissions(search: false, categorise: false, summary: false)->anyAdminEnabled());
        self::assertFalse($this->permissions(search: false, categorise: false, writingHelp: false, summary: false)->anyAdminEnabled());
    }

    private function permissions(
        bool $search = true,
        bool $categorise = true,
        bool $writingHelp = true,
        bool $summary = true,
    ): AiPermissions {
        // Reused rather than inserted afresh, because ai_settings is a
        // singleton held by a unique index — a second insert inside one test is
        // a constraint violation, not a second installation.
        $settings = $this->settings->current() ?? new AiSettings();

        $settings->isEnabled             = true;
        $settings->baseUrl               = 'http://10.0.0.5:11434';
        $settings->chatModel             = 'llama3.1:8b';
        $settings->embeddingModel        = 'nomic-embed-text';
        $settings->searchEnabled         = $search;
        $settings->categorisationEnabled = $categorise;
        $settings->writingHelpEnabled    = $writingHelp;
        $settings->summaryEnabled        = $summary;

        $this->em->persist($settings);
        $this->em->flush();

        return new AiPermissions($this->assistant());
    }

    /**
     * The real AiAssistant over an HTTP client nothing here ever reaches.
     *
     * Nothing in this file makes a call — every assertion is about a decision
     * taken before one would be — so the client is a MockHttpClient with no
     * responses queued, which fails loudly rather than quietly if that ever
     * stops being true.
     */
    private function assistant(): AiAssistant
    {
        return new AiAssistant(
            $this->settings,
            new OllamaClient(new MockHttpClient([]), new NullLogger()),
            new AiCallRecorder($this->createStub(Connection::class), new NullLogger()),
            new NullLogger(),
        );
    }
}
