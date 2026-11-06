<?php

namespace App\Console\Commands;

use App\Enums\ItpStage;
use App\Models\PromptVersion;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Imports the prompt library (a Markdown file) as prompt versions. The method core is the first
 * code block under "### The method core"; each stage is the first code block under its
 * "## Stage N — …" heading, mapped by N. Every block is saved as a new, active version, unless it
 * is already identical to the active one, so running it twice changes nothing.
 *
 * All or nothing: if any of the ten blocks can't be found, nothing is saved.
 */
#[Signature('itp:import-prompts {path? : The prompt library (default: database/data/prompts/ITP_Prompt_Library_v1.md)}')]
#[Description('Import the method core and every stage prompt from the prompt library as new active versions')]
class ImportPrompts extends Command
{
    /**
     * The library's stage numbers.
     *
     * @var array<int, ItpStage>
     */
    public const array STAGE_NUMBERS = [
        0 => ItpStage::Safety,
        1 => ItpStage::Extraction,
        2 => ItpStage::Followups,
        3 => ItpStage::Reversal,
        4 => ItpStage::Attunement,
        5 => ItpStage::Regeneration,
        6 => ItpStage::Breathe11,
        7 => ItpStage::QuantumWhys,
        8 => ItpStage::Title,
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->argument('path') ?: database_path('data/prompts/ITP_Prompt_Library_v1.md');

        if (! File::isFile($path)) {
            $this->components->error("No prompt library at {$path}.");

            return self::FAILURE;
        }

        $markdown = str_replace("\r\n", "\n", File::get($path));

        $bodies = [ItpStage::MethodCore->value => $this->firstCodeBlock($this->section($markdown, '/^###\s+The method core\b.*$/mi'))];

        foreach (self::STAGE_NUMBERS as $number => $stage) {
            $bodies[$stage->value] = $this->firstCodeBlock($this->section($markdown, '/^##\s+Stage\s+'.$number.'\s*[—–-].*$/mu'));
        }

        $missing = array_keys(array_filter($bodies, fn (?string $body) => blank($body)));

        if ($missing !== []) {
            $this->components->error('Nothing was imported. No prompt found in '.basename($path).' for: '.implode(', ', $missing).'.');

            return self::FAILURE;
        }

        $notes = 'Imported from '.basename($path);

        $results = DB::transaction(fn (): array => collect($bodies)
            ->map(fn (string $body, string $stage) => $this->import(ItpStage::from($stage), $body, $notes))
            ->all());

        $this->components->twoColumnDetail('<fg=gray>Prompt</>', '<fg=gray>Result</>');

        foreach ($results as $stage => $result) {
            $this->components->twoColumnDetail(ItpStage::from($stage)->label(), $result);
        }

        $imported = count(array_filter($results, fn (string $result) => str_starts_with($result, 'imported')));

        $this->components->info("{$imported} imported, ".(count($results) - $imported).' unchanged.');

        return self::SUCCESS;
    }

    /**
     * Save the body as the stage's next active version, unless the active one already matches.
     */
    protected function import(ItpStage $stage, string $body, string $notes): string
    {
        $active = PromptVersion::activeFor($stage);

        if ($active?->body === $body) {
            return "unchanged (v{$active->version})";
        }

        $version = PromptVersion::create(['stage' => $stage, 'body' => $body, 'notes' => $notes]);
        $version->activate();

        return "imported as v{$version->version}";
    }

    /**
     * The text after the first line matching the heading pattern, up to the next heading of the
     * same or a higher level, or null when there is no such heading.
     */
    protected function section(string $markdown, string $headingPattern): ?string
    {
        if (preg_match($headingPattern, $markdown, $heading, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        $level = strspn($heading[0][0], '#');
        $rest = substr($markdown, $heading[0][1] + strlen($heading[0][0]));

        $end = preg_match('/^#{1,'.$level.'}\s/m', $this->withoutFencedHeadings($rest), $next, PREG_OFFSET_CAPTURE) === 1
            ? $next[0][1]
            : strlen($rest);

        return substr($rest, 0, $end);
    }

    /**
     * The text with every "#" at the start of a line inside a code block masked, so a heading-like
     * line in a prompt never ends a section. The length is unchanged.
     */
    protected function withoutFencedHeadings(string $text): string
    {
        return (string) preg_replace_callback(
            '/^```.*?^```[ \t]*$/ms',
            fn (array $block) => preg_replace('/^#/m', ' ', $block[0]),
            $text,
        );
    }

    /**
     * The content of the first fenced code block, without its fences.
     */
    protected function firstCodeBlock(?string $section): ?string
    {
        if ($section === null || preg_match('/^```[^\n]*\n(.*?)\n```[ \t]*$/ms', $section, $block) !== 1) {
            return null;
        }

        return $block[1];
    }
}
