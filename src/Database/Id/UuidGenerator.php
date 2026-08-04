<?php
declare(strict_types=1);

namespace Crustum\Mongo\Database\Id;

use Random\RandomException;

/**
 * UUID v7 identifier generator.
 *
 * Generates time-ordered UUIDs (RFC 9562, version 7), which sort by creation
 * time and are BSON-ordering-friendly — the migration-guide convention for
 * sequential-looking ids. Implemented natively (no external uuid dependency).
 *
 * @see mongodb-odm Id/SymfonyUuidGenerator.php
 */
class UuidGenerator implements IdGeneratorInterface
{
    /**
     * @inheritDoc
     */
    public function generate(array|object|null $document = null): string
    {
        return self::uuidV7();
    }

    /**
     * Returns a UUID v7 string.
     *
     * The 48-bit millisecond timestamp occupies the high bits; the version
     * (7) and variant (10) bits are set in the middle bytes, the rest is
     * randomness.
     *
     * @return string The UUID in canonical `xxxxxxxx-xxxx-7xxx-axxx-xxxxxxxxxxxx` form.
     * @throws \Random\RandomException When a secure random source is unavailable.
     */
    protected static function uuidV7(): string
    {
        try {
            $bytes = random_bytes(16);
        } catch (RandomException $randomException) {
            throw new RandomException('Unable to generate a UUID v7.', 0, $randomException);
        }

        $unixTimeMs = (int)(microtime(true) * 1000);
        $bytes[0] = chr(($unixTimeMs >> 40) & 0xFF);
        $bytes[1] = chr(($unixTimeMs >> 32) & 0xFF);
        $bytes[2] = chr(($unixTimeMs >> 24) & 0xFF);
        $bytes[3] = chr(($unixTimeMs >> 16) & 0xFF);
        $bytes[4] = chr(($unixTimeMs >> 8) & 0xFF);
        $bytes[5] = chr($unixTimeMs & 0xFF);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x70);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );
    }
}
