<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Controller\ChecksCsrf;
use App\Entity\Mail\Account;
use App\Entity\Template\MailTemplate;
use App\Entity\User\User;
use App\Security\Voter\OwnershipVoter;
use App\Service\Mail\ComposeWindow;
use App\Service\Mail\MailBodySanitizer;
use App\Service\Mail\SenderResolver;
use App\Service\Template\TemplateEditorViewData;
use App\Service\Template\TemplateLibrary;
use App\Service\Template\TemplateRenderer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Templates as the compose window meets them: the list to pick from, one
 * template rendered for insertion, and saving the message on screen as a new
 * one.
 *
 * Beside ComposeController rather than inside it. That class is the life of a
 * draft — open, save, send, undo — and none of these three touches a draft:
 * they read the template library and answer the browser, which does the
 * inserting (compose--template-picker, compose--compose#insertTemplate).
 *
 * EVERY ROUTE TAKES THE FROM TOKEN the window's hidden account select carries
 * (`accountId|address`, see SenderResolver), not an account id. The address
 * half is what tells an alias's signature from its account's, and the token is
 * resolved against the signed-in user's own accounts — so a token naming
 * somebody else's account resolves to nothing and falls back to the default,
 * exactly as it does for a send.
 */
#[Route('/compose/templates', name: 'app_compose_templates_')]
#[IsGranted('ROLE_USER')]
final class ComposeTemplateController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly TemplateLibrary        $library,
        private readonly TemplateRenderer       $renderer,
        private readonly TemplateEditorViewData $editorData,
        private readonly SenderResolver         $senders,
        private readonly ComposeWindow          $window,
        private readonly MailBodySanitizer      $sanitizer,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface    $translator,
    ) {
    }

    /**
     * The picker: every template the user has, the ones filed under the
     * current From account first.
     *
     * All of them, not only that account's and the top level's. Filing a
     * template under an account says where it is usually wanted, not where it
     * is allowed; the day somebody needs the work reply from the private
     * address, a list that hid it would send them to Settings to copy it.
     */
    #[Route('/panel', name: 'panel', methods: ['GET'])]
    public function panel(Request $request): Response
    {
        $user = $this->currentUser();

        return $this->render('compose/_template_panel.html.twig', [
            'tree'       => $this->library->tree($user),
            'current'    => $this->account($request->query->get('account'), $user),
            'chipLabels' => $this->editorData->chipLabels($user),
        ]);
    }

    /**
     * One template, with everything the server knows filled in.
     *
     * A GET: it changes nothing, and what it reads — the user's own template,
     * signature and clock — is theirs already.
     */
    #[Route('/{id}/render', name: 'render', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function renderTemplate(Request $request, MailTemplate $template): JsonResponse
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $template);

        $user    = $this->currentUser();
        $token   = $request->query->get('account');
        $account = $this->account($token, $user);

        if (null === $account) {
            return $this->json(['ok' => false], Response::HTTP_CONFLICT);
        }

        $rendered = $this->renderer->render(
            $template->subject,
            $template->body,
            $account,
            $this->senders->addressFor($token, $account, $user),
            $user,
        );

        return $this->json(['ok' => true, 'subject' => $rendered->subject, 'html' => $rendered->html]);
    }

    /**
     * Keep the message on screen as a template.
     *
     * Filed under the account it is being written from, and named after its
     * subject: both are guesses, both are what the person would most likely
     * have typed, and both are one click to change in Settings — which the
     * toast links to. Asking first would put a form between somebody and a
     * button that reads as "save".
     *
     * The browser sends the body with the window's own signature block already
     * swapped for a `{{signature}}` variable and the quoted original removed
     * (compose--template-picker#saveDraft). Without the first, the template
     * would carry today's signature as fixed text and gain a second one every
     * time it was inserted; without the second, it would carry somebody else's
     * mail. The sanitiser then treats what arrives as it treats every body.
     */
    #[Route('/save-draft', name: 'save_draft', methods: ['POST'])]
    public function saveDraft(Request $request): JsonResponse
    {
        $this->assertCsrf($request, 'template_save_draft');

        $user    = $this->currentUser();
        $payload = $request->toArray();
        $body    = $this->sanitizer->sanitizeFragment((string) ($payload['body'] ?? ''));

        if ('' === trim(strip_tags($body))) {
            return $this->json(['ok' => false], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $subject = mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) ($payload['subject'] ?? '')))), 0, 255);
        $account = $this->account($payload['account'] ?? null, $user);

        $template          = new MailTemplate();
        $template->usr     = $user;
        $template->name    = '' === $subject ? $this->translator->trans('compose.template.untitled') : $subject;
        $template->subject = '' === $subject ? null : $subject;
        $template->body    = $body;

        $this->library->place(
            $template,
            $this->library->locate($user, null === $account ? 'root' : sprintf('account:%d', $account->id))
                ?? $this->library->locate($user, 'root'),
        );

        $this->em->persist($template);
        $this->em->flush();

        return $this->json([
            'ok'      => true,
            'message' => $this->translator->trans('compose.template.saved', ['%name%' => $template->name]),
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /** The account a From token names, else the one a new window would open on. */
    private function account(mixed $token, User $user): ?Account
    {
        return $this->senders->accountFor($token, $user) ?? $this->window->defaultAccountFor($user);
    }

    private function currentUser(): User
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }
}
