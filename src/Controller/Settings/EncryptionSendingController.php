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
 * Settings → Encryption → Sending: the four switches on User that say how
 * mail goes out once plMail can encrypt it. See User::SETTING_CRYPTO_AUTOCRYPT
 * for what each one does and why it defaults the way it does.
 *
 * A plain form that submits on change and redirects, like the keyboard
 * shortcuts card: the composer reads these when its window opens.
 */
#[IsGranted('ROLE_USER')]
final class EncryptionSendingController extends AbstractController
{
    use ChecksCsrf;

    #[Route('/settings/encryption/sending', name: 'app_settings_encryption_sending', methods: ['POST'])]
    public function update(Request $request, EntityManagerInterface $em): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $this->assertCsrf($request, 'settings-encryption-sending');

        // Each read only when posted: a form that did not mention a switch must
        // not move it.
        foreach (['autocrypt' => 'autocryptHeader', 'attach_key' => 'attachPublicKey', 'sign_plain' => 'signPlainMail', 'block_partial' => 'blockPartialEncryption', 'protect_subject' => 'protectSubject'] as $field => $property) {
            if (true === $request->request->has($field)) {
                $user->{$property} = '1' === $request->request->getString($field);
            }
        }

        $em->flush();

        return $this->redirectToRoute('app_settings_index', ['section' => 'encryption']);
    }
}
