<?php

declare(strict_types=1);

use App\Application\Intelligence\DTO\NarrativeBrief;
use App\Application\Intelligence\Exception\LanguageModelUnavailable;
use App\Application\Intelligence\Service\FigureScanner;
use App\Domain\Advertising\MetricSource;
use App\Domain\Intelligence\AssistantMode;
use App\Domain\Intelligence\Confidence;
use App\Domain\Intelligence\Evidence;
use App\Domain\Intelligence\Finding;
use App\Domain\Intelligence\FindingSeverity;
use App\Infrastructure\Intelligence\AnthropicLanguageModel;
use App\Infrastructure\Intelligence\NoLanguageModel;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;

/*
|--------------------------------------------------------------------------
| The Messages API adapter
|--------------------------------------------------------------------------
|
| BLOCKED_EXTERNAL on an API key: this repository holds no model credential and cannot issue itself one, so
| nothing here has made a live call. What is asserted is the whole contract against the documented shapes — the
| request headers and body, the response parsing, the error mapping and the retry rule — so that the day a key
| exists, the only thing untested is the network.
|
| The one test that matters most is the last: **the brief carries no client identity.** Sending a third party
| "Alhambra's cost per click doubled" discloses a commercial fact about a named company; sending "the account's
| cost per click doubled" discloses a number.
|
*/

function languageModel(): AnthropicLanguageModel
{
    return new AnthropicLanguageModel(
        app(HttpFactory::class),
        'sk-ant-test-key',
        'claude-sonnet-5-5',
        1024,
        20,
        'https://api.anthropic.com',
    );
}

function modelBrief(): NarrativeBrief
{
    return NarrativeBrief::of(
        AssistantMode::Analyse,
        'the last thirty days',
        [new Finding(
            code: 'efficiency_drift.cpc',
            severity: FindingSeverity::Warning,
            subject: 'The account',
            headline: 'Cost per click is worse than the period before.',
            detail: 'A move of this size is usually a change in what is being bid on.',
            evidence: [new Evidence(
                metric: 'cpc',
                label: 'Cost per click',
                value: 4.2,
                comparedWith: 2.1,
                period: 'the last thirty days',
                source: MetricSource::Synced,
                readFrom: 'analytics totals',
            )],
            confidence: Confidence::Firm,
        )],
        30,
        new FigureScanner,
    );
}

/**
 * The documented success shape.
 *
 * @return array<string, mixed>
 */
function messagesResponse(string $text = 'The account paid more per click than before.'): array
{
    return [
        'id' => 'msg_01XFDUDYJgAACzvnptvVoYEL',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-sonnet-5-5',
        'content' => [['type' => 'text', 'text' => $text]],
        'stop_reason' => 'end_turn',
        'stop_sequence' => null,
        'usage' => [
            'input_tokens' => 412,
            'output_tokens' => 37,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
        ],
    ];
}

/**
 * The documented error shape.
 *
 * @return array<string, mixed>
 */
function messagesError(string $type, string $message): array
{
    return [
        'type' => 'error',
        'error' => ['type' => $type, 'message' => $message],
        'request_id' => 'req_011CSHoEeqs5C35K2UUqR7Fy',
    ];
}

describe('the request', function (): void {
    it('sends what the documented contract requires, and nothing deprecated', function (): void {
        Http::fake(['api.anthropic.com/*' => Http::response(messagesResponse())]);

        languageModel()->write(modelBrief());

        Http::assertSent(function (Illuminate\Http\Client\Request $request): bool {
            expect($request->url())->toBe('https://api.anthropic.com/v1/messages');
            expect($request->method())->toBe('POST');

            expect($request->header('x-api-key'))->toBe(['sk-ant-test-key']);
            /* Pinned, because the response shape is what this adapter parses. */
            expect($request->header('anthropic-version'))->toBe(['2023-06-01']);

            /** @var array<string, mixed> $body */
            $body = $request->data();

            expect($body['model'])->toBe('claude-sonnet-5-5');
            expect($body['max_tokens'])->toBe(1024);
            expect($body['messages'][0]['role'])->toBe('user');
            expect($body['system'])->toBeString();

            /*
             * `temperature` is deprecated for models after Claude Opus 4.6, and this is a task with one right
             * answer: restate the brief in good prose. `thinking` is omitted because the accepted settings differ
             * across the models this could run against and adaptive is the default on all of them.
             */
            expect($body)->not->toHaveKey('temperature');
            expect($body)->not->toHaveKey('thinking');

            return true;
        });
    });

    it('tells the model the rule the guard enforces anyway', function (): void {
        Http::fake(['api.anthropic.com/*' => Http::response(messagesResponse())]);

        languageModel()->write(modelBrief());

        Http::assertSent(function (Illuminate\Http\Client\Request $request): bool {
            /** @var array<string, mixed> $body */
            $body = $request->data();

            /*
             * Belt and braces, deliberately. The model is told not to produce a figure and the answer is checked
             * regardless: "the model was instructed not to" is not a control.
             */
            expect($body['system'])->toContain('Use only the figures given to you');
            expect($body['system'])->toContain('Do not calculate');

            return true;
        });
    });

    it('sends no client identity', function (): void {
        Http::fake(['api.anthropic.com/*' => Http::response(messagesResponse())]);

        languageModel()->write(modelBrief());

        Http::assertSent(function (Illuminate\Http\Client\Request $request): bool {
            $payload = json_encode($request->data(), JSON_THROW_ON_ERROR);

            /*
             * The brief carries findings and figures. It does not carry who they belong to, and the system prompt
             * tells the model it has not been told and must not guess.
             */
            expect($payload)->toContain('The account');
            expect($payload)->not->toContain('Alhambra');
            expect($request->data()['system'])->toContain('must not guess');

            return true;
        });
    });
});

describe('the response', function (): void {
    it('returns the text blocks, joined', function (): void {
        Http::fake(['api.anthropic.com/*' => Http::response([
            ...messagesResponse(),
            'content' => [
                ['type' => 'text', 'text' => 'First paragraph.'],
                ['type' => 'text', 'text' => 'Second paragraph.'],
            ],
        ])]);

        expect(languageModel()->write(modelBrief()))->toBe("First paragraph.\n\nSecond paragraph.");
    });

    it('treats a response with no text as unavailable', function (): void {
        Http::fake(['api.anthropic.com/*' => Http::response([
            ...messagesResponse(),
            'content' => [],
            'stop_reason' => 'max_tokens',
        ])]);

        /*
         * Rather than an empty narrative. The caller then serves the product's own sentences, which is better
         * than a blank paragraph attributed to a model.
         */
        expect(fn (): string => languageModel()->write(modelBrief()))
            ->toThrow(LanguageModelUnavailable::class, 'max_tokens');
    });
});

describe('errors', function (): void {
    it('reports the documented error type and the request id', function (): void {
        Http::fake(['api.anthropic.com/*' => Http::response(
            messagesError('authentication_error', 'invalid x-api-key'),
            401,
        )]);

        /* The request id is the only thing support can act on; a message that dropped it is unreportable. */
        expect(fn (): string => languageModel()->write(modelBrief()))
            ->toThrow(LanguageModelUnavailable::class, 'req_011CSHoEeqs5C35K2UUqR7Fy');
    });

    it('does not retry an answer', function (): void {
        Http::fake(['api.anthropic.com/*' => Http::response(
            messagesError('invalid_request_error', 'messages: at least one message is required'),
            400,
        )]);

        try {
            languageModel()->write(modelBrief());
        } catch (LanguageModelUnavailable) {
            // Expected.
        }

        /* A 400 is an answer. Answers are never retried; repeating one only doubles the load. */
        Http::assertSentCount(1);
    });

    it('retries once when the documentation says to', function (): void {
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push(messagesError('overloaded_error', 'Overloaded'), 529)
            ->push(messagesResponse('Written on the second attempt.')),
        ]);

        expect(languageModel()->write(modelBrief()))->toBe('Written on the second attempt.');

        Http::assertSentCount(2);
    });

    it('gives up after one retry rather than hammering a busy API', function (): void {
        Http::fake(['api.anthropic.com/*' => Http::response(
            messagesError('rate_limit_error', 'Number of requests has exceeded your rate limit'),
            429,
        )]);

        try {
            languageModel()->write(modelBrief());
        } catch (LanguageModelUnavailable) {
            // Expected.
        }

        Http::assertSentCount(2);
    });
});

describe('when there is no key', function (): void {
    it('reports itself unconfigured rather than calling anything', function (): void {
        Http::fake();

        $model = new AnthropicLanguageModel(
            app(HttpFactory::class),
            null,
            'claude-sonnet-5-5',
            1024,
            20,
            'https://api.anthropic.com',
        );

        expect($model->isConfigured())->toBeFalse();
        expect(fn (): string => $model->write(modelBrief()))->toThrow(LanguageModelUnavailable::class);

        Http::assertNothingSent();
    });

    it('is what the container binds, in this repository', function (): void {
        /*
         * BLOCKED_EXTERNAL: no model credential is held here and none can be issued to this repository. The
         * assistant works regardless, because the product computes every finding and every figure itself.
         */
        expect(app(App\Application\Intelligence\Contract\LanguageModel::class))
            ->toBeInstanceOf(NoLanguageModel::class);
    });
});
