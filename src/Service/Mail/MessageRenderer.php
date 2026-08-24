<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Mail\TrustedImageSenderRepository;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The single place that decides how a message body is put in front of a reader.
 *
 * Two things are settled here that used to be settled by a Twig `|raw`:
 *
 *  1. Whether the sender's servers get to hear about this read (K-06).
 *  2. What the body is allowed to do once it is on screen (K-07) — expressed
 *     as the sandbox attribute and CSP the reading frame carries.
 */
final readonly class MessageRenderer
{
    /**
     * WHY `allow-scripts` IS IN THIS LIST.
     *
     * The brief asked for `allow-popups allow-popups-to-escape-sandbox` and for
     * the frame's height to follow its content. Those two cannot both be true.
     * Height is a fact only the framed document can measure, and a frame
     * without `allow-same-origin` — which is the entire security value of the
     * arrangement — cannot be measured from outside it. Without a script inside
     * the frame there is no channel at all, and the alternatives are a fixed
     * height (a scrollbar inside a scrollbar) or a guess.
     *
     * So `allow-scripts` is here, and the CSP below is what pays for it: script
     * execution is restricted to the HASH of our own height-and-hover reporter,
     * which no markup arriving in an email can match — a hash authorises one
     * exact script and nothing else. See MessageFrameScript for why it is a
     * hash rather than the nonce it used to be. The security properties that matter are untouched, and they
     * come from what is NOT in this list:
     *
     *   no allow-same-origin      → opaque origin: no session cookie, no
     *                               localStorage, no reach into the parent
     *                               document. This is the K-07 blast radius.
     *   no allow-forms            → a phishing form cannot even be submitted.
     *   no allow-top-navigation   → the page cannot be replaced under the reader.
     *   no allow-modals           → no alert() loop to trap them in.
     *   no allow-downloads        → no drive-by file.
     *
     * `allow-popups` and its escape token are what keep a normal target=_blank
     * link working; without the escape token the new tab would inherit this
     * sandbox and render the linked site crippled.
     */
    private const string SANDBOX = 'allow-popups allow-popups-to-escape-sandbox allow-scripts';

    public function __construct(
        private RemoteContentBlocker         $blocker,
        private QuoteCollapser               $quoteCollapser,
        private SenderIdentityChecker        $identityChecker,
        private TrustedImageSenderRepository $trustedSenders,
        private Security                     $security,
        private RequestStack                 $requestStack,
        private MessageFrameScript           $frameScript,
    ) {
    }

    /**
     * The trust lookup is one indexed point query per message, and a
     * conversation renders one of these per message in it. Deliberately not
     * memoised on this service: it is a shared instance, and under a worker
     * runtime a cache on it would outlive the request and answer one reader's
     * question with another reader's allowlist. A cheap query beats that trade
     * every time.
     *
     * @param bool $forceImages render as if the reader had opted in. Nothing
     *                          passes true today — the reading pane asks the
     *                          frame instead, and the print view deliberately
     *                          follows the same allowlist as the screen. It
     *                          exists for a caller that has already obtained
     *                          consent by some other means.
     */
    public function render(Message $message, bool $forceImages = false): MessageRender
    {
        $user      = $this->security->getUser();
        $trusted   = $user instanceof User
            && true === $this->trustedSenders->isTrusted($user, $message->fromAddress);
        $inSpam    = self::isInSpam($message);

        // A message sitting in Spam never loads images on an allowlist. The
        // allowlist records a belief about a sender; a message in Spam is the
        // provider disagreeing about whether this really is that sender, and
        // the safe reading of a disagreement is the cautious one.
        $allow = (true === $trusted || true === $forceImages) && false === $inSpam;

        // The blocker settles what the body may load; the collapser then folds
        // its trailing reply-history behind a "Show quoted text" toggle. Both
        // are pure display transformations over the same safe HTML — the image
        // counts the blocker measured are about images, so wrapping a quote
        // leaves them untouched.
        $blocked = $this->blocker->rewrite($message->bodyHtmlSafe, $allow);
        $content = new RemoteContent(
            $this->quoteCollapser->collapse($blocked->html),
            $blocked->blocked,
            $blocked->allowed,
        );

        return new MessageRender(
            content:       $content,
            imagesAllowed: $allow,
            senderTrusted: $trusted,
            inSpam:        $inSpam,
            mismatch:      $this->identityChecker->check($message),
            sandbox:       self::SANDBOX,
            csp:           $this->contentSecurityPolicy(),
            frameScript:   $this->frameScript->source(),
            text:          $message->bodyHtmlSafe ? null : $message->bodyText,
        );
    }

    /**
     * The frame's own policy, a second wall behind the blocker.
     *
     * `default-src 'none'` and then only what mail legitimately needs. The one
     * that carries weight is img-src: it names OUR origin and `data:` and
     * nothing else, so a remote image the blocker somehow failed to catch is
     * refused by the browser rather than loaded. That is the property worth
     * having — the blocker and the CSP have to BOTH be wrong for a pixel to
     * fire.
     *
     * The origin is spelled out rather than written `'self'` on purpose: the
     * frame is an opaque origin, so `'self'` in its policy matches nothing at
     * all and would block our own proxy along with everything else.
     */
    private function contentSecurityPolicy(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        $origin  = null !== $request ? $request->getSchemeAndHttpHost() : '';

        return implode('; ', [
            "default-src 'none'",
            trim(sprintf('img-src %s data:', $origin)),
            "style-src 'unsafe-inline'",
            'font-src data:',
            sprintf("script-src '%s'", $this->frameScript->hash()),
            "form-action 'none'",
            "frame-src 'none'",
            "connect-src 'none'",
            "object-src 'none'",
            "base-uri 'none'",
        ]);
    }

    private static function isInSpam(Message $message): bool
    {
        foreach ($message->labels as $label) {
            if (LabelRole::Spam === $label->role) {
                return true;
            }
        }

        return false;
    }
}
