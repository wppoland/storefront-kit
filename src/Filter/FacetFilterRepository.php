<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Filter;

/**
 * Host-owned facet index (table name and schema stay in the adapter).
 *
 * Row shape for writes: object_id, facet_slug (index key), value (string), value_num (float|null).
 *
 * @see docs/SIEVE-KIT-ADAPTER.md in plogins monorepo — P1 contract; Sieve IndexRepository is the first implementor.
 */
interface FacetFilterRepository
{
    /**
     * Object IDs matching any of the given values for one facet (OR within a facet).
     *
     * @param array<int, string> $values
     * @return array<int, int>
     */
    public function objectsForValues(string $facetSlug, array $values): array;

    /**
     * Object IDs whose numeric value for a facet falls within [min, max].
     *
     * @return array<int, int>
     */
    public function objectsForRange(string $facetSlug, ?float $min, ?float $max): array;

    /**
     * Replace all index rows for one object with a fresh set.
     *
     * @param array<int, array{facet_slug: string, value: string, value_num: float|null}> $rows
     */
    public function reindexObject(int $objectId, array $rows): void;

    public function deleteObject(int $objectId): void;
}
