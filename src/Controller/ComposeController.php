<?php

namespace App\Controller;

use App\Domain\Enum\MessageFlag;
use App\Entity\Message;
use App\Form\ComposeType;
use App\Message\SendMessageMessage;
use App\Repository\MailboxRepository;
use App\Repository\MessageRepository;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/compose', name: 'app_compose_')]
class ComposeController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly MailboxRepository      $mailboxRepository,
        private readonly MessageRepository      $messageRepository,
        private readonly MessageBusInterface    $bus,
    ) {
    }

    #[Route('/new', name: 'new', methods: ['GET'])]
    #[Route('/edit/{id}', name: 'edit', methods: ['GET'])]
    public function compose(?Message $message = null): Response
    {
        if (null === $message) {
            $message = new Message()
                ->setMailbox($this->mailboxRepository->findPrimaryDraftMailboxForUser($this->getUser()))
                ->setCreatedAt(new DateTimeImmutable());
        }

        $form = $this->createForm(ComposeType::class, $message, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default'],
        ]);

        return $this->render('compose/_window.html.twig', [
            'form'    => $form,
            'message' => $message,
        ]);
    }

    #[Route('/reply/{id}', name: 'reply', methods: ['GET'])]
    public function reply(Message $original): Response
    {
        $this->assertOwnership($original);

        $draft = $this->buildReply($original, replyAll: false);

        $form = $this->createForm(ComposeType::class, $draft, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default'],
        ]);

        return $this->render('compose/_window.html.twig', [
            'form'    => $form,
            'message' => $draft,
        ]);
    }

    #[Route('/reply-all/{id}', name: 'reply_all', methods: ['GET'])]
    public function replyAll(Message $original): Response
    {
        $this->assertOwnership($original);

        $draft = $this->buildReply($original, replyAll: true);

        $form = $this->createForm(ComposeType::class, $draft, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default'],
        ]);

        return $this->render('compose/_window.html.twig', [
            'form'    => $form,
            'message' => $draft,
        ]);
    }

    #[Route('/forward/{id}', name: 'forward', methods: ['GET'])]
    public function forwardMessage(Message $original): Response
    {
        $this->assertOwnership($original);

        $draft = $this->buildForward($original);

        $form = $this->createForm(ComposeType::class, $draft, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default'],
        ]);

        return $this->render('compose/_window.html.twig', [
            'form'    => $form,
            'message' => $draft,
        ]);
    }

    #[Route('/draft', name: 'form_new', methods: ['POST'])]
    #[Route('/draft/{id}', name: 'form_edit', methods: ['POST'])]
    public function draft(Request $request, ?Message $message = null): Response
    {
        if (null === $message) {
            $message = new Message()
                ->setMailbox($this->mailboxRepository->findPrimaryDraftMailboxForUser($this->getUser()))
                ->setCreatedAt(new DateTimeImmutable());
        }

        $form = $this->createForm(ComposeType::class, $message, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default'],
        ]);

        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            $this->persistDraft($message);

            return $this->render('compose/_window.html.twig', [
                'form'    => $form,
                'message' => $message,
                'saved'   => true,
            ]);
        }

        return $this->render('compose/_window.html.twig', [
            'form'    => $form,
            'message' => $message,
        ]);
    }

    #[Route('/send', name: 'mail_send', methods: ['POST'])]
    #[Route('/send/{id}', name: 'mail_send_draft', methods: ['POST'])]
    public function send(Request $request, ?Message $message): Response
    {
        if (null === $message) {
            $message = new Message();
        }

        $form = $this->createForm(ComposeType::class, $message, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default', 'send'],
        ]);

        $form->handleRequest($request);

        if (true === $form->isSubmitted() && true === $form->isValid()) {
            if (null !== $message->getSentAt()) {
                return $this->render('compose/_send_toast.html.twig', [
                    'message' => $message,
                ], new Response('', 200, ['Content-Type' => 'text/vnd.turbo-stream.html']));
            }

            $this->persistDraft($message);

            $this->bus->dispatch(
                new SendMessageMessage($message->getId()),
                [new DelayStamp(10_000)],
            );

            return $this->render('compose/_send_toast.html.twig', [
                'message' => $message,
            ], new Response('', 200, ['Content-Type' => 'text/vnd.turbo-stream.html']));
        }

        return $this->render('compose/_window.html.twig', [
            'form'    => $form,
            'message' => $message,
        ]);
    }

    #[Route('/undo/{id}', name: 'mail_undo', methods: ['POST'])]
    public function undo(Message $message): Response
    {
        $this->assertOwnership($message);

        $message->setCancelled(true);
        $this->em->flush();

        $form = $this->createForm(ComposeType::class, $message, [
            'user' => $this->getUser(),
            'validation_groups' => ['Default'],
        ]);

        return $this->render('compose/_undo_toast.html.twig', [
            'form'    => $form,
            'message' => $message,
        ]);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function buildReply(Message $original, bool $replyAll): Message
    {
        $draftMailbox = $this->mailboxRepository->findPrimaryDraftMailboxForUser($this->getUser());
        $account      = $draftMailbox->getAccount();

        // "To" is always the original sender.
        $to = [[
            'name'    => $original->getFromName() ?? '',
            'address' => $original->getFromAddress() ?? '',
        ]];

        // Reply-all: add original To/Cc, minus our own address.
        $cc = [];
        if (true === $replyAll) {
            $ownAddress = strtolower($account->getEmail() ?? '');
            $candidates = array_merge(
                $original->getToAddresses() ?? [],
                $original->getCcAddresses() ?? [],
            );
            foreach ($candidates as $addr) {
                if (strtolower($addr['address'] ?? '') !== $ownAddress) {
                    $cc[] = $addr;
                }
            }
        }

        $subject = $this->prefixSubject('Re', $original->getSubject());

        // References chain: append original message-id to existing References.
        $references = array_merge(
            $original->getReferences() ?? [],
            array_filter([$original->getMessageId()]),
        );

        $quotedBody = $this->buildQuotedHtml($original, 'reply');

        $draft = (new Message())
            ->setMailbox($draftMailbox)
            ->setSubject($subject)
            ->setToAddresses($to)
            ->setCcAddresses($cc)
            ->setBodyHtml($quotedBody)
            ->setInReplyTo(array_filter([$original->getMessageId()]))
            ->setReferences(array_values(array_unique($references)))
            ->setHasAttachments(false)
            ->setCreatedAt(new DateTimeImmutable())
            ->setUpdatedAt(new DateTimeImmutable());

        if (null !== $original->getThread()) {
            $draft->setThread($original->getThread());
        }

        return $draft;
    }

    private function buildForward(Message $original): Message
    {
        $draftMailbox = $this->mailboxRepository->findPrimaryDraftMailboxForUser($this->getUser());

        $subject    = $this->prefixSubject('Fwd', $original->getSubject());
        $quotedBody = $this->buildQuotedHtml($original, 'forward');

        $draft = (new Message())
            ->setMailbox($draftMailbox)
            ->setSubject($subject)
            ->setToAddresses([])
            ->setBodyHtml($quotedBody)
            ->setHasAttachments(false)
            ->setCreatedAt(new DateTimeImmutable())
            ->setUpdatedAt(new DateTimeImmutable());

        return $draft;
    }

    private function prefixSubject(string $prefix, ?string $subject): string
    {
        $subject = trim($subject ?? '');

        if ($subject === '') {
            return $prefix . ': ';
        }

        // Avoid stacking: don't add "Re:" if it already starts with Re:/Fwd:
        $pattern = '/^(re|fwd?)\s*:\s*/i';
        if (preg_match($pattern, $subject)) {
            if (strtolower($prefix) === 're') {
                return $subject;
            }
        }

        return $prefix . ': ' . $subject;
    }

    /**
     * Build the quoted-message HTML block inserted below the cursor.
     * The compose editor will place the cursor BEFORE this block.
     */
    private function buildQuotedHtml(Message $original, string $mode): string
    {
        $dateStr    = $original->getReceivedAt() ? $original->getReceivedAt()->format('D, M j, Y \a\t g:i a') : '';
        $fromName   = htmlspecialchars($original->getFromName() ?? '', ENT_QUOTES, 'UTF-8');
        $fromAddr   = htmlspecialchars($original->getFromAddress() ?? '', ENT_QUOTES, 'UTF-8');
        $fromLine   = $fromName !== '' ? "{$fromName} &lt;{$fromAddr}&gt;" : $fromAddr;

        $bodyHtml = trim($original->getBodyHtml() ?? '');
        $bodyText = trim($original->getBodyText() ?? '');

        // Prefer HTML; fall back to text wrapped in <pre>.
        if ($bodyHtml !== '') {
            $innerBody = $bodyHtml;
        } elseif ($bodyText !== '') {
            $innerBody = '<pre style="white-space:pre-wrap;font-family:inherit;margin:0">'
                . htmlspecialchars($bodyText, ENT_QUOTES, 'UTF-8')
                . '</pre>';
        } else {
            $innerBody = '';
        }

        if ($mode === 'reply') {
            $attribution = "On {$dateStr}, {$fromLine} wrote:";

            return <<<HTML
                <p><br></p>
                <p style="margin:0;font-size:0.85em;color:#555">{$attribution}</p>
                <blockquote style="margin:0 0 0 0.5em;padding:0 0 0 1em;border-left:3px solid #c7c7c7;color:#444">
                    {$innerBody}
                </blockquote>
                HTML;
        }

        // Forward: include a header block with original metadata.
        $subjectLine = htmlspecialchars($original->getSubject() ?? '', ENT_QUOTES, 'UTF-8');
        $toLine = implode(', ', array_map(
            static fn(array $a) => htmlspecialchars(
                ($a['name'] ? $a['name'] . ' <' . $a['address'] . '>' : $a['address']),
                ENT_QUOTES,
                'UTF-8',
            ),
            $original->getToAddresses() ?? [],
        ));

        return <<<HTML
            <p><br></p>
            <div style="border-top:1px solid #e0e0e0;padding-top:0.75em;margin-top:0.5em;font-size:0.85em;color:#555">
                <p style="margin:0 0 0.25em"><strong>---------- Forwarded message ----------</strong></p>
                <p style="margin:0 0 0.1em"><strong>From:</strong> {$fromLine}</p>
                <p style="margin:0 0 0.1em"><strong>Date:</strong> {$dateStr}</p>
                <p style="margin:0 0 0.1em"><strong>Subject:</strong> {$subjectLine}</p>
                <p style="margin:0 0 0.75em"><strong>To:</strong> {$toLine}</p>
                <div>{$innerBody}</div>
            </div>
            HTML;
    }

    private function persistDraft(Message $message): void
    {
        $now = new DateTimeImmutable();

        $message
            ->setFromAddress($message->getMailbox()->getAccount()->getEmail())
            ->setFromName($message->getMailbox()->getAccount()->getName())
            ->addFlag(MessageFlag::DRAFT)
            ->setHasAttachments(false)
            ->setUpdatedAt($now);

        $this->em->persist($message);
        $this->em->flush();
    }

    private function assertOwnership(Message $message): void
    {
        if ($message->getMailbox()->getAccount()->getUsr() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }
    }
}
