<?php

declare(strict_types=1);

namespace App\Controller\Mail;

use App\Entity\Mail\Message;
use App\Security\Voter\OwnershipVoter;
use App\Service\Rule\RuleMessageCheck;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * "Test my filters on this message", from the message's Details panel.
 *
 * Read-only: it answers into the panel's own Turbo Frame and changes nothing,
 * which is why it is a GET. See RuleMessageCheck for what is checked.
 */
#[Route('/mail/message/{id}/filters', name: 'app_mail_message_filters', requirements: ['id' => '\d+'], methods: ['GET'])]
#[IsGranted('ROLE_USER')]
final class MessageFilterCheckController extends AbstractController
{
    public function __construct(
        private readonly RuleMessageCheck $check,
    ) {}

    public function __invoke(Message $message): Response
    {
        $this->denyAccessUnlessGranted(OwnershipVoter::OWN, $message);

        return $this->render('mail/_message_filter_check.html.twig', [
            'message' => $message,
            ...$this->check->check($message),
        ]);
    }
}
