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
        $texts = [];

        foreach ($items as $item) {
            if (array_key_exists($item['id'], $texts)) {
                throw UnusableResponse::invalid($stage, "{$what} id {$item['id']} appears twice", $response);
            }

            $texts[$item['id']] = trim($item['text']);
        }

        ksort($texts);

        $texts = array_filter($texts, fn (string $text) => $text !== '');

        return $texts !== [] ? $texts : throw UnusableResponse::missing($stage, $what, $response);
    }
}
