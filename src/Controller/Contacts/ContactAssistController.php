<?php

declare(strict_types=1);

namespace App\Controller\Contacts;

use App\Controller\ChecksCsrf;
use App\Entity\Contacts\Contact;
use App\Entity\User\User;
use App\Repository\Contacts\ContactRepository;
use App\Service\Ai\ContactAssistant;
use App\Service\Contacts\ContactTextParser;
use App\Service\Contacts\SignatureFinder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The contact editor's helpers, as JSON for its Stimulus controllers:
 * reading pasted text (rules only, on every pause in typing), and the two
 * "Improve with AI" buttons. Nothing here saves; the form does.
 *
 * Apart from ContactController because these answer fetch() calls and that
 * one renders pages, and because the AI routes share a name prefix
 * (`app_contacts_ai_`) that InteractiveAiActivitySubscriber watches — a
 * person is waiting on them, so background indexing stands aside.
 *
 * One token id per kind of call, sent in the X-CSRF-Token header: reading
 * text costs nothing, asking the model costs a GPU, and a token for the first
 * should not be good for the second.
 */
#[Route('/contacts', name: 'app_contacts_')]
#[IsGranted('ROLE_USER')]
final class ContactAssistController extends AbstractController
{
    use ChecksCsrf;

    /** What the box sends at most; the parser cuts again at its own limit. */
    private const int MAX_TEXT = 8000;

    public function __construct(
        private readonly ContactTextParser $parser,
        private readonly ContactAssistant $assistant,
        private readonly SignatureFinder $signatures,
        private readonly ContactRepository $contacts,
    ) {
    }

    #[Route('/parse', name: 'parse', methods: ['POST'])]
    public function parse(Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'contacts_parse');

        $draft = $this->parser->parse($this->text($request), self::region($request));

        return $this->json($draft->toArray() + ['existing' => $this->existing($draft->fields)]);
    }

    /**
     * The pasted text read by the rules, then corrected by the model.
     *
     * 409 when contact help is off for this person or this installation —
     * the ComposeAssistController answer: nothing is forbidden, the page
     * should simply stop offering the button. 502 when the model was asked
     * and gave nothing usable.
     */
    #[Route('/ai/parse', name: 'ai_parse', methods: ['POST'])]
    public function aiParse(Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'contacts_ai');

        $user = $this->user();
        if (false === $this->assistant->isAvailableFor($user)) {
            return $this->json(['error' => 'not available'], Response::HTTP_CONFLICT);
        }

        $text  = $this->text($request);
        $rules = $this->parser->parse($text, self::region($request));
        $draft = $this->assistant->improve($user, $text, $rules, $request->getLocale());

        if (null === $draft) {
            return $this->json(['error' => 'no answer'], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json($draft->toArray() + ['existing' => $this->existing($draft->fields)]);
    }

    /**
     * Suggestions for a saved contact, from what is saved and the signature
     * of their newest mail. The answer says which mail that was, so the page
     * can tell the person what was sent.
     */
    #[Route('/{id<\d+>}/ai/suggest', name: 'ai_suggest', methods: ['POST'])]
    public function aiSuggest(int $id, Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'contacts_ai');

        $user    = $this->user();
        $contact = $this->contacts->findOneBy(['id' => $id, 'usr' => $user, 'trashedAt' => null]);

        if (!$contact instanceof Contact) {
            throw $this->createNotFoundException();
        }

        if (false === $contact->isEditable()) {
            throw $this->createAccessDeniedException();
        }

        if (false === $this->assistant->isAvailableFor($user)) {
            return $this->json(['error' => 'not available'], Response::HTTP_CONFLICT);
        }

        $signature   = $this->signatures->latestFor($user, $contact);
        $suggestions = $this->assistant->suggest($user, $contact, $signature, $request->getLocale());

        if (null === $suggestions) {
            return $this->json(['error' => 'no answer'], Response::HTTP_BAD_GATEWAY);
        }

        return $this->json($suggestions + [
            'signature' => null === $signature ? null : [
                'receivedAt' => $signature['receivedAt']?->format(\DATE_ATOM),
                'subject'    => $signature['subject'],
            ],
        ]);
    }

    /**
     * Contacts that already have one of the found addresses — quick add
     * offers to open that contact instead of making a second one.
     *
     * @param list<array<string, mixed>> $fields
     *
     * @return list<array{email: string, name: string, url: string}>
     */
    private function existing(array $fields): array
    {
        $emails = [];
        foreach ($fields as $field) {
            if ('email' === $field['type']) {
                $emails[] = (string) $field['value'];
            }
        }

        $out = [];
        foreach ($this->contacts->findByEmails($this->user(), $emails) as $row) {
            $out[] = [
                'email' => $row->email,
                'name'  => $row->contact->displayName,
                'url'   => $this->generateUrl('app_contacts_show', ['id' => $row->contact->id]),
            ];
        }

        return $out;
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }

    private function text(Request $request): string
    {
        return mb_substr($request->request->getString('text'), 0, self::MAX_TEXT);
    }

    /**
     * The country a number without one is read against: the region of the
     * browser's first language that names one (`de-AT` → AT), else the
     * country that goes with the page's language. English names none — it
     * could be any of a dozen — and such a number is kept as typed.
     */
    private static function region(Request $request): ?string
    {
        foreach ($request->getLanguages() as $language) {
            if (1 === preg_match('/^[a-z]{2}[_-]([A-Z]{2})$/i', $language, $m)) {
                return strtoupper($m[1]);
            }
        }

        return match (substr($request->getLocale(), 0, 2)) {
            'de'    => 'DE',
            'fr'    => 'FR',
            'zh'    => 'CN',
            default => null,
        };
    }
}
