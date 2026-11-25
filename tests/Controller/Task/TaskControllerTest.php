<?php

declare(strict_types=1);

namespace App\Tests\Controller\Task;

use App\Domain\DTO\Task\RemoteTaskList;
use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Enum\Task\TaskStatus;
use App\Entity\Calendar\Calendar;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
use App\Entity\Task\Task;
use App\Entity\Task\TaskAttachment;
use App\Entity\Task\TaskList;
use App\Entity\User\User;
use App\Repository\Task\TaskListRepository;
use App\Repository\User\User\UserRepository;
use App\Service\Task\TaskAttachmentStorage;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The tasks routes refuse what is not yours, and what is not writable.
 *
 * Ownership is the voter's and read-only is the writer's, so what this pins is
 * that every route actually asks them, including the two forms a page can no
 * longer offer once a list has become read-only. Also that a title reaches the
 * page as text: it may have come from another client.
 *
 * Against the real stack, staged in a transaction that is rolled back, as
 * AccountColourTest is.
 */
final class TaskControllerTest extends WebTestCase
{
    private const string USER_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::USER_EMAIL]);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->user = $user;
        $this->client->loginUser($user);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testThePageRendersAndProvisionsTheDefaultListOnce(): void
    {
        $this->client->request('GET', '/tasks');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/tasks');
        self::assertResponseIsSuccessful();

        self::assertCount(1, $this->em->getRepository(TaskList::class)->findBy(['usr' => $this->user, 'isDefault' => true]));
    }

    /** Regression guard: the default used to be written after the read that lists it. */
    public function testTheVeryFirstVisitAlreadyOffersTheDefaultList(): void
    {
        $this->client->loginUser($this->otherUser());

        $crawler = $this->client->request('GET', '/tasks');

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $crawler->filter('nav a[href*="list="]')->count());
        self::assertNotSame('', (string) $crawler->filter('form[action="/tasks/quick-add"] input[name="listId"]')->attr('value'));
    }

    public function testAFrameRequestGetsTheFrameAlone(): void
    {
        $this->client->request('GET', '/tasks', server: ['HTTP_TURBO_FRAME' => 'tasks-pane-frame']);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#tasks-pane-frame');
        self::assertSelectorNotExists('[data-side-pane]');
    }

    /** Regression guard: a task with no date, steps or repeat used to show no list at all. */
    public function testARowNamesItsListWithTheListsColourBesideAllOfThem(): void
    {
        $list        = $this->list();
        $list->color = '#16a34a';
        $this->em->flush();

        $task = $this->task($list, 'Coloured');

        $crawler = $this->client->request('GET', '/tasks?view=lists');
        $label   = $crawler->filter(sprintf('[data-task-row="%d"] [data-task-row-list]', $task->id));

        self::assertStringContainsString($list->name, $label->text());
        self::assertStringContainsString('#16a34a', (string) $label->filter('span[aria-hidden]')->attr('style'));
    }

    public function testATitleIsRenderedAsTextAndItsAddressAsALink(): void
    {
        $this->task($this->list(), '<script>alert(1)</script> https://example.org/x');

        $crawler = $this->client->request('GET', '/tasks?view=lists');

        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('<script>alert(1)', (string) $this->client->getResponse()->getContent());
        self::assertSame('https://example.org/x', $crawler->filter('[data-task-row] p a')->attr('href'));
    }

    public function testQuickAddOnMyDayIsDueTodaySoItStaysInTheView(): void
    {
        $crawler = $this->client->request('GET', '/tasks');
        $this->client->submit($crawler->filter('form[action="/tasks/quick-add"]')->form(['title' => 'Typed on My Day']));

        self::assertResponseRedirects('/tasks', 303);

        $task = $this->em->getRepository(Task::class)->findOneBy(['usr' => $this->user, 'title' => 'Typed on My Day']);

        self::assertNotNull($task?->dueDate);
    }

    public function testQuickAddTakesTheOptionsChosenUnderIt(): void
    {
        $crawler = $this->client->request('GET', '/tasks');
        $form    = $crawler->filter('form[action="/tasks/quick-add"]')->form([
            'title'      => 'With options',
            'dueDate'    => '2031-03-04',
            'reminder'   => '2031-03-03T18:00',
            'recurrence' => 'weekly',
        ]);
        $this->client->submit($form);

        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => 'With options']);

        self::assertNotNull($task);
        self::assertSame('2031-03-04', $task->dueDate?->format('Y-m-d'), 'a chosen date wins over My Day\'s today');
        self::assertNotNull($task->reminderAt);
        self::assertSame('FREQ=WEEKLY', $task->recurrenceRule);
    }

    public function testAddingSavingAndTickingEachSaySo(): void
    {
        $crawler = $this->client->request('GET', '/tasks');
        $this->client->submit($crawler->filter('form[action="/tasks/quick-add"]')->form(['title' => 'Said so']));
        self::assertStringContainsString('Task added.', (string) $this->client->followRedirect()->html());

        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Said so']);

        $this->client->request('POST', sprintf('/tasks/%d/toggle', $task->id), ['_token' => $this->token('tasks_toggle')]);
        self::assertStringContainsString('Task completed.', (string) $this->client->followRedirect()->html());

        $this->client->request('POST', sprintf('/tasks/%d/toggle', $task->id), ['_token' => $this->token('tasks_toggle')]);
        self::assertStringContainsString('Task reopened.', (string) $this->client->followRedirect()->html());

        $this->client->request('POST', '/tasks/save', ['_token' => $this->token('tasks_editor'), 'title' => 'New in the dialog']);
        self::assertStringContainsString('Task added.', (string) $this->client->getResponse()->getContent());

        $this->client->request('POST', '/tasks/save', ['_token' => $this->token('tasks_editor'), 'taskId' => $task->id, 'title' => 'Renamed']);
        self::assertStringContainsString('Task saved.', (string) $this->client->getResponse()->getContent());
    }

    public function testARepeatingTaskTickedOffSaysItMovedRatherThanThatItIsDone(): void
    {
        $task                 = $this->task($this->list(), 'Repeats');
        $task->recurrenceRule = 'FREQ=DAILY';
        $this->em->flush();

        $this->client->request('POST', sprintf('/tasks/%d/toggle', $task->id), ['_token' => $this->token('tasks_toggle')]);

        $page = (string) $this->client->followRedirect()->html();

        self::assertStringContainsString('Done. The task moved to its next date.', $page);
        self::assertStringNotContainsString('Task completed.', $page);
    }

    public function testUntickingAnEntrySaysTheTickWasUndoneAndAnEntryOffersNoRepeatOrSteps(): void
    {
        $task                 = $this->task($this->list(), 'Repeats');
        $task->dueDate        = new DateTimeImmutable('2026-06-01');
        $task->recurrenceRule = 'FREQ=WEEKLY';
        $this->em->flush();

        $this->client->request('POST', sprintf('/tasks/%d/toggle', $task->id), ['_token' => $this->token('tasks_toggle')]);

        $entry = $this->em->getRepository(Task::class)->findOneBy(['occurrenceOf' => $task->id]);

        self::assertNotNull($entry);

        $this->client->request('GET', sprintf('/tasks/%d/edit', $entry->id));
        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('select[name="recurrence"]');
        self::assertSelectorNotExists('#task-steps-heading');

        $this->client->request('POST', sprintf('/tasks/%d/toggle', $entry->id), ['_token' => $this->token('tasks_toggle')]);

        self::assertStringContainsString('Tick undone.', (string) $this->client->followRedirect()->html());
        self::assertNull($this->em->getRepository(Task::class)->findOneBy(['occurrenceOf' => $task->id]));
    }

    public function testQuickAddInAListHasNoDueDate(): void
    {
        $list = $this->list();

        $crawler = $this->client->request('GET', sprintf('/tasks?view=lists&list=%d', $list->id));
        $this->client->submit($crawler->filter('form[action="/tasks/quick-add"]')->form(['title' => 'Typed in a list']));

        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Typed in a list']);

        self::assertSame($list->id, $task?->taskList?->id);
        self::assertNull($task?->dueDate);
    }

    public function testAnotherUsersTaskIsRefusedOnEveryRoute(): void
    {
        $theirs = $this->task($this->list($this->otherUser()), 'Theirs');

        foreach ([
            ['GET', sprintf('/tasks/%d/edit', $theirs->id)],
            ['POST', sprintf('/tasks/%d/toggle', $theirs->id)],
            ['POST', sprintf('/tasks/%d/delete', $theirs->id)],
        ] as [$method, $path]) {
            $this->client->request($method, $path, ['_token' => $this->token('POST' === $method && str_ends_with($path, 'toggle') ? 'tasks_toggle' : 'tasks_editor')]);

            self::assertResponseStatusCodeSame(403, sprintf('%s %s', $method, $path));
        }

        self::assertTrue($this->reloaded($theirs)->status->isOpen());
    }

    public function testAnotherUsersListIsNotAPlaceToAddTo(): void
    {
        $theirs = $this->list($this->otherUser());

        $crawler = $this->client->request('GET', '/tasks');
        $form    = $crawler->filter('form[action="/tasks/quick-add"]')->form(['title' => 'Aimed at someone else']);
        $form['listId']->setValue((string) $theirs->id);
        $this->client->submit($form);

        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Aimed at someone else']);

        self::assertNotSame($theirs->id, $task?->taskList?->id);
        self::assertSame($this->user->id, $task?->taskList?->usr?->id);
    }

    public function testAMissingCsrfTokenIsRefusedRatherThanWritten(): void
    {
        $task = $this->task($this->list(), 'Protected');

        $this->client->request('POST', sprintf('/tasks/%d/toggle', $task->id));

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->reloaded($task)->status->isOpen());
    }

    public function testAReadOnlyListCannotBeTicked(): void
    {
        $list             = $this->list();
        $list->isReadOnly = true;
        $task             = $this->task($list, 'Read only');

        $this->client->request('POST', sprintf('/tasks/%d/toggle', $task->id), ['_token' => $this->token('tasks_toggle')]);

        self::assertTrue($this->reloaded($task)->status->isOpen());
    }

    public function testASaveWithoutATitleIsSentBackToTheDialog(): void
    {
        $this->client->request('POST', '/tasks/save', ['_token' => $this->token('tasks_editor'), 'title' => '   ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('turbo-frame#modal [role="alert"]');
    }

    public function testASaveAnswersWithAStreamThatPointsThePaneBack(): void
    {
        $this->client->request('POST', '/tasks/save', [
            '_token' => $this->token('tasks_editor'),
            'title'  => 'Saved in the dialog',
            'view'   => 'lists',
        ]);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'text/vnd.turbo-stream.html; charset=UTF-8');
        self::assertStringContainsString(
            'src="/tasks?view=lists"',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testTheDefaultListCannotBeDeleted(): void
    {
        $this->client->request('GET', '/tasks');

        $default = $this->em->getRepository(TaskList::class)->findOneBy(['usr' => $this->user, 'isDefault' => true]);

        $this->client->request('POST', sprintf('/tasks/list/%d/delete', $default->id), ['_token' => $this->token('tasks_list_editor')]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testASyncedListKeepsTheRemotesNameAndCannotBeDeleted(): void
    {
        $list           = $this->list();
        $list->remoteId = 'remote';
        $this->em->flush();

        $this->client->request('POST', '/tasks/list/save', [
            '_token' => $this->token('tasks_list_editor'),
            'listId' => $list->id,
            'name'   => 'Renamed here',
            'color'  => '#dc2626',
        ]);

        $list = $this->em->getRepository(TaskList::class)->find($list->id);

        self::assertSame('List', $list->name);
        self::assertSame('#dc2626', $list->color);

        $this->client->request('POST', sprintf('/tasks/list/%d/delete', $list->id), ['_token' => $this->token('tasks_list_editor')]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testDeletingAListTakesItsTasksWithIt(): void
    {
        $list   = $this->list();
        $taskId = $this->task($list, 'Goes with it')->id;

        $this->client->request('POST', sprintf('/tasks/list/%d/delete', $list->id), ['_token' => $this->token('tasks_list_editor')]);

        self::assertResponseIsSuccessful();
        $this->em->clear();
        self::assertNull($this->em->find(Task::class, $taskId));
    }

    public function testTheListsDialogOffersEachListAndDeleteOnlyWhereItIsAllowed(): void
    {
        $this->client->request('GET', '/tasks');

        $default = $this->em->getRepository(TaskList::class)->findOneBy(['usr' => $this->user, 'isDefault' => true]);
        $own     = $this->list();
        $synced  = $this->list();

        $synced->remoteId = 'remote';
        $this->em->flush();

        $crawler = $this->client->request('GET', '/tasks/lists');

        self::assertResponseIsSuccessful();

        foreach ([$default, $own, $synced] as $list) {
            self::assertSame(
                sprintf('/tasks/list/%d/edit', $list->id),
                $crawler->filter(sprintf('[data-task-list="%d"] a', $list->id))->attr('href'),
            );
        }

        $deletes = static fn (TaskList $list): int => $crawler->filter(sprintf('[data-task-list="%d"] form[action$="/delete"]', $list->id))->count();

        self::assertSame(1, $deletes($own));
        self::assertSame(0, $deletes($default), 'where a task lands without a list');
        self::assertSame(0, $deletes($synced), 'the remote decides that');
        self::assertSelectorExists('a[href="/tasks/list/new"]');
    }

    /** It stays open: a save and a delete answer with the Lists dialog, and tell the pane. */
    public function testASaveAndADeleteComeBackToTheListsDialogAndRedrawThePane(): void
    {
        $this->client->request('POST', '/tasks/list/save', [
            '_token' => $this->token('tasks_list_editor'),
            'listId' => 0,
            'name'   => 'Made here',
            'color'  => '#16a34a',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('turbo-frame#modal', 'Made here');
        self::assertSelectorExists('turbo-frame#modal turbo-stream[action="open_pane"][src="/tasks"]');
        self::assertStringContainsString('List saved.', (string) $this->client->getResponse()->getContent());

        $made = $this->em->getRepository(TaskList::class)->findOneBy(['name' => 'Made here']);

        // Deleted while the pane shows it: the pane goes to all of them.
        $this->client->request('POST', sprintf('/tasks/list/%d/delete', $made->id), [
            '_token' => $this->token('tasks_list_editor'),
            'view'   => 'lists',
            'list'   => $made->id,
        ]);

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#modal turbo-stream[action="open_pane"][src="/tasks?view=lists"]');
        self::assertSelectorNotExists(sprintf('[data-task-list="%d"]', (int) $made->id));
        self::assertStringContainsString('List deleted.', (string) $this->client->getResponse()->getContent());
    }

    public function testAFileIsKeptAndDownloadsAsADownloadOfNoParticularType(): void
    {
        $task = $this->task($this->list(), 'With a file');
        $path = tempnam(sys_get_temp_dir(), 'task');
        file_put_contents($path, '<script>alert(1)</script>');

        $this->client->request(
            'POST',
            '/tasks/save',
            ['_token' => $this->token('tasks_editor'), 'taskId' => $task->id, 'title' => 'With a file'],
            ['file' => new UploadedFile($path, 'evil.html', 'text/html', null, true)],
        );

        self::assertResponseIsSuccessful();

        $crawler = $this->client->request('GET', sprintf('/tasks/%d/edit', $task->id));

        self::assertStringContainsString('evil.html', $crawler->filter('[data-task-attachment]')->text());
        self::assertCount(0, $crawler->filter('[data-editor-part="files"] button:not([aria-label])'), 'a file goes up with Save, no button of its own');

        $attachment = $this->em->getRepository(TaskAttachment::class)->findOneBy(['filename' => 'evil.html']);

        $this->client->request('GET', sprintf('/tasks/attachment/%d', $attachment->id));

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/octet-stream');
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertStringContainsString('attachment', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
        self::assertStringContainsString('sandbox', (string) $this->client->getResponse()->headers->get('Content-Security-Policy'));

        static::getContainer()->get(TaskAttachmentStorage::class)->remove((string) $attachment->storedName);
    }

    public function testAnotherUsersFileIsRefused(): void
    {
        $task = $this->task($this->list($this->otherUser()), 'Theirs');

        $attachment             = new TaskAttachment();
        $attachment->task       = $task;
        $attachment->usr        = $task->usr;
        $attachment->filename   = 'theirs.pdf';
        $attachment->storedName = 'nothing';
        $this->em->persist($attachment);
        $this->em->flush();

        $this->client->request('GET', sprintf('/tasks/attachment/%d', $attachment->id));

        self::assertResponseStatusCodeSame(403);
    }

    public function testAForeignLinkHasNoFileHereToServe(): void
    {
        $task = $this->task($this->list(), 'Linked');

        $attachment              = new TaskAttachment();
        $attachment->task        = $task;
        $attachment->usr         = $task->usr;
        $attachment->filename    = 'elsewhere.pdf';
        $attachment->externalUrl = 'https://example.org/elsewhere.pdf';
        $this->em->persist($attachment);
        $this->em->flush();

        $this->client->request('GET', sprintf('/tasks/attachment/%d', $attachment->id));

        self::assertResponseStatusCodeSame(404);
    }

    public function testStepsAreSavedWithTheDialog(): void
    {
        $task = $this->task($this->list(), 'Parent');

        $this->client->request('POST', '/tasks/save', [
            '_token' => $this->token('tasks_editor'),
            'taskId' => $task->id,
            'title'  => 'Parent',
            'steps'  => ['First step', '   ', ''],
        ]);

        self::assertResponseIsSuccessful();

        $step = $this->em->getRepository(Task::class)->findOneBy(['title' => 'First step']);

        self::assertSame($task->id, $step?->parent?->id);
        self::assertCount(1, $this->em->getRepository(Task::class)->findBy(['parent' => $task->id]), 'an empty field is not a step');

        $crawler = $this->client->request('GET', sprintf('/tasks/%d/edit', $task->id));

        self::assertSame('First step', $crawler->filter('[data-task-step] [role="checkbox"]')->attr('aria-label'));
    }

    public function testASavedStepIsRenamedWithTheDialogAndAnEmptiedOneKeepsItsTitle(): void
    {
        $task    = $this->task($this->list(), 'Parent');
        $renamed = $this->stepOf($task, 'Old title');
        $emptied = $this->stepOf($task, 'Kept title');
        $foreign = $this->stepOf($this->task($task->taskList, 'Someone else'), 'Not this one');

        $crawler = $this->client->request('GET', sprintf('/tasks/%d/edit', $task->id));

        self::assertSame('Old title', $crawler->filter(sprintf('input[name="stepTitles[%d]"]', $renamed->id))->attr('value'));

        $this->client->request('POST', '/tasks/save', [
            '_token'     => $this->token('tasks_editor'),
            'taskId'     => $task->id,
            'title'      => 'Parent',
            'stepTitles' => [$renamed->id => '  New title ', $emptied->id => '  ', $foreign->id => 'Hijacked'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('New title', $this->reloaded($renamed)->title);
        self::assertSame('Kept title', $this->reloaded($emptied)->title);
        self::assertSame('Not this one', $this->reloaded($foreign)->title, 'only the task being saved has its steps read');
    }

    public function testADialogActionPutsBackATypedStepTitleAndSavesNoneOfIt(): void
    {
        $task  = $this->task($this->list(), 'Parent');
        $step  = $this->stepOf($task, 'Stored');
        $other = $this->stepOf($task, 'Going');

        $crawler = $this->client->request('POST', sprintf('/tasks/step/%d/delete', $other->id), [
            '_token'     => $this->token('tasks_editor'),
            'stepTitles' => [$step->id => 'Typed'],
        ]);

        self::assertSame('Typed', $crawler->filter(sprintf('input[name="stepTitles[%d]"]', $step->id))->attr('value'));
        self::assertSame('Stored', $this->reloaded($step)->title);
    }

    public function testANewStepRowOffersItsCrossOnlyOnceSomethingIsTyped(): void
    {
        $crawler = $this->client->request('POST', '/tasks/save', [
            '_token' => $this->token('tasks_editor'),
            'title'  => '',
            'steps'  => ['Typed', ''],
        ]);

        $crosses = $crawler->filter('[data-tasks--new-steps-target="list"] [data-step-remove]');

        self::assertCount(2, $crosses);
        self::assertNull($crosses->eq(0)->attr('disabled'));
        self::assertNotNull($crosses->eq(1)->attr('disabled'));
    }

    public function testANewStepTickedAsItIsTypedIsSavedDoneAndAnEmptyRowDoesNotMoveTheTicks(): void
    {
        $task = $this->task($this->list(), 'Parent');

        $this->client->request('POST', '/tasks/save', [
            '_token'    => $this->token('tasks_editor'),
            'taskId'    => $task->id,
            'title'     => 'Parent',
            'steps'     => ['', 'Done already', 'Still to do', ''],
            'stepsDone' => ['0', '1', '0', '0'],
        ]);

        self::assertResponseIsSuccessful();

        $repository = $this->em->getRepository(Task::class);

        self::assertFalse($repository->findOneBy(['title' => 'Done already'])?->status->isOpen());
        self::assertNotNull($repository->findOneBy(['title' => 'Done already'])?->completedAt);
        self::assertTrue($repository->findOneBy(['title' => 'Still to do'])?->status->isOpen());
        self::assertTrue($this->reloaded($task)->status->isOpen(), 'a step finishing does not finish its task');
    }

    /** Regression guard: a task's own id here deleted it and drew its dialog again. */
    public function testAStepDeleteWithATasksIdIsNotFoundAndDeletesNothing(): void
    {
        $task = $this->task($this->list(), 'Not a step');

        $this->client->request('POST', sprintf('/tasks/step/%d/delete', $task->id), ['_token' => $this->token('tasks_editor')]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame('Not a step', $this->reloaded($task)->title, 'still there');
    }

    public function testADialogActionPutsBackTheTickOfAStepNotSavedYet(): void
    {
        $task = $this->task($this->list(), 'Parent');
        $step = $this->task($task->taskList, 'A step');

        $step->parent = $task;
        $this->em->flush();

        $crawler = $this->client->request('POST', sprintf('/tasks/step/%d/delete', $step->id), [
            '_token'    => $this->token('tasks_editor'),
            'steps'     => ['Open one', 'Ticked one', ''],
            'stepsDone' => ['0', '1', '0'],
        ]);

        $ticks = $crawler->filter('[data-tasks--new-steps-target="list"] [role="checkbox"]');

        self::assertSame(['false', 'true', 'false'], $ticks->each(static fn ($tick): ?string => $tick->attr('aria-checked')));
        self::assertNull($ticks->eq(1)->attr('disabled'));
        self::assertNotNull($ticks->eq(2)->attr('disabled'), 'an empty row has nothing to tick');
    }

    public function testARowOffersItsStepsToTickAndOneWithoutStepsOffersNothing(): void
    {
        $task    = $this->task($this->list(), 'With steps');
        $without = $this->task($task->taskList, 'Without steps');
        $step    = $this->stepOf($task, 'A step');

        $crawler = $this->client->request('GET', '/tasks?view=lists');
        $row     = $crawler->filter(sprintf('[data-task-row="%d"]', $task->id));

        self::assertCount(0, $crawler->filter(sprintf('[data-task-row="%d"]', $step->id)), 'a step is no row of its own');
        self::assertCount(0, $crawler->filter(sprintf('[data-task-row="%d"] button[aria-expanded]', $without->id)));
        self::assertCount(1, $row->filter('button[aria-expanded="false"]'));
        self::assertNotNull($row->filter('ul')->attr('hidden'), 'folded until somebody unfolds it');
        self::assertSame(
            sprintf('/tasks/step/%d/tick', $step->id),
            $crawler->filter(sprintf('[data-task-row-step="%d"] [role="checkbox"]', $step->id))->attr('data-tasks--step-tick-url-value'),
        );
    }

    public function testAStepOnAReadOnlyListCannotBeTickedFromItsRow(): void
    {
        $list = $this->list();
        $task = $this->task($list, 'With steps');
        $step = $this->stepOf($task, 'A step');

        $list->isReadOnly = true;
        $this->em->flush();

        $crawler = $this->client->request('GET', '/tasks?view=lists');

        self::assertNotNull($crawler->filter(sprintf('[data-task-row-step="%d"] button', $step->id))->attr('disabled'));
    }

    public function testATickOfAStepAnswersWithTheConfirmationAndTheCount(): void
    {
        $task = $this->task($this->list(), 'Parent');
        $step = $this->stepOf($task, 'A step');
        $this->stepOf($task, 'Another step');

        $this->client->request('POST', sprintf('/tasks/step/%d/tick', $step->id), ['_token' => $this->token('tasks_toggle'), 'done' => '1']);

        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('text/vnd.turbo-stream.html', (string) $this->client->getResponse()->headers->get('Content-Type'));

        $answer = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Step completed.', $answer);
        self::assertStringContainsString(sprintf('target="task-steps-count-%d"', $task->id), $answer);
        self::assertStringContainsString('1 of 2', $answer);
        self::assertFalse($this->reloaded($step)->status->isOpen());
        self::assertTrue($this->reloaded($task)->status->isOpen(), 'a step finishing does not finish its task');

        $this->client->request('POST', sprintf('/tasks/step/%d/tick', $step->id), ['_token' => $this->token('tasks_toggle'), 'done' => '0']);

        self::assertStringContainsString('Step reopened.', (string) $this->client->getResponse()->getContent());
        self::assertTrue($this->reloaded($step)->status->isOpen());
    }

    /** What was on screen when it was pressed: a toggle would undo the other device's tick. */
    public function testATickOfAStepAlreadyDoneElsewhereLeavesItDone(): void
    {
        $step = $this->stepOf($this->task($this->list(), 'Parent'), 'A step');

        $step->status      = TaskStatus::Completed;
        $step->completedAt = new DateTimeImmutable('2026-06-01 10:00:00');
        $this->em->flush();

        $this->client->request('POST', sprintf('/tasks/step/%d/tick', $step->id), ['_token' => $this->token('tasks_toggle'), 'done' => '1']);

        self::assertResponseIsSuccessful();
        self::assertFalse($this->reloaded($step)->status->isOpen());
        self::assertEquals(new DateTimeImmutable('2026-06-01 10:00:00'), $this->reloaded($step)->completedAt);
    }

    /** An error rather than a toast with a redirect: the page has a tick to take back. */
    public function testATickThatCannotBeSavedIsAnError(): void
    {
        $list = $this->list();
        $task = $this->task($list, 'Parent');
        $step = $this->stepOf($task, 'A step');

        $list->isReadOnly = true;
        $this->em->flush();

        $this->client->request('POST', sprintf('/tasks/step/%d/tick', $step->id), ['_token' => $this->token('tasks_toggle'), 'done' => '1']);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->reloaded($step)->status->isOpen());

        $this->client->request('POST', sprintf('/tasks/step/%d/tick', $task->id), ['_token' => $this->token('tasks_toggle'), 'done' => '1']);

        self::assertResponseStatusCodeSame(404);
    }

    /** A finished entry is offered no fields for them, so this is a forged post. */
    public function testAFinishedEntryTakesNoSteps(): void
    {
        $series = $this->task($this->list(), 'Repeats');

        $entry               = $this->task($series->taskList, 'Entry');
        $entry->occurrenceOf = $series;
        $this->em->flush();

        $this->client->request('POST', '/tasks/save', [
            '_token' => $this->token('tasks_editor'),
            'taskId' => $entry->id,
            'title'  => 'Entry',
            'steps'  => ['Forged'],
        ]);

        self::assertNull($this->em->getRepository(Task::class)->findOneBy(['title' => 'Forged']));
    }

    /** Regression guard: the dialog used to answer as stored, dropping all of this. */
    public function testADialogActionPutsBackWhatWasTypedAndSavesNoneOfIt(): void
    {
        $task = $this->task($this->list(), 'Stored title');
        $step = $this->task($task->taskList, 'A step');

        $step->parent = $task;
        $this->em->flush();

        $crawler = $this->client->request('POST', sprintf('/tasks/step/%d/delete', $step->id), [
            '_token' => $this->token('tasks_editor'),
            'title'  => 'Typed title',
            'notes'  => 'Typed notes',
            'steps'  => ['Typed step', ''],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('Typed title', $crawler->filter('#task-title')->attr('value'));
        self::assertSame('Typed notes', $crawler->filter('#task-notes')->text());
        self::assertSame('Typed step', $crawler->filter('[data-tasks--new-steps-target="list"] input[name="steps[]"]')->first()->attr('value'));

        self::assertSame('Stored title', $this->reloaded($task)->title, 'typed is not saved until the dialog is');
        self::assertNull($this->em->getRepository(Task::class)->findOneBy(['title' => 'Typed step']));
    }

    public function testAFileChosenBesideADialogActionIsKeptRatherThanLost(): void
    {
        $task = $this->task($this->list(), 'Parent');
        $step = $this->task($task->taskList, 'A step');

        $step->parent = $task;
        $this->em->flush();

        $path = tempnam(sys_get_temp_dir(), 'task');
        file_put_contents($path, 'the plan');

        $this->client->request('POST', sprintf('/tasks/step/%d/delete', $step->id), [
            '_token' => $this->token('tasks_editor'),
        ], ['file' => new UploadedFile($path, 'beside.txt', 'text/plain', null, true)]);

        self::assertResponseIsSuccessful();

        $attachment = $this->em->getRepository(TaskAttachment::class)->findOneBy(['filename' => 'beside.txt']);

        self::assertNotNull($attachment);

        static::getContainer()->get(TaskAttachmentStorage::class)->remove((string) $attachment->storedName);
    }

    /**
     * Regression guard: Enter presses a form's first submit button, and that was the
     * footer's Delete. Asserted on the markup, since no client here presses Enter.
     */
    public function testTheFormsFirstSubmitButtonIsThePlainSave(): void
    {
        $task = $this->task($this->list(), 'Parent');

        $crawler = $this->client->request('GET', sprintf('/tasks/%d/edit', $task->id));
        $first   = $crawler->filter('#task-editor button[type="submit"], button[type="submit"][form="task-editor"]')->first();

        self::assertNull($first->attr('formaction'));
    }

    public function testANewTaskTakesItsStepsAndItsFileWithTheForm(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'task');
        file_put_contents($path, 'the plan');

        $this->client->request('POST', '/tasks/save', [
            '_token' => $this->token('tasks_editor'),
            'title'  => 'Whole',
            'steps'  => ['First', '  ', 'Second'],
        ], ['file' => new UploadedFile($path, 'plan.txt', 'text/plain', null, true)]);

        self::assertResponseIsSuccessful();

        $task = $this->em->getRepository(Task::class)->findOneBy(['title' => 'Whole']);

        self::assertNotNull($task);

        $this->em->refresh($task);

        self::assertSame(['First', 'Second'], array_map(static fn (Task $step): string => $step->title, $task->liveSteps()));
        self::assertCount(1, $task->attachments);

        static::getContainer()->get(TaskAttachmentStorage::class)->remove((string) $task->attachments->first()->storedName);
    }

    public function testARefusedSaveOfANewTaskPutsWhatWasTypedBack(): void
    {
        $crawler = $this->client->request('POST', '/tasks/save', [
            '_token'  => $this->token('tasks_editor'),
            'title'   => '   ',
            'notes'   => 'Kept notes',
            'dueDate' => '2031-03-04',
            'steps'   => ['Kept step'],
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('Kept notes', $crawler->filter('#task-notes')->text());
        self::assertSame('2031-03-04', $crawler->filter('#task-due-date')->attr('value'));
        self::assertSame('Kept step', $crawler->filter('[data-tasks--new-steps-target="list"] input[name="steps[]"]')->first()->attr('value'));
        self::assertCount(2, $crawler->filter('[data-tasks--new-steps-target="list"] input[name="steps[]"]'), 'and one empty, for the next');
    }

    /** Microsoft To Do keeps the day only; a time offered there would be gone at the next sync. */
    public function testOnAMicrosoftListTheDueTimeIsNotOffered(): void
    {
        $account                    = new Account();
        $account->usr               = $this->user;
        $account->name              = 'todo@example.test';
        $account->username          = 'todo@example.test';
        $account->email             = 'todo@example.test';
        $account->authType          = AuthType::OAuth2->value;
        $account->oauthProvider     = MailProvider::Microsoft->value;
        $account->oauthAccessToken  = 'access-token';
        $account->oauthRefreshToken = 'refresh-token';
        $this->em->persist($account);

        $list           = $this->list();
        $list->account  = $account;
        $list->remoteId = 'L1';
        $this->em->flush();

        $crawler = $this->client->request('GET', sprintf('/tasks/%d/edit', $this->task($list, 'To Do')->id));

        self::assertNotNull($crawler->filter('#task-due-time')->attr('disabled'));
        self::assertSelectorExists('#task-due-day-only');

        $crawler = $this->client->request('GET', sprintf('/tasks/%d/edit', $this->task($this->list(), 'Local')->id));

        self::assertNull($crawler->filter('#task-due-time')->attr('disabled'));
    }

    /** The first time, the list somebody with one list means; after that, what is mirrored and nothing else. */
    public function testTheSubscribeDialogTicksTheDefaultListOnlyTheFirstTime(): void
    {
        // The form's CSRF token wants a session, which a bare render has not got.
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        static::getContainer()->get('request_stack')->push($request);

        $twig    = static::getContainer()->get('twig');
        $default = new RemoteTaskList('L1', 'Tasks', isDefault: true);
        $other   = new RemoteTaskList('L2', 'Groceries');
        $render  = static fn (array $offered): Crawler => new Crawler($twig->render('tasks/_subscribe.html.twig', [
            'offered'     => $offered,
            'sourceKind'  => 'account',
            'sourceId'    => 1,
            'sourceLabel' => 'someone@example.test',
            'error'       => null,
            'colors'      => Calendar::COLORS,
        ]));
        $ticked = static fn (Crawler $page): array => $page->filter('input[name="remoteIds[]"][checked]')->each(static fn (Crawler $box): ?string => $box->attr('value'));

        self::assertSame(['L1'], $ticked($render([['remote' => $default, 'local' => null], ['remote' => $other, 'local' => null]])));
        $mirrored        = $this->list();
        $mirrored->color = Calendar::COLORS[4];

        $page = $render([['remote' => $default, 'local' => null], ['remote' => $other, 'local' => $mirrored]]);

        self::assertSame(['L2'], $ticked($page));

        // Its colour is picked right there, starting from what it has.
        self::assertSame(Calendar::COLORS[4], $page->filter('[data-task-subscribe-list="L2"] input[type="radio"][checked]')->attr('value'));
        self::assertCount(count(Calendar::COLORS), $page->filter('[data-task-subscribe-list="L1"] input[name="colors[L1]"]'));
    }

    public function testATaskIsMovedToAnotherOfTheUsersListsButNotToSomeoneElses(): void
    {
        $from    = $this->list();
        $to      = $this->list();
        $foreign = $this->list($this->otherUser());
        $task    = $this->task($from, 'Moving');

        $crawler = $this->client->request('GET', sprintf('/tasks/%d/edit', $task->id));

        self::assertContains((string) $to->id, $crawler->filter('#task-list option')->each(static fn (Crawler $option): ?string => $option->attr('value')));

        $this->client->request('POST', '/tasks/save', ['_token' => $this->token('tasks_editor'), 'taskId' => $task->id, 'title' => 'Moving', 'listId' => $foreign->id]);
        self::assertSame($from->id, $this->reloaded($task)->taskList->id, 'not to a list of someone else');

        $this->client->request('POST', '/tasks/save', ['_token' => $this->token('tasks_editor'), 'taskId' => $task->id, 'title' => 'Moving', 'listId' => $to->id]);

        self::assertStringContainsString('Task moved.', (string) $this->client->getResponse()->getContent());
        self::assertSame($to->id, $this->reloaded($task)->taskList->id);
    }

    /** The whole order, from the user's own rows: someone else's list is not moved, one left out goes last. */
    public function testTheListsAreKeptInTheOrderArrangedInTheDialog(): void
    {
        $this->client->request('GET', '/tasks');

        $a       = $this->list();
        $b       = $this->list();
        $c       = $this->list();
        $foreign = $this->list($this->otherUser());

        $ids = array_map(static fn (TaskList $list): int => (int) $list->id, [$c, $foreign, $a]);

        $this->client->request('POST', '/tasks/list/order', server: [
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_X_CSRF_TOKEN' => $this->token('tasks_list_order'),
        ], content: json_encode(['ids' => $ids], JSON_THROW_ON_ERROR));

        self::assertResponseIsSuccessful();

        $ordered = array_map(
            static fn (TaskList $list): ?int => $list->id,
            static::getContainer()->get(TaskListRepository::class)->findForUser($this->user),
        );

        self::assertSame([$c->id, $a->id], array_slice($ordered, 0, 2));
        self::assertGreaterThan(array_search($a->id, $ordered, true), array_search($b->id, $ordered, true), 'left out, so after');
        self::assertNotContains($foreign->id, $ordered);
    }

    /** The star in Lists: where a task lands when nothing else was picked. */
    public function testNewTasksLandOnTheChosenListAndBackOnTheFirstWhenItCannotTakeThem(): void
    {
        $chosen = $this->list();

        $this->client->request('POST', sprintf('/tasks/list/%d/landing', $chosen->id), ['_token' => $this->token('tasks_list_editor')]);
        self::assertSelectorExists(sprintf('[data-task-list="%d"] [data-task-list-landing]', $chosen->id));

        $this->client->request('POST', '/tasks/quick-add', ['_token' => $this->token('tasks_quick_add'), 'title' => 'Lands', 'listId' => 0]);
        self::assertSame($chosen->id, $this->em->getRepository(Task::class)->findOneBy(['title' => 'Lands'])?->taskList->id);

        // Read again: the request before has cleared what this test held.
        $chosen             = $this->em->getRepository(TaskList::class)->find($chosen->id);
        $chosen->isReadOnly = true;
        $this->em->flush();

        $this->client->request('POST', '/tasks/quick-add', ['_token' => $this->token('tasks_quick_add'), 'title' => 'Falls back', 'listId' => 0]);
        self::assertTrue($this->em->getRepository(Task::class)->findOneBy(['title' => 'Falls back'])?->taskList->isDefault);

        $this->client->request('POST', sprintf('/tasks/list/%d/landing', $chosen->id), ['_token' => $this->token('tasks_list_editor')]);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheDialogGoesInTheAgreedOrderForANewTaskAndASavedOne(): void
    {
        $order = ['title', 'steps', 'reminder', 'due', 'repeat', 'files', 'notes', 'list'];
        $parts = static fn ($crawler): array => $crawler->filter('[data-editor-part]')->each(static fn ($part): ?string => $part->attr('data-editor-part'));

        self::assertSame($order, $parts($this->client->request('GET', '/tasks/new')));

        $task = $this->task($this->list(), 'Saved');

        self::assertSame($order, $parts($this->client->request('GET', sprintf('/tasks/%d/edit', $task->id))));
    }

    public function testADialogWithoutARepeatFieldDoesNotStopTheRepeat(): void
    {
        $task                 = $this->task($this->list(), 'Repeats');
        $task->recurrenceRule = 'FREQ=DAILY';
        $this->em->flush();

        $this->client->request('POST', '/tasks/save', [
            '_token' => $this->token('tasks_editor'),
            'taskId' => $task->id,
            'title'  => 'Renamed',
        ]);

        self::assertSame('FREQ=DAILY', $this->reloaded($task)->recurrenceRule);
    }

    /** The group shows its count at once and reads its rows only once it is opened. */
    public function testFinishedTasksOfAListComeInTheirOwnFrame(): void
    {
        $list = $this->list();

        foreach (['One', 'Two'] as $title) {
            $task         = $this->task($list, 'Finished ' . $title);
            $task->status = TaskStatus::Completed;
        }

        $this->task($list, 'Still open');
        $this->em->flush();

        $crawler = $this->client->request('GET', sprintf('/tasks?view=lists&list=%d', $list->id));
        $group   = $crawler->filter('[data-tasks-section="completed"]');

        self::assertStringContainsString('2', $group->filter('summary')->text());
        self::assertCount(0, $group->filter('[data-task-row]'), 'nothing read until it is opened');
        self::assertSame('lazy', $group->filter('turbo-frame#tasks-completed')->attr('loading'));

        $crawler = $this->client->request('GET', sprintf('/tasks/completed?view=lists&list=%d', $list->id));

        self::assertCount(2, $crawler->filter('turbo-frame#tasks-completed [data-task-row]'));
        self::assertSame('tasks-pane-frame', $crawler->filter('turbo-frame#tasks-completed')->attr('target'), 'a tick answers with the pane');
        self::assertCount(0, $crawler->filter('a[href*="offset="]'), 'and no more to show');
    }

    /** In the gear's menu, and only with something to sync from. */
    public function testTheSyncEntryIsOnlyOfferedWithSomethingToSyncFrom(): void
    {
        $this->client->request('GET', '/tasks');
        self::assertSelectorNotExists('[data-tasks-subscribe]');

        $this->em->persist(new Integration($this->freshUser(), Provider::CalDav, 'Server'));
        $this->em->flush();

        $this->client->request('GET', '/tasks');
        self::assertSelectorExists('[data-ui--dropdown-target="menu"] [data-tasks-subscribe][role="menuitem"]');
        self::assertCount(1, $this->client->getCrawler()->filter('[data-tasks-subscribe]'), 'and nowhere else');
    }

    public function testAFileConnectionNoDriverSpeaksForDoesNotBringTheButton(): void
    {
        $this->em->persist(new Integration($this->freshUser(), Provider::Dropbox, 'Files'));
        $this->em->flush();

        $this->client->request('GET', '/tasks');

        self::assertSelectorNotExists('[data-tasks-subscribe]');
    }

    /** Regression guard: this page once linked to a route that does not exist. */
    public function testTheDialogLeftOpenWithNoSourceLeftStillRenders(): void
    {
        $this->client->request('GET', '/tasks/subscribe');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('turbo-frame#modal a[href*="/settings"]');
    }

    public function testASourceNoDriverSpeaksForCannotBeSubscribedTo(): void
    {
        $this->client->request('GET', '/tasks/subscribe/integration/999999');

        self::assertResponseStatusCodeSame(403);
    }

    public function testThePaneStateIsClampedAndRemembered(): void
    {
        $this->client->request('POST', '/tasks/pane-state', [
            '_token' => $this->token('tasks_pane_state'),
            'open'   => '1',
            'width'  => '99999',
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame(
            ['open' => true, 'width' => User::TASKS_PANE_MAX_WIDTH],
            json_decode((string) $this->client->getResponse()->getContent(), true),
        );
    }

    /** The session the token lives in, carried by hand, as AccountColourTest does. */
    // ── Transfer to a local list ─────────────────────────────────────────

    public function testOnlyASyncedListOffersATransfer(): void
    {
        $own    = $this->list();
        $synced = $this->synced();

        $crawler = $this->client->request('GET', '/tasks/lists');

        $transfers = static fn (TaskList $list): int => $crawler->filter(sprintf('[data-task-list="%d"] a[href*="/transfer"]', $list->id))->count();

        self::assertSame(1, $transfers($synced));
        self::assertSame(0, $transfers($own));

        $this->client->request('GET', sprintf('/tasks/list/%d/transfer', $own->id));
        self::assertResponseStatusCodeSame(404);

        $crawler = $this->client->request('GET', sprintf('/tasks/list/%d/transfer', $synced->id));
        self::assertResponseIsSuccessful();
        self::assertSame('0', $crawler->filter('#task-transfer-target option')->first()->attr('value'), 'a new list first');
        self::assertCount(0, $crawler->filter(sprintf('#task-transfer-target option[value="%d"]', $synced->id)), 'not into itself');
        self::assertCount(1, $crawler->filter(sprintf('#task-transfer-target option[value="%d"]', $own->id)));
    }

    /**
     * Regression guard for the server that is gone for good: asking it first is
     * right, and failing to must not leave the tasks stuck in it.
     */
    public function testAServerThatCannotBeAskedMovesNothingAndOffersToGoAhead(): void
    {
        $synced = $this->synced();
        $taskId = $this->task($synced, 'Stays for now')->id;

        $crawler = $this->client->request('POST', sprintf('/tasks/list/%d/transfer', $synced->id), [
            '_token' => $this->token('tasks_list_editor'),
            'target' => 0,
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('[data-task-transfer-failure]');
        self::assertSame('1', $crawler->filter('input[name="stale"]')->attr('value'));

        $this->em->clear();
        self::assertSame($synced->id, $this->em->find(Task::class, $taskId)?->taskList->id);
    }

    public function testATransferTakesEveryTaskToANewListInTheSyncedOnesPlaceAndLeavesTheServerAlone(): void
    {
        $synced            = $this->synced();
        $synced->color     = '#dc2626';
        $synced->sortOrder = 7;

        $open                = $this->task($synced, 'Open');
        $open->remoteId      = 'T1';
        $open->remoteEtag    = 'W/"1"';
        $open->remotePayload = '{"id":"T1"}';
        $step                = $this->stepOf($open, 'Step');
        $done                = $this->task($synced, 'Done');
        $done->status        = TaskStatus::Completed;
        $gone                = $this->task($synced, 'Deleted here');
        $gone->remoteId      = 'T3';
        $gone->syncState     = SyncState::PendingDelete;

        $this->user->setSetting(User::SETTING_TASKS_ADD_TO, $synced->id);
        $this->em->flush();

        $ids      = [$open->id, $step->id, $done->id];
        $goneId   = $gone->id;
        $syncedId = $synced->id;

        $this->client->request('POST', sprintf('/tasks/list/%d/transfer', $syncedId), [
            '_token' => $this->token('tasks_list_editor'),
            'target' => 0,
            'stale'  => 1,
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('2 tasks moved to “Synced”', (string) $this->client->getResponse()->getContent(), 'the step is not counted as a task');
        self::assertSelectorExists('turbo-stream[action="open_pane"]');

        $this->em->clear();

        self::assertNull($this->em->find(TaskList::class, $syncedId), 'unsubscribed');
        self::assertNull($this->em->find(Task::class, $goneId), 'a delete never sent is just gone');

        $moved = array_map(fn (int $id): Task => $this->em->find(Task::class, $id), $ids);
        $to    = $moved[0]->taskList;

        self::assertNull($to->remoteId);
        self::assertSame('Synced', $to->name);
        self::assertSame('#dc2626', $to->color);
        self::assertSame(7, $to->sortOrder);

        foreach ($moved as $task) {
            self::assertSame($to->id, $task->taskList->id);
            self::assertNull($task->remoteId);
            self::assertNull($task->remoteEtag);
            self::assertNull($task->remotePayload);
            self::assertSame(SyncState::Clean, $task->syncState);
        }

        self::assertSame($moved[0]->id, $moved[1]->parent?->id, 'a step stays with its task');
        self::assertSame($to->id, $this->em->find(User::class, $this->user->id)?->tasksAddToListId, 'new tasks land where the tasks went');
    }

    /** Transferring a list again, after subscribing to it again, brings the same uids to the same list. */
    public function testATransferIntoAListThatHasTheSameUidGivesTheNewcomerAnother(): void
    {
        $own    = $this->list();
        $synced = $this->synced();
        $there  = $this->task($own, 'From last time');
        $again  = $this->task($synced, 'Again');

        $again->uid = $there->uid;
        $this->em->flush();

        $againId = $again->id;
        $uid     = $there->uid;

        $this->client->request('POST', sprintf('/tasks/list/%d/transfer', $synced->id), [
            '_token' => $this->token('tasks_list_editor'),
            'target' => $own->id,
            'stale'  => 1,
        ]);

        self::assertResponseIsSuccessful();

        $this->em->clear();
        $moved = $this->em->find(Task::class, $againId);

        self::assertSame($own->id, $moved?->taskList->id);
        self::assertNotSame($uid, $moved->uid);
    }

    public function testATransferGoesOnlyToALocalListOfTheSameUser(): void
    {
        $synced = $this->synced();
        $other  = $this->synced();
        $local  = $this->list();
        $theirs = $this->list($this->otherUser());

        foreach ([$other, $theirs] as $target) {
            $this->client->request('POST', sprintf('/tasks/list/%d/transfer', $synced->id), [
                '_token' => $this->token('tasks_list_editor'),
                'target' => $target->id,
                'stale'  => 1,
            ]);

            self::assertResponseStatusCodeSame(404);
        }

        $this->client->request('POST', sprintf('/tasks/list/%d/transfer', $local->id), [
            '_token' => $this->token('tasks_list_editor'),
            'stale'  => 1,
        ]);

        self::assertResponseStatusCodeSame(404, 'a local list has nothing to transfer from');
    }

    private function token(string $id): string
    {
        $this->client->request('GET', '/tasks');

        $stack   = static::getContainer()->get('request_stack');
        $carrier = new Request();
        $carrier->setSession($this->client->getRequest()->getSession());
        $stack->push($carrier);

        try {
            return static::getContainer()->get('security.csrf.token_manager')->getToken($id)->getValue();
        } finally {
            $stack->pop();
        }
    }

    /** The signed-in user as this request's manager holds it. */
    private function freshUser(): User
    {
        $user = $this->em->getRepository(User::class)->find($this->user->id);

        self::assertNotNull($user);

        return $user;
    }

    /** What the database says now: a request clears the manager this test holds. */
    private function reloaded(Task $task): Task
    {
        $found = $this->em->getRepository(Task::class)->find($task->id);

        self::assertNotNull($found);

        return $found;
    }

    private function list(?User $owner = null): TaskList
    {
        $list       = new TaskList();
        $list->usr  = $owner ?? $this->user;
        $list->name = 'List';
        $this->em->persist($list);
        $this->em->flush();

        return $list;
    }

    /** Synced to nothing at all, so a pull of it fails the way an unreachable server's does. */
    private function synced(): TaskList
    {
        $list           = $this->list();
        $list->name     = 'Synced';
        $list->remoteId = uniqid('remote-', true);
        $this->em->flush();

        return $list;
    }

    private function task(TaskList $list, string $title): Task
    {
        $task           = new Task();
        $task->usr      = $list->usr;
        $task->taskList = $list;
        $task->title    = $title;
        $task->uid      = uniqid('task-', true);
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }

    private function stepOf(Task $task, string $title): Task
    {
        $step         = $this->task($task->taskList, $title);
        $step->parent = $task;

        $task->steps->add($step);
        $this->em->flush();

        return $step;
    }

    private function otherUser(): User
    {
        $other            = new User();
        $other->email     = sprintf('tasks-other-%s@example.test', uniqid());
        $other->nameFirst = 'Other';
        $other->nameLast  = 'Fixture';
        $other->password  = 'x';
        $this->em->persist($other);
        $this->em->flush();

        return $other;
    }
}
