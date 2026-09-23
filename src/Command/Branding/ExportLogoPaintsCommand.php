<?php

declare(strict_types=1);

namespace App\Command\Branding;

use App\Domain\Enum\Theme\LogoMotif;
use App\Domain\Enum\Theme\LogoStyle;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Print the logo paint table: every icon × every paint → the colour of every
 * part, as JSON.
 *
 * For the Android build, which cannot ask the server at build time and must not
 * guess. Its launcher icons are generated from a committed copy of this table,
 * one adaptive icon per combination, and a phone that drew "ocean" on the
 * mailbox one shade off the topbar's would be a disagreement nobody could see
 * the cause of. So the phone's copy comes from HERE — the recipes in LogoMotif,
 * run — rather than from a transcription of them.
 *
 * The output is exactly the schema and order of the committed fixture
 * (tests/Domain/Enum/Theme/fixtures/logo-paints.json): motifs in enum order,
 * paints `original` first and then LogoStyle's order, parts in each motif's
 * own order. LogoMotifTest decodes this command's output and holds it equal to
 * that file, so regenerating the phone's copy is
 *
 *     php bin/console app:branding:export-paints > logo-paints.json
 *
 * and a diff of nothing is the expected result until a recipe changes.
 *
 * Printed rather than written to a path for the reason app:push:generate-vapid-keys
 * gives: where the file belongs is the other repository's business.
 */
#[AsCommand(
    name: 'app:branding:export-paints',
    description: 'Print every launcher icon × paint as part colours (JSON), for the Android build',
)]
final class ExportLogoPaintsCommand extends Command
{
    /**
     * The table's own description of itself, verbatim from the board's
     * export. Part of the data: a decoded comparison includes it.
     */
    private const string ABOUT = "Every launcher icon x paint -> part colours. A part is '#rrggbb', null (not drawn), "
        . "or {'ramp': [7 stops]}: a linear gradient, glyph parts from (8,6) to (40,42) in the 48 grid, "
        . 'the background from (18,18) to (90,90) on the 108 canvas.';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = json_encode($this->table(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // One space per level rather than json_encode()'s four, because that is
        // how the committed copies are indented: regenerating one from this
        // should diff as nothing at all, not as every line of the file.
        $json = (string) preg_replace_callback(
            '/^(?: {4})+/m',
            static fn (array $indent): string => str_repeat(' ', intdiv(strlen($indent[0]), 4)),
            $json,
        );

        // RAW: this is data for a file, and a console formatter reading `<…>`
        // as a style tag is the last thing it should meet.
        $output->writeln($json, OutputInterface::OUTPUT_RAW);

        return Command::SUCCESS;
    }

    /**
     * @return array{version: int, about: string, motifs: list<array{wire: string, parts: list<string>, paints: array<string, array<string, mixed>>}>}
     */
    private function table(): array
    {
        $motifs = [];

        foreach (LogoMotif::cases() as $motif) {
            $paints = [LogoMotif::ORIGINAL => $motif->paints(null)];

            foreach (LogoStyle::cases() as $style) {
                $paints[$style->value] = $motif->paints($style);
            }

            $motifs[] = ['wire' => $motif->value, 'parts' => $motif->parts(), 'paints' => $paints];
        }

        return ['version' => 1, 'about' => self::ABOUT, 'motifs' => $motifs];
    }
}
