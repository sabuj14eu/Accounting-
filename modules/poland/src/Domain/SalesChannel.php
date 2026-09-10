<?php

declare(strict_types=1);

namespace Poland\Domain;

/**
 * Where a month's takings came from.
 *
 * A closed list rather than free text: per-channel figures are compared month
 * to month and feed the JPK document type, and a channel somebody spells
 * differently once quietly stops being compared. Adding a channel is a code
 * change with a reason.
 *
 * Kept as string constants (not an enum) so SalesLine stays constructible from
 * stored rows and CLI input without a cast at every call site.
 */
final class SalesChannel
{
    /** The fiscal cash register's monthly report — the shop counter. */
    public const SHOP_REGISTER = 'shop_register';

    /** Orders delivered through Glovo, recorded from the platform settlement. */
    public const GLOVO = 'glovo';

    /** Anything else the shop invoiced itself (an outgoing KSeF invoice). */
    public const OTHER = 'other';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::SHOP_REGISTER, self::GLOVO, self::OTHER];
    }

    public static function isKnown(string $channel): bool
    {
        return in_array($channel, self::all(), true);
    }

    public static function label(string $channel): string
    {
        return match ($channel) {
            self::SHOP_REGISTER => 'Sklep — kasa fiskalna',
            self::GLOVO => 'Glovo',
            self::OTHER => 'Inne',
            default => $channel,
        };
    }

    private function __construct()
    {
    }
}
