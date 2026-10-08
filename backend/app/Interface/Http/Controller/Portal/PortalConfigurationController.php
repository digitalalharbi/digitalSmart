<?php

declare(strict_types=1);

namespace App\Interface\Http\Controller\Portal;

use App\Application\Portal\Command\EnablePortal;
use App\Application\Portal\Command\ResumePortal;
use App\Application\Portal\Command\SetPortalModules;
use App\Application\Portal\Command\SuspendPortal;
use App\Application\Portal\DTO\PortalSummary;
use App\Application\Portal\Query\GetPortal;
use App\Application\Portal\Query\ListPortals;
use App\Domain\Portal\Exception\InvalidPortalChange;
use App\Domain\Portal\PortalModule;
use App\Interface\Http\Controller\Controller;
use App\Interface\Http\Resource\Portal\PortalResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The agency's control over what each client can read.
 *
 * Thin by design. Every decision — whether a module may be exposed at all, whether a reason is required, what gets
 * written to the audit trail — is in the application layer, because this is the surface where a mistake is a
 * disclosure rather than a bug.
 */
final class PortalConfigurationController extends Controller
{
    public function vocabulary(): JsonResponse
    {
        return response()->json(['data' => PortalResource::vocabulary()]);
    }

    public function index(ListPortals $portals): JsonResponse
    {
        return response()->json([
            'data' => array_map(
                static fn (PortalSummary $summary): array => PortalResource::summary($summary),
                $portals(),
            ),
        ]);
    }

    public function show(string $client, GetPortal $get): JsonResponse
    {
        return response()->json(['data' => PortalResource::detail($get($client))]);
    }

    public function enable(string $client, EnablePortal $enable): JsonResponse
    {
        try {
            return response()->json(['data' => PortalResource::detail($enable($client))]);
        } catch (InvalidPortalChange $refusal) {
            return $this->refused($refusal);
        }
    }

    public function suspend(Request $request, string $client, SuspendPortal $suspend): JsonResponse
    {
        $validated = $request->validate([
            /*
             * Required here as well as in the use case. The message a person reads for an empty field belongs in
             * validation; the rule that a suspension cannot exist without a reason belongs in the domain, and
             * neither placement makes the other redundant.
             */
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            return response()->json([
                'data' => PortalResource::detail($suspend($client, (string) $validated['reason'])),
            ]);
        } catch (InvalidPortalChange $refusal) {
            return $this->refused($refusal);
        }
    }

    public function resume(string $client, ResumePortal $resume): JsonResponse
    {
        try {
            return response()->json(['data' => PortalResource::detail($resume($client))]);
        } catch (InvalidPortalChange $refusal) {
            return $this->refused($refusal);
        }
    }

    public function modules(Request $request, string $client, SetPortalModules $set): JsonResponse
    {
        $validated = $request->validate([
            /*
             * `present` rather than `required`: an empty array is the legitimate way to say "expose nothing", and
             * `required` would reject the one request that closes a portal's surfaces without closing the portal.
             */
            'modules' => ['present', 'array'],
            'modules.*' => ['string', 'in:'.implode(',', PortalModule::values())],
        ]);

        /** @var list<string> $values */
        $values = array_values((array) ($validated['modules'] ?? []));

        $modules = array_map(
            static fn (string $value): PortalModule => PortalModule::from($value),
            $values,
        );

        try {
            return response()->json(['data' => PortalResource::detail($set($client, $modules))]);
        } catch (InvalidPortalChange $refusal) {
            return $this->refused($refusal);
        }
    }

    private function refused(InvalidPortalChange $refusal): JsonResponse
    {
        return response()->json([
            'message' => $refusal->getMessage(),
            'code' => $refusal->reasonCode,
        ], Response::HTTP_CONFLICT);
    }
}
