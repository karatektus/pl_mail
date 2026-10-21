<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Note;

use App\Entity\Note\Note;
use App\Jmap\Method\MethodRegistry;
use App\Jmap\Method\Note\NoteGetMethod;
use App\Jmap\Method\Note\NoteSetMethod;
use App\Jmap\Protocol\Capability;
use App\Jmap\Session\SessionBuilder;
use App\Tests\Jmap\JmapTestCase;

/**
 * Notes over JMAP: `Note/get` and `Note/set`.
 *
 * What is pinned down: a note round-trips whole, the closed vocabularies (colour, link type) are
 * enforced, oversize and malformed input is refused rather than truncated, and `ifInState` stops a
 * stale writer.
 */
final class NoteSetMethodTest extends JmapTestCase
{
    private NoteGetMethod $get;
    private NoteSetMethod $set;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();
        $this->get = $container->get(NoteGetMethod::class);
        $this->set = $container->get(NoteSetMethod::class);
    }

    public function testANoteRoundTripsWithItsChecklistAndLinks(): void
    {
        $result = $this->set->handle(['create' => ['n1' => [
            'title'  => 'Dentist',
            'body'   => "Bring the X-rays\nAsk about the crown",
            'items'  => [['text' => 'Insurance card', 'done' => true], ['text' => 'Parking', 'done' => false]],
            'color'  => 'teal',
            'pinned' => true,
            'links'  => [['type' => 'event', 'id' => 'ev-1', 'title' => 'Dentist, 3 Nov']],
        ]]], $this->context());

        $id = $result['created']['n1']['id'];

        self::assertNotSame($result['oldState'], $result['newState']);

        $read = $this->get->handle(['ids' => [$id]], $this->context());
        $note = $read['list'][0];

        self::assertSame('Dentist', $note['title']);
        self::assertSame('teal', $note['color']);
        self::assertTrue($note['pinned']);
        self::assertFalse($note['archived']);
        self::assertFalse($note['trashed']);
        self::assertSame([['text' => 'Insurance card', 'done' => true], ['text' => 'Parking', 'done' => false]], $note['items']);
        self::assertSame([['type' => 'event', 'id' => 'ev-1', 'title' => 'Dentist, 3 Nov']], $note['links']);
        self::assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $note['createdAt']);
    }

    public function testTrashingStampsTheNoteAndRestoringClearsIt(): void
    {
        $id = $this->set->handle(['create' => ['n' => ['title' => 'x']]], $this->context())['created']['n']['id'];

        $this->set->handle(['update' => [$id => ['trashed' => true]]], $this->context());
        self::assertNotNull($this->em->find(Note::class, (int) $id)->trashedAt);
        self::assertTrue($this->get->handle(['ids' => [$id]], $this->context())['list'][0]['trashed']);

        $this->set->handle(['update' => [$id => ['trashed' => false]]], $this->context());
        self::assertNull($this->em->find(Note::class, (int) $id)->trashedAt);
    }

    public function testTheClosedVocabulariesAndSizesAreEnforced(): void
    {
        $result = $this->set->handle(['create' => [
            'colour' => ['color' => 'chartreuse'],
            'type'   => ['links' => [['type' => 'banana', 'id' => '1']]],
            'big'    => ['body' => str_repeat('a', 50_001)],
            'extra'  => ['title' => 'x', 'nope' => 1],
            'flag'   => ['pinned' => 'yes'],
        ]], $this->context());

        foreach (['colour', 'type', 'big', 'extra', 'flag'] as $creationId) {
            self::assertSame('invalidProperties', $result['notCreated'][$creationId]['type'], $creationId);
        }

        self::assertSame([], $this->get->handle([], $this->context())['list']);
    }

    public function testADuplicateLinkCollapsesAndControlCharactersAreStripped(): void
    {
        $id = $this->set->handle(['create' => ['n' => [
            'body'  => "a\x00b\r\nc",
            'links' => [
                ['type' => 'file', 'id' => 'f1', 'title' => 'Old'],
                ['type' => 'file', 'id' => 'f1', 'title' => 'New'],
            ],
        ]]], $this->context())['created']['n']['id'];

        $note = $this->get->handle(['ids' => [$id]], $this->context())['list'][0];

        self::assertSame("ab\nc", $note['body']);
        self::assertSame([['type' => 'file', 'id' => 'f1', 'title' => 'New']], $note['links']);
    }

    public function testLabelsAreTrimmedAndEachKeptOnce(): void
    {
        $id = $this->set->handle(['create' => ['n' => [
            'title'  => 'x',
            'labels' => [' Work ', 'work', 'Shopping', '', 42],
        ]]], $this->context())['created']['n']['id'];

        $note = $this->get->handle(['ids' => [$id]], $this->context())['list'][0];
        self::assertSame(['Work', 'Shopping'], $note['labels']);
    }

    public function testAStaleStateIsRefusedAndDestroyRemovesTheNote(): void
    {
        $id = $this->set->handle(['create' => ['n' => ['title' => 'x']]], $this->context())['created']['n']['id'];

        try {
            $this->set->handle(['ifInState' => 'stale', 'update' => [$id => ['title' => 'y']]], $this->context());
            self::fail('wrote from a stale state');
        } catch (\App\Jmap\Protocol\Exception\MethodException $exception) {
            self::assertSame('stateMismatch', $exception->toError()['type']);
        }

        $result = $this->set->handle(['destroy' => [$id, '999999']], $this->context());

        self::assertSame([$id], $result['destroyed']);
        self::assertSame('notFound', $result['notDestroyed']['999999']['type']);
    }

    public function testTheSessionAdvertisesTheCapabilityAndItsMethods(): void
    {
        $container = self::getContainer();
        $session   = $container->get(SessionBuilder::class)->build($this->user);

        self::assertContains(Capability::NOTES, Capability::SUPPORTED);
        self::assertContains('event', $session['capabilities'][Capability::NOTES]['linkTypes']);

        foreach ($session['accounts'] as $account) {
            self::assertArrayNotHasKey(Capability::NOTES, $account['accountCapabilities'], 'notes are the user\'s, not an account\'s');
        }

        foreach (['Note/get', 'Note/set'] as $name) {
            self::assertNotNull($container->get(MethodRegistry::class)->get($name), sprintf('"%s" is not registered', $name));
        }
    }
}
