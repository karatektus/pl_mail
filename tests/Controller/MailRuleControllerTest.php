<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Label\Label;
use App\Entity\Rule\MailRule;
use App\Entity\User\User;
use App\Repository\Rule\MailRuleRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Saving a rule, and the four ways it must refuse to.
 *
 * A rule is the one thing in this application that acts on mail without
 * anybody watching, so what reaches the database matters more here than
 * anywhere else: the condition tree arrives as JSON in a hidden field, is fed
 * to a SQL compiler, and then runs against every message that arrives. The
 * refusals are the feature.
 *
 * Written as requests rather than against the controller directly, because
 * half of what is being checked — the CSRF token, the ownership rule, the 422
 * that keeps a rejected tree on screen — is not in the method body at all.
 */
final class MailRuleControllerTest extends WebTestCase
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

    public function testARuleIsSavedWithItsTreeAndActions(): void
    {
        $client = $this->signIn();
        $label  = $this->seedLabel('Receipts');

        $client->request('POST', '/settings/filters/save', [
            '_token'     => $this->saveToken($client),
            'name'       => 'File the receipts',
            'conditions' => json_encode(['subject' => 'invoice'], JSON_THROW_ON_ERROR),
            'actions'    => json_encode([['type' => 'applyLabel', 'labelId' => $label->id]], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseIsSuccessful();

        $rule = $this->onlyRule();

        self::assertSame('File the receipts', $rule->name);
        self::assertSame(['subject' => 'invoice'], $rule->conditions);
        self::assertSame('applyLabel', $rule->actions[0]['type']);
        self::assertTrue($rule->isEnabled);
    }

    /**
     * The rule this session added: no conditions means "everything in scope",
     * which the validator used to reject outright — making "label everything
     * arriving in this account" impossible to express.
     */
    public function testARuleWithNoConditionsIsAllowed(): void
    {
        $client = $this->signIn();

        $client->request('POST', '/settings/filters/save', [
            '_token'     => $this->saveToken($client),
            'name'       => 'Everything',
            'conditions' => '{}',
            'actions'    => json_encode([['type' => 'archive']], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->onlyRule()->conditions);
    }

    /**
     * A rule that matches everything and does nothing is not a rule, and a
     * nameless one cannot be found again in the list.
     */
    public function testANamelessOrActionlessRuleIsRefusedAndKeptOnScreen(): void
    {
        $client = $this->signIn();

        $client->request('POST', '/settings/filters/save', [
            '_token'     => $this->saveToken($client),
            'name'       => '   ',
            'conditions' => json_encode(['subject' => 'invoice'], JSON_THROW_ON_ERROR),
            'actions'    => '[]',
        ]);

        // 422 rather than a redirect, so Turbo renders the editor back with
        // the tree the author had rather than silently discarding it.
        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->rules->findForUserOrdered($this->user));
    }

    /**
     * The tree feeds a SQL compiler, so a shape it does not recognise is
     * refused rather than sanitised — dropping an unrecognised condition would
     * widen the rule, and a filter that matches more mail than was asked for is
     * exactly what a rules engine must never do.
     */
    public function testATreeOutsideTheVocabularyIsRefused(): void
    {
        $client = $this->signIn();

        $client->request('POST', '/settings/filters/save', [
            '_token'     => $this->saveToken($client),
            'name'       => 'Sneaky',
            'conditions' => json_encode(['subjectt' => 'typo'], JSON_THROW_ON_ERROR),
            'actions'    => json_encode([['type' => 'archive']], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertCount(0, $this->rules->findForUserOrdered($this->user));
    }

    /** Every mutation on this page carries a token; this is the proof. */
    public function testSavingWithoutATokenIsRefused(): void
    {
        $client = $this->signIn();

        $client->request('POST', '/settings/filters/save', [
            'name'       => 'No token',
            'conditions' => '{}',
            'actions'    => json_encode([['type' => 'archive']], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->rules->findForUserOrdered($this->user));
    }

    /**
     * Rules are addressed by id in the URL, so the ownership check is the only
     * thing between one user's id and another user's rule.
     */
    public function testAnotherUsersRuleIsNotReachable(): void
    {
        $client   = $this->signIn();
        $stranger = $this->seedUser();
        $rule     = $this->seedRule($stranger);

        $client->request('GET', '/settings/filters/' . $rule->id . '/edit');

        self::assertResponseStatusCodeSame(403);
    }

    /** The live readout the editor is built around. */
    public function testThePreviewCountsAndDescribesWithoutSaving(): void
    {
        $client = $this->signIn();

        $client->request(
            'POST',
            '/settings/filters/preview',
            server: [
                'CONTENT_TYPE'   => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $this->previewToken($client),
            ],
            content: json_encode([
                'conditions' => ['subject' => 'invoice'],
                'actions'    => [['type' => 'archive']],
            ], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertTrue($payload['ok']);
        self::assertSame(0, $payload['count']);
        self::assertStringContainsString('Subject contains invoice', $payload['description']);
        self::assertCount(0, $this->rules->findForUserOrdered($this->user), 'a preview must never persist anything');
    }

    /** A tree the compiler cannot take must come back as an error, not a 500. */
    public function testThePreviewRefusesAnInvalidTreePolitely(): void
    {
        $client = $this->signIn();

        $client->request(
            'POST',
            '/settings/filters/preview',
            server: [
                'CONTENT_TYPE'      => 'application/json',
                'HTTP_X_CSRF_TOKEN' => $this->previewToken($client),
            ],
            content: json_encode(['conditions' => ['subjectt' => 'typo']], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();

        $payload = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertFalse($payload['ok']);
        self::assertNotSame('', (string) $payload['error']);
    }

    public function testARuleIsToggledAndDeleted(): void
    {
        $client = $this->signIn();
        $rule   = $this->seedRule($this->user);

        $client->request('POST', '/settings/filters/' . $rule->id . '/toggle', [
            '_token' => $this->rowToken($client, (int) $rule->id, 'toggle'),
        ]);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->rules->find($rule->id)?->isEnabled);

        $client->request('POST', '/settings/filters/' . $rule->id . '/delete', [
            '_token' => $this->rowToken($client, (int) $rule->id, 'delete'),
        ]);

        self::assertResponseIsSuccessful();
        self::assertNull($this->rules->find($rule->id));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function onlyRule(): MailRule
    {
        $rules = $this->rules->findForUserOrdered($this->user);

        self::assertCount(1, $rules);

        return $rules[0];
    }

    /**
     * The save token, read out of the editor the way a browser gets it.
     *
     * Minted through the token manager instead, it is a token for a session the
     * test happens to hold rather than the one the form was rendered into —
     * which the same-origin manager rejects, correctly and confusingly.
     */
    private function saveToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/settings/filters/new');

        return (string) $crawler->filter('input[name="_token"]')->first()->attr('value');
    }

    /** The preview endpoint takes its token in a header, so the editor
     *  publishes it as a data attribute rather than a form field. */
    private function previewToken(KernelBrowser $client): string
    {
        $crawler = $client->request('GET', '/settings/filters/new');

        return (string) $crawler->filter('[data-rules--rule-builder-csrf-value]')
            ->first()
            ->attr('data-rules--rule-builder-csrf-value');
    }

    /** A row's own token, from the list where that row's button lives. */
    private function rowToken(KernelBrowser $client, int $ruleId, string $action): string
    {
        $crawler = $client->request('GET', '/settings?section=filters');

        return (string) $crawler
            ->filter(sprintf('form[action="/settings/filters/%d/%s"] input[name="_token"]', $ruleId, $action))
            ->first()
            ->attr('value');
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

    private function seedRule(User $owner): MailRule
    {
        $rule             = new MailRule();
        $rule->usr        = $owner;
        $rule->name       = 'Existing';
        $rule->conditions = ['subject' => 'invoice'];
        $rule->actions    = [['type' => 'archive']];

        $this->em->persist($rule);
        $this->em->flush();

        return $rule;
    }

    private function seedLabel(string $name): Label
    {
        $label            = new Label();
        $label->usr       = $this->user;
        $label->name      = $name;
        $label->isVisible = true;

        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'rules-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Rules';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = '$2y$04$abcdefghijklmnopqrstuvwxyz0123456789ABCDEFGHIJKLMNOP';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
