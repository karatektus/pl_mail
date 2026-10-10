<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Mail\SidebarRow;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Which system folders the sidebar keeps behind "More".
 *
 * Redirects rather than answering with a stream, like the clock and the
 * language: the choice moves rows in the sidebar of every page, so there is no
 * fragment small enough to be worth swapping.
 */
#[IsGranted('ROLE_USER')]
final class SidebarMoreController extends AbstractController
{
    use ChecksCsrf;

    #[Route('/settings/sidebar/more', name: 'app_settings_sidebar_more_update', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->assertCsrf($request, 'settings-sidebar');

        // Read only when posted, like every control on this page: a post that
        // did not mention the rows must not move them. The form always sends a
        // hidden empty entry, so "every box cleared" is a post that DOES
        // mention them, and is stored as the empty choice.
        if (true === $request->request->has('rows')) {
            $user->setSidebarMoreRows($this->rows($request));
        }

        $em->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'general']);
    }

    /**
     * The posted rows, each of which has to be one the form offered.
     *
     * An unknown value or the pinned Inbox is somebody editing the request, so
     * it is refused outright rather than quietly dropped: a save that stored
     * part of what it was sent would leave the form and the sidebar disagreeing
     * with no sign of why.
     *
     * @return list<SidebarRow>
     */
    private function rows(Request $request): array
    {
        $posted = $request->request->all('rows');
        $rows   = [];

        foreach ($posted as $value) {
            // The hidden entry that says "the form was submitted".
            if ('' === $value) {
                continue;
            }

            $row = is_string($value) ? SidebarRow::tryFrom($value) : null;

            if (null === $row || true === $row->isPinned()) {
                throw $this->createNotFoundException('Not a row the sidebar offers.');
            }

            $rows[] = $row;
        }

        return $rows;
    }
}
