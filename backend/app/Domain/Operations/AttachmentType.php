<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * What may be attached to a client request, as an allow-list.
 *
 * **An allow-list, decided by the file's own bytes.** Not by its extension, which the uploader chose, and not by
 * the `Content-Type` header, which the uploader also chose. A product that trusted either would accept a file
 * called `brief.png` that is an HTML document, serve it from its own origin, and hand whoever uploaded it a
 * script running as the person who opened it.
 *
 * **SVG is deliberately absent**, and it is the one omission worth explaining. An SVG is a document: it can carry
 * `<script>`, and every stored-XSS in a file-upload feature is an SVG served inline. Nothing here needs vector
 * art badly enough to take that, and "export it as a PNG" is an answer a client can act on.
 *
 * **PDF is in, and is the reason downloads are never inline.** A PDF can carry JavaScript, which a browser's own
 * viewer may run; served as an attachment with `Content-Disposition: attachment` it is a file on somebody's disk
 * rather than a document in this product's origin.
 */
enum AttachmentType: string
{
    case Png = 'image/png';

    case Jpeg = 'image/jpeg';

    case Webp = 'image/webp';

    case Gif = 'image/gif';

    case Pdf = 'application/pdf';

    case Csv = 'text/csv';

    case Xlsx = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    case Docx = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    case PlainText = 'text/plain';

    /**
     * The type a file actually is, from its own bytes, or null when it is not one this product accepts.
     *
     * The caller passes what a sniffer reported — `finfo`, which reads the magic bytes — so an extension and a
     * header never reach this decision at all.
     */
    public static function fromSniffedType(string $sniffed): ?self
    {
        $normalised = strtolower(trim(explode(';', $sniffed)[0]));

        /*
         * Office files sniff as a zip, because that is what they are. The distinction between a .xlsx and an
         * arbitrary zip cannot be made from the magic bytes alone, so a zip is **refused**: accepting one on the
         * strength of its extension is exactly the trust this class exists to avoid.
         */
        return self::tryFrom($normalised);
    }

    /** The extension to give the stored file, which is never the one the uploader sent. */
    public function extension(): string
    {
        return match ($this) {
            self::Png => 'png',
            self::Jpeg => 'jpg',
            self::Webp => 'webp',
            self::Gif => 'gif',
            self::Pdf => 'pdf',
            self::Csv => 'csv',
            self::Xlsx => 'xlsx',
            self::Docx => 'docx',
            self::PlainText => 'txt',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Png, self::Jpeg, self::Webp, self::Gif => 'Image',
            self::Pdf => 'PDF',
            self::Csv => 'CSV',
            self::Xlsx => 'Spreadsheet',
            self::Docx => 'Document',
            self::PlainText => 'Text',
        };
    }

    /** Whether a thumbnail could be shown. Not whether it will be served inline — nothing is. */
    public function isImage(): bool
    {
        return $this === self::Png || $this === self::Jpeg || $this === self::Webp || $this === self::Gif;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
