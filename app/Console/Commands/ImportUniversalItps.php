<?php

namespace App\Console\Commands;

use BackedEnum;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Itp\Core\Enums\UniversalItpKind;
use Itp\Core\Models\UniversalItp;
use JsonException;

/**
 * Brings the universal and base ITPs from the committed data file into `universal_itps`. Each
 * entry's `source_key` is the row's slug, so running it again updates rows instead of adding new
 * ones. Writes go through the model, so every created or changed row gets a sync stamp and
 * reaches the phones. Rows missing from the file, and soft-deleted rows, are left alone.
 */
#[Signature('itp:import-universal-itps {--path= : The JSON data file (default: database/data/universal-itps.json)}')]
#[Description('Create or update the universal and base ITPs from the data file')]
class ImportUniversalItps extends Command
{
    /**
     * Data-file fields written to the row. `source_key` becomes the slug; anything else in an
     * entry (such as `legacy`) is kept for reference only.
     *
     * @var list<string>
     */
    private const array FIELDS = [
        'kind',
        'sort_order',
        'title',
        'description',
        'image_path',
        'attunement_text',
        'attunement_timings',
        'attunement_audio_path',
        'breath11_lines',
        'breath11_timings',
        'breath11_audio_path',
        'quantum_whys',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->option('path') ?: database_path('data/universal-itps.json');

        if (! File::isFile($path)) {
            $this->components->error("No data file at {$path}.");

            return self::FAILURE;
        }

        try {
            $entries = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->components->error("{$path} is not valid JSON: {$exception->getMessage()}");

            return self::FAILURE;
        }

        if (! is_array($entries) || ! array_is_list($entries)) {
            $this->components->error("{$path} must hold a JSON list of ITPs.");

            return self::FAILURE;
        }

        $validator = Validator::make($entries, $this->rules());

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];

        DB::transaction(function () use ($entries, &$counts): void {
            foreach ($entries as $entry) {
                $counts[$this->import($entry)]++;
            }
        });

        $this->components->info(sprintf(
            'Universal ITPs: %d created, %d updated, %d unchanged.',
            $counts['created'],
            $counts['updated'],
            $counts['unchanged'],
        ));

        if ($counts['skipped'] > 0) {
            $this->components->warn("{$counts['skipped']} skipped because the row is soft-deleted.");
        }

        return self::SUCCESS;
    }

    /**
     * Create or update the row for one entry, writing only the fields that differ.
     *
     * @param  array<string, mixed>  $entry
     * @return 'created'|'updated'|'unchanged'|'skipped'
     */
    private function import(array $entry): string
    {
        $itp = UniversalItp::withTrashed()->firstWhere('slug', $entry['source_key'])
            ?? new UniversalItp(['slug' => $entry['source_key']]);

        if ($itp->trashed()) {
            return 'skipped';
        }

        $changes = collect(self::FIELDS)
            ->mapWithKeys(fn (string $field): array => [$field => $entry[$field] ?? null])
            ->reject(fn (mixed $value, string $field): bool => $itp->exists && $this->isSame($itp->getAttribute($field), $value));

        if ($changes->isEmpty()) {
            return 'unchanged';
        }

        $itp->fill($changes->all())->save();

        return $itp->wasRecentlyCreated ? 'created' : 'updated';
    }

    /**
     * Compare a stored value with the file's. MySQL's JSON type reorders object keys and may
     * write `2.0` as `2`, so neither may count as a change, or every run would re-stamp every row.
     */
    private function isSame(mixed $stored, mixed $incoming): bool
    {
        return $this->canonical($stored) === $this->canonical($incoming);
    }

    private function canonical(mixed $value): mixed
    {
        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if (is_int($value)) {
            return (float) $value;
        }

        if (! is_array($value)) {
            return $value;
        }

        $value = array_map($this->canonical(...), $value);

        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            '*' => ['array'],
            '*.source_key' => ['required', 'string', 'alpha_dash:ascii', 'max:255', 'distinct'],
            '*.kind' => ['required', Rule::enum(UniversalItpKind::class)],
            '*.sort_order' => ['nullable', 'integer', 'min:0'],
            '*.title' => ['required', 'string', 'max:255'],
            '*.description' => ['nullable', 'string'],
            '*.attunement_text' => ['nullable', 'string'],
            '*.image_path' => ['nullable', 'string', 'max:255'],
            '*.attunement_audio_path' => ['nullable', 'string', 'max:255'],
            '*.breath11_audio_path' => ['nullable', 'string', 'max:255'],
            '*.attunement_timings' => ['nullable', 'list'],
            '*.breath11_lines' => ['nullable', 'list'],
            '*.breath11_timings' => ['nullable', 'list'],
            '*.quantum_whys' => ['nullable', 'list'],
        ];
    }
}
