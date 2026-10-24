<?php

declare(strict_types=1);

namespace App\Tests\Controller\AddressBook;

use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Ai\AiSettings;
use App\Entity\Contacts\Contact;
use App\Entity\Contacts\ContactEmail;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The contact editor's JSON helpers: reading pasted text, and the two
 * "Improve with AI" routes — what they refuse, what they send the model, and
 * what they make of its answer. The model is a MockHttpClient that answers
 * with whatever $this->answer holds and keeps what it was asked.
 */
final class ContactAssistTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    /** What the fake model answers: the JSON the assistant asked for. */
    private string $answer = '{}';

    /** @var list<array<string, mixed>> what the fake model was sent */
    private array $asked = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        // Before anything builds the real one; see ThreadSummaryTest::signIn().
        $container->set('http_client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->asked[] = json_decode((string) ($options['body'] ?? '{}'), true) ?? [];

            return new MockResponse(json_encode(['message' => ['content' => $this->answer], 'done' => true, 'eval_count' => 1]));
        }));

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->connection->beginTransaction();

        $this->user            = new User();
        $this->user->email     = 'contact-assist-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Contact';
        $this->user->nameLast  = 'Assist';
        $this->user->roles     = ['ROLE_USER'];
        $this->user->password  = 'x';
        $this->em->persist($this->user);
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testPastedTextIsReadAndAnAddressAlreadyOnAContactIsPointedOut(): void
    {
        $mia = $this->contact('Mia Hoffmann', 'mia@example.net');

        $data = $this->post('/contacts/parse', $this->token('parse'), ['text' => "Mia Hoffmann <MIA@example.net>\nwww.mia.example"]);

        self::assertResponseIsSuccessful();
        self::assertSame('rules', $data['source']);
        self::assertSame('Mia Hoffmann', $data['name']);
        self::assertSame(['givenName' => 'Mia', 'familyName' => 'Hoffmann'], $data['parts']);
        self::assertSame([['type' => 'email', 'label' => 'work', 'value' => 'MIA@example.net'], ['type' => 'url', 'label' => 'work', 'value' => 'www.mia.example']], $data['fields']);
        self::assertSame('Mia Hoffmann', $data['existing'][0]['name'], 'the address matches whatever its case');
        self::assertSame('/contacts/' . $mia->id, $data['existing'][0]['url']);
        self::assertSame([], $this->asked, 'reading by rules asks no model');
    }

    public function testReadingNeedsItsOwnToken(): void
    {
        $this->post('/contacts/parse', 'not-a-token', ['text' => 'Ada Lovelace']);

        self::assertResponseStatusCodeSame(403);
    }

    public function testWithoutContactHelpThereIsNoButtonAndTheRoutesSaySo(): void
    {
        $this->client->request('GET', '/contacts/new');
        self::assertSelectorNotExists('[data-contacts--quick-add-ai-url-value]');

        $this->switchOn();
        $token = $this->token('ai');

        // Through the container's entity manager: the request above reset the
        // one this test started with, and $this->user is no longer its.
        $em   = static::getContainer()->get(EntityManagerInterface::class);
        $user = $em->find(User::class, $this->user->id);
        self::assertInstanceOf(User::class, $user);
        $user->aiPreferences->contactHelpOff = true;
        $em->flush();

        $this->post('/contacts/ai/parse', $token, ['text' => 'Ada Lovelace']);

        self::assertResponseStatusCodeSame(409, (string) $this->client->getResponse()->getContent());
        self::assertSame([], $this->asked);
    }

    public function testTheAiReadingMarksOnlyWhatItChangedAndKeepsToTheForm(): void
    {
        $this->switchOn();
        $this->answer = json_encode([
            'name'   => 'Dr. Anna-Lena van der Berg',
            'parts'  => ['namePrefix' => 'Dr.', 'givenName' => 'Anna-Lena', 'familyName' => 'van der Berg'],
            'fields' => [
                ['type' => 'jobTitle', 'label' => '', 'value' => 'Leiterin Einkauf', 'reason' => 'Kept'],
                ['type' => 'company', 'label' => '', 'value' => 'Berg & Söhne Feinkost', 'reason' => 'The line the rules could not place'],
                ['type' => 'phone', 'label' => 'cellular', 'value' => '+49 221 98765-99'],
                ['type' => 'linkedin', 'label' => '', 'value' => 'anna-lena'],
            ],
        ], \JSON_UNESCAPED_UNICODE);

        $data = $this->post('/contacts/ai/parse', $this->token('ai'), ['text' => "Dr. Anna-Lena van der Berg\nLeiterin Einkauf\nBERG & SÖHNE Feinkost"]);

        self::assertResponseIsSuccessful();
        self::assertSame('ai', $data['source']);
        self::assertSame(['namePrefix' => 'Dr.', 'givenName' => 'Anna-Lena', 'familyName' => 'van der Berg'], $data['parts']);
        self::assertSame([
            ['type' => 'jobTitle', 'label' => '', 'value' => 'Leiterin Einkauf'],
            ['type' => 'company', 'label' => '', 'value' => 'Berg & Söhne Feinkost', 'reason' => 'The line the rules could not place'],
            ['type' => 'phone', 'label' => 'other', 'value' => '+49 221 98765-99', 'reason' => ''],
        ], $data['fields'], 'a row the rules had is not marked; an unknown label is "other"; an unknown type is dropped');

        $sent = json_encode($this->asked[0], \JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('BERG & SÖHNE Feinkost', (string) $sent, 'the text goes to the model');
        self::assertStringContainsString('unplaced_lines', (string) $sent, 'with the rules\' reading');
    }

    public function testSuggestionsSendTheSignatureAndNeverTheMail(): void
    {
        $this->switchOn();
        $lena = $this->contact('Lena Vogt', 'lena.vogt@nordlicht.example');
        $this->mail('lena.vogt@nordlicht.example', "Hi,\n\nthe secret plans are attached.\n\nBest regards\nLena Vogt\nCreative Director\n+49 30 5550123\n\n> On Monday you wrote:\n> the old quote");
        $this->answer = json_encode([
            'name'   => null,
            'fields' => [['type' => 'jobTitle', 'label' => '', 'value' => 'Creative Director', 'reason' => 'From her signature']],
        ]);

        $data = $this->post('/contacts/' . $lena->id . '/ai/suggest', $this->token('ai', $lena), []);

        self::assertResponseIsSuccessful();
        self::assertSame([['type' => 'jobTitle', 'label' => '', 'value' => 'Creative Director', 'reason' => 'From her signature']], $data['fields']);
        self::assertNotNull($data['signature']['receivedAt']);

        $sent = (string) json_encode($this->asked[0], \JSON_UNESCAPED_UNICODE);
        self::assertStringContainsString('Creative Director', $sent);
        self::assertStringNotContainsString('secret plans', $sent, 'only what is under the closing');
        self::assertStringNotContainsString('old quote', $sent, 'nor the quoted reply');
    }

    public function testNobodyAsksAboutSomebodyElsesContact(): void
    {
        $this->switchOn();
        $token = $this->token('ai');

        $stranger           = new User();
        $stranger->email     = 'contact-stranger-' . uniqid('', true) . '@example.test';
        $stranger->nameFirst = 'Somebody';
        $stranger->nameLast  = 'Else';
        $stranger->roles     = ['ROLE_USER'];
        $stranger->password = 'x';
        $this->em->persist($stranger);
        $theirs = Contact::create($stranger, 'Not Yours');
        $this->em->persist($theirs);
        $this->em->flush();

        $this->post('/contacts/' . $theirs->id . '/ai/suggest', $token, []);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->asked);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function switchOn(): void
    {
        $this->connection->executeStatement('DELETE FROM ai_settings');

        $settings                     = new AiSettings();
        $settings->isEnabled          = true;
        $settings->baseUrl            = 'http://model-host.invalid:11434';
        $settings->chatModel          = 'qwen3:30b';
        $settings->contactHelpEnabled = true;

        $this->em->persist($settings);
        $this->em->flush();
    }

    /**
     * A token from the page that would send it: quick add for both, or a
     * contact's editor for the suggestions.
     */
    private function token(string $kind, ?Contact $contact = null): string
    {
        $crawler = $this->client->request('GET', null === $contact ? '/contacts/new' : '/contacts/' . $contact->id . '/edit');
        $form    = $crawler->filter(null === $contact ? '[data-controller="contacts--quick-add"]' : '[data-controller="contacts--editor"]');
        $prefix  = null === $contact ? 'data-contacts--quick-add-' : 'data-contacts--editor-';

        return (string) $form->attr($prefix . $kind . '-token-value');
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array<string, mixed>
     */
    private function post(string $url, string $token, array $fields): array
    {
        $this->client->request('POST', $url, $fields, [], ['HTTP_X-CSRF-Token' => $token, 'HTTP_ACCEPT' => 'application/json']);

        return json_decode((string) $this->client->getResponse()->getContent(), true) ?? [];
    }

    private function contact(string $name, string $email): Contact
    {
        $contact = Contact::create($this->user, $name);
        $contact->emails->add(ContactEmail::create($contact, $email, 'work', true));
        $this->em->persist($contact);
        $this->em->flush();

        return $contact;
    }

    private function mail(string $from, string $body): void
    {
        $account                 = new Account();
        $account->usr            = $this->user;
        $account->name           = 'Assist fixture';
        $account->email          = 'assist@example.test';
        $account->username       = 'assist-' . uniqid('', true) . '@example.test';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);

        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = 'Proofs';
        $thread->normalizedSubject = 'proofs';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable('2026-10-14');
        $thread->unreadCount       = 0;
        $thread->messageCount      = 1;
        $this->em->persist($thread);

        $message                 = new Message();
        $message->account        = $account;
        $message->thread         = $thread;
        $message->subject        = 'Proofs';
        $message->fromAddress    = $from;
        $message->fromName       = 'Lena Vogt';
        $message->bodyText       = $body;
        $message->receivedAt     = new DateTimeImmutable('2026-10-14 09:00');
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->messageId      = sprintf('<assist-%s@example.test>', uniqid('', true));
        $thread->addMessage($message);
        $this->em->persist($message);

        $this->em->flush();
    }
}
