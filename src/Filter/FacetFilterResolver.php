<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Filter;

/**
 * Neutral facet ID resolution: AND across facet sets, optional search constraint.
 *
 * Host adapters supply per-facet ID sets (OR within a facet is the adapter's job).
 * Mirrors the intersection contract used by Sieve's FilterService::resolve() without
 * facet models, index keys, or option names.
 *
 * @see docs/SIEVE-KIT-ADAPTER.md in plogins monorepo — P2
 */
final class FacetFilterResolver
{
    /**
     * Intersect facet match sets. Empty $sets => unrestricted (null).
     *
     * @param array<int, array<int, int>> $sets Non-empty facet match sets.
     * @return array<int, int>|null Matching IDs, or null when no facet constrained the result.
     */
    public function intersectSets(array $sets): ?array
    {
        if ([] === $sets) {
            return null;
        }

        $result = array_shift($sets);

        if (null === $result) {
            return [];
        }

        foreach ($sets as $set) {
            $result = array_values(array_intersect($result, $set));

            if ([] === $result) {
                return [];
            }
        }

        return $result;
    }

    /**
     * Apply a pre-resolved search ID list to a facet result.
     *
     * @param array<int, int>|null $facetIds From intersectSets(); null = unrestricted.
     * @param array<int, int>|null $searchIds null = search inactive; [] = matched nothing.
     * @return array<int, int>|null
     */
    public function applySearchConstraint(?array $facetIds, ?array $searchIds): ?array
    {
        if (null === $searchIds) {
            return $facetIds;
        }

        if (null === $facetIds) {
            return $searchIds;
        }

        return array_values(array_intersect($facetIds, $searchIds));
    }

    /**
     * @param array<int, array<int, int>> $sets
     * @param array<int, int>|null $searchIds
     * @return array<int, int>|null
     */
    public function resolve(array $sets, ?array $searchIds = null): ?array
    {
        return $this->applySearchConstraint($this->intersectSets($sets), $searchIds);
    }
}
