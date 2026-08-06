<?php

declare(strict_types=1);

namespace App\Tests\Form\Admin;

use App\Entity\Push\FcmConfig;
use App\Form\Admin\FcmConfigType;
use App\Tests\Jmap\Push\FirebaseFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;

/**
 * The paste is the only place a wrong Firebase setup can still be caught.
 *
 * Everything after this point believes what is stored: the sender signs with
 * the key it is given, the Session publishes the client values it is given, and
 * neither can tell that they describe different projects. So the two refusals
 * here are the last honest error message anyone gets.
 *
 * **The mismatch is the important one.** A service-account key for project A
 * beside a google-services.json for project B produces an install where every
 * screen says push is live: the app registers, the token is stored, the send
 * goes out and Firebase answers SENDER_ID_MISMATCH into a log file. The user's
 * symptom is "notifications don't work", months from anyone looking at this.
 *
 * **The wrong file is the one people actually paste.** The Firebase console
 * offers the web app config, an OAuth client, the service-account key and
 * google-services.json, and all four are valid JSON — so the message has to
 * name what is missing rather than say "invalid", which is the difference
 * between a two-minute fix and a support thread.
 */
final class FcmConfigTypeTest extends KernelTestCase
{
    private FormFactoryInterface $forms;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->forms = self::getContainer()->get('form.factory');
    }

    public function testAMatchingPairIsAccepted(): void
    {
        $form = $this->submit([
            'serviceAccountJson' => FirebaseFixture::serviceAccountJson('plmail-test'),
            'googleServicesJson' => FirebaseFixture::googleServicesJson('plmail-test'),
            'isEnabled'          => '1',
        ]);

        self::assertTrue($form->isValid(), (string) $form->getErrors(true, false));
    }

    public function testTwoFilesFromDifferentProjectsAreRefusedSayingWhichIsWhich(): void
    {
        $form = $this->submit([
            'serviceAccountJson' => FirebaseFixture::serviceAccountJson('plmail-sending'),
            'googleServicesJson' => FirebaseFixture::googleServicesJson('plmail-app'),
            'isEnabled'          => '1',
        ]);

        $errors = (string) $form->getErrors(true, false);

        self::assertFalse($form->isValid());
        self::assertStringContainsString('plmail-sending', $errors);
        self::assertStringContainsString('plmail-app', $errors);
    }

    public function testAFileThatIsNotAGoogleServicesJsonIsRefusedNamingWhatItLacks(): void
    {
        $form = $this->submit([
            'serviceAccountJson' => FirebaseFixture::serviceAccountJson(),
            // The web app config — the neighbouring download in the console.
            'googleServicesJson' => (string) json_encode(['apiKey' => 'AIza…', 'appId' => '1:1:web:1']),
        ]);

        $errors = (string) $form->getErrors(true, false);

        self::assertFalse($form->isValid());
        self::assertStringContainsString('project_info', $errors);
        self::assertStringContainsString('client', $errors);
    }

    public function testAnOAuthClientPastedAsTheServiceAccountKeyIsRefusedNamingTheTypeItNeeds(): void
    {
        $form = $this->submit([
            'serviceAccountJson' => (string) json_encode(['web' => ['client_id' => '1.apps.googleusercontent.com']]),
        ]);

        self::assertFalse($form->isValid());
        self::assertStringContainsString('service_account', (string) $form->getErrors(true, false));
    }

    /** Enabling with nothing behind it would put `fcm: true` in every Session. */
    public function testTheToggleCannotBeTurnedOnWithNoCredentials(): void
    {
        $form = $this->submit(['isEnabled' => '1']);

        self::assertFalse($form->isValid());
    }

    /**
     * @param array<string,string> $values
     */
    private function submit(array $values): FormInterface
    {
        // CSRF off for the form under test only: the token is browser
        // machinery and StateChangingPostsNeedCsrfTest already asserts it
        // project-wide, whereas here it would just be a fixture nobody reads.
        $form = $this->forms->create(FcmConfigType::class, new FcmConfig(), ['csrf_protection' => false]);

        $form->submit([
            'serviceAccountJson' => '',
            'googleServicesJson' => '',
            ...$values,
        ]);

        return $form;
    }
}
