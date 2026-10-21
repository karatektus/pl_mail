<?php

declare(strict_types=1);

namespace App\Controller\Notes;

use App\Entity\Note\NoteShare;
use App\Repository\Note\NoteShareRepository;
use App\Service\Appearance\PublicAppearanceResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A shared note, for someone with no account here: /n/<token>.
 *
 * Like a Drive share (App\Controller\Drive\ShareController): the token is the
 * gate, a password is an optional second one, rate-limited per IP and link,
 * and no session starts — the page goes through sharing/_layout.
 *
 * A collaborator link (`canEdit`) can also save the title, the text and the
 * checklist through /n/<token>/save. With a password, a save must carry the
 * `grant` the unlocked page was given: an HMAC of the token and the password
 * hash under the app secret, so it proves the password was entered and stops
 * working the moment the link is deleted or its password changes. There is no
 * CSRF token for the same reason Drive's page has none — there is no session
 * to forge a request in; the token in the URL is what a caller must hold.
 */
final class NoteShareController extends AbstractController
{
    private const int MAX_BODY  = 50_000;
    private const int MAX_ITEMS = 300;

    public function __construct(
        private readonly NoteShareRepository         $shares,
        private readonly EntityManagerInterface      $em,
        private readonly PublicAppearanceResolver    $appearance,
        private readonly RateLimiterFactoryInterface $noteSharePasswordLimiter,
        private readonly RateLimiterFactoryInterface $noteShareSaveLimiter,
        private readonly \App\Service\Note\NoteMediaResponder $mediaResponder,
        #[Autowire('%kernel.secret%')]
        private readonly string                      $secret,
    ) {
    }

    #[Route('/n/{token<[a-f0-9]{64}>}', name: 'app_note_share', methods: ['GET', 'POST'])]
    public function show(Request $request, string $token): Response
    {
        $share    = $this->liveShare($token);
        $error    = null;
        $unlocked = null === $share->passwordHash;

        if (false === $unlocked && $request->isMethod('POST')) {
            $limiter = $this->noteSharePasswordLimiter->create($request->getClientIp() . '|' . $share->id);

            if (false === $limiter->consume()->isAccepted()) {
                $error = 'notes.share.too_many';
            } elseif ($share->checkPassword((string) $request->request->get('password', ''))) {
                $limiter->reset();
                $unlocked = true;
            } else {
                $error = 'notes.share.wrong_password';
            }
        }

        if (true === $unlocked) {
            ++$share->viewCount;
            $this->em->flush();
        }

        $response = $this->render('notes/share_public.html.twig', [
            'share'      => $share,
            'note'       => $share->note,
            'unlocked'   => $unlocked,
            'grant'      => $unlocked ? $this->grant($share) : null,
            'error'      => $error,
            'appearance' => $this->appearance->forOwner($share->note->usr),
        ], new Response(status: null !== $error ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    #[Route('/n/{token<[a-f0-9]{64}>}/save', name: 'app_note_share_save', methods: ['POST'])]
    public function save(Request $request, string $token): JsonResponse
    {
        $share = $this->liveShare($token);

        // Answered here rather than thrown: an AccessDeniedException for a
        // visitor with no session is turned into a redirect to the login page.
        if (false === $share->canEdit) {
            return new JsonResponse(['error' => 'This link is view only.'], Response::HTTP_FORBIDDEN);
        }

        if (false === $this->noteShareSaveLimiter->create($request->getClientIp() . '|' . $share->id)->consume()->isAccepted()) {
            return new JsonResponse(['error' => 'Too many saves.'], Response::HTTP_TOO_MANY_REQUESTS);
        }

        $data = json_decode($request->getContent(), true);

        if (false === \is_array($data)) {
            return new JsonResponse(['error' => 'Malformed note.'], Response::HTTP_BAD_REQUEST);
        }

        if (null !== $share->passwordHash && false === hash_equals($this->grant($share), (string) ($data['grant'] ?? ''))) {
            return new JsonResponse(['error' => 'Enter the password first.'], Response::HTTP_FORBIDDEN);
        }

        $note        = $share->note;
        $note->title = mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($data['title'] ?? '')))), 0, 255);

        if (true === \is_array($data['items'] ?? null) && [] !== $note->items) {
            $items = [];
            foreach ($data['items'] as $item) {
                if (true === \is_array($item)) {
                    $items[] = [
                        'text' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) ($item['text'] ?? ''))), 0, 1_000),
                        'done' => true === ($item['done'] ?? false),
                    ];
                }
            }
            $note->items = \array_slice($items, 0, self::MAX_ITEMS) ?: [['text' => '', 'done' => false]];
        } elseif ([] === $note->items) {
            $body       = str_replace(["\r\n", "\r"], "\n", (string) ($data['body'] ?? ''));
            $note->body = mb_substr(rtrim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $body)), 0, self::MAX_BODY);
        }

        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    /**
     * One of the note's pictures or recordings. Behind a password, the page
     * puts the grant in the URL (`?g=`), since an <img> cannot send a header.
     */
    #[Route('/n/{token<[a-f0-9]{64}>}/media/{mediaId<[a-f0-9]{24}>}', name: 'app_note_share_media', methods: ['GET'])]
    public function media(Request $request, string $token, string $mediaId): Response
    {
        $share = $this->liveShare($token);

        if (null !== $share->passwordHash && false === hash_equals($this->grant($share), (string) $request->query->get('g', ''))) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        $response = $this->mediaResponder->respond($share->note, $mediaId);
        if (null === $response) {
            throw $this->createNotFoundException();
        }
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function liveShare(string $token): NoteShare
    {
        $share = $this->shares->findByToken($token);

        if (null === $share || $share->isExpired() || null !== $share->note->trashedAt) {
            throw $this->createNotFoundException();
        }

        return $share;
    }

    private function grant(NoteShare $share): string
    {
        return hash_hmac('sha256', $share->token . '|' . ($share->passwordHash ?? ''), $this->secret);
    }
}
