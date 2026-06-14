<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Filter;

/**
 * Namespace-neutral facet filter orchestration (powers Sieve and future consumers).
 *
 * A single run() drives SSR and AJAX so fragment markup stays identical. All
 * facet config, index access, rendering and search resolution stay in the host
 * via constructor-injected closures — no text domains, option keys or table names.
 *
 * @see docs/SIEVE-KIT-ADAPTER.md in plogins monorepo — P2
 */
final class FacetFilterEngine
{
    /**
     * @param \Closure $resolveObjectIds Request in, matching object IDs or null out.
     * @param \Closure $renderFacets
     * @param \Closure $renderToolbar
     * @param \Closure $renderResults
     * @param \Closure $renderPagination
     * @param \Closure(): int $perPage
     * @param \Closure(): int $columns
     */
    public function __construct(
        private readonly \Closure $resolveObjectIds,
        private readonly \Closure $renderFacets,
        private readonly \Closure $renderToolbar,
        private readonly \Closure $renderResults,
        private readonly \Closure $renderPagination,
        private readonly \Closure $perPage,
        private readonly \Closure $columns,
    ) {
    }

    /**
     * @param array{filters: array<string, string>, orderby: string, paged: int, search: string} $request
     * @return array{facets_html: string, toolbar_html: string, results_html: string, pagination_html: string, found: int, count_text: string}
     */
    public function run(array $request): array
    {
        $objectIds = ($this->resolveObjectIds)($request);
        $results = ($this->renderResults)(
            $objectIds,
            $request['orderby'],
            max(1, $request['paged']),
            max(1, ($this->perPage)()),
        );

        $countText = $results['count_text'];

        return [
            'facets_html' => ($this->renderFacets)($request, $objectIds),
            'toolbar_html' => ($this->renderToolbar)($request, $objectIds, $countText),
            'results_html' => $results['html'],
            'pagination_html' => ($this->renderPagination)($request['paged'], $results['max_pages']),
            'found' => $results['found'],
            'count_text' => $countText,
        ];
    }

    /**
     * @param array{filters: array<string, string>, orderby: string, paged: int, search: string} $request
     * @param \Closure $wrapContainer Fragments + column count in, container HTML out.
     */
    public function container(array $request, \Closure $wrapContainer): string
    {
        return $wrapContainer($this->run($request), max(1, ($this->columns)()));
    }
}
