<?php

declare(strict_types=1);

namespace App\Command\Diagnostics;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Ai\AiFeature;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\PromptLibrary;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Ask the configured model to sort seven messages whose answers are known.
 *
 * WHY THIS EXISTS RATHER THAN A TEST
 * ──────────────────────────────────
 * The categorisation prompt can only be judged by a model, and which model is
 * an installation's own decision. A phpunit test would either need a live host
 * — making the suite depend on somebody's GPU — or a fake, which would be a
 * test of the fake. So this is a command: it runs against whatever is actually
 * configured, and the numbers it prints are about THAT model rather than about
 * one somebody benchmarked once.
 *
 * WHAT IT IS FOR
 * ──────────────
 * Two things, and the second is the reason it was written.
 *
 * Changing the prompt — from Admin → AI, or in PromptRules — without a way to
 * tell whether it helped is how a prompt gets worse while reading better. This
 * gives a number before and after.
 *
 * And it is how you find out whether the model you are running is up to the
 * job. The fixtures include the case that prompted all this: a recruitment
 * mail with a human sender name, the reader's first name in the subject, and
 * second-person prose — mail engineered to read as personal. A 30B model
 * classified that `primary` on a live installation while a 4B model in a test
 * harness got it right, which is the sort of thing no amount of reading the
 * prompt tells you.
 *
 * The fixtures are shaped exactly as ClassifyMailHandler::describe() shapes a
 * real message, bulk-header line and all. A benchmark against a different input
 * format would be measuring a prompt nobody sends.
 */
#[AsCommand(
    name: 'app:ai:categorise-check',
    description: 'Score the categorisation prompt against the configured model.',
)]
final class CategorisePromptCheckCommand extends Command
{
    /**
     * Seven messages and their right answers.
     *
     * Small on purpose: this spends real seconds of somebody's GPU per case,
     * and the point is a signal about the prompt rather than a benchmark suite.
     * Each one is a category the cascade can reach, plus two that exist to be
     * hard — the recruitment mail and the recipe newsletter, both of which are
     * marketing wearing a first name.
     *
     * @return list<array{name: string, want: MessageCategory, mail: string}>
     */
    private const array CASES = [
        [
            'name' => 'job match, personalised',
            'want' => 'promotions',
            'mail' => "From: Natascha Frey von Workwise <natascha.frey@workwise.io>\n"
                . "Subject: Paul, dieser Job passt zu deinem Profil.\n"
                . "Bulk headers: list-unsubscribe, feedback-id\n\n"
                . "1 Job passt zu deinem Profil: Backend Entwickler für PHP und NodeJS (m/w/d),\n"
                . "H2 invent GmbH, Lörrach, Remote Job, 65.000 € pro Jahr.\n"
                . "Alle passenden Jobs anschauen. Du bist nicht mehr auf Jobsuche?",
        ],
        [
            'name' => 'newsletter, personalised',
            'want' => 'promotions',
            'mail' => "From: Anna Bauer <anna@chefkoch.de>\n"
                . "Subject: Paul, dein Rezept der Woche wartet\n"
                . "Bulk headers: list-unsubscribe\n\n"
                . "Hallo Paul, ich habe diese Woche etwas für dich herausgesucht: Kürbissuppe.\n"
                . "Schau dir alle Rezepte an, die zu deinem Geschmack passen.",
        ],
        [
            'name' => 'colleague',
            'want' => 'primary',
            'mail' => "From: Sarah Weber <sarah.weber@h2invent.de>\n"
                . "Subject: Re: Deployment am Freitag\n\n"
                . "Hi Paul, passt Freitag 14 Uhr für den Deploy? Ich hänge den Rollback-Plan an.",
        ],
        [
            'name' => 'invoice',
            'want' => 'updates',
            'mail' => "From: Stadtwerke <rechnung@stadtwerke.de>\n"
                . "Subject: Ihre Rechnung 2026-0817\n"
                . "Bulk headers: list-unsubscribe\n\n"
                . "Ihre Abrechnung für Juli 2026 liegt bereit. 82,40 EUR werden am 15.08. abgebucht.",
        ],
        [
            'name' => 'shop sale',
            'want' => 'promotions',
            'mail' => "From: Zalando <news@zalando.de>\n"
                . "Subject: -30% auf alles, nur heute\n"
                . "Bulk headers: list-unsubscribe\n\n"
                . "Sichere dir 30% auf das gesamte Sortiment. Jetzt shoppen.",
        ],
        [
            'name' => 'social network',
            'want' => 'social',
            'mail' => "From: Instagram <no-reply@mail.instagram.com>\n"
                . "Subject: sieh dir an, was du verpasst hast\n"
                . "Bulk headers: list-unsubscribe\n\n"
                . "sashagrey und andere haben etwas Neues gepostet.",
        ],
        [
            'name' => 'mailing list',
            'want' => 'forums',
            'mail' => "From: symfony-devs <devs@lists.symfony.com>\n"
                . "Subject: Re: [devs] RFC: deprecating the annotation reader\n"
                . "Bulk headers: list-unsubscribe, list-id\n\n"
                . "I disagree with the timeline. Replying to the list with my reasoning below.",
        ],
    ];

    public function __construct(
        private readonly AiAssistant   $ai,
        private readonly PromptLibrary $prompts,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (false === $this->ai->settings()->enabledFor(AiFeature::Categorise)) {
            $io->warning('Mail sorting by assistant is switched off; there is no model to ask.');

            return Command::INVALID;
        }

        $system = $this->prompts->forCategorisation();

        $io->text(sprintf('Model: %s', (string) $this->ai->settings()->chatModel));
        $io->text(sprintf('Prompt: %d characters', mb_strlen($system)));
        $io->newLine();

        $rows   = [];
        $scored = 0;

        foreach (self::CASES as $case) {
            $answer = $this->ai->chat(AiFeature::Categorise, [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $case['mail']],
            ], 0.0);

            $got = self::interpret($answer);
            $ok  = $got === $case['want'];
            $scored += $ok ? 1 : 0;

            $rows[] = [$case['name'], $case['want'], $got ?? '(no answer)', $ok ? 'ok' : 'WRONG'];
        }

        $io->table(['case', 'expected', 'model said', ''], $rows);

        $io->writeln(sprintf('<info>%d of %d</info>', $scored, count(self::CASES)));

        // Not a failure exit code for a low score: the command reports, and
        // what counts as good enough is the operator's judgement about their
        // own mail. It fails only when it could not ask.
        return Command::SUCCESS;
    }

    /**
     * The first category name the answer contains.
     *
     * Deliberately laxer than ClassifyMailHandler's reading, which refuses an
     * ambiguous answer outright. Here an ambiguous answer is still worth
     * showing — "promotions, though it could be updates" is the model getting
     * it right in a format the prompt asked it not to use, and hiding that
     * behind "(no answer)" would report a prompt problem as a model problem.
     */
    private static function interpret(?string $answer): ?string
    {
        $said = mb_strtolower(trim((string) $answer));

        foreach (MessageCategory::cases() as $category) {
            if (true === str_contains($said, $category->value)) {
                return $category->value;
            }
        }

        return null;
    }
}
