<?php

declare(strict_types=1);

namespace App\Infrastructure\Intelligence;

use App\Application\Intelligence\Contract\LanguageModel;
use App\Application\Intelligence\DTO\NarrativeBrief;
use App\Application\Intelligence\Exception\LanguageModelUnavailable;
use App\Domain\Intelligence\Evidence;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;

/**
 * Writing the narrative with Claude's Messages API.
 *
 * Built against the published contract: `POST /v1/messages` with `x-api-key` and `anthropic-version`, a body of
 * `model`, `max_tokens`, `system` and `messages`, and a response whose `content` is an array of blocks of which
 * the `text` ones carry the prose. Errors come back as `{"type":"error","error":{"type","message"},"request_id"}`
 * with the documented status codes, and the `request-id` header is on every response.
 *
 * **BLOCKED_EXTERNAL on an API key.** This repository holds no model credential and cannot issue itself one, so
 * nothing here has completed a live call. What is built is the whole contract — the request, the response parsing,
 * the error mapping, the retry rule — exercised against fixtures of the documented shapes. `NoLanguageModel` is
 * bound in its place until a key exists, and the assistant works either way because the product computes every
 * figure itself.
 *
 * Three decisions about the request are deliberate.
 *
 * **No `temperature`.** It is deprecated for models after Claude Opus 4.6, and this is a task with one right
 * answer: restate the brief in good prose. Variation is not a feature here.
 *
 * **No `thinking` configuration.** The models this would run against differ in which settings they accept — some
 * require adaptive, some reject `disabled` — and the default is adaptive on all of them. Sending nothing is the
 * one choice that is correct across the range.
 *
 * **One retry, on the statuses the documentation says to retry.** 429 honouring `retry-after`, and 500, 504 and
 * 529 with a short backoff. Everything else is an answer, and answers are not retried. A model that is busy costs
 * a reader their paragraph; it must not cost them the request.
 */
final readonly class AnthropicLanguageModel implements LanguageModel
{
    /** The documented version header. Pinned, because the response shape is what this class parses. */
    private const string API_VERSION = '2023-06-01';

    /** @var list<int> */
    private const array RETRYABLE = [429, 500, 504, 529];

    private const int RETRY_DELAY_MS = 500;

    public function __construct(
        private HttpFactory $http,
        private ?string $apiKey,
        private string $model,
        private int $maxTokens,
        private int $timeoutSeconds,
        private string $baseUrl,
    ) {}

    public function isConfigured(): bool
    {
        return $this->apiKey !== null && trim($this->apiKey) !== '';
    }

    public function name(): string
    {
        return $this->model;
    }

    public function write(NarrativeBrief $brief): string
    {
        if (! $this->isConfigured()) {
            throw LanguageModelUnavailable::notConfigured();
        }

        $response = $this->send($brief);

        if ($response->failed()) {
            throw LanguageModelUnavailable::because($this->explain($response));
        }

        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];

        return $this->textOf($body);
    }

    private function send(NarrativeBrief $brief): Response
    {
        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxTokens,
            'system' => $this->system(),
            'messages' => [['role' => 'user', 'content' => $this->prompt($brief)]],
        ];

        $attempt = 0;

        while (true) {
            try {
                $response = $this->http
                    ->timeout($this->timeoutSeconds)
                    ->withHeaders([
                        'x-api-key' => (string) $this->apiKey,
                        'anthropic-version' => self::API_VERSION,
                        'content-type' => 'application/json',
                    ])
                    ->post(rtrim($this->baseUrl, '/').'/v1/messages', $payload);
            } catch (ConnectionException $exception) {
                if ($attempt > 0) {
                    throw LanguageModelUnavailable::because($exception->getMessage());
                }

                $attempt++;
                usleep(self::RETRY_DELAY_MS * 1000);

                continue;
            }

            if ($attempt === 0 && in_array($response->status(), self::RETRYABLE, true)) {
                $attempt++;
                usleep($this->waitFor($response) * 1000);

                continue;
            }

            return $response;
        }
    }

    /** How long to wait before the one retry, in milliseconds. */
    private function waitFor(Response $response): int
    {
        $after = $response->header('retry-after');

        if ($after !== '' && is_numeric($after)) {
            // Capped: a rate limit asking for a minute is a rate limit this request should not wait out.
            return (int) min((float) $after * 1000, 5_000);
        }

        return self::RETRY_DELAY_MS;
    }

    /**
     * What went wrong, in the terms the API uses.
     *
     * The request id is included because it is the only thing Anthropic's support can act on, and a message that
     * dropped it would make a real outage unreportable.
     */
    private function explain(Response $response): string
    {
        /** @var array<string, mixed> $body */
        $body = $response->json() ?? [];
        /** @var array<string, mixed> $error */
        $error = is_array($body['error'] ?? null) ? $body['error'] : [];

        $type = is_string($error['type'] ?? null) ? $error['type'] : 'http_'.$response->status();
        $message = is_string($error['message'] ?? null) ? $error['message'] : 'The model did not answer.';
        $requestId = is_string($body['request_id'] ?? null)
            ? $body['request_id']
            : $response->header('request-id');

        return $requestId === ''
            ? sprintf('%s: %s', $type, $message)
            : sprintf('%s: %s (request %s)', $type, $message, $requestId);
    }

    /**
     * The prose out of the response.
     *
     * Every `text` block, joined. A response that carries none — all tool use, or stopped at `max_tokens` before
     * writing anything — is treated as unavailable rather than as an empty narrative, so the reader gets the
     * product's own sentences instead of a blank paragraph.
     *
     * @param  array<string, mixed>  $body
     */
    private function textOf(array $body): string
    {
        $blocks = is_array($body['content'] ?? null) ? $body['content'] : [];
        $text = [];

        foreach ($blocks as $block) {
            if (! is_array($block) || ($block['type'] ?? null) !== 'text') {
                continue;
            }

            if (is_string($block['text'] ?? null) && trim($block['text']) !== '') {
                $text[] = trim($block['text']);
            }
        }

        if ($text === []) {
            throw LanguageModelUnavailable::because(sprintf(
                'The model returned no text (stop reason: %s).',
                is_string($body['stop_reason'] ?? null) ? $body['stop_reason'] : 'unknown',
            ));
        }

        return implode("\n\n", $text);
    }

    /**
     * The instruction.
     *
     * It states the rule the guard enforces, which is belt and braces on purpose: the model is told not to
     * produce a figure, and the answer is checked anyway. "The model was instructed not to" is not a control.
     */
    private function system(): string
    {
        return implode("\n", [
            'You write short, plain summaries of advertising performance for a marketing agency.',
            '',
            'Rules:',
            '- Use only the figures given to you. Do not calculate anything, including percentages, averages,',
            '  differences or totals. If a figure you want is not listed, write the sentence without it.',
            '- Never introduce a number of any kind that is not in the brief.',
            '- Do not speculate about causes beyond what the brief states.',
            '- Write at most one short paragraph per finding, in the order given.',
            '- Plain British English. No headings, no bullet points, no preamble, no sign-off.',
            '- The subject is "the account". You have not been told whose it is, and must not guess.',
        ]);
    }

    /**
     * The brief, rendered for a model.
     *
     * It carries each finding's subject — the account, a platform, measurement — and no client name, project or
     * campaign identifier beyond that. See `NarrativeBrief`. Sending a third party "Alhambra's cost per click doubled" discloses a
     * commercial fact about a named company; sending "the account's cost per click doubled" discloses a number.
     */
    private function prompt(NarrativeBrief $brief): string
    {
        $lines = [
            sprintf('Mode: %s', $brief->mode->value),
            sprintf('Period: %s', $brief->period),
            '',
            'Findings:',
        ];

        foreach ($brief->findings as $index => $finding) {
            $lines[] = sprintf('%d. [%s] %s', $index + 1, $finding->severity->value, $finding->headline);
            /*
             * What the finding is about: the account as a whole, a named platform, or measurement. Without it a
             * narrative cannot say *which* platform stopped, which is the one thing a reader needs from that
             * finding. Platform names are not client identity; the subject never carries one.
             */
            $lines[] = sprintf('   About: %s', $finding->subject);
            $lines[] = sprintf('   %s', $finding->detail);
            $lines[] = sprintf('   Confidence: %s', $finding->confidence->value);

            foreach ($finding->evidence as $evidence) {
                $lines[] = sprintf('   - %s', $this->describe($evidence));
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function describe(Evidence $evidence): string
    {
        if ($evidence->value === null) {
            return sprintf(
                '%s: not available (%s) [%s]',
                $evidence->label,
                $evidence->reason ?? 'no reason recorded',
                $evidence->source->value,
            );
        }

        $base = sprintf('%s: %s', $evidence->label, $this->number($evidence->value));

        if ($evidence->comparedWith !== null) {
            $base .= sprintf(' (was %s)', $this->number($evidence->comparedWith));
        }

        return $base.sprintf(' [%s, %s]', $evidence->period, $evidence->source->value);
    }

    /**
     * A figure, written the way the guard will read it back.
     *
     * No thousands separators and a fixed number of decimals, because the brief's permitted list is the source of
     * truth for what the prose may contain: a figure rendered one way here and matched another way there would
     * have the guard refusing the model for repeating what it was given.
     */
    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
