<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Controller\RendersTurboStreams;
use App\Domain\DTO\Template\TemplateToken;
use App\Domain\Enum\Template\TemplateVariable;
use App\Entity\Template\MailTemplate;
use App\Entity\Template\TemplateFolder;
use App\Entity\User\User;
use App\Security\Voter\OwnershipVoter;
use App\Service\Mail\ComposeWindow;
use App\Service\Mail\MailBodySanitizer;
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
 * Settings → Templates: the tree on the left, one editor on the right.
 *
 * Shaped after MailRuleController, which is the other section that is a list
 * plus an editor in a frame: the editor is fetched into `template-editor`,
 * every write answers with a stream that redraws the tree and closes the
 * editor, and the tree is the thing that says whether the write worked.
 *
 * Templates and folders share the controller and the frame because they share
 * the tree. A folder form is three fields; giving it a controller of its own
 * would mean a second class that redraws the same tree after every write.
 *
 * WHAT THE BODY IS WHEN IT ARRIVES. The editor shows variables as chips and
 * turns them back into `{{tokens}}` before it posts, so the body here is
 * ordinary HTML with tokens in its text. It is sanitised regardless — with the
 * inbound-mail allow-list, because it is the innerHTML of a contenteditable
 * and will be injected into the compose window's own document — and a chip
 * that somehow arrived as markup simply loses its attributes and becomes its
 * label. The editor is a convenience, never a guarantee.
 *
 * A place in the tree always arrives as a key and always goes through
 * TemplateLibrary::locate(), which answers null for anything that is not this
 * user's. That is the ownership check for the Folder dropdown; the voter covers
 * the template or folder named in the URL.
 */
#[Route('/settings/templates', name: 'app_settings_templates_')]
#[IsGranted('ROLE_USER')]
final class TemplateController extends AbstractController
{
    use ChecksCsrf;
    use RendersTurboStreams;

    private const int NAME_LENGTH = 255;

    public function __construct(
        private readonly TemplateLibrary        $library,
        private readonly TemplateRenderer       $renderer,
        private readonly TemplateEditorViewData $editorData,
        private readonly MailBodySanitizer      $sanitizer,
        private readonly ComposeWindow          $window,
        private readonly EntityManagerInterface $em,
        private readonly TranslatorInterface    $translator,
    ) {
    }

    // ── Templates ─────────────────────────────────────────────────────────────

    /** A blank editor, pre-filed under whichever folder its "+" was pressed in. */
    #[Route('/new', name: 'new', methods: ['GET'])]
    public function new(Request $request): Response
    {
        $template = new MailTemplate();
        $location = $this->library->locate($this->currentUser(), (string) $request->query->get('in', ''));

        if (null !== $location) {
            $this->library->place($template, $location);
        }

        return $this->editor($template);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function edit(MailTemplate $template): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $template);

        return $this->editor($template);
    }

    /** Closes the editor frame without saving. */
    #[Route('/cancel', name: 'cancel', methods: ['GET'])]
    public function cancel(): Response
    {
        return $this->render('settings/templates/_editor_empty.html.twig');
    }

    #[Route('/save', name: 'save', methods: ['POST'])]
    public function save(Request $request): Response
    {
        $this->assertCsrf($request, 'template_save');

        $user     = $this->currentUser();
        $id       = (string) $request->request->get('id', '');
        $template = '' === $id ? new MailTemplate() : $this->em->find(MailTemplate::class, (int) $id);

        if (null === $template) {
            throw $this->createNotFoundException();
        }

        if (null !== $template->id) {
            $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $template);
        }

        $location = $this->library->locate($user, (string) $request->request->get('location', ''));

        if (null === $location) {
            throw $this->createNotFoundException();
        }

        $name = $this->line($request->request->get('name'));

        // Onto the entity before validating, so a refused save re-renders the
        // editor with what was typed rather than with what was stored. Nothing
        // is flushed on that path, and the entity is not persisted yet if new.
        $template->name    = $name;
        $template->subject = '' === ($subject = $this->line($request->request->get('subject'))) ? null : $subject;
        $template->body    = $this->sanitizer->sanitizeFragment((string) $request->request->get('body', ''));
        $this->library->place($template, $location);

        if ('' === $name) {
            return $this->editor($template, ['settings.templates.error.name_required'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (null === $template->id) {
            $template->usr = $user;
            $this->em->persist($template);
        }

        $this->em->flush();

        return $this->treeStream('settings.templates.saved');
    }

    #[Route('/{id}/duplicate', name: 'duplicate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function duplicate(Request $request, MailTemplate $template): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $template);
        $this->assertCsrf($request, 'template_duplicate' . $template->id);

        $this->library->duplicate(
            $template,
            mb_substr($this->translator->trans('settings.templates.copy_of', ['%name%' => $template->name]), 0, self::NAME_LENGTH),
        );
        $this->em->flush();

        return $this->treeStream('settings.templates.duplicated');
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Request $request, MailTemplate $template): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $template);
        $this->assertCsrf($request, 'template_delete' . $template->id);

        $this->em->remove($template);
        $this->em->flush();

        return $this->treeStream('settings.templates.deleted');
    }

    /**
     * What the template on screen would insert, without saving it.
     *
     * Not CSRF-checked, like the filter editor's preview: it writes nothing.
     * It reads only what the caller already has — their own signature and
     * today's date.
     *
     * The From it renders against is the account the template is filed under,
     * or the default one for a template at the top level, because those are
     * the windows it will most often be inserted into. With no account at all
     * there is nothing to sign as, and the answer says so rather than
     * inventing a sender.
     */
    #[Route('/preview', name: 'preview', methods: ['POST'])]
    public function preview(Request $request): JsonResponse
    {
        $user    = $this->currentUser();
        $payload = $request->toArray();

        $location = $this->library->locate($user, (string) ($payload['location'] ?? ''));
        $account  = $location->account ?? $this->window->defaultAccountFor($user);

        if (null === $account) {
            return $this->json(['ok' => false]);
        }

        $rendered = $this->renderer->render(
            $this->line($payload['subject'] ?? ''),
            $this->sanitizer->sanitizeFragment((string) ($payload['body'] ?? '')),
            $account,
            null,
            $user,
        );

        return $this->json(['ok' => true, 'subject' => $rendered->subject, 'html' => $rendered->html]);
    }

    /**
     * One date, written with the offset and format being chosen — the live
     * example beside the date controls.
     *
     * Asked of the server rather than worked out in the browser so that the
     * example cannot differ from the mail: the browser's Intl and PHP's ICU
     * agree on most dates and nobody can say which ones they do not.
     */
    #[Route('/date-example', name: 'date_example', methods: ['POST'])]
    public function dateExample(Request $request): JsonResponse
    {
        $payload = $request->toArray();

        return $this->json([
            'text' => $this->renderer->dateExample(
                new TemplateToken(TemplateVariable::Date, [
                    'offset' => (string) ($payload['offset'] ?? ''),
                    'format' => (string) ($payload['format'] ?? ''),
                ]),
                $this->currentUser(),
            ),
        ]);
    }

    // ── Folders ───────────────────────────────────────────────────────────────

    #[Route('/folder/new', name: 'folder_new', methods: ['GET'])]
    public function newFolder(Request $request): Response
    {
        $user = $this->currentUser();

        return $this->render('settings/templates/_folder_editor.html.twig', [
            'folder'   => new TemplateFolder(),
            'location' => $this->library->locate($user, (string) $request->query->get('in', ''))?->key() ?? 'root',
            'tree'     => $this->library->tree($user),
            'errors'   => [],
        ]);
    }

    #[Route('/folder/{id}/edit', name: 'folder_edit', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function editFolder(TemplateFolder $folder): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $folder);

        return $this->render('settings/templates/_folder_editor.html.twig', [
            'folder'   => $folder,
            'location' => null,
            'tree'     => null,
            'errors'   => [],
        ]);
    }

    /**
     * Create a folder, or rename one.
     *
     * Rename only: an existing folder cannot be moved. Moving one means
     * re-filing every folder and template beneath it under a different
     * account, and refusing a move into its own descendant; neither is needed
     * to sort a few dozen templates, and a folder that is in the wrong place
     * can be emptied and deleted.
     */
    #[Route('/folder/save', name: 'folder_save', methods: ['POST'])]
    public function saveFolder(Request $request): Response
    {
        $this->assertCsrf($request, 'template_folder_save');

        $user   = $this->currentUser();
        $id     = (string) $request->request->get('id', '');
        $name   = $this->line($request->request->get('name'));
        $folder = '' === $id ? null : $this->em->find(TemplateFolder::class, (int) $id);

        if ('' !== $id) {
            if (null === $folder) {
                throw $this->createNotFoundException();
            }

            $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $folder);
        }

        $location = null === $folder
            ? $this->library->locate($user, (string) $request->request->get('location', ''))
            : null;

        if (null === $folder && null === $location) {
            throw $this->createNotFoundException();
        }

        if ('' === $name) {
            return $this->render('settings/templates/_folder_editor.html.twig', [
                'folder'   => $folder ?? new TemplateFolder(),
                'location' => $location?->key(),
                'tree'     => null === $folder ? $this->library->tree($user) : null,
                'errors'   => ['settings.templates.error.folder_name_required'],
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        if (null === $folder) {
            $this->library->createFolder($user, $name, $location);
        } else {
            $folder->name = $name;
        }

        $this->em->flush();

        return $this->treeStream('settings.templates.folder_saved');
    }

    #[Route('/folder/{id}/delete', name: 'folder_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteFolder(Request $request, TemplateFolder $folder): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $folder);
        $this->assertCsrf($request, 'template_folder_delete' . $folder->id);

        $this->library->deleteFolder($folder);
        $this->em->flush();

        return $this->treeStream('settings.templates.folder_deleted');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param list<string> $errors translation keys
     */
    private function editor(MailTemplate $template, array $errors = [], int $status = Response::HTTP_OK): Response
    {
        return $this->render(
            'settings/templates/_editor.html.twig',
            ['template' => $template, 'errors' => $errors] + $this->editorData->build($this->currentUser(), $template),
            new Response(status: $status),
        );
    }

    /** Redraw the tree, close the editor, say what happened. */
    private function treeStream(string $toastMessage): Response
    {
        return $this->renderTurboStream('settings/templates/_saved.stream.html.twig', [
            'tree'         => $this->library->tree($this->currentUser()),
            'toastMessage' => $toastMessage,
        ]);
    }

    /**
     * A single line of text out of a form: no markup, no line breaks, no
     * longer than the column.
     */
    private function line(mixed $value): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags(is_scalar($value) ? (string) $value : '')));

        return mb_substr($text, 0, self::NAME_LENGTH);
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
