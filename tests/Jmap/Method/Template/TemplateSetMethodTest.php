<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Template;

use App\Entity\Template\MailTemplate;
use App\Entity\Template\TemplateFolder;
use App\Entity\User\User;
use App\Jmap\Method\Template\TemplateFolderGetMethod;
use App\Jmap\Method\Template\TemplateFolderSetMethod;
use App\Jmap\Method\Template\TemplateGetMethod;
use App\Jmap\Method\Template\TemplateSetMethod;
use App\Jmap\Protocol\Exception\MethodException;
use App\Service\Mail\UserAccounts;
use App\Tests\Jmap\JmapTestCase;

/**
 * Templates and their folders over JMAP: `Template/get|set` and
 * `TemplateFolder/get|set`.
 *
 * The rules are the settings page's, and that is the claim: a template written
 * from a phone is sanitised by the same sanitiser, filed by the same library
 * and owned by the same check as one written in the browser, because it will
 * be inserted into the same compose window. A second door into the table with
 * its own idea of any of the three is how a body with script in it, or a
 * template filed under somebody else's account, gets stored.
 */
final class TemplateSetMethodTest extends JmapTestCase
{
    private TemplateGetMethod $get;
    private TemplateSetMethod $set;
    private TemplateFolderGetMethod $folderGet;
    private TemplateFolderSetMethod $folderSet;

    protected function setUp(): void
    {
        parent::setUp();

        $container       = self::getContainer();
        $this->get       = $container->get(TemplateGetMethod::class);
        $this->set       = $container->get(TemplateSetMethod::class);
        $this->folderGet = $container->get(TemplateFolderGetMethod::class);
        $this->folderSet = $container->get(TemplateFolderSetMethod::class);

        // The library reads accounts through a per-request memo.
        $container->get(UserAccounts::class)->reset();
    }

    public function testATemplateRoundTripsWithItsVariablesAsTokens(): void
    {
        $result = $this->set->handle(['create' => ['t1' => [
            'name'      => 'Invoice reminder',
            'subject'   => 'Reminder from {{date|offset=-14d}}',
            'htmlBody'  => '<p>Hi {{recipient.first_name}},</p><p>{{signature}}</p>',
            'accountId' => $this->accountId(),
        ]]], $this->context());

        $id = $result['created']['t1']['id'];

        self::assertNotSame($result['oldState'], $result['newState'], 'a create moves the state token');

        $read = $this->get->handle(['ids' => [$id]], $this->context());

        self::assertSame($result['newState'], $read['state']);
        self::assertSame([[
            'id'        => $id,
            'name'      => 'Invoice reminder',
            'subject'   => 'Reminder from {{date|offset=-14d}}',
            'htmlBody'  => '<p>Hi {{recipient.first_name}},</p><p>{{signature}}</p>',
            'accountId' => $this->accountId(),
            'folderId'  => null,
        ]], $read['list']);
    }

    /**
     * The body is injected into the web compose window's document. What a
     * client sent is not what is stored when the two differ — and the client
     * is told, so it does not go on believing otherwise (RFC 8620 §5.3).
     */
    public function testTheBodyIsSanitisedAndTheClientIsToldWhatWasStored(): void
    {
        $result = $this->set->handle(['create' => ['t1' => [
            'name'     => 'Hostile',
            'htmlBody' => '<p onclick="x()">Hi</p><script>alert(1)</script>',
        ]]], $this->context());

        self::assertSame('<p>Hi</p>', $result['created']['t1']['htmlBody']);
        self::assertSame(
            '<p>Hi</p>',
            $this->em->find(MailTemplate::class, (int) $result['created']['t1']['id'])->body,
        );
    }

    public function testATemplateNeedsANameAndTakesNoUnknownProperty(): void
    {
        $result = $this->set->handle(['create' => [
            'nameless' => ['htmlBody' => '<p>x</p>'],
            'extra'    => ['name' => 'x', 'colour' => 'red'],
        ]], $this->context());

        self::assertSame('invalidProperties', $result['notCreated']['nameless']['type']);
        self::assertSame('invalidProperties', $result['notCreated']['extra']['type']);
        self::assertSame([], $this->get->handle([], $this->context())['list']);
    }

    /**
     * A folder is under one account, so naming the folder is naming the
     * account. Naming both is accepted when they agree and refused when they
     * do not: preferring either would file the template where nobody asked.
     */
    public function testAFolderDecidesItsTemplatesAccount(): void
    {
        $other  = $this->secondAccount();
        self::getContainer()->get(UserAccounts::class)->reset();

        $folder = $this->folderSet->handle(['create' => ['f1' => [
            'name'      => 'Invoices',
            'accountId' => $this->accountId(),
        ]]], $this->context())['created']['f1'];

        $result = $this->set->handle(['create' => [
            'alone'    => ['name' => 'By folder alone', 'folderId' => $folder['id']],
            'agreeing' => ['name' => 'Both, agreeing', 'folderId' => $folder['id'], 'accountId' => $this->accountId()],
            'clashing' => ['name' => 'Both, clashing', 'folderId' => $folder['id'], 'accountId' => (string) $other->id],
        ]], $this->context());

        self::assertSame($this->accountId(), $result['created']['alone']['accountId'], 'the account the server filled in is echoed');
        self::assertArrayHasKey('agreeing', $result['created']);
        self::assertSame('invalidProperties', $result['notCreated']['clashing']['type']);

        // Moving to another account by naming only the account leaves the
        // folder behind: it belongs to the account the template is leaving.
        $id    = $result['created']['alone']['id'];
        $moved = $this->set->handle(['update' => [$id => ['accountId' => (string) $other->id]]], $this->context());

        self::assertSame(['folderId' => null], $moved['updated'][$id]);

        $read = $this->get->handle(['ids' => [$id], 'properties' => ['accountId', 'folderId']], $this->context())['list'][0];

        self::assertSame(['id' => $id, 'accountId' => (string) $other->id, 'folderId' => null], $read);
    }

    /**
     * `#creationId` across two calls of one request: a folder and the template
     * that goes into it, made together.
     */
    public function testAFolderAndItsFirstTemplateCanBeCreatedInOneRequest(): void
    {
        $context = $this->context();

        $this->folderSet->handle(['create' => ['f1' => ['name' => 'Together']]], $context);
        $result = $this->set->handle(['create' => ['t1' => ['name' => 'Inside', 'folderId' => '#f1']]], $context);

        $template = $this->em->find(MailTemplate::class, (int) $result['created']['t1']['id']);

        self::assertSame('Together', $template->folder?->name);
    }

    public function testAnotherUsersTemplatesAreNeitherListedNorWritable(): void
    {
        $theirs = $this->someoneElsesTemplate();

        self::assertSame([], $this->get->handle([], $this->context())['list']);
        self::assertSame(
            [(string) $theirs->id],
            $this->get->handle(['ids' => [(string) $theirs->id]], $this->context())['notFound'],
        );

        $result = $this->set->handle([
            'update'  => [(string) $theirs->id => ['name' => 'Taken']],
            'destroy' => [(string) $theirs->id],
        ], $this->context());

        self::assertSame('notFound', $result['notUpdated'][(string) $theirs->id]['type']);
        self::assertSame('notFound', $result['notDestroyed'][(string) $theirs->id]['type']);

        $this->em->clear();
        self::assertSame('Theirs', $this->em->find(MailTemplate::class, $theirs->id)->name);
    }

    public function testATemplateCannotBeFiledUnderSomebodyElsesAccount(): void
    {
        $theirs = $this->someoneElsesTemplate();

        $result = $this->set->handle(['create' => ['t1' => [
            'name'      => 'Misfiled',
            'accountId' => (string) $theirs->account->id,
        ]]], $this->context());

        self::assertSame('invalidProperties', $result['notCreated']['t1']['type']);
    }

    /**
     * Templates are the user's, so the methods take no accountId — and say so
     * rather than ignore one, as Appearance/get does.
     */
    public function testAnAccountIdArgumentIsRefusedRatherThanIgnored(): void
    {
        foreach ([$this->get, $this->set, $this->folderGet, $this->folderSet] as $method) {
            try {
                $method->handle(['accountId' => $this->accountId()], $this->context());
                self::fail(sprintf('%s accepted an accountId argument', $method->name()));
            } catch (MethodException $exception) {
                self::assertSame('invalidArguments', $exception->toError()['type']);
            }
        }
    }

    public function testAStaleIfInStateIsRefused(): void
    {
        $this->expectException(MethodException::class);

        $this->set->handle(['ifInState' => 'not-the-state', 'create' => ['t1' => ['name' => 'x']]], $this->context());
    }

    // ── folders ──────────────────────────────────────────────────────────────

    /**
     * A folder cannot be moved, and an update that tries is refused rather
     * than ignored: a client that believes it moved a folder would go on
     * drawing it in the wrong place.
     */
    public function testAFolderIsRenamedButNotMoved(): void
    {
        $id = $this->folderSet->handle(['create' => ['f1' => ['name' => 'Before']]], $this->context())['created']['f1']['id'];

        $result = $this->folderSet->handle(['update' => [$id => ['name' => 'After']]], $this->context());

        self::assertArrayHasKey($id, $result['updated']);

        $result = $this->folderSet->handle(['update' => [$id => ['accountId' => $this->accountId()]]], $this->context());

        self::assertSame('invalidProperties', $result['notUpdated'][$id]['type']);
        self::assertSame(
            [['id' => $id, 'name' => 'After', 'accountId' => null, 'parentId' => null]],
            $this->folderGet->handle([], $this->context())['list'],
        );
    }

    /**
     * Destroying a folder destroys the folders inside it and NO template: they
     * move up to where the folder was, and the Template state token moves so a
     * client knows to read them again.
     */
    public function testDestroyingAFolderKeepsItsTemplatesAndMovesTheirState(): void
    {
        $context = $this->context();

        $outer = $this->folderSet->handle(['create' => ['o' => ['name' => 'Outer', 'accountId' => $this->accountId()]]], $context)['created']['o']['id'];
        $inner = $this->folderSet->handle(['create' => ['i' => ['name' => 'Inner', 'parentId' => $outer]]], $context)['created']['i'];

        self::assertSame($this->accountId(), $inner['accountId'], 'a nested folder takes its parent\'s account');

        $template = $this->set->handle(['create' => ['t' => ['name' => 'Deep', 'folderId' => $inner['id']]]], $context)['created']['t']['id'];
        $before   = $this->get->handle([], $this->context())['state'];

        // Both named: the second went with the first, which is not an error
        // worth more than a notFound.
        $result = $this->folderSet->handle(['destroy' => [$outer, $inner['id']]], $this->context());

        self::assertSame([$outer], $result['destroyed']);
        self::assertSame([], $this->folderGet->handle([], $this->context())['list']);

        $read = $this->get->handle(['ids' => [$template]], $this->context());

        self::assertNull($read['list'][0]['folderId']);
        self::assertSame($this->accountId(), $read['list'][0]['accountId'], 'it stays under the account the folder was in');
        self::assertNotSame($before, $read['state']);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function someoneElsesTemplate(): MailTemplate
    {
        $mine = [$this->user, $this->account];

        // A second fixture user with an account, by the base class's own seed.
        $other = new User();
        $other->email = 'jmap-other-' . uniqid('', true) . '@example.test';
        $other->nameFirst = 'Other';
        $other->nameLast = 'Fixture';
        $other->roles = ['ROLE_USER'];
        $other->password = 'x';
        $this->em->persist($other);

        $this->user = $other;
        $account    = $this->secondAccount();
        [$this->user, $this->account] = $mine;

        $template          = new MailTemplate();
        $template->usr     = $other;
        $template->name    = 'Theirs';
        $template->body    = '<p>Private</p>';
        $template->account = $account;

        $folder       = new TemplateFolder();
        $folder->usr  = $other;
        $folder->name = 'Their folder';

        $this->em->persist($folder);
        $this->em->persist($template);
        $this->em->flush();

        return $template;
    }
}
