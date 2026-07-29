<?php

namespace App\Services\Audit\Security;

/**
 * A deterministic before/after/added/removed diff over a set of NAMES — role
 * names, or permission names (OMS Task 9B.4).
 *
 * Every array this produces is de-duplicated, sorted with a plain byte-wise
 * sort() and re-indexed with array_values(), for two reasons:
 *
 *  1. STABILITY. `syncRoles(['Admin','Accountant'])` and
 *     `syncRoles(['Accountant','Admin'])` are the same logical action and must
 *     produce byte-identical audit payloads, so a diff between two audit rows
 *     reflects a real permission change and never mere submission order.
 *     Spatie's own pivot reads (`$role->permissions()->pluck('name')`) come
 *     back in whatever order the database returns them, which is not a
 *     guarantee of anything.
 *  2. COMPARABILITY. `isEmpty()` is what decides whether an update writes an
 *     event at all, so "unchanged" has to mean set equality, not array
 *     equality.
 *
 * Sorting is intentionally NOT locale/collation-aware: role and permission
 * names in this application are stable ASCII identifiers (`Super Admin`,
 * `users.update`), and a locale-sensitive sort would make the stored payload
 * depend on the server's locale.
 */
final class SecurityNameDiff
{
    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @param  list<string>  $added
     * @param  list<string>  $removed
     */
    private function __construct(
        public readonly array $before,
        public readonly array $after,
        public readonly array $added,
        public readonly array $removed,
    ) {}

    /**
     * @param  array<int, string>  $before
     * @param  array<int, string>  $after
     */
    public static function between(array $before, array $after): self
    {
        $normalizedBefore = self::normalize($before);
        $normalizedAfter = self::normalize($after);

        return new self(
            before: $normalizedBefore,
            after: $normalizedAfter,
            added: self::normalize(array_diff($normalizedAfter, $normalizedBefore)),
            removed: self::normalize(array_diff($normalizedBefore, $normalizedAfter)),
        );
    }

    public function isEmpty(): bool
    {
        return $this->added === [] && $this->removed === [];
    }

    /**
     * The canonical, already-sorted form of one name list — used for the
     * "full snapshot" payloads (user created/deleted/restored, role created/
     * deleted) where there is no before/after pair to diff.
     *
     * @param  array<int, string>  $names
     * @return list<string>
     */
    public static function normalize(array $names): array
    {
        $clean = array_values(array_unique(array_filter(
            array_map(static fn (mixed $name): string => (string) $name, $names),
            static fn (string $name): bool => $name !== '',
        )));

        sort($clean);

        return $clean;
    }
}
