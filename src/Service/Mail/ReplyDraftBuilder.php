<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;

/**
 * Builds the draft that answers a message: reply, reply-all and forward.
 *
 * Extracted from ComposeController, which is where it was written and not
 * where it belongs — none of this touches a Request, a Form or a Response. It
 * decides what a reply *is*: who it goes to, what its subject becomes, which
 * References it inherits, and how the original is quoted underneath.
 *
 * Which matters beyond tidiness, because there is a second answer to that
 * question in the codebase. JmapDraftWriter builds drafts for every non-browser
 * client, and its own docblock says it mirrors the controller — a copy that has
 * already drifted. Anything the two must agree on belongs somewhere they can
 * both reach; this is the first piece to arrive.
 *
 * The returned draft is unmanaged. Persisting it, filing it into Drafts and
 * recording the JMAP state change are separate jobs and stay separate.
 */
final class ReplyDraftBuilder
{
    /**
     * @param bool $replyAll copy in everyone the original was addressed to,
     *                       minus the sender's own addresses
     */
    public function reply(Message $original, Account $account, bool $replyAll = false): Message
    {
        $draft = $this->draft($original, $account, 'Re', 'reply');

        $draft->toAddresses = [[
            'name'    => $original->fromName ?? '',
            'address' => $original->fromAddress ?? '',
        ]];

        $draft->ccAddresses = true === $replyAll
            ? $this->everyoneElse($original, $account)
            : [];

        $this->linkToOriginal($draft, $original);

        return $draft;
    }

    /**
     * Put a draft into the conversation it answers, and into its header chain.
     *
     * Separate from reply() because a reply written in the browser is built
     * twice: once when the window opens, and again when the first autosave
     * POSTs to a route with no message id and the server builds a brand new
     * Message. Without this the second one would have lost the thread and the
     * In-Reply-To the first was created with, and the reply would arrive as a
     * conversation of its own.
     */
    public function linkToOriginal(Message $draft, Message $original): void
    {
        // A reply belongs to the conversation it answers. A forward starts one,
        // which is why only replies come through here.
        if (null !== $original->thread) {
            $draft->thread = $original->thread;
        }

        // Whatever the draft already carries wins: it was linked when the
        // window opened, and the original this is being re-derived from is the
        // same message anyway.
        if ([] !== ($draft->inReplyTo ?? [])) {
            return;
        }

        // in-reply-to is the one header a threading client keys on, and
        // references is the chain it walks when in-reply-to is missing. Both
        // are filtered because a message synced without a Message-ID would
        // otherwise contribute a null to the chain.
        $draft->inReplyTo  = array_filter([$original->messageId]);
        $draft->references = array_values(array_unique(array_merge(
            $original->references ?? [],
            array_filter([$original->messageId]),
        )));
    }

    public function forward(Message $original): Message
    {
        $draft = $this->draft($original, $original->account, 'Fwd', 'forward');

        $draft->toAddresses = [];

        return $draft;
    }

    /**
     * Everyone the original reached except the person now answering it.
     *
     * Compared against the account's owned addresses rather than its primary
     * one: an alias is still the user, and replying to all should not put them
     * in their own Cc.
     *
     * @return list<array<string,mixed>>
     */
    private function everyoneElse(Message $original, Account $account): array
    {
        $own  = $account->ownedAddresses;
        $seen = [];

        foreach (array_merge($original->toAddresses ?? [], $original->ccAddresses ?? []) as $address) {
            if (false === in_array(strtolower($address['address'] ?? ''), $own, true)) {
                $seen[] = $address;
            }
        }

        return $seen;
    }

    private function draft(Message $original, ?Account $account, string $prefix, string $mode): Message
    {
        $draft                 = new Message();
        $draft->account        = $account;
        $draft->subject        = $this->prefixSubject($prefix, $original->subject);
        $draft->bodyHtml       = $this->quote($original, $mode);
        $draft->hasAttachments = false;

        return $draft;
    }

    /**
     * "Re: Re: Re:" is noise, so a subject already answering something keeps
     * the one prefix it has. A forward is not the same case: forwarding a reply
     * is a different act from replying again, and "Fwd: Re: x" says so.
     */
    private function prefixSubject(string $prefix, ?string $subject): string
    {
        $subject = trim($subject ?? '');

        if ('' === $subject) {
            return $prefix.': ';
        }

        if (1 === preg_match('/^(re|fwd?)\s*:\s*/i', $subject) && 're' === strtolower($prefix)) {
            return $subject;
        }

        return $prefix.': '.$subject;
    }

    /**
     * The original, rendered underneath the empty space the reply is written in.
     *
     * `data-quoted` marks the whole block — attribution line included — so the
     * editor can collapse it and the autosave guard can tell the user's own
     * writing from the mail they are answering.
     *
     * A message with no HTML body is escaped and line-broken rather than
     * dropped; a plain-text mail is still worth quoting.
     */
    private function quote(Message $original, string $mode): string
    {
        $date     = $original->receivedAt?->format('D, M j, Y \a\t g:i a') ?? '';
        $fromName = htmlspecialchars($original->fromName ?? '', ENT_QUOTES, 'UTF-8');
        $fromAddr = htmlspecialchars($original->fromAddress ?? '', ENT_QUOTES, 'UTF-8');
        $from     = '' !== $fromName ? "{$fromName} &lt;{$fromAddr}&gt;" : $fromAddr;

        $html = trim($original->bodyHtml ?? '');
        $text = trim($original->bodyText ?? '');
        $body = '' !== $html ? $html : nl2br(htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));

        if ('reply' === $mode) {
            return <<<HTML
                <p><br></p>
                <div data-quoted="1">
                    <div style="font-size:0.85em;color:#555;margin-bottom:0.25em">
                        On {$date}, {$from} wrote:
                    </div>
                    <blockquote style="border-left:2px solid #e0e0e0;margin:0;padding-left:0.75em;color:#555">
                        {$body}
                    </blockquote>
                </div>
                HTML;
        }

        $subject = htmlspecialchars($original->subject ?? '', ENT_QUOTES, 'UTF-8');
        $to      = implode(', ', array_map(
            static fn (array $a) => htmlspecialchars(
                '' !== ($a['name'] ?? '') ? $a['name'].' <'.$a['address'].'>' : ($a['address'] ?? ''),
                ENT_QUOTES,
                'UTF-8',
            ),
            $original->toAddresses ?? [],
        ));

        return <<<HTML
            <p><br></p>
            <div data-quoted="1" style="border-top:1px solid #e0e0e0;padding-top:0.75em;margin-top:0.5em;font-size:0.85em;color:#555">
                <p style="margin:0 0 0.25em"><strong>---------- Forwarded message ----------</strong></p>
                <p style="margin:0 0 0.1em"><strong>From:</strong> {$from}</p>
                <p style="margin:0 0 0.1em"><strong>Date:</strong> {$date}</p>
                <p style="margin:0 0 0.1em"><strong>Subject:</strong> {$subject}</p>
                <p style="margin:0 0 0.75em"><strong>To:</strong> {$to}</p>
                <div>{$body}</div>
            </div>
            HTML;
    }
}
