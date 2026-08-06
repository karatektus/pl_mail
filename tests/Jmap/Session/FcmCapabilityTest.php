<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Session;

use App\Entity\Push\FcmConfig;
use App\Jmap\Protocol\Capability;
use App\Jmap\Session\SessionBuilder;
use App\Tests\Jmap\JmapTestCase;
use App\Tests\Jmap\Push\FirebaseFixture;

/**
 * The Session is where a client decides whether Firebase is on the table at
 * all, so what it says has to be exactly what PushSubscription/set will do.
 *
 * Two shapes, and the difference between them is deliberate.
 *
 * **`fcm` is always present.** A client has to be able to tell "this server does
 * not do FCM" from "this server predates FCM", and the sensible reaction to
 * each is the opposite one — fall back to UnifiedPush, or ask again after an
 * upgrade. A key that appears only when true collapses the two.
 *
 * **`fcmConfig` is absent when it is empty, not null.** It carries the inputs to
 * FirebaseOptions.Builder, published because the plMail Android app ships as one
 * APK from the Play Store while every install has its own Firebase project — so
 * the app cannot bake in a google-services.json. A null-valued object invites a
 * client to read `.projectId` off it and get null; absence cannot be
 * dereferenced.
 *
 * The half-configured case is here because it is the one an admin actually
 * produces: a service-account key saved, the google-services.json not yet
 * fetched. The server could send to devices that can never register, which
 * looks from every side like working push that silently does nothing.
 */
final class FcmCapabilityTest extends JmapTestCase
{
    private SessionBuilder $sessions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sessions = self::getContainer()->get(SessionBuilder::class);
    }

    public function testAnInstallWithNoFirebaseProjectSaysSoAndOffersNoConfig(): void
    {
        $push = $this->push();

        self::assertFalse($push['fcm']);
        self::assertArrayNotHasKey('fcmConfig', $push, 'an absent object cannot be dereferenced; a null one can');
        self::assertArrayHasKey('vapidPublicKey', $push, 'Web Push is unaffected by any of this');
    }

    public function testAConfiguredAndEnabledProjectPublishesTheFirebaseOptionsInputs(): void
    {
        $this->configure(enabled: true);

        $push = $this->push();

        self::assertTrue($push['fcm']);
        self::assertSame([
            'projectId'     => 'plmail-test',
            'applicationId' => '1:1234567890:android:0123456789abcdef',
            'apiKey'        => 'AIzaSyTestKeyForPlMail',
            'senderId'      => '1234567890',
        ], $push['fcmConfig']);
    }

    /** Switched off is a decision, and it must read exactly like unconfigured. */
    public function testCredentialsOnFileWithTheToggleOffAdvertiseNothing(): void
    {
        $this->configure(enabled: false);

        $push = $this->push();

        self::assertFalse($push['fcm']);
        self::assertArrayNotHasKey('fcmConfig', $push);
    }

    /**
     * The half-configured install. A client told `fcm: true` here would register
     * against a project it has no way to initialise for.
     */
    public function testAServiceAccountWithNoClientConfigIsNotAdvertised(): void
    {
        $config = new FcmConfig();
        $config->useCredentials(FirebaseFixture::serviceAccountJson(), null);
        $config->isEnabled = true;
        $this->em->persist($config);
        $this->em->flush();

        self::assertFalse($this->push()['fcm']);
    }

    /**
     * @return array<string,mixed>
     */
    private function push(): array
    {
        return $this->sessions->build($this->user)['capabilities'][Capability::PUSH];
    }

    private function configure(bool $enabled): void
    {
        $config = new FcmConfig();
        $config->useCredentials(FirebaseFixture::serviceAccountJson(), FirebaseFixture::googleServicesJson());
        $config->isEnabled = $enabled;

        $this->em->persist($config);
        $this->em->flush();
    }
}
