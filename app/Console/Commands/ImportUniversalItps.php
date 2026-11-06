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
 *
 * Each entry's `id` is fixed, so the server row, the app's bundled snapshot and every phone share
 * one primary key. A row found by slug under a different id (an import from before the ids were
 * fixed) is re-keyed: force-deleted and created again under the file's id. That leaves the old
 * id on any phone that already pulled it, which is acceptable only because ITP is pre-release.
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
        'short_name',
        'is_preview',
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

        $counts = ['created' => 0, 'updated' => 0, 're-keyed' => 0, 'unchanged' => 0, 'skipped' => 0];

        DB::transaction(function () use ($entries, &$counts): void {
            foreach ($entries as $entry) {
                $counts[$this->import($entry)]++;
            }
        });

        $this->components->info(sprintf(
            'Universal ITPs: %d created, %d updated, %d re-keyed, %d unchanged.',
            $counts['created'],
            $counts['updated'],
            $counts['re-keyed'],
            $counts['unchanged'],
        ));

        if ($counts['skipped'] > 0) {
            $this->components->warn("{$counts['skipped']} skipped because the row is soft-deleted.");
        }

        return self::SUCCESS;
    }

    /**
     * Create, re-key or update the row for one entry, writing only the fields that differ. Runs
     * inside the import's transaction, so a re-key never leaves the slug without a row.
     *
     * @param  array<string, mixed>  $entry
     * @return 'created'|'updated'|'re-keyed'|'unchanged'|'skipped'
     */
    private function import(array $entry): string
    {
        $existing = UniversalItp::withTrashed()->firstWhere('slug', $entry['source_key']);

        if ($existing?->trashed()) {
            return 'skipped';
        }

        if ($existing !== null && $existing->getKey() !== $entry['id']) {
            $existing->forceDelete();
            $this->write($this->newItp($entry), $entry);

            return 're-keyed';
        }

        return $this->write($existing ?? $this->newItp($entry), $entry);
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function newItp(array $entry): UniversalItp
    {
        return (new UniversalItp(['slug' => $entry['source_key']]))->forceFill(['id' => $entry['id']]);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return 'created'|'updated'|'unchanged'
     */
    private function write(UniversalItp $itp, array $entry): string
    {
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
            '*.id' => ['required', 'string', 'ulid', 'lowercase', 'distinct'],
            '*.source_key' => ['required', 'string', 'alpha_dash:ascii', 'max:255', 'distinct'],
            '*.kind' => ['required', Rule::enum(UniversalItpKind::class)],
            '*.sort_order' => ['nullable', 'integer', 'min:0'],
            '*.title' => ['required', 'string', 'max:255'],
            '*.short_name' => ['nullable', 'string', 'max:255'],
            '*.is_preview' => ['required', 'boolean'],
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
