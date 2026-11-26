<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Controller\Mail\ComposeController;
use App\Domain\DTO\Mail\ForwardAgent;
use App\Domain\Enum\Crypto\RecipientPolicy;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\SendMessageMessage;
use App\Service\Crypto\RecipientKeys;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * "Forward to agent": the forward the composer would have built, addressed to
 * one of the person's agents, with their note above the quote, sent.
 *
 * Every step is the composer's own, in the composer's order — the draft from
 * ReplyDraftBuilder::forward(), the files from ForwardedAttachments, the save
 * through DraftPersister, the same SendMessageMessage behind the same delay —
 * so a hand-off is an ordinary forward in every way that matters afterwards:
 * it sits in Sent, it threads, and its Undo reopens it in the compose dock.
 *
 * Forwards only the browser composer can make are refused rather than sent:
 * encrypted mail, whose quote it decrypts, and a send it would sign or
 * encrypt — "sign plain mail", "always encrypt" for the address it goes from,
 * or a recipient, the agent or a default Bcc, whose address has a key or its
 * own Always policy. Sent from here, the first would carry a marker where the
 * mail should be and the rest would go out in the clear without saying so.
 *
 * A forward whose attachments could not all be copied is saved, not sent: the
 * composer shows a missing file as a missing chip, and this dialog has no
 * chips to show it with.
 */
final readonly class AgentForwarder
{
    public const string REFUSED_ENCRYPTED = 'encrypted';

    public const string REFUSED_SEALED = 'sealed';

    public function __construct(
        private ReplyDraftBuilder      $replyDrafts,
        private DraftPersister         $drafts,
        private ForwardedAttachments   $forwardedAttachments,
        private ComposeWindow          $window,
        private SenderResolver         $senders,
        private MessageBusInterface    $bus,
        private EntityManagerInterface $entityManager,
        private RecipientKeys          $recipientKeys,
        private DefaultBccProvider     $defaultBcc,
    ) {
    }

    /**
     * Why this mail cannot be forwarded to an agent from here, as one of the
     * REFUSED_ constants, or null when it can.
     */
    public function refusal(Message $original, User $user): ?string
    {
        if (true === $original->crypto?->isEncrypted()) {
            return self::REFUSED_ENCRYPTED;
        }

        $account = $this->sendingAccount($original, $user);

        if (null === $account) {
            return null;
        }

        // The address the forward would really go from, which is the alias
        // the mail came in on when there is one, not the account's own.
        $sender = $this->senderOf($this->replyDrafts->forward($original), $account, $user);

        if (true === self::sealsWhatItSends($user, $sender) || true === $this->sealsAny($user, $this->defaultBcc->addressesFor($account, $sender))) {
            return self::REFUSED_SEALED;
        }

        return null;
    }

    /**
     * Whether the composer would encrypt or sign mail to this agent, which a
     * forward from here cannot. The keys plMail already holds, without looking
     * one up: the composer's lookup happens as an address is typed, and an
     * agent picked from a list was never typed.
     */
    public function sealsFor(User $user, ForwardAgent $agent): bool
    {
        return $this->sealsAny($user, [$agent->address]);
    }

    /**
     * Build, save and queue the forward. Answers the draft and whether it was
     * queued — false when an attachment could not be copied and the forward
     * was left in Drafts — or null when there is nothing to send it from or
     * the original cannot be forwarded from here.
     *
     * @return array{draft: Message, sent: bool}|null
     */
    public function forward(Message $original, ForwardAgent $agent, string $note, User $user): ?array
    {
        $account = $this->sendingAccount($original, $user);

        if (null === $account || true === $original->crypto?->isEncrypted()) {
            return null;
        }

        $draft              = $this->replyDrafts->forward($original);
        $draft->toAddresses = [['name' => $agent->name, 'address' => $agent->address]];
        $draft->bodyHtml    = self::withNote($note, (string) $draft->bodyHtml);

        $sender = $this->senderOf($draft, $account, $user);

        // The builder copied the Bcc of the account the mail is in; the
        // forward goes from $sender, whose own Bcc is the one that applies.
        $draft->bccAddresses = null;
        $this->defaultBcc->applyTo($draft, $account, $sender);

        if (true === self::sealsWhatItSends($user, $sender) || true === $this->sealsAny($user, self::recipients($draft))) {
            return null;
        }

        // Saved before the files are copied and again after, as the composer
        // does across opening a forward and pressing Send: the bytes are
        // bucketed by message id, which the first save gives the draft.
        $this->drafts->save($draft, $account, $sender);

        $expected = $this->forwardedAttachments->count($original);

        if (0 < $expected) {
            $copied = $this->forwardedAttachments->copy($original, $draft);
            $this->drafts->save($draft, $account, $sender);

            if ($copied < $expected) {
                return ['draft' => $draft, 'sent' => false];
            }
        }

        $draft->bodyText = self::wholeText((string) $draft->bodyHtml);
        $this->entityManager->flush();

        $this->bus->dispatch(
            new SendMessageMessage((int) $draft->id, $draft->submissionSendAt),
            [new DelayStamp(ComposeController::SEND_DELAY_MS)],
        );

        return ['draft' => $draft, 'sent' => true];
    }

    private function senderOf(Message $draft, Account $account, User $user): string
    {
        return $this->senders->addressFor($this->senders->tokenFor($draft, $account), $account, $user);
    }

    /** @param list<string> $addresses */
    private function sealsAny(User $user, array $addresses): bool
    {
        if ([] === $addresses) {
            return false;
        }

        foreach ($this->recipientKeys->describe($user, $addresses, false) as $recipient) {
            // A key alone is enough: whatever the address's encrypt policy,
            // the composer signs mail to anyone it holds a key for.
            if (RecipientPolicy::Always->value === $recipient['encrypt']
                || RecipientPolicy::Always->value === $recipient['sign']
                || null !== $recipient['key']
                || null !== $recipient['smime']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The account the mail is in, as a forward from the composer would use,
     * unless that account only receives; then the one a new message would go
     * from.
     */
    private function sendingAccount(Message $original, User $user): ?Account
    {
        return true === $original->account->sendEnabled
            ? $original->account
            : $this->window->defaultAccountFor($user);
    }

    /** @return list<string> */
    private static function recipients(Message $draft): array
    {
        return array_values(array_filter(array_map(
            static fn (array $entry): string => (string) ($entry['address'] ?? ''),
            [...($draft->toAddresses ?? []), ...($draft->ccAddresses ?? []), ...($draft->bccAddresses ?? [])],
        )));
    }

    private static function sealsWhatItSends(User $user, string $from): bool
    {
        return true === $user->signPlainMail
            || 'always' === ($user->ownAddressEncrypt[strtolower(trim($from))] ?? null);
    }

    /**
     * The note as paragraphs, in place of the empty one the forward opens with
     * for somebody to type into. A blank line starts a new paragraph and a
     * single line break stays one.
     */
    private static function withNote(string $note, string $forward): string
    {
        $note = trim(str_replace(["\r\n", "\r"], "\n", $note));

        if ('' === $note) {
            return $forward;
        }

        $paragraphs = array_map(
            static fn (string $paragraph): string => sprintf(
                '<p>%s</p>',
                nl2br(htmlspecialchars(trim($paragraph), ENT_QUOTES, 'UTF-8'), false),
            ),
            preg_split('/\n\s*\n/', $note) ?: [$note],
        );

        $writingSpace = '<p><br></p>';

        if (true === str_starts_with($forward, $writingSpace)) {
            $forward = substr($forward, strlen($writingSpace));
        }

        return implode("\n", $paragraphs) . "\n" . ltrim($forward);
    }

    /**
     * The text/plain part with the forwarded mail in it, not only the note.
     *
     * DraftPersister::plainTextBody() keeps just the sender's own writing and
     * drops everything from the quote down, which suits a person reading the
     * HTML. An agent may read the text part alone, and the mail is the point,
     * so a link keeps where it goes: "View invoice <https://…>".
     */
    private static function wholeText(string $html): ?string
    {
        $html = (string) preg_replace('#<(style|script|head)\b.*?</\1\s*>#is', '', $html);
        $html = (string) preg_replace_callback(
            '#<a\b[^>]*?\bhref\s*=\s*(?:"([^"]*)"|\'([^\']*)\')[^>]*>(.*?)</a\s*>#is',
            static function (array $link): string {
                $href  = trim(html_entity_decode($link[1] . $link[2], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $label = trim(html_entity_decode(strip_tags($link[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if (1 !== preg_match('#^(https?:|mailto:)#i', $href) || $label === $href || 'mailto:' . $label === $href) {
                    return $link[3];
                }

                return $link[3] . ' &lt;' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '&gt;';
            },
            $html,
        );

        // A <pre> keeps its spacing: set aside before whitespace is collapsed
        // and put back as it was. The marker is a private-use character, which
        // strip_tags() and html_entity_decode() pass through.
        $preformatted = [];
        $html         = (string) preg_replace_callback(
            '#<pre\b[^>]*>(.*?)</pre\s*>#is',
            static function (array $block) use (&$preformatted): string {
                $marker                = "\u{E000}" . count($preformatted) . "\u{E000}";
                $preformatted[$marker] = html_entity_decode(
                    strip_tags((string) preg_replace('#<br\s*/?>#i', "\n", $block[1])),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8',
                );

                return '<br>' . $marker . '<br>';
            },
            $html,
        );

        $html = (string) preg_replace('/\s+/', ' ', $html);
        $html = (string) preg_replace('#<div[^>]*data-quoted#i', "\n$0", $html);
        $html = (string) preg_replace('#<br\s*/?>|</(p|div|li|tr|h[1-6]|blockquote)\s*>#i', "$0\n", $html);
        $html = (string) preg_replace('#</t[dh]\s*>#i', "$0\t", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = (string) preg_replace('/^[ \t]+|[ \t]+$/m', '', $text);
        $text = (string) preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim(strtr($text, $preformatted));

        return '' === $text ? null : $text;
    }
}
