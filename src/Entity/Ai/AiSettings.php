<?php

declare(strict_types=1);

namespace App\Entity\Ai;

use App\Domain\Trait\TimestampableTrait;
use App\Entity\Embeddable\AiPrompts;
use App\Infrastructure\Doctrine\Type\EncryptedStringType;
use App\Repository\Ai\AiSettingsRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Which model host this installation talks to, and which of the AI features it
 * is allowed to do anything with.
 *
 * OFF IS THE DEFAULT AND OFF IS A REAL STATE
 * ──────────────────────────────────────────
 * Everything here starts false and empty, and plMail is a complete mail client
 * in that state — no degraded search, no empty panels, no "configure AI"
 * nagging. A self-hosted mail client that quietly stopped working properly
 * because somebody had not set up a language model would be a worse product
 * than one without the feature.
 *
 * So every consumer asks {@see enabledFor()} first, and the answer is a plain
 * no until an administrator has said otherwise, named a host, and named a
 * model. Three separate conditions, because two of them are the ones people
 * actually get wrong: switched on with no host, or a host with no model.
 *
 * WHY THE FEATURES ARE SEPARATE FLAGS
 * ───────────────────────────────────
 * They have very different costs and very different appetites for being wrong.
 * Writing help is asked for explicitly, once, by somebody who is looking at the
 * result. Categorisation runs on every message that arrives, unattended, and a
 * model that is confidently wrong quietly misfiles mail. Embedding runs over
 * the entire mailbox once and then on every new message. Summarising is asked
 * for explicitly like writing help, but the answer is read INSTEAD of the mail
 * rather than beside it, so being wrong is not caught by the person who asked.
 * Somebody may reasonably want the first and not the third, and a single master
 * switch would make that choice for them.
 *
 * THE ENDPOINT IS NOT USER INPUT
 * ──────────────────────────────
 * It is typed into a form only an administrator can open and stored as
 * configuration. That is the whole reason OllamaClient performs no address
 * validation while ImageProxyFetcher refuses every private address: the danger
 * there is a URL arriving inside a message, and there is no such path to here.
 * Nothing may ever write this column from anything a user sent.
 */
#[ORM\Entity(repositoryClass: AiSettingsRepository::class)]
#[ORM\Table(name: 'ai_settings')]
// One row, held by the index rather than by a check in PHP — every such check
// happens before the insert, and therefore before another request's insert.
#[ORM\UniqueConstraint(name: 'uniq_ai_settings_singleton', columns: ['singleton'])]
#[ORM\HasLifecycleCallbacks]
class AiSettings
{
    use TimestampableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    public private(set) ?int $id = null;

    /** Always 1; it exists so the unique index above has something to be unique over. */
    #[ORM\Column(options: ['default' => 1])]
    public private(set) int $singleton = 1;

    /**
     * The master switch. Off means every feature below is off whatever it says,
     * so an administrator can stop all of it in one action without losing the
     * configuration underneath — the difference between "we are not using this"
     * and "we have lost the settings", which FcmConfig makes for the same
     * reason.
     */
    #[ORM\Column(options: ['default' => false])]
    public bool $isEnabled = false;

    /** e.g. `http://10.0.0.5:11434`. A box on the operator's own network. */
    #[ORM\Column(name: 'base_url', length: 255, nullable: true)]
    public ?string $baseUrl = null;

    /**
     * Optional, because Ollama itself has no authentication — but people put it
     * behind a reverse proxy that does, and a feature that cannot be used
     * behind one would push them towards exposing it instead.
     *
     * Encrypted at rest like every other credential this application stores.
     */
    #[ORM\Column(name: 'api_token', type: EncryptedStringType::NAME, nullable: true)]
    public ?string $apiToken = null;

    /** The model that writes: replies, subject lines, summaries. */
    #[ORM\Column(name: 'chat_model', length: 128, nullable: true)]
    public ?string $chatModel = null;

    /** The model that turns text into vectors. Usually a much smaller one. */
    #[ORM\Column(name: 'embedding_model', length: 128, nullable: true)]
    public ?string $embeddingModel = null;

    /**
     * How many components the embedding model produces.
     *
     * Recorded rather than assumed, and recorded FROM THE MODEL'S OWN ANSWER
     * the first time it is asked: 768 and 1024 are both common, the number is
     * not in the model's name, and a mailbox embedded at one width and searched
     * at another returns nonsense rather than an error. Changing the model has
     * to invalidate what was stored, and this is what makes that detectable.
     */
    #[ORM\Column(name: 'embedding_dimensions', nullable: true)]
    public ?int $embeddingDimensions = null;

    /** Semantic search over the mailbox. */
    #[ORM\Column(name: 'search_enabled', options: ['default' => false])]
    public bool $searchEnabled = false;

    /** Letting a model decide which tab a message belongs in. */
    #[ORM\Column(name: 'categorisation_enabled', options: ['default' => false])]
    public bool $categorisationEnabled = false;

    /** Drafting help in the composer. */
    #[ORM\Column(name: 'writing_help_enabled', options: ['default' => false])]
    public bool $writingHelpEnabled = false;

    /**
     * Summarising a conversation, when somebody asks for one.
     *
     * The same model writing help uses, and the same appetite for being wrong —
     * but a longer call: a whole thread is a much larger prompt than a draft,
     * and the answer is read INSTEAD of the mail rather than beside it. Its own
     * flag for the reason the three above have theirs: an operator who is happy
     * to spend half a minute of GPU on a reply somebody will proofread may not
     * be happy to spend it on a paraphrase somebody will trust.
     */
    #[ORM\Column(name: 'summary_enabled', options: ['default' => false])]
    public bool $summaryEnabled = false;

    /**
     * What an administrator has typed in place of the prompts we ship.
     *
     * An embeddable rather than seven properties here, for the reason
     * User::$aiPreferences is one: this is a fixed set of free text that ends up
     * inside a prompt, so the bound has to be a column length and a clamping
     * setter rather than a convention. Keeping it in its own object also keeps
     * this class about what the installation is ALLOWED to do — which is what
     * every consumer asks it — rather than about what it says.
     *
     * Everything in it is null until somebody edits a prompt, and null means the
     * shipped text. Nothing here is the answer to "what is sent": see
     * {@see \App\Service\Ai\PromptLibrary}, which is the one reader that
     * resolves an override against the default.
     *
     * private(set) like User's two embeddables: the fields inside are written
     * directly, but nobody may swap the whole object out from under a hydrated
     * row.
     */
    #[ORM\Embedded(class: AiPrompts::class, columnPrefix: 'prompt_')]
    public private(set) AiPrompts $prompts;

    /**
     * Present only because an embeddable has to be instantiated.
     *
     * Doctrine builds an embeddable itself on hydration, so this runs for a
     * settings object that PHP made — the `new AiSettings()` that
     * AiSettingsRepository::currentOrDefault() hands back on an installation
     * that has never configured anything, and which the admin form binds to.
     * Without it that path is a typed property access error the first time the
     * page is opened on a fresh install.
     */
    public function __construct()
    {
        $this->prompts = new AiPrompts();
    }

    /**
     * Whether a model host is configured at all — as opposed to switched on.
     *
     * Switched on with no host is the commonest half-finished state, and the
     * one that produces a feature that appears to exist and never answers.
     */
    public function isConfigured(): bool
    {
        return null !== $this->baseUrl && '' !== trim($this->baseUrl);
    }

    /**
     * The only question consumers should ask.
     *
     * Three conditions rather than one, because being switched on is not the
     * same as being usable: a host with no model named for the job answers
     * nothing, and a caller that checked only the master switch would spend a
     * request finding that out on every message.
     */
    public function enabledFor(AiFeature $feature): bool
    {
        if (false === $this->isEnabled || false === $this->isConfigured()) {
            return false;
        }

        $model = match ($feature) {
            AiFeature::Search       => $this->embeddingModel,
            AiFeature::Categorise,
            AiFeature::WritingHelp,
            AiFeature::Summary      => $this->chatModel,
        };

        if (null === $model || '' === trim($model)) {
            return false;
        }

        return match ($feature) {
            AiFeature::Search      => $this->searchEnabled,
            AiFeature::Categorise  => $this->categorisationEnabled,
            AiFeature::WritingHelp => $this->writingHelpEnabled,
            AiFeature::Summary     => $this->summaryEnabled,
        };
    }
}
