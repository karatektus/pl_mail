<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Controller\ChecksCsrf;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ForgetDecryptedMailMessage;
use App\Repository\Mail\AccountRepository;
use App\Security\Voter\OwnershipVoter;
use App\Service\Crypto\DecryptedMail;
use App\Service\Crypto\DecryptedMailPolicy;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Settings → Encryption → Decrypted mail on this server: per account, whether
 * the decrypted text of encrypted mail is kept on the server, and whether the
 * models may read it.
 *
 * Switching either on needs the box under it ticked — the card says in plain
 * words what that means — and the models' switch, for a model host outside
 * the installation's own network, a second box naming that host. The host is
 * remembered with the consent (DecryptedMailPolicy): another host later is
 * one nobody agreed to.
 *
 * Switching off takes the text back off the server (ForgetDecryptedMailMessage,
 * in the background), or, for the models' switch, what the models made of it.
 */
#[IsGranted('ROLE_USER')]
final class DecryptedMailSettingsController extends AbstractController
{
    use ChecksCsrf;

    #[Route('/settings/encryption/decrypted/{id}', name: 'app_settings_decrypted_mail', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function update(
        Request                $request,
        Account                $account,
        EntityManagerInterface $entityManager,
        DecryptedMailPolicy    $policy,
        DecryptedMail          $decryptedMail,
        MessageBusInterface    $bus,
        AccountRepository      $accounts,
        TranslatorInterface    $translator,
    ): Response {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $account);
        $this->assertCsrf($request, 'decrypted-mail-' . $account->id);

        $keep = $request->request->has('keep') ? '1' === $request->request->getString('keep') : $account->keepDecrypted;
        $ai   = $request->request->has('ai') ? '1' === $request->request->getString('ai') : $account->keepDecryptedAi;

        if (true === $keep && false === $account->keepDecrypted) {
            if (false === $policy->allowedOnInstall() || '1' !== $request->request->get('confirm')) {
                $this->addFlash('encryption', $translator->trans('encryption.decrypted.confirm_needed'));

                return $this->back();
            }

            $account->keepDecrypted = true;
            $this->summarise($account, $accounts);
        }

        if (false === $keep && true === $account->keepDecrypted) {
            $account->keepDecrypted   = false;
            $account->keepDecryptedAi = false;
            $account->unsetSetting(Account::SETTING_KEEP_DECRYPTED_AI_HOST);
            $this->summarise($account, $accounts);
            $entityManager->flush();

            $bus->dispatch(new ForgetDecryptedMailMessage((int) $account->id));

            return $this->back();
        }

        if (true === $ai && false === $account->keepDecryptedAi && true === $account->keepDecrypted) {
            $model = $policy->modelHost();

            if (null === $model['host'] || (false === $model['local'] && '1' !== $request->request->get('consent_external'))) {
                $this->addFlash('encryption', $translator->trans('encryption.decrypted.ai.consent_needed'));

                return $this->back();
            }

            $account->keepDecryptedAi = true;
            $account->setSetting(Account::SETTING_KEEP_DECRYPTED_AI_HOST, true === $model['local'] ? null : $model['host']);
        }

        if (false === $ai && true === $account->keepDecryptedAi) {
            $account->keepDecryptedAi = false;
            $account->unsetSetting(Account::SETTING_KEEP_DECRYPTED_AI_HOST);
            $decryptedMail->forgetModelOutput($account);
        }

        $entityManager->flush();

        return $this->back();
    }

    /** User::SETTING_KEEPS_DECRYPTED_ANY, from every account of the person's. */
    private function summarise(Account $changed, AccountRepository $accounts): void
    {
        $any = false;

        foreach ($accounts->findForUserOrdered($changed->usr) as $account) {
            $any = $any || true === $account->keepDecrypted;
        }

        $changed->usr->setSetting(User::SETTING_KEEPS_DECRYPTED_ANY, $any ? true : null);
    }

    private function back(): Response
    {
        return $this->redirectToRoute('app_settings_index', ['section' => 'encryption'], Response::HTTP_SEE_OTHER);
    }
}
