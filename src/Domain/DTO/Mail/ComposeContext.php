<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

/**
 * Where a compose window lives, and what it has to say about itself on the way
 * back.
 *
 * The dock is the default; the thread view passes
 * `?frame=compose_inline&thread={id}` on every URL it hands to the client
 * (open, autosave, send, undo) so the round trip stays self-describing — the
 * autosave fetch sends no Turbo-Frame header, so nothing else would carry it.
 *
 * `replyTo` is the message being answered. It has to survive the round trip
 * because the first autosave POSTs to /compose/draft with no id, so the server
 * builds a brand new Message that would otherwise have lost the thread and the
 * In-Reply-To the reply was created with.
 *
 * ── Why a class and not the array it used to be ──────────────────────────────
 * This was `array{inline: bool, frame: string, thread: int|null, replyTo: int|
 * null, urlParams: array<string, int|string>}`, and that shape was written out
 * in EIGHT docblocks across ComposeController because every method that touched
 * it had to redeclare it for PHPStan. A key added to it meant eight edits, and
 * a key misspelled at a read site meant null rather than an error. The shape is
 * now declared once, by the language.
 *
 * `urlParams` was also a stored key computed from the other four, so the array
 * carried a value that could disagree with the fields it was derived from. Here
 * it is a method, and cannot.
 *
 * No framework types, so it belongs in Domain: building one from a Request is
 * {@see \App\Infrastructure\Http\ComposeContextResolver}'s job.
 */
final readonly class ComposeContext
{
    /**
     * The dock — one per page, at the bottom right, and where a window goes
     * unless the caller asked for otherwise.
     */
    public const string DOCK_FRAME = 'compose_dock';

    /**
     * The form name inline windows submit under.
     *
     * Inline windows get their own so their DOM ids cannot collide with a dock
     * window open at the same time (`compose_inline_subject` against
     * `compose_subject`). The CSRF token id is shared, so tokens interchange.
     */
    public const string INLINE_FORM = 'compose_inline';

    /**
     * Frames that mean "in the thread, not in the dock": the reply box at the
     * foot of a conversation (`compose_inline`), and a draft being edited in
     * place in its own row (`compose_draft_{id}`).
     */
    private const string INLINE_FRAME_PATTERN = '/^compose_(inline|draft_\d+)$/';

    public function __construct(
        public bool $inline,
        public string $frame,
        public ?int $thread = null,
        public ?int $replyTo = null,
    ) {}

    /**
     * The context a request describes, from values already pulled off it.
     *
     * Takes the raw frame rather than a decided one: whether a frame is inline
     * is this class's rule, and asking each caller to apply it is how two of
     * them would come to disagree.
     */
    public static function forFrame(?string $frame, ?int $thread = null, ?int $replyTo = null): self
    {
        $frame  = null === $frame || '' === $frame ? self::DOCK_FRAME : $frame;
        $inline = 1 === preg_match(self::INLINE_FRAME_PATTERN, $frame);

        return new self(
            inline: $inline,
            frame: $inline ? $frame : self::DOCK_FRAME,
            thread: $thread,
            replyTo: $replyTo,
        );
    }

    /**
     * Query params to bake into the draft/send URLs the window reports back.
     *
     * Derived on read rather than stored, so it cannot fall out of step with
     * the fields it comes from — which is what the old `urlParams` array key
     * could do.
     *
     * `thread` is not inline-only: below md a reply opens in the dock instead
     * of in the thread (see compose--frame-target), and the window still has to
     * know which conversation it belongs to so its draft row lands there.
     *
     * @return array<string, int|string>
     */
    public function urlParams(): array
    {
        $params = [];

        if (true === $this->inline) {
            $params['frame'] = $this->frame;
        }

        if (null !== $this->thread) {
            $params['thread'] = $this->thread;
        }

        if (null !== $this->replyTo) {
            $params['reply_to'] = $this->replyTo;
        }

        return $params;
    }

    /** The same window, told which message it is answering. */
    public function withReplyTo(?int $replyTo): self
    {
        return new self($this->inline, $this->frame, $this->thread, $replyTo);
    }

    /**
     * The same window, told which conversation it now sits in.
     *
     * Used by the inline undo, which learns the thread only after the draft has
     * been reattached to one. That template used to write `ctx|merge({thread:
     * …})`, which is why this exists: `merge` is an array filter, and it was the
     * one thing standing between this shape and being a type.
     */
    public function withThread(?int $thread): self
    {
        return new self($this->inline, $this->frame, $thread, $this->replyTo);
    }
}
