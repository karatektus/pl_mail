<?php

declare(strict_types=1);

namespace App\Controller\Notes;

use App\Controller\ChecksCsrf;
use App\Entity\Note\Note;
use App\Entity\User\User;
use App\Repository\Calendar\CalendarEventRepository;
use App\Repository\Contacts\ContactRepository;
use App\Repository\Note\NoteRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Entity\Note\NoteShare;
use App\Twig\NoteFormatExtension;
use App\Service\Note\NoteMediaRefused;
use App\Service\Note\NoteMediaResponder;
use App\Service\Note\NoteMediaStorage;
use App\Repository\Note\NoteShareRepository;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Keep-style notes in the browser: the same notes the Android app keeps
 * through Note/get and Note/set, on the same `user_note` rows.
 *
 * The wall is server-rendered; the editor saves itself as you type
 * (notes--editor posts the whole note to autosave, debounced), the way Keep
 * does, so there is no Save button to forget. The limits are Note/set's, so a
 * note written here always reads back in the app and the other way round.
 */
#[Route('/notes', name: 'app_notes_')]
#[IsGranted('ROLE_USER')]
final class NoteController extends AbstractController
{
    use ChecksCsrf;

    private const array VIEWS = ['notes', 'archive', 'trash'];

    private const int MAX_BODY  = 50_000;
    private const int MAX_ITEMS = 300;
    private const int MAX_ITEM  = 1_000;
    private const int MAX_LINKS = 50;

    public function __construct(
        private readonly NoteRepository $notes,
        private readonly ContactRepository $contacts,
        private readonly CalendarEventRepository $events,
        private readonly EntityManagerInterface $em,
        private readonly NoteShareRepository $shares,
        private readonly NoteMediaStorage $media,
        private readonly NoteMediaResponder $mediaResponder,
    ) {}

    #[Route('', name: 'list', methods: ['GET'])]
    public function list(Request $request): Response
    {
        $view  = (string) $request->query->get('view', 'notes');
        $view  = \in_array($view, self::VIEWS, true) ? $view : 'notes';
        $q     = trim((string) $request->query->get('q', ''));
        $label = trim((string) $request->query->get('label', ''));

        $all    = $this->notes->findForUser($this->user());
        $labels = [];
        foreach ($all as $note) {
            foreach ($note->labels as $name) {
                $labels[mb_strtolower($name)] ??= $name;
            }
        }
        natcasesort($labels);

        $shown = array_values(array_filter(
            $all,
            static fn (Note $note): bool => match ($view) {
                'trash'   => null !== $note->trashedAt,
                'archive' => null === $note->trashedAt && $note->archived,
                default   => null === $note->trashedAt && false === $note->archived,
            } && ('' === $q || self::matches($note, $q))
              && ('' === $label || \in_array(mb_strtolower($label), array_map('mb_strtolower', $note->labels), true)),
        ));

        return $this->render('notes/list.html.twig', [
            'view'   => $view,
            'q'      => $q,
            'label'  => $label,
            'labels' => array_values($labels),
            'pinned' => array_values(array_filter($shown, static fn (Note $n): bool => 'notes' === $view && $n->pinned)),
            'others' => array_values(array_filter($shown, static fn (Note $n): bool => 'notes' !== $view || false === $n->pinned)),
            'colors' => Note::COLORS,
        ]);
    }

    /** The quick "take a note" form on the wall. */
    #[Route('', name: 'create', methods: ['POST'])]
    public function create(Request $request): Response
    {
        $this->assertCsrf($request, 'notes_create');

        $note      = new Note();
        $note->usr = $this->user();
        $this->applyForm($note, $request);

        if (true === self::isEmpty($note)) {
            return $this->redirectToRoute('app_notes_list', [], Response::HTTP_SEE_OTHER);
        }

        $this->em->persist($note);
        $this->em->flush();

        return $this->redirectToRoute('app_notes_list', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * A new note straight into the editor, optionally already linked — "Add
     * note" on a contact or an event, or "New list". The editor deletes it
     * again on Close if nothing was typed.
     */
    #[Route('/new', name: 'new', methods: ['POST'])]
    public function new(Request $request): Response
    {
        $this->assertCsrf($request, 'notes_new');

        $note      = new Note();
        $note->usr = $this->user();

        if ($request->request->getBoolean('checklist')) {
            $note->items = [['text' => '', 'done' => false]];
        }

        $link = self::link(
            (string) $request->request->get('link_type', ''),
            (string) $request->request->get('link_id', ''),
            (string) $request->request->get('link_title', ''),
        );
        if (null !== $link) {
            $note->links = [$link];
        }

        $this->em->persist($note);
        $this->em->flush();

        $params = ['id' => $note->id];
        $back   = (string) $request->request->get('return', '');
        if (str_starts_with($back, '/') && false === str_starts_with($back, '//')) {
            $params['return'] = $back;
        }

        return $this->redirectToRoute('app_notes_edit', $params, Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id<\d+>}', name: 'edit', methods: ['GET'])]
    public function edit(int $id, Request $request): Response
    {
        $note = $this->ownNote($id);
        $back = (string) $request->query->get('return', '');

        return $this->render('notes/edit.html.twig', [
            'note'     => $note,
            'colors'   => Note::COLORS,
            'contacts' => $this->contacts->findForUser($this->user(), null, null, 'name', 500, 0),
            'events'   => $this->events->findForNoteLinks($this->user()),
            'returnTo' => str_starts_with($back, '/') && false === str_starts_with($back, '//') ? $back : null,
            'shares'   => $this->shares->forNote($note),
        ]);
    }

    /**
     * The note as message HTML, for compose's "Insert" picker (the Notes group
     * of compose/_template_panel): the same JSON shape a template renders to,
     * so compose--compose#insertTemplate puts it in. No subject: a note dropped
     * into a mail is part of the message, not its name.
     */
    #[Route('/{id<\d+>}/as-message', name: 'as_message', methods: ['GET'])]
    public function asMessage(int $id): JsonResponse
    {
        $note = $this->ownNote($id);
        $html = '' !== $note->title ? '<p><strong>' . htmlspecialchars($note->title, ENT_QUOTES, 'UTF-8') . '</strong></p>' : '';

        if ([] !== $note->items) {
            $html .= '<ul>';
            foreach ($note->items as $item) {
                $html .= '<li>' . (true === ($item['done'] ?? false) ? '☑ ' : '☐ ') . htmlspecialchars((string) ($item['text'] ?? ''), ENT_QUOTES, 'UTF-8') . '</li>';
            }
            $html .= '</ul>';
        } else {
            $html .= (string) preg_replace(['/<div class="[^"]*">/', '/<div>/', '/<\/div>/', '/ class="[^"]*"/'], ['<p>', '<p>', '</p>', ''], NoteFormatExtension::format($note->body));
        }

        return new JsonResponse(['ok' => true, 'subject' => '', 'html' => $html]);
    }

    /**
     * Adds a picture, a drawing or a voice recording (multipart `file`, and
     * `kind`: image, drawing or audio). Answers the new entry and its URL.
     */
    #[Route('/{id<\d+>}/media', name: 'media_add', methods: ['POST'])]
    public function addMedia(int $id, Request $request): JsonResponse
    {
        $note = $this->ownNote($id);
        $this->assertCsrf($request, 'notes_' . $id);

        $file = $request->files->get('file');
        if (false === $file instanceof \Symfony\Component\HttpFoundation\File\UploadedFile || false === $file->isValid()) {
            return new JsonResponse(['error' => 'No file arrived.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $entry = $this->media->add(
                $note,
                (string) $request->request->get('kind', 'image'),
                $file->getClientOriginalName(),
                (string) ($file->getClientMimeType() ?: $file->getMimeType()),
                $file->getPathname(),
            );
        } catch (NoteMediaRefused $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->em->flush();

        return new JsonResponse(['ok' => true, 'media' => $entry, 'url' => $this->generateUrl('app_notes_media_show', ['id' => $note->id, 'mediaId' => $entry['id']])]);
    }

    #[Route('/{id<\d+>}/media/{mediaId<[a-f0-9]{24}>}', name: 'media_show', methods: ['GET'])]
    public function showMedia(int $id, string $mediaId): Response
    {
        return $this->mediaResponder->respond($this->ownNote($id), $mediaId) ?? throw $this->createNotFoundException();
    }

    #[Route('/{id<\d+>}/media/{mediaId<[a-f0-9]{24}>}/delete', name: 'media_delete', methods: ['POST'])]
    public function deleteMedia(int $id, string $mediaId, Request $request): JsonResponse
    {
        $note = $this->ownNote($id);
        $this->assertCsrf($request, 'notes_' . $id);

        if (false === $this->media->remove($note, $mediaId)) {
            return new JsonResponse(['error' => 'No such file.'], Response::HTTP_NOT_FOUND);
        }

        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    /** The note as a .txt file: title, then the text or the checklist. */
    #[Route('/{id<\d+>}/download.txt', name: 'download_txt', methods: ['GET'])]
    public function downloadTxt(int $id): Response
    {
        $note  = $this->ownNote($id);
        $lines = [];

        if ('' !== $note->title) {
            $lines[] = $note->title;
            $lines[] = '';
        }

        if ([] !== $note->items) {
            foreach ($note->items as $item) {
                $lines[] = (true === ($item['done'] ?? false) ? '[x] ' : '[ ] ') . ($item['text'] ?? '');
            }
        } else {
            $lines[] = $note->body;
        }

        $name     = '' !== $note->title ? $note->title : 'note-' . $note->id;
        $response = new Response(implode("\n", $lines) . "\n", Response::HTTP_OK, ['Content-Type' => 'text/plain; charset=UTF-8']);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            str_replace(['/', '\\'], '_', $name) . '.txt',
            (preg_replace('/[^\x20-\x7e]|[\/\\\\%]/', '_', $name) ?? 'note') . '.txt',
        ));

        return $response;
    }

    /**
     * A share link: anyone holding it can read the note (/n/<token>); with
     * "can edit" they can change it too. Optional password and expiry.
     */
    #[Route('/{id<\d+>}/shares', name: 'share_create', methods: ['POST'])]
    public function createShare(int $id, Request $request): Response
    {
        $note = $this->ownNote($id);
        $this->assertCsrf($request, 'notes_' . $id);

        $days    = (int) $request->request->get('expires', 0);
        $expires = \in_array($days, [1, 7, 30, 365], true) ? new \DateTimeImmutable(sprintf('+%d days', $days)) : null;
        $password = mb_substr((string) $request->request->get('password', ''), 0, 200);

        $this->em->persist(NoteShare::create($note, '' === $password ? null : $password, $expires, $request->request->getBoolean('can_edit')));
        $this->em->flush();

        return $this->redirect($this->generateUrl('app_notes_edit', ['id' => $note->id]) . '#share', Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id<\d+>}/shares/{shareId<\d+>}/delete', name: 'share_delete', methods: ['POST'])]
    public function deleteShare(int $id, int $shareId, Request $request): Response
    {
        $note = $this->ownNote($id);
        $this->assertCsrf($request, 'notes_' . $id);

        $share = $this->shares->find($shareId);
        if (null !== $share && $share->note === $note) {
            $this->em->remove($share);
            $this->em->flush();
        }

        return $this->redirect($this->generateUrl('app_notes_edit', ['id' => $note->id]) . '#share', Response::HTTP_SEE_OTHER);
    }

    /** Fallback for the editor without script: the whole form, posted. */
    #[Route('/{id<\d+>}', name: 'update', methods: ['POST'])]
    public function update(int $id, Request $request): Response
    {
        $note = $this->ownNote($id);
        $this->assertCsrf($request, 'notes_' . $id);
        $this->applyForm($note, $request);
        $this->em->flush();

        return $this->redirectToRoute('app_notes_edit', ['id' => $note->id], Response::HTTP_SEE_OTHER);
    }

    /**
     * The editor's autosave: the whole note as JSON — title, body or items,
     * colour, labels — replacing what is stored, as Note/set does.
     */
    #[Route('/{id<\d+>}/autosave', name: 'autosave', methods: ['POST'])]
    public function autosave(int $id, Request $request): JsonResponse
    {
        $note = $this->ownNote($id);
        $this->assertCsrf($request, 'notes_' . $id);

        $data = json_decode($request->getContent(), true);
        if (false === \is_array($data)) {
            return new JsonResponse(['error' => 'Malformed note.'], Response::HTTP_BAD_REQUEST);
        }

        $note->title = self::line($data['title'] ?? '');
        $note->color = \in_array($data['color'] ?? null, Note::COLORS, true) ? $data['color'] : null;
        $note->labels = Note::cleanLabels(\is_array($data['labels'] ?? null) ? $data['labels'] : []);

        if (true === ($data['checklist'] ?? false)) {
            $items = [];
            foreach (\is_array($data['items'] ?? null) ? $data['items'] : [] as $item) {
                if (false === \is_array($item)) {
                    continue;
                }
                $items[] = [
                    'text' => mb_substr(self::line($item['text'] ?? ''), 0, self::MAX_ITEM),
                    'done' => true === ($item['done'] ?? false),
                ];
            }
            $note->items = \array_slice($items, 0, self::MAX_ITEMS);
            // Keep keeps an emptied checklist a checklist: one blank line.
            if ([] === $note->items) {
                $note->items = [['text' => '', 'done' => false]];
            }
            $note->body = '';
        } else {
            $note->body  = self::text($data['body'] ?? '');
            $note->items = [];
        }

        $this->em->flush();

        return new JsonResponse(['ok' => true, 'updatedAt' => $note->updatedAt->format(\DATE_ATOM)]);
    }

    /** Close in the editor: an untouched new note is not kept. */
    #[Route('/{id<\d+>}/close', name: 'close', methods: ['POST'])]
    public function close(int $id, Request $request): Response
    {
        $note = $this->ownNote($id);
        $this->assertCsrf($request, 'notes_' . $id);

        $back = (string) $request->request->get('return', '');
        $view = null !== $note->trashedAt ? 'trash' : ($note->archived ? 'archive' : 'notes');

        if (true === self::isEmpty($note)) {
            $this->em->remove($note);
            $this->em->flush();
        }

        if (str_starts_with($back, '/') && false === str_starts_with($back, '//')) {
            return $this->redirect($back, Response::HTTP_SEE_OTHER);
        }

        return $this->redirectToRoute('app_notes_list', 'notes' === $view ? [] : ['view' => $view], Response::HTTP_SEE_OTHER);
    }

    /**
     * Set or clear the reminder: JSON `{"at": "<ISO 8601 with offset>"}` or
     * `{"at": null}`. Answers the stored instant, so the page can show it.
     */
    #[Route('/{id<\d+>}/reminder', name: 'reminder', methods: ['POST'])]
    public function reminder(int $id, Request $request): JsonResponse
    {
        $note = $this->ownNote($id);
        $this->assertCsrf($request, 'notes_' . $id);

        $data = json_decode($request->getContent(), true);
        $at   = \is_array($data) ? ($data['at'] ?? null) : false;

        if (null === $at) {
            $note->remindAt(null);
        } elseif (\is_string($at) && 1 === preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', $at)) {
            $note->remindAt(new \DateTimeImmutable($at)->setTimezone(new \DateTimeZone('UTC')));
        } else {
            return new JsonResponse(['error' => 'Malformed reminder.'], Response::HTTP_BAD_REQUEST);
        }

        $this->em->flush();

        return new JsonResponse([
            'ok' => true,
            'at' => $note->reminderAt?->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    #[Route('/{id<\d+>}/link', name: 'link', methods: ['POST'])]
    public function linkTo(int $id, Request $request): Response
    {
        $note = $this->ownNote($id);
        $this->assertCsrf($request, 'notes_' . $id);

        // The picker sends "type|id|title" in one select value.
        $parts = explode('|', (string) $request->request->get('target', ''), 3);
        $link  = self::link($parts[0], $parts[1] ?? '', $parts[2] ?? '');

        if (null !== $link && \count($note->links) < self::MAX_LINKS) {
            $links = [];
            foreach ([...$note->links, $link] as $l) {
                $links[$l['type'] . '|' . $l['id']] = $l;
            }
            $note->links = array_values($links);
            $this->em->flush();
        }

        return $this->redirectToRoute('app_notes_edit', ['id' => $note->id], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id<\d+>}/unlink', name: 'unlink', methods: ['POST'])]
    public function unlinkFrom(int $id, Request $request): Response
    {
        $note = $this->ownNote($id);
        $this->assertCsrf($request, 'notes_' . $id);

        $type = (string) $request->request->get('type', '');
        $lid  = (string) $request->request->get('link_id', '');
        $note->links = array_values(array_filter(
            $note->links,
            static fn (array $l): bool => !(($l['type'] ?? '') === $type && ($l['id'] ?? '') === $lid),
        ));
        $this->em->flush();

        return $this->redirectToRoute('app_notes_edit', ['id' => $note->id], Response::HTTP_SEE_OTHER);
    }

    /** One-click actions from a card or the editor: pin, archive, bin, restore, delete, tick an item. */
    #[Route('/{id<\d+>}/{action<pin|archive|trash|restore|destroy|toggle>}', name: 'action', methods: ['POST'])]
    public function action(int $id, string $action, Request $request): Response
    {
        $note = $this->ownNote($id);
        $this->assertCsrf($request, 'notes_' . $id);

        switch ($action) {
            case 'pin':
                $note->pinned = !$note->pinned;
                if ($note->pinned) {
                    $note->archived = false;
                }
                break;
            case 'archive':
                $note->archived = !$note->archived;
                if ($note->archived) {
                    $note->pinned = false;
                }
                break;
            case 'trash':
                $note->trashedAt ??= new DateTimeImmutable();
                $note->pinned = false;
                break;
            case 'restore':
                $note->trashedAt = null;
                break;
            case 'destroy':
                $this->media->removeAll($note);
                $this->em->remove($note);
                $this->em->flush();

                return $this->redirectToRoute('app_notes_list', ['view' => 'trash'], Response::HTTP_SEE_OTHER);
            case 'toggle':
                $index = $request->request->getInt('item', -1);
                $items = $note->items;
                if (isset($items[$index])) {
                    $items[$index]['done'] = !($items[$index]['done'] ?? false);
                    $note->items = array_values($items);
                }
                break;
        }

        $this->em->flush();

        if ('edit' === (string) $request->request->get('return', '')) {
            return $this->redirectToRoute('app_notes_edit', ['id' => $note->id], Response::HTTP_SEE_OTHER);
        }

        $view = null !== $note->trashedAt ? 'trash' : ($note->archived ? 'archive' : 'notes');

        return $this->redirectToRoute('app_notes_list', 'notes' === $view ? [] : ['view' => $view], Response::HTTP_SEE_OTHER);
    }

    // ── Private ───────────────────────────────────────────────────────────

    /**
     * Title, colour, labels, and either the text or the checklist, from the
     * plain form. A checklist arrives as one item per line; "[x]" marks done.
     */
    private function applyForm(Note $note, Request $request): void
    {
        $note->title = self::line($request->request->get('title', ''));

        $color       = (string) $request->request->get('color', '');
        $note->color = \in_array($color, Note::COLORS, true) ? $color : null;

        if ($request->request->has('labels')) {
            $note->labels = Note::cleanLabels(explode(',', (string) $request->request->get('labels', '')));
        }

        $text = self::text($request->request->get('body', ''));

        if ($request->request->getBoolean('checklist')) {
            $items = [];
            foreach (explode("\n", $text) as $line) {
                $done = 1 === preg_match('/^\s*\[[xX]\]\s*/', $line);
                $line = trim((string) preg_replace('/^\s*\[[ xX]?\]\s*/', '', $line));
                if ('' === $line) {
                    continue;
                }
                $items[] = ['text' => mb_substr($line, 0, self::MAX_ITEM), 'done' => $done];
            }
            $note->items = \array_slice($items, 0, self::MAX_ITEMS);
            $note->body  = '';
        } else {
            $note->body  = $text;
            $note->items = [];
        }
    }

    /**
     * @return array{type: string, id: string, title: ?string}|null
     */
    private static function link(string $type, string $id, string $title): ?array
    {
        if (false === \in_array($type, Note::LINK_TYPES, true) || '' === $id || mb_strlen($id) > 191) {
            return null;
        }

        $title = self::line($title);

        return ['type' => $type, 'id' => $id, 'title' => '' === $title ? null : $title];
    }

    private static function line(mixed $value): string
    {
        $text = \is_scalar($value) ? (string) $value : '';

        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($text))), 0, 255);
    }

    private static function text(mixed $value): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", \is_scalar($value) ? (string) $value : '');
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);

        return mb_substr(rtrim($text), 0, self::MAX_BODY);
    }

    private static function isEmpty(Note $note): bool
    {
        // Links do not count: "Add note" on a contact starts with one, and
        // closing it untouched should not leave a blank card behind.
        if ('' !== $note->title || '' !== trim($note->body)) {
            return false;
        }

        foreach ($note->items as $item) {
            if ('' !== trim((string) ($item['text'] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    private static function matches(Note $note, string $q): bool
    {
        $haystack = $note->title . "\n" . $note->body . "\n" . implode("\n", $note->labels);
        foreach ($note->items as $item) {
            $haystack .= "\n" . ($item['text'] ?? '');
        }

        return false !== mb_stripos($haystack, $q);
    }

    private function ownNote(int $id): Note
    {
        $note = $this->notes->find($id);

        if (null === $note || $note->usr->id !== $this->user()->id) {
            throw $this->createNotFoundException();
        }

        return $note;
    }

    private function user(): User
    {
        $user = $this->getUser();
        \assert($user instanceof User);

        return $user;
    }
}
