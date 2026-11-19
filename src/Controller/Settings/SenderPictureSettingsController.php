<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Domain\Enum\Mail\SenderPictureKind;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\QueueSenderPicturesMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Settings → Reading & writing → Reading → Sender pictures: which looked-up
 * pictures this reader wants (#639).
 *
 * Three switches, one per lookup kind; contact photos and other users'
 * pictures are not switches, because they never leave the server. What each
 * switch defaults to, and why Gravatar alone starts off, is on the enum
 * (SenderPictureKind::onByDefault()).
 *
 * **Switching one on looks up the inbox already there.** Without that, only
 * senders who write after the click would ever get a picture, and the person
 * who just asked for pictures would see none for weeks. Switching one off
 * needs nothing done: SenderPictures checks the switch every time a row is
 * drawn.
 *
 * A token, as on the images switch beside it: a cross-site POST that turned
 * Gravatar on would send a hash of every address this person hears from to a
 * third party they never chose.
 */
#[IsGranted('ROLE_USER')]
final class SenderPictureSettingsController extends AbstractController
{
    use ChecksCsrf;

    #[Route('/settings/sender-pictures', name: 'app_settings_sender_pictures_update', methods: ['POST'])]
    public function update(
        Request $request,
        #[CurrentUser] User $user,
        EntityManagerInterface $em,
        MessageBusInterface $bus,
    ): Response {
        $this->assertCsrf($request, 'settings-sender-pictures');

        $switchedOn = false;

        // Read only when posted, like every control on the settings pages: a
        // form that did not mention a switch must not move it.
        foreach (SenderPictureKind::lookups() as $kind) {
            if (false === $request->request->has($kind->value)) {
                continue;
            }

            $on = '1' === (string) $request->request->get($kind->value);

            if (true === $on && false === $user->allowsSenderPicture($kind)) {
                $switchedOn = true;
            }

            $user->allowSenderPicture($kind, $on);
        }

        $em->flush();

        if (true === $switchedOn && null !== $user->id) {
            $bus->dispatch(new QueueSenderPicturesMessage($user->id));
        }

        return $this->redirectToRoute('app_settings_page', ['page' => 'reading']);
    }
}
