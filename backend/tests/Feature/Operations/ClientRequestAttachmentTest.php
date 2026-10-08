<?php

declare(strict_types=1);

use App\Application\Operations\Command\AttachToClientRequest;
use App\Domain\Access\SystemRole;
use App\Domain\Audit\AuditAction;
use App\Infrastructure\Persistence\Eloquent\Model\AuditLog;
use App\Infrastructure\Persistence\Eloquent\Model\Client;
use App\Infrastructure\Persistence\Eloquent\Model\Project;
use App\Infrastructure\Persistence\Eloquent\Model\ProjectMember;
use App\Infrastructure\Persistence\Eloquent\Model\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\WorkspaceScenario;

/*
|--------------------------------------------------------------------------
| Files on a client request
|--------------------------------------------------------------------------
|
| Three things are being tested, and only the first is a feature.
|
| **The audience rule, applied to files.** An internal note is a sentence; an internal attachment is a
| competitor's rate card or a screenshot of an argument about the client. Both are excluded in SQL, by the same
| rule, and both are asserted from the client's own session rather than from a unit test of a filter.
|
| **The type is the file's own.** Not its extension and not the header it arrived with, both of which the uploader
| chose. A file called `brief.png` that is an HTML document is the attack this allow-list exists for, and the test
| that matters is the one that uploads exactly that.
|
| **A download is never a document in this origin.** Always an attachment, always the stored type, never inline.
|
*/

/** @return array{scenario: WorkspaceScenario, client: Client, project: Project, viewer: User} */
function attachmentScenario(): array
{
    Storage::fake('local');

    $scenario = WorkspaceScenario::create();
    $client = Client::factory()->create(['workspace_id' => $scenario->workspace->getKey()]);
    $project = Project::factory()->forClient($client)->create(['name' => 'Always-on']);

    $viewer = $scenario->addMember(SystemRole::ClientViewer);

    ProjectMember::query()->create([
        'project_id' => $project->getKey(),
        'user_id' => $viewer->getKey(),
        'role_id' => $scenario->role(SystemRole::ClientViewer)->getKey(),
    ]);

    return ['scenario' => $scenario, 'client' => $client, 'project' => $project, 'viewer' => $viewer];
}

/**
 * A real upload, whose bytes are on disk.
 *
 * **`UploadedFile::fake()` is deliberately not used**, and finding that out was the most useful thing in this
 * file: the fake overrides `getMimeType()` with a type derived from the *filename*. Every assertion here is about
 * what a sniffer says about the bytes, so a fake would have made the whole suite pass by agreeing with the
 * extension — the precise mistake the production code refuses to make.
 *
 * `$test: true` lets Symfony accept a path that did not come from a real upload; everything else, including the
 * sniffing, behaves as it does in production.
 */
function realUpload(string $name, string $contents): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'ds-upload-');

    if ($path === false) {
        throw new RuntimeException('Could not write a temporary file for the upload.');
    }

    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, null, null, true);
}

/**
 * A real PNG, as bytes.
 *
 * Generated rather than fixtured, because the point of every assertion here is what a **sniffer** says about the
 * bytes — and a fixture named `.png` that is not one would make the tests pass for the wrong reason.
 */
function pngBytes(): string
{
    $image = imagecreatetruecolor(4, 4);

    if ($image === false) {
        throw new RuntimeException('GD is not available, so this suite cannot make a real PNG.');
    }

    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

/**
 * @param  array{scenario: WorkspaceScenario, client: Client, project: Project, viewer: User}  $setup
 * @return array<string, mixed>
 */
function requestFor(array $setup, ?User $as = null): array
{
    /** @var array<string, mixed> */
    return test()->actingAs($as ?? $setup['scenario']->owner)
        ->postJson('/api/client-requests', [
            'client_id' => (string) $setup['client']->getKey(),
            'project_id' => (string) $setup['project']->getKey(),
            'kind' => 'change',
            'title' => 'The product images are wrong on three lines',
            'detail' => 'Screenshots attached.',
        ])
        ->assertCreated()
        ->json('data');
}

describe('uploading', function (): void {
    it('takes a real image from the client and files it as visible to them', function (): void {
        $setup = attachmentScenario();
        $request = requestFor($setup, $setup['viewer']);

        $response = test()->actingAs($setup['viewer'])
            ->post("/api/client-requests/{$request['id']}/attachments", [
                /* A client's audience is forced by the use case, so this is deliberately the wrong one. */
                'audience' => 'internal',
                'file' => realUpload('screenshot.png', pngBytes()),
            ])
            ->assertCreated()
            ->json('data');

        expect($response['attachments'])->toHaveCount(1);

        $file = $response['attachments'][0];

        /*
         * Forced to client-visible, whatever was sent. Their own file has to reach the person they are talking
         * to, and a file of theirs filed as internal would be hidden from the person who sent it — which a CHECK
         * also refuses.
         */
        expect($file['is_visible_to_client'])->toBeTrue();
        expect($file['is_from_client'])->toBeTrue();
        expect($file['type'])->toBe('image/png');
        expect($file['name'])->toBe('screenshot.png');
    });

    it('refuses an HTML document wearing a PNG filename', function (): void {
        $setup = attachmentScenario();
        $request = requestFor($setup);

        $response = test()->actingAs($setup['scenario']->owner)
            ->post("/api/client-requests/{$request['id']}/attachments", [
                'audience' => 'client',
                'file' => realUpload(
                    'brief.png',
                    '<html><body><script>alert(document.cookie)</script></body></html>',
                ),
            ])
            ->assertStatus(422);

        /*
         * The attack this allow-list exists for. An extension check and a `Content-Type` check both pass here,
         * because the uploader chose both — only the bytes disagree.
         */
        expect($response->json('code'))->toBe('attachment_type_not_accepted');
        /* And the message names what it actually is, which is the one useful thing to say. */
        expect($response->json('message'))->toContain('html');
    });

    it('refuses an SVG, and says what to do instead', function (): void {
        $setup = attachmentScenario();
        $request = requestFor($setup);

        $response = test()->actingAs($setup['scenario']->owner)
            ->post("/api/client-requests/{$request['id']}/attachments", [
                'audience' => 'client',
                'file' => realUpload(
                    'logo.svg',
                    '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
                ),
            ])
            ->assertStatus(422);

        /*
         * An SVG is a document that can carry a script, and every stored-XSS in a file-upload feature is an SVG.
         * Nothing here needs vector art badly enough, and "export it as a PNG" is an answer somebody can act on.
         */
        expect($response->json('code'))->toBe('attachment_type_not_accepted');
        expect($response->json('message'))->toContain('exported as a PNG');
    });

    it('refuses a file over the limit with the two figures in it', function (): void {
        $setup = attachmentScenario();
        $request = requestFor($setup);

        $oversized = str_repeat('a', AttachToClientRequest::MAX_BYTES + 1);

        $response = test()->actingAs($setup['scenario']->owner)
            ->post("/api/client-requests/{$request['id']}/attachments", [
                'audience' => 'client',
                'file' => realUpload('notes.txt', $oversized),
            ])
            ->assertStatus(422);

        expect($response->json('code'))->toBe('attachment_too_large');
        // Both figures, because "too large" without a limit is not an instruction.
        expect($response->json('message'))->toContain('5.0 MB');
    });

    it('refuses to add to a settled request', function (): void {
        $setup = attachmentScenario();
        $request = requestFor($setup);

        test()->actingAs($setup['scenario']->owner)
            ->postJson("/api/client-requests/{$request['id']}/state", [
                'state' => 'declined',
                'reason' => 'We cannot do this one.',
            ])
            ->assertOk();

        $response = test()->actingAs($setup['scenario']->owner)
            ->post("/api/client-requests/{$request['id']}/attachments", [
                'audience' => 'client',
                'file' => realUpload('late.png', pngBytes()),
            ])
            ->assertStatus(422);

        // Not a permission problem: the thread is finished, and adding to it loses the record of the asking.
        expect($response->json('code'))->toBe('attachment_request_settled');
    });

    it('stores the bytes under a name it generated, never the uploader\'s', function (): void {
        $setup = attachmentScenario();
        $request = requestFor($setup);

        test()->actingAs($setup['scenario']->owner)
            ->post("/api/client-requests/{$request['id']}/attachments", [
                'audience' => 'client',
                'file' => realUpload('../../etc/passwd.png', pngBytes()),
            ])
            ->assertCreated();

        $stored = (string) DB::table('client_request_attachments')->value('stored_name');

        /*
         * A path built from an uploaded filename is a path traversal waiting for somebody to try it. Nothing of
         * the uploader's survives into the filesystem — the extension comes from the sniffed type.
         */
        expect($stored)->not->toContain('passwd');
        expect($stored)->not->toContain('..');
        expect($stored)->toEndWith('.png');
        expect($stored)->toContain((string) $setup['scenario']->workspace->getKey());

        /* And the name a reader sees is theirs, with the separators taken out. */
        $shown = (string) DB::table('client_request_attachments')->value('original_name');
        expect($shown)->not->toContain('/');
    });

    it('records the audience, the sniffed type and the size, and not the bytes', function (): void {
        $setup = attachmentScenario();
        $request = requestFor($setup);

        test()->actingAs($setup['scenario']->owner)
            ->post("/api/client-requests/{$request['id']}/attachments", [
                'audience' => 'internal',
                'file' => realUpload('rates.csv', "a,b\n1,2\n"),
            ])
            ->assertCreated();

        $entry = AuditLog::query()->where('action', AuditAction::ClientRequestAttached->value)->first();

        expect($entry)->not->toBeNull();

        /** @var array<string, mixed> $changes */
        $changes = $entry->changes ?? [];

        // The three fields an argument about a leaked or a malicious file turns on.
        expect($changes['audience'])->toBe('internal');
        expect($changes['sniffed_type'])->toBe('text/csv');
        expect($changes['byte_size'])->toBeGreaterThan(0);

        // An audit log is not a second place a client's files live.
        expect(json_encode($changes))->not->toContain('1,2');
    });
});

describe('what a client can and cannot fetch', function (): void {
    it('never selects an internal file for the client, on the request or by id', function (): void {
        $setup = attachmentScenario();
        $request = requestFor($setup);

        test()->actingAs($setup['scenario']->owner)
            ->post("/api/client-requests/{$request['id']}/attachments", [
                'audience' => 'internal',
                'file' => realUpload('rates.csv', "competitor,rate\nx,99\n"),
            ])
            ->assertCreated();

        $internalId = (string) DB::table('client_request_attachments')->value('id');

        $asClient = test()->actingAs($setup['viewer'])
            ->getJson("/api/client-requests/{$request['id']}")
            ->assertOk()
            ->json('data');

        /* Excluded in the WHERE clause, not filtered afterwards. */
        expect($asClient['attachments'])->toBe([]);

        /*
         * And 404 rather than 403 on the file itself. A 403 would confirm that an internal attachment exists on
         * their own request, which is a sentence about the agency rather than an access decision.
         */
        test()->actingAs($setup['viewer'])
            ->get("/api/client-request-attachments/{$internalId}")
            ->assertNotFound();
    });

    it('serves a client-visible file as a download and never inline', function (): void {
        $setup = attachmentScenario();
        $request = requestFor($setup);

        test()->actingAs($setup['scenario']->owner)
            ->post("/api/client-requests/{$request['id']}/attachments", [
                'audience' => 'client',
                'file' => realUpload('proof.pdf', "%PDF-1.4\n%%EOF\n"),
            ])
            ->assertCreated();

        $id = (string) DB::table('client_request_attachments')->value('id');

        $response = test()->actingAs($setup['viewer'])
            ->get("/api/client-request-attachments/{$id}")
            ->assertOk();

        /*
         * A PDF can carry JavaScript and a browser's own viewer may run it. `attachment` makes it a file on
         * somebody's disk rather than a document in this product's origin, and the type is the stored sniffed one
         * rather than anything the uploader said.
         */
        expect($response->headers->get('Content-Type'))->toBe('application/pdf');
        expect($response->headers->get('Content-Disposition'))->toContain('attachment;');
        expect($response->headers->get('Content-Disposition'))->toContain('proof.pdf');

        /* And it cannot run anything even if it is opened directly. */
        expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'none'");
        expect($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    });

    it('answers 404 when the record is there and the bytes are not', function (): void {
        $setup = attachmentScenario();
        $request = requestFor($setup);

        test()->actingAs($setup['scenario']->owner)
            ->post("/api/client-requests/{$request['id']}/attachments", [
                'audience' => 'client',
                'file' => realUpload('proof.png', pngBytes()),
            ])
            ->assertCreated();

        $stored = (string) DB::table('client_request_attachments')->value('stored_name');
        Storage::disk('local')->delete($stored);

        $id = (string) DB::table('client_request_attachments')->value('id');

        /*
         * A real state, not a server error: a restore from a database backup without the disk behind it produces
         * exactly this, and the honest answer is that the file is not there rather than a 500 inviting a retry.
         */
        test()->actingAs($setup['scenario']->owner)
            ->get("/api/client-request-attachments/{$id}")
            ->assertNotFound();
    });

    it('records who took a copy', function (): void {
        $setup = attachmentScenario();
        $request = requestFor($setup);

        test()->actingAs($setup['scenario']->owner)
            ->post("/api/client-requests/{$request['id']}/attachments", [
                'audience' => 'client',
                'file' => realUpload('proof.png', pngBytes()),
            ])
            ->assertCreated();

        $id = (string) DB::table('client_request_attachments')->value('id');

        test()->actingAs($setup['viewer'])->get("/api/client-request-attachments/{$id}")->assertOk();

        $entry = AuditLog::query()
            ->where('action', AuditAction::ClientRequestAttachmentRead->value)
            ->first();

        // A download is a read of somebody else's file, which is the read worth recording.
        expect($entry)->not->toBeNull();
        expect($entry?->actor_id)->toBe((string) $setup['viewer']->getKey());
    });

    it('refuses an attachment on a client this reader has no project with', function (): void {
        $setup = attachmentScenario();
        $request = requestFor($setup);

        test()->actingAs($setup['scenario']->owner)
            ->post("/api/client-requests/{$request['id']}/attachments", [
                'audience' => 'client',
                'file' => realUpload('proof.png', pngBytes()),
            ])
            ->assertCreated();

        $id = (string) DB::table('client_request_attachments')->value('id');

        /* A client viewer of the same workspace, on no project at all. */
        $stranger = $setup['scenario']->addMember(SystemRole::ClientViewer);

        test()->actingAs($stranger)
            ->get("/api/client-request-attachments/{$id}")
            ->assertNotFound();
    });
});
