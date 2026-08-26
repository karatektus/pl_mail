<?php

declare(strict_types=1);

namespace App\Entity\Ai;

/**
 * The three things a model is allowed to be used for here.
 *
 * An enum rather than three booleans passed around, so that a caller cannot ask
 * "is AI on" in the abstract. It never is: it is on for a purpose, with a model
 * chosen for that purpose, and the answer differs per feature. See
 * AiSettings::enabledFor().
 */
enum AiFeature: string
{
    /** Semantic search — needs the EMBEDDING model. */
    case Search = 'search';

    /** Deciding which tab a message belongs in — needs the CHAT model. */
    case Categorise = 'categorise';

    /** Drafting help in the composer — needs the CHAT model. */
    case WritingHelp = 'writing_help';
}
