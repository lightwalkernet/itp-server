<?php

namespace App\Services\Itp;

use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpStage;

/**
 * The `[{id, text}]` lists the prompt library's writing stages return (attunement segments,
 * Breathe 11 lines, Quantum Whys, replacement segments).
 */
class IdentifiedTexts
{
    /**
     * The schema of one list.
     *
     * @return array<string, mixed>
     */
    public static function schema(string $idDescription, string $textDescription, string $listDescription): array
    {
        return JsonSchema::list(JsonSchema::object([
            'id' => JsonSchema::integer($idDescription),
            'text' => JsonSchema::string($textDescription),
        ]), $listDescription);
    }

    /**
     * The texts keyed by id, sorted by id, trimmed, with blank texts dropped. A repeated id
     * makes the response unusable, as does a list with nothing left in it.
     *
     * @param  list<array{id: int, text: string}>  $items
     * @param  array<string, mixed>  $response  The whole response, for the error
     * @return array<int, string>
     *
     * @throws UnusableResponse
     */
    public static function byId(ItpStage $stage, array $items, string $what, array $response): array
    {
        return array_map(fn (array $item) => $item['text'], static::itemsById($stage, $items, $what, $response));
    }

    /**
     * The whole items keyed by id, as `byId()` sorts and filters them, with each text trimmed.
     * For lists whose items carry more than an id and a text.
     *
     * @template TItem of array{id: int, text: string}
     *
     * @param  list<TItem>  $items
     * @param  array<string, mixed>  $response  The whole response, for the error
     * @return array<int, TItem>
     *
     * @throws UnusableResponse
     */
    public static function itemsById(ItpStage $stage, array $items, string $what, array $response): array
    {
        $byId = [];

        foreach ($items as $item) {
            if (array_key_exists($item['id'], $byId)) {
                throw UnusableResponse::invalid($stage, "{$what} id {$item['id']} appears twice", $response);
            }

            $byId[$item['id']] = [...$item, 'text' => trim($item['text'])];
        }

        ksort($byId);

        $byId = array_filter($byId, fn (array $item) => $item['text'] !== '');

        return $byId !== [] ? $byId : throw UnusableResponse::missing($stage, $what, $response);
    }
}
