<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\PartyRepository;

/**
 * Generates short, unambiguous join codes (no 0/O, 1/I/L) and display keys.
 */
class PartyCodeGenerator
{
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function __construct(private readonly PartyRepository $parties)
    {
    }

    public function generateCode(int $length = 5): string
    {
        for ($attempt = 0; $attempt < 50; ++$attempt) {
            $code = $this->random($length);
            if (!$this->parties->codeExists($code)) {
                return $code;
            }
        }

        // Extremely unlikely: fall back to a longer code.
        return $this->random($length + 2);
    }

    public function generateDisplayKey(): string
    {
        return bin2hex(random_bytes(12));
    }

    public function generateGuestToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    private function random(int $length): string
    {
        $max = \strlen(self::ALPHABET) - 1;
        $code = '';
        for ($i = 0; $i < $length; ++$i) {
            $code .= self::ALPHABET[random_int(0, $max)];
        }

        return $code;
    }
}
