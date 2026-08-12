<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\Message;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The compose-time inverse of MailBodySanitizer::resolveCids().
 *
 * An inline image lives twice. In the browser it has to be a URL the editor
 * can actually render — the attachment route — because a contenteditable
 * showing `src="cid:…"` shows a broken icon. On the wire it has to be
 * `cid:…`, because that is the only reference a receiving mail client can
 * resolve against the embedded part; a mail carrying a link back into plMail
 * renders as a broken image for everyone who is not logged in to it, which is
 * everyone.
 *
 * The bridge between the two is `data-cid`, written by the compose controller
 * next to the src. It is the identity of the part, so neither direction has to
 * parse a URL or trust that the route shape never changes:
 *
 *   editor / display   <img src="/mail/attachment/12" data-cid="a@plmail">
 *   stored / outbound  <img src="cid:a@plmail"        data-cid="a@plmail">
 *
 * toCid() runs inside DraftPersister::save(), so an autosave and a send store
 * the same body and plainTextBody() sees the same HTML. toDisplay() runs when
 * a draft is rendered back into the compose window.
 */
final readonly class InlineImageRewriter
{
    /**
     * Every <img> tag, captured whole so the attributes can be rewritten
     * without a parser and without touching anything else in the body.
     */
    private const string IMG = '/<img\b[^>]*>/i';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Point every inline image at its `cid:` reference — what goes on the wire.
     *
     * Driven by `data-cid` alone: an <img> without one is a remote or pasted
     * image the user put there themselves and is left exactly as it is.
     */
    public function toCid(?string $html): ?string
    {
        return $this->rewriteImages($html, function (string $tag, string $cid): string {
            return $this->withSrc($tag, 'cid:' . $cid);
        });
    }

    /**
     * Point every inline image back at the attachment route — what the editor
     * and the draft preview can render.
     *
     * Absolute path rather than URL, like resolveCids(): no request or host
     * context is needed, and the body is rendered inside this app either way.
     */
    public function toDisplay(?string $html, Message $message): ?string
    {
        $urls = $this->urlsByContentId($message);

        if ([] === $urls) {
            return $html;
        }

        return $this->rewriteImages($html, function (string $tag, string $cid) use ($urls): string {
            $url = $urls[strtolower($cid)] ?? null;

            // The part is gone — leave the tag alone rather than inventing a
            // route to a row that no longer exists.
            return null === $url ? $tag : $this->withSrc($tag, $url);
        });
    }

    /**
     * @param callable(string, string): string $rewrite tag and content id in,
     *                                                  replacement tag out
     */
    private function rewriteImages(?string $html, callable $rewrite): ?string
    {
        if (null === $html || '' === $html) {
            return $html;
        }

        if (false === str_contains($html, 'data-cid')) {
            return $html;
        }

        return (string) preg_replace_callback(
            self::IMG,
            static function (array $m) use ($rewrite): string {
                $tag = $m[0];

                if (1 !== preg_match('/\bdata-cid\s*=\s*(["\'])(.*?)\1/i', $tag, $attr)) {
                    return $tag;
                }

                $cid = html_entity_decode($attr[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return '' === $cid ? $tag : $rewrite($tag, $cid);
            },
            $html,
        );
    }

    /** Replace the tag's src, or add one to a tag that somehow lost it. */
    private function withSrc(string $tag, string $src): string
    {
        $escaped = htmlspecialchars($src, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $rewritten = preg_replace(
            '/\bsrc\s*=\s*(["\']).*?\1/i',
            'src="' . $escaped . '"',
            $tag,
            1,
            $count,
        );

        if (null !== $rewritten && $count > 0) {
            return $rewritten;
        }

        return (string) preg_replace('/^<img\b/i', '<img src="' . $escaped . '"', $tag, 1);
    }

    /**
     * @return array<string, string> lowercased content id → attachment path
     */
    private function urlsByContentId(Message $message): array
    {
        $urls = [];

        foreach ($message->messageParts as $part) {
            $cid = $part->contentId;

            if (null === $cid || '' === $cid || null === $part->id) {
                continue;
            }

            $urls[strtolower($cid)] = $this->urlGenerator->generate(
                'app_mail_attachment',
                ['id' => $part->id],
                UrlGeneratorInterface::ABSOLUTE_PATH,
            );
        }

        return $urls;
    }
}
