<?php

declare(strict_types=1);

namespace App\Domain\Operations\Exception;

use App\Domain\Shared\Exception\DomainException;

/**
 * Why a file was refused.
 *
 * Each refusal says what to do instead, because the alternative is a client re-uploading the same file three
 * times. "Unsupported file type" is not an instruction; "we accept PNG, JPEG, PDF…" is.
 */
final class InvalidAttachment extends DomainException
{
    private function __construct(string $message, private readonly string $reasonCode)
    {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->reasonCode;
    }

    public static function empty(): self
    {
        return new self('That file is empty. Nothing was uploaded.', 'attachment_empty');
    }

    public static function tooLarge(int $bytes, int $limitBytes): self
    {
        return new self(
            sprintf(
                'That file is %s and the limit is %s. Send a smaller version, or a link to it.',
                self::readable($bytes),
                self::readable($limitBytes),
            ),
            'attachment_too_large',
        );
    }

    public static function typeNotAccepted(string $sniffed): self
    {
        /*
         * The **sniffed** type in the message, not the one the uploader claimed. A client who renamed a file to
         * get past an extension check deserves to be told what it actually is, and a client who did not deserves
         * to know their export is not the format they think it is.
         */
        return new self(
            sprintf(
                'That file is %s, which we cannot accept. We take images (PNG, JPEG, WebP, GIF), PDFs, CSVs, '
                .'spreadsheets, Word documents and plain text. An SVG has to be exported as a PNG first.',
                $sniffed === '' ? 'of an unknown type' : $sniffed,
            ),
            'attachment_type_not_accepted',
        );
    }

    public static function tooMany(int $limit): self
    {
        return new self(
            sprintf('This request already has %d files, which is as many as it can hold.', $limit),
            'attachment_too_many',
        );
    }

    public static function requestSettled(): self
    {
        return new self(
            'This request is closed. Raise a new one rather than adding to a finished thread.',
            'attachment_request_settled',
        );
    }

    private static function readable(int $bytes): string
    {
        return $bytes >= 1_048_576
            ? sprintf('%.1f MB', $bytes / 1_048_576)
            : sprintf('%d KB', (int) ceil($bytes / 1024));
    }
}
