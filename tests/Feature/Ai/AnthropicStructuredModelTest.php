<?php

use Anthropic\Client;
use App\Ai\AnthropicStructuredModel;
use App\Ai\Exceptions\MissingPromptVersion;
use App\Ai\Exceptions\UnusableResponse;
use App\Enums\ItpStage;
use App\Models\AiUsage;
use App\Models\ItpGeneration;
use App\Models\PromptVersion;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/*
 * The real gateway, on the real SDK, with the HTTP transport replaced: no request leaves.
 */
beforeEach(function () {
    $this->sent = [];
    $this->transport = new MockHandler;

    $stack = HandlerStack::create($this->transport);
    $stack->push(Middleware::history($this->sent));

    $this->app->instance(Client::class, new Client(apiKey: 'test-key', requestOptions: [
        'transporter' => new GuzzleClient(['handler' => $stack]),
        'maxRetries' => 0,
    ]));

    $this->gateway = app(AnthropicStructuredModel::class);
    $this->schema = ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text'], 'additionalProperties' => false];
    $this->methodCore = PromptVersion::factory()->forStage(ItpStage::MethodCore)->active()->create(['body' => '=== ITP METHOD CORE ===']);
});

/**
 * @param  array<string, mixed>  $overrides
 */
function anthropicMessage(array $overrides = []): Response
{
    return new Response(200, ['Content-Type' => 'application/json'], json_encode([
        'id' => 'msg_01',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-opus-5-5',
        'content' => [
            ['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'],
            ['type' => 'text', 'text' => '{"text":"You are already home."}'],
        ],
        'stop_reason' => 'end_turn',
        'stop_sequence' => null,
        'usage' => [
            'input_tokens' => 1200,
            'output_tokens' => 300,
            'cache_creation_input_tokens' => 4000,
            'cache_read_input_tokens' => 0,
        ],
        ...$overrides,
    ]));
}

/**
 * @return array<string, mixed>
 */
function sentBody(array $sent, int $index = 0): array
{
    return json_decode((string) $sent[$index]['request']->getBody(), true);
}

it('sends the method core then the stage prompt, each cached, with the schema and the stage settings, and returns the JSON', function () {
    config([
        'itp.ai.stages.regeneration.model' => 'claude-opus-5-5',
        'itp.ai.stages.regeneration.effort' => 'high',
        'itp.ai.stages.regeneration.max_tokens' => 12000,
    ]);
    PromptVersion::factory()->forStage(ItpStage::Regeneration)->active()->create(['body' => 'Rewrite the flagged line.']);
    $this->transport->append(anthropicMessage());

    $result = $this->gateway->generate(ItpStage::Regeneration, 'The flagged line.', $this->schema);

    expect($result)->toBe(['text' => 'You are already home.']);

    $body = sentBody($this->sent);
    expect((string) $this->sent[0]['request']->getUri())->toEndWith('/v1/messages')
        ->and($body['model'])->toBe('claude-opus-5-5')
        ->and($body['max_tokens'])->toBe(12000)
        ->and($body['system'])->toBe([
            ['type' => 'text', 'text' => '=== ITP METHOD CORE ===', 'cache_control' => ['type' => 'ephemeral']],
            ['type' => 'text', 'text' => 'Rewrite the flagged line.', 'cache_control' => ['type' => 'ephemeral']],
        ])
        ->and($body['messages'])->toBe([['role' => 'user', 'content' => 'The flagged line.']])
        ->and($body['thinking'])->toBe(['type' => 'adaptive'])
        ->and($body['output_config'])->toBe(['effort' => 'high', 'format' => ['type' => 'json_schema', 'schema' => $this->schema]])
        ->and($body)->not->toHaveKeys(['temperature', 'top_p']);
});

it('sends only the stage prompt for a stage that does not write in the ITP voice', function (ItpStage $stage) {
    PromptVersion::factory()->forStage($stage)->active()->create(['body' => 'Classify.']);
    $this->transport->append(anthropicMessage());

    $this->gateway->generate($stage, 'The intake.', $this->schema);

    expect(sentBody($this->sent)['system'])->toBe([['type' => 'text', 'text' => 'Classify.', 'cache_control' => ['type' => 'ephemeral']]])
        ->and(AiUsage::sole()->method_core_version_id)->toBeNull();
})->with([ItpStage::Safety, ItpStage::Extraction, ItpStage::Followups, ItpStage::Title]);

it('logs the call with its tokens, cost and both prompt versions, and notes them on the generation', function () {
    $prompt = PromptVersion::factory()->forStage(ItpStage::Regeneration)->active()->create();
    $generation = ItpGeneration::factory()->create();
    $this->transport->append(anthropicMessage());

    $this->gateway->generate(ItpStage::Regeneration, 'The flagged line.', $this->schema, $generation);

    $usage = AiUsage::sole();
    expect($usage->generation_id)->toBe($generation->id)
        ->and($usage->stage)->toBe(ItpStage::Regeneration)
        ->and($usage->provider)->toBe('anthropic')
        ->and($usage->model)->toBe('claude-opus-5-5')
        ->and($usage->input_tokens)->toBe(1200)
        ->and($usage->output_tokens)->toBe(300)
        ->and($usage->cache_write_tokens)->toBe(4000)
        ->and($usage->cache_read_tokens)->toBe(0)
        // 1200 × $4 + 300 × $20 + 4000 × $5, per million tokens
        ->and($usage->cost_usd)->toBe('0.030800')
        ->and($usage->prompt_version_id)->toBe($prompt->id)
        ->and($usage->method_core_version_id)->toBe($this->methodCore->id)
        ->and($usage->duration_ms)->toBeInt()
        ->and($generation->fresh()->prompt_versions)->toBe(['regeneration' => $prompt->id, 'method_core' => $this->methodCore->id]);
});

it('throws on a refusal or a truncated answer, and still logs the billed call', function (string $stopReason, string $message) {
    PromptVersion::factory()->forStage(ItpStage::Regeneration)->active()->create();
    $this->transport->append(anthropicMessage(['stop_reason' => $stopReason]));

    expect(fn () => $this->gateway->generate(ItpStage::Regeneration, 'The flagged line.', $this->schema))
        ->toThrow(UnusableResponse::class, $message);

    expect(AiUsage::count())->toBe(1);
})->with([
    'refusal' => ['refusal', 'The model refused the [regeneration] stage.'],
    'max tokens' => ['max_tokens', 'The [regeneration] stage hit its max_tokens before finishing.'],
]);

it('strips a stray code fence before decoding', function () {
    PromptVersion::factory()->forStage(ItpStage::Title)->active()->create();
    $this->transport->append(anthropicMessage(['content' => [['type' => 'text', 'text' => "```json\n{\"text\":\"Fenced.\"}\n```"]]]));

    expect($this->gateway->generate(ItpStage::Title, 'The attunement.', $this->schema))->toBe(['text' => 'Fenced.']);
});

it('throws with the raw output when the answer is not a JSON object', function () {
    PromptVersion::factory()->forStage(ItpStage::Regeneration)->active()->create();
    $this->transport->append(anthropicMessage(['content' => [['type' => 'text', 'text' => 'not json']]]));

    expect(fn () => $this->gateway->generate(ItpStage::Regeneration, 'The flagged line.', $this->schema))
        ->toThrow(UnusableResponse::class, "The [regeneration] stage did not return a JSON object.\n\nRaw output:\nnot json");
});

it('throws with the raw output when the JSON does not match the schema', function () {
    PromptVersion::factory()->forStage(ItpStage::Title)->active()->create();
    $this->transport->append(anthropicMessage(['content' => [['type' => 'text', 'text' => '{"text":"Hi","extra":1}']]]));

    expect(fn () => $this->gateway->generate(ItpStage::Title, 'The attunement.', $this->schema))
        ->toThrow(UnusableResponse::class, 'The [title] stage returned an unusable response: $ has unexpected key extra.'."\n\nRaw output:\n".'{"text":"Hi","extra":1}');
});

it('throws before calling the API when the stage, or the method core it needs, has no active prompt', function (ItpStage $missing) {
    PromptVersion::factory()->forStage(ItpStage::Regeneration)->active()->create();
    PromptVersion::query()->where('stage', $missing)->update(['activated_at' => null]);

    expect(fn () => $this->gateway->generate(ItpStage::Regeneration, 'The flagged line.', $this->schema))
        ->toThrow(MissingPromptVersion::class, "No active prompt version for the [{$missing->value}] stage. Activate one in Studio.");

    expect($this->sent)->toBe([])
        ->and(AiUsage::count())->toBe(0);
})->with([ItpStage::Regeneration, ItpStage::MethodCore]);

it('refuses to call the method core on its own', function () {
    expect(fn () => $this->gateway->generate(ItpStage::MethodCore, 'Anything.', $this->schema))
        ->toThrow(InvalidArgumentException::class);

    expect($this->sent)->toBe([]);
});
