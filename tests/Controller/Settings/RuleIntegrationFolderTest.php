<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Repository\Rule\MailRuleRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The folder a "save attachments to" rule saves into, from the browser's end.
 *
 * Every other piece of this was already in place — the executor reads the
 * folder off the action, the message carries it, the handler prefers it over
 * the connection's default — and none of it was reachable, because the rule
 * editor never offered a folder to choose. So the two halves worth pinning are
 * the ones that were only ever exercised by hand: that a posted folder survives
 * sanitizeActions() and comes back out in the editor, and that the destination
 * picker opens with no attachment in hand.
 *
 * Written as requests rather than against the controllers, because the part
 * that used to be missing is not in either method body: the picker refused a
 * part-less browse from a shared helper, and a rule's folder is dropped by a
 * sanitiser the save method delegates to.
 */
final class RuleIntegrationFolderTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MailRuleRepository $rules;
    private User $user;

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAChosenFolderIsStoredAndComesBackToTheEditor(): void
    {
        $client = $this->signIn();
        $integration = $this->seedIntegration();

        $client->request('POST', '/settings/filters/save', [
            '_token' => $this->saveToken($client),
            'name' => 'Invoices to the cloud',
            'conditions' => json_encode(['subject' => 'invoice'], JSON_THROW_ON_ERROR),
            'actions' => json_encode([[
                'type' => 'saveToIntegration',
                'integrationId' => $integration->id,
                'folder' => 'Mail/Invoices',
            ]], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseIsSuccessful();

        $rules = $this->rules->findForUserOrdered($this->user);

        self::assertCount(1, $rules);
        self::assertSame('Mail/Invoices', $rules[0]->actions[0]['folder'] ?? null);

        // The other half of the round trip: the editor seeds itself from the
        // stored actions, so a folder that is stored but not published back is
        // a folder that silently resets the next time the rule is opened.
        $crawler = $client->request('GET', '/settings/filters/' . $rules[0]->id . '/edit');

        self::assertResponseIsSuccessful();

        // Decoded rather than matched as text: the attribute is JSON, so a
        // folder with a slash in it — which is most of them, ids being paths
        // for a file store — is escaped in the markup and a substring check
        // would fail on a value that is perfectly correct.
        $published = json_decode(
            (string) $crawler->filter('[data-rules--rule-builder-actions-value]')
                ->first()
                ->attr('data-rules--rule-builder-actions-value'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame('Mail/Invoices', $published[0]['folder'] ?? null);
    }

    /**
     * The root of a file store and the "Library" row of a photo library both
     * answer with an empty id, so an empty folder has to mean "no folder" — the
     * upload handler then falls back to the connection's own default, which is
     * what choosing the top level is asking for. Stored as an empty string
     * instead, it would be a destination the driver has to make sense of.
     */
    public function testAnEmptyFolderIsStoredAsNoFolderAtAll(): void
    {
        $client = $this->signIn();
        $integration = $this->seedIntegration();

        $client->request('POST', '/settings/filters/save', [
            '_token' => $this->saveToken($client),
            'name' => 'Straight to the default',
            'conditions' => '{}',
            'actions' => json_encode([[
                'type' => 'saveToIntegration',
                'integrationId' => $integration->id,
                'folder' => '   ',
            ]], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseIsSuccessful();

        $action = $this->rules->findForUserOrdered($this->user)[0]->actions[0];

        self::assertSame('saveToIntegration', $action['type']);
        self::assertArrayNotHasKey('folder', $action);
    }

    /**
     * The picker used to insist on an attachment: browsing for a destination
     * resolved a part before it would render anything, so the rule editor —
     * which has no attachment, only a folder to name for later — could not open
     * it at all.
     *
     * The connection here has no credentials, so the driver refuses before it
     * reaches the network and the chooser renders its error branch. That is the
     * case worth asserting anyway: the top level is always a valid answer, so
     * the choose control is offered even when the listing failed.
     */
    public function testTheDestinationPickerOpensWithNoAttachmentInHand(): void
    {
        $client = $this->signIn();
        $integration = $this->seedIntegration();

        $client->request('GET', '/integrations/' . $integration->id . '/browse?mode=destination');

        self::assertResponseIsSuccessful();

        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('data-controller="integration--destination-picker"', $html);
        self::assertStringContainsString('data-destination-folder', $html);

        // Nothing here may upload: there is no attachment, and a rule's folder
        // is a choice to be reported back rather than acted on.
        self::assertStringNotContainsString('/save-attachment/', $html);
    }

    /**
     * The other side of the same change. Pick mode is the ABSENCE of a part,
     * not a part that fails to resolve — a save path that could reach an
     * attachment by naming one that does not exist would be the ownership check
     * gone missing.
     */
    public function testABrowseNamingAPartThatIsNotOursIsStillRefused(): void
    {
        $client = $this->signIn();
        $integration = $this->seedIntegration();

        $client->request('GET', '/integrations/' . $integration->id . '/browse?mode=destination&part=0');

        self::assertResponseStatusCodeSame(404);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * The save token, read out of the editor the way a browser gets it — minted
     * through the token manager it would belong to the test's session rather
     * than the one the form was rendered into, which the same-origin manager
     * rejects.
     */
    private function saveToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/settings/filters/new');

        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }

    /**
     * Upload-capable and deliberately credential-less: the rule side never
     * talks to the service, and the picker side is being asked what it renders
     * when the service cannot be reached.
     */
    private function seedIntegration(): Integration
    {
        $integration = new Integration($this->user, Provider::Nextcloud, 'Home cloud');
        $integration->baseUrl = 'https://cloud.example.test';

        $this->em->persist($integration);
        $this->em->flush();

        return $integration;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->rules      = $container->get(MailRuleRepository::class);

        $this->connection->beginTransaction();

        $this->user = $this->seedUser();
        $client->loginUser($this->user);

        return $client;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'rule-folder-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Rule';
        $user->nameLast  = 'Folder';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
