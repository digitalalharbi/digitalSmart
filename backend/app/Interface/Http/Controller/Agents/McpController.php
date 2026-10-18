<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Agents;

use App\Application\Agents\Tool\ToolGateway;
use App\Interface\Http\Controller\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use stdClass;

/**
 * digitalSmart as an MCP server: JSON-RPC 2.0 over HTTP, with the tools the registry declares.
 *
 * Nothing about a connection is trusted. The caller authenticates as a real user, in that user's workspace, and
 * every `tools/call` goes through the same gateway an internal agent uses — permission, schema, rate limit, log. The
 * tools are business-level (`propose_budget_change`, never "send a request to Google"), no tool returns a credential,
 * and every proposal a client makes is a request for a person to decide.
 */
final class McpController extends Controller
{
    private const PROTOCOL = '2025-06-18';

    public function __invoke(Request $request, ToolGateway $gateway): JsonResponse
    {
        $body = $request->json()->all();
        $id = $body['id'] ?? null;
        $method = is_string($body['method'] ?? null) ? $body['method'] : '';
        $params = is_array($body['params'] ?? null) ? $body['params'] : [];

        /* A notification has no id and gets no answer. */
        if (! array_key_exists('id', $body)) {
            return response()->json(null, 202);
        }

        return match ($method) {
            'initialize' => $this->result($id, [
                'protocolVersion' => self::PROTOCOL,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'digitalsmart', 'version' => '1.0.0'],
                'instructions' => 'Business-level tools for Smart Code’s campaigns. Read tools change nothing. Proposal tools create a decision for a person; they never change a platform.',
            ]),
            'ping' => $this->result($id, new stdClass),
            'tools/list' => $this->result($id, ['tools' => array_map(static fn ($d): array => [
                'name' => $d->name,
                'description' => $d->description,
                'inputSchema' => $d->inputSchema(),
                'annotations' => [
                    'readOnlyHint' => $d->risk === 'read',
                    'destructiveHint' => false,
                    'idempotentHint' => $d->risk === 'read',
                    'openWorldHint' => false,
                ],
            ], $gateway->definitions())]),
            'tools/call' => $this->call($id, $params, $gateway),
            default => response()->json([
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => -32601, 'message' => 'Method not found'],
            ]),
        };
    }

    /** @param array<string, mixed> $params */
    private function call(mixed $id, array $params, ToolGateway $gateway): JsonResponse
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        $outcome = $gateway->call($name, $arguments, 'mcp', 'external_ai');

        return $this->result($id, [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($outcome['ok'] ? ($outcome['data'] ?? []) : ($outcome['error'] ?? []), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]],
            'isError' => ! $outcome['ok'],
        ]);
    }

    private function result(mixed $id, mixed $result): JsonResponse
    {
        return response()->json(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }
}
