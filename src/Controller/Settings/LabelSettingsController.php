<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The switches in the "Labels in the sidebar" card, both about how the
 * sidebar lists labels and both off by default:
 *
 *  - `by_account` (#690): labels under each account instead of merged in
 *    LABELS (User::$labelsByAccount).
 *  - `folders_in_labels` (#221 step 3): whether the merged LABELS list shows
 *    folders too, or holds the tags alone and leaves folders under their
 *    account (User::$foldersInLabels).
 *
 * One endpoint for the card, posted by settings--toggle like the other On/Off
 * segments in Settings. It answers with the sidebar redrawn, both copies,
 * which settings--toggle applies: both switches are about the sidebar, and
 * one that changes nothing on screen until the next page load reads as one
 * that did not work. They used to be two icon buttons in the label list's
 * header, each with its own route and its own frame answering with itself — a
 * control found nowhere else, with a tooltip as its only explanation.
 */
#[IsGranted('ROLE_USER')]
final class LabelSettingsController extends AbstractController
{
    use ChecksCsrf;

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    #[Route('/settings/labels/view', name: 'settings_labels_view_toggle', methods: ['POST'])]
    public function toggle(Request $request): Response
    {
        $user = $this->getUser();

        if (false === $user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $this->assertCsrf($request, 'labels_view_toggle');

        $key     = $request->request->getString('key');
        $enabled = $request->request->getBoolean('enabled');

        // An unknown key is a bug in our own page, not something to store.
        if ('by_account' === $key) {
            $user->labelsByAccount = $enabled;
        } elseif ('folders_in_labels' === $key) {
            $user->foldersInLabels = $enabled;
        } else {
            return $this->json(['ok' => false, 'error' => 'unknown setting'], Response::HTTP_BAD_REQUEST);
        }

        $this->em->flush();

        return new Response(
            $this->renderView('_partials/_sidebar.stream.html.twig'),
            headers: ['Content-Type' => 'text/vnd.turbo-stream.html'],
        );
    }
}
