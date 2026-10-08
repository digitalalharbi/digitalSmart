<?php

declare(strict_types=1);

use App\Domain\Operations\AttachmentType;
use App\Domain\Operations\MessageAudience;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Files on a client request.
 *
 * The table carries the **audience** for the same reason a message does, and it is the whole security story of
 * this feature: an agency member's internal attachment — a competitor's rate card, a screenshot of an argument
 * about the client — must never reach the client, and the exclusion happens in SQL on the same column, by the
 * same rule, as the messages beside it. Two rules would eventually disagree.
 *
 * `stored_name` is generated and `original_name` is kept only to show a reader what they sent. A path built from
 * an uploader's filename is a path traversal and a content type taken from an uploader's extension is a stored
 * XSS, so neither is ever used for anything but display.
 *
 * `sniffed_type` is what the bytes said, not what the request claimed. It is the column the download sets its
 * `Content-Type` from — and it is constrained to the allow-list, so a type this product does not serve cannot be
 * stored in the first place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_request_attachments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('client_request_id')->constrained('client_requests')->cascadeOnDelete();

            /** No default, exactly as on a message. An audience somebody did not choose is an audience nobody owns. */
            $table->string('audience');

            /** What the uploader called it. For display only — never for a path, never for a type. */
            $table->string('original_name');

            /** Where it actually lives, relative to the disk. Generated here, never supplied. */
            $table->string('stored_name')->unique();

            /** What the bytes said. The download's `Content-Type` comes from this column and nowhere else. */
            $table->string('sniffed_type');

            $table->unsignedBigInteger('byte_size');

            /*
             * SHA-256 of the contents. Not deduplication: it is how somebody can tell whether the file served a
             * year from now is the file that was uploaded, which an audit of "what did the client actually send"
             * eventually needs.
             */
            $table->string('checksum', 64);

            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            /*
             * Whether the uploader was acting as the client. Recorded rather than derived from their role, like
             * a message's side of the conversation: a member's role can change and a file's provenance cannot.
             */
            $table->boolean('is_from_client')->default(false);

            $table->timestamps();

            $table->index(['workspace_id', 'client_request_id']);
            $table->index(['workspace_id', 'audience']);
        });

        DB::statement(sprintf(
            'ALTER TABLE client_request_attachments ADD CONSTRAINT client_request_attachments_audience_check '
            ."CHECK (audience IN ('%s'))",
            implode("','", MessageAudience::values()),
        ));

        /*
         * The allow-list, in the schema. A type the application refuses cannot be written by a migration, a
         * seeder or a console command either — which is the point of putting a domain list in a CHECK everywhere
         * else in this schema.
         */
        DB::statement(sprintf(
            'ALTER TABLE client_request_attachments ADD CONSTRAINT client_request_attachments_type_check '
            ."CHECK (sniffed_type IN ('%s'))",
            implode("','", AttachmentType::values()),
        ));

        /*
         * A file from the client is always visible to the client, enforced rather than trusted — the same rule the
         * messages table carries. An interface bug that filed a client's own upload as internal would hide their
         * own file from them.
         */
        DB::statement(
            'ALTER TABLE client_request_attachments ADD CONSTRAINT client_request_attachments_client_visible '
            ."CHECK (is_from_client = false OR audience = '".MessageAudience::Client->value."')"
        );

        /* An empty file is not an attachment, and a zero here would make every size figure a lie. */
        DB::statement(
            'ALTER TABLE client_request_attachments ADD CONSTRAINT client_request_attachments_size_positive '
            .'CHECK (byte_size > 0)'
        );

        DB::statement(
            'ALTER TABLE client_request_attachments ADD CONSTRAINT client_request_attachments_name_present '
            ."CHECK (btrim(original_name) <> '')"
        );
    }

    public function down(): void
    {
        /*
         * The rows go; the files on the disk do not. A migration that deleted a client's uploads on a rollback
         * would make a reversible schema change an irreversible data loss, and the files are addressed by a
         * column that no longer exists rather than left reachable.
         */
        Schema::dropIfExists('client_request_attachments');
    }
};
