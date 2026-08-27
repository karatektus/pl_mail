<?php

declare(strict_types=1);

namespace App\Entity\Embeddable;

use App\Domain\Enum\Ai\ReplyContext;
use App\Entity\Ai\AiFeature;
use Doctrine\ORM\Mapping as ORM;

/**
 * What one person has decided about the AI features an administrator switched
 * on for everybody.
 *
 * WHAT THESE SETTINGS ARE ABOUT
 * ─────────────────────────────
 * Usefulness and speed, and nothing else. plMail is self-hosted and the model
 * runs on the operator's own network, so none of this is about what leaves the
 * building — nothing does. It is about a feature somebody does not find useful
 * (switch it off and stop paying for it), a model that writes better when it
 * knows who it is writing for (the persona), and one shared GPU that the
 * composer and the indexer already queue behind each other on (the context
 * setting).
 *
 * A SUBTRACTION, NEVER AN ADDITION
 * ────────────────────────────────
 * The four feature fields are stored as OFF and not as on, which is the rule
 * User::SETTING_INSIGHTS_DISABLED gives its reasons for and which this needs
 * for a second, sharper one: AiSettings is the installation's ceiling, and a
 * field spelled `writingHelpEnabled` here is one `?? $settings->writingHelpEnabled`
 * away from a user switching on something an administrator switched off. A
 * field spelled `writingHelpOff` is structurally incapable of it. The AND is
 * still enforced on the read side — see App\Service\Ai\AiPermissions — because
 * the shape of the column is an argument and not a guarantee.
 *
 * AN EMBEDDABLE AND NOT THE SETTINGS BAG
 * ──────────────────────────────────────
 * User::$settings is "the bag for the long tail" by its own docblock: keys
 * whose readers assume a default at the call site. This is the other case that
 * docblock names — a fixed, validated set — and two of its fields are free text
 * that ends up inside a prompt, where a bound has to be a column length and a
 * clamp rather than a convention. It also travels in a config backup the way
 * Appearance does, through toArray()/applyArray(), instead of needing a line in
 * ConfigBackupUsers' curated allowlist that nothing tests. That allowlist today
 * omits `insights.disabled_extractors`, `insights.pane_disabled` and
 * `compose.*` with nothing catching it, and a restored user re-doing their AI
 * setup has been told the restore did not work.
 *
 * NOT ON THE WIRE
 * ───────────────
 * No JMAP surface, deliberately, like Appearance::$logoLinked — a real column
 * the web pane edits that AppearanceMapper::PROPERTIES has never listed. JMAP
 * has no vocabulary for any of this: a persona and a context depth are answers
 * to questions only plMail's own composer asks, and a third-party client
 * setting them would be setting something it cannot then use.
 */
#[ORM\Embeddable]
final class AiPreferences
{
    /**
     * The ceiling on what a person may add to a prompt.
     *
     * Named rather than inlined because the settings template publishes them as
     * `maxlength` and the controller clamps to them — two copies of these
     * numbers is how a textarea accepts 4000 characters and reports success
     * while the server stores 600.
     *
     * Small on purpose, and the reason is a budget rather than a fear.
     * OllamaClient sends `model`, `messages`, `stream` and a temperature and
     * nothing else — there is no `num_ctx` on the wire — while WritingAssistant
     * already ships up to 3000 characters of context and 3000 of draft. The
     * model's default window is what decides which of that survives, and
     * everything added here pushes the actual mail out of it silently rather
     * than erroring. A persona that crowds out the message it is meant to help
     * answer has made the feature worse, and nothing would report it.
     */
    public const int MAX_SYSTEM_PROMPT = 600;
    public const int MAX_ABOUT_ME      = 1200;

    /** Semantic search over this person's mail: indexing it and searching it. */
    #[ORM\Column(name: 'search_off', type: 'boolean', options: ['default' => false])]
    public bool $searchOff = false;

    /** Letting a model break the tie on which tab this person's mail lands in. */
    #[ORM\Column(name: 'categorise_off', type: 'boolean', options: ['default' => false])]
    public bool $categoriseOff = false;

    /** The composer's drafting help. */
    #[ORM\Column(name: 'writing_help_off', type: 'boolean', options: ['default' => false])]
    public bool $writingHelpOff = false;

    /** The reading pane's offer to summarise a long conversation. */
    #[ORM\Column(name: 'summary_off', type: 'boolean', options: ['default' => false])]
    public bool $summaryOff = false;

    /**
     * The writer's own standing instruction, appended to the app's.
     *
     * Truncated in the setter rather than refused, which is the posture
     * Appearance's setters take and the right one for a closed web form: this
     * arrives from a textarea on a page only its owner can open, and a paste
     * that is forty characters too long should not lose the other six hundred.
     * Trimmed as well, so whitespace cannot smuggle length past the check that
     * asks whether there is anything here at all.
     *
     * mb_substr BEFORE trim, in that order: trimming first would let six
     * hundred characters of leading spaces survive the cut and leave nothing of
     * the sentence behind them.
     */
    #[ORM\Column(name: 'system_prompt', type: 'text', options: ['default' => ''])]
    public string $systemPrompt = '' {
        set => trim(mb_substr($value, 0, self::MAX_SYSTEM_PROMPT));
    }

    /** The same, for who the writer is rather than how they want to be written for. */
    #[ORM\Column(name: 'about_me', type: 'text', options: ['default' => ''])]
    public string $aboutMe = '' {
        set => trim(mb_substr($value, 0, self::MAX_ABOUT_ME));
    }

    /**
     * How much of the conversation the composer's drafting help is given.
     *
     * Defaults to the middle value, which is what the composer already did — so
     * an upgrade changes nobody's drafts. The generous end is the interesting
     * one: a model that has read the thread stops answering a message it has
     * only seen the last turn of. See {@see ReplyContext}, which spends its
     * docblock on why this is a quality-against-time trade and not a caution.
     */
    #[ORM\Column(name: 'reply_context', type: 'string', length: 16, enumType: ReplyContext::class, options: ['default' => 'message'])]
    public ReplyContext $replyContext = ReplyContext::Message;

    /**
     * Whether this person still allows a feature the installation allows.
     *
     * Half of the question and never all of it. AiSettings::enabledFor() is the
     * other half and the ceiling; see AiPermissions, which is the only thing
     * that should ever call this.
     */
    public function allows(AiFeature $feature): bool
    {
        return false === match ($feature) {
            AiFeature::Search      => $this->searchOff,
            AiFeature::Categorise  => $this->categoriseOff,
            AiFeature::WritingHelp => $this->writingHelpOff,
            AiFeature::Summary     => $this->summaryOff,
        };
    }

    /**
     * The export payload, which is every field.
     *
     * Nothing here is derived and nothing is unportable, unlike Appearance's
     * background file — so the honest list is the whole of it, and
     * ConfigBackupUsersTest asserts the round trip because nothing else would
     * catch a field added above and forgotten here.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version'        => 1,
            'searchOff'      => $this->searchOff,
            'categoriseOff'  => $this->categoriseOff,
            'writingHelpOff' => $this->writingHelpOff,
            'summaryOff'     => $this->summaryOff,
            'systemPrompt'   => $this->systemPrompt,
            'aboutMe'        => $this->aboutMe,
            'replyContext'   => $this->replyContext->value,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function applyArray(array $data): static
    {
        foreach (['searchOff', 'categoriseOff', 'writingHelpOff', 'summaryOff'] as $flag) {
            if (true === isset($data[$flag])) {
                $this->{$flag} = self::boolean($data[$flag]);
            }
        }

        // The two strings go through their own setters, so a document written
        // by a build with larger caps — or edited by hand — is clamped on the
        // way in rather than restored over the ceiling.
        if (true === isset($data['systemPrompt'])) {
            $this->systemPrompt = (string) $data['systemPrompt'];
        }

        if (true === isset($data['aboutMe'])) {
            $this->aboutMe = (string) $data['aboutMe'];
        }

        if (true === isset($data['replyContext'])) {
            // tryFrom and not from(): a document written by a build that
            // offered a fourth depth must restore the rest of the person's
            // setup rather than throwing, and the default is a working answer.
            $this->replyContext = ReplyContext::tryFrom((string) $data['replyContext']) ?? $this->replyContext;
        }

        return $this;
    }

    /**
     * A checkbox over the wire, the same way Appearance reads one.
     *
     * A backup file carries real booleans; a form posts the string in its DOM
     * node, so "0" arrives for off — and "0" is truthy to PHP's cast. Both
     * spellings are named rather than trusted to (bool).
     */
    private static function boolean(mixed $value): bool
    {
        if (true === is_bool($value)) {
            return $value;
        }

        return false === in_array($value, ['0', 0, 'false', '', null], true);
    }
}
