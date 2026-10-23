<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Tracking\Contract\OpaqueTokens;

final readonly class RandomOpaqueTokens implements OpaqueTokens
{
    private const string ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

    public function make(int $length): string
    {
        $out = '';

        while (strlen($out) < $length) {
            /* Rejection sampling, so no character is likelier than another. */
            foreach (str_split(random_bytes($length * 2)) as $byte) {
                $n = ord($byte);

                if ($n < 248) {
                    $out .= self::ALPHABET[$n % 62];
                }

                if (strlen($out) === $length) {
                    break;
                }
            }
        }

        return $out;
    }
}
