<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Lightwalker\Sync\Server\SyncServer;
use Lightwalker\Sync\SyncRegistry;

/**
 * Writes every global sync type (universal ITPs, wildcards) to the JSON snapshot the app bundles
 * at `resources/content/snapshot.json`:
 *
 *     {"generated_at": "2026-11-07T12:00:00Z", "types": {"universal_itps": [...], "wildcards": [...]}}
 *
 * Rows come from the sync server's own pull, so each one is exactly a pulled record without
 * `sync_seq`: raw column values, JSON columns as the JSON strings the database stores. Tombstones
 * are left out: a new install has nothing to delete, and its first pull delivers them anyway.
 */
#[Signature('itp:export-content-snapshot {--path= : Where to write the snapshot (default: storage/app/content-snapshot.json)}')]
#[Description('Write the universal ITPs and wildcards to a JSON snapshot for the app to bundle')]
class ExportContentSnapshot extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SyncServer $server, SyncRegistry $registry): int
    {
        $path = $this->option('path') ?: storage_path('app/content-snapshot.json');

        $types = collect($registry->types())
            ->filter(fn (string $type): bool => $registry->definition($type)['scope'] === 'global')
            ->mapWithKeys(fn (string $type): array => [$type => $this->rows($server, $type)]);

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(
            ['generated_at' => now()->utc()->toIso8601ZuluString(), 'types' => $types->all()],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        )."\n");

        $this->components->info(sprintf(
            'Content snapshot written to %s (%s).',
            $path,
            $types->map(fn (array $rows, string $type): string => count($rows)." {$type}")->implode(', '),
        ));

        return self::SUCCESS;
    }

    /**
     * Every live row of a global type, oldest change first, paging through the pull.
     *
     * @return list<array<string, mixed>>
     */
    private function rows(SyncServer $server, string $type): array
    {
        $rows = [];
        $after = 0;

        do {
            $page = $server->pull($type, '', $after, (int) config('sync.server.max_pull_limit'));

            foreach ($page['records'] as $record) {
                if (($record['deleted_at'] ?? null) === null) {
                    $rows[] = Arr::except($record, ['sync_seq']);
                }
            }

            $after = $page['next_cursor'];
        } while ($page['has_more']);

        return $rows;
    }
}
