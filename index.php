<?php

use Kirby\Cms\App as Kirby;
use Kirby\Toolkit\Collection;
use JanHerman\Search\Search;

@include_once __DIR__ . '/vendor/autoload.php';

Kirby::plugin('jan-herman/search', [
    'options' => [
        'fuse' => [],
    ],
    'components' => [
        'search' => function (Kirby $kirby, Collection $collection, string|null $query = null, string|array $params = []): Collection {
            $fuzzy = $params['fuzzy'] ?? false;
            $search = new Search($kirby, $collection, $query, $params);

            if ($fuzzy) {
                return $search->fuzzySearch();
            } else {
                return $search->search();
            }
        },
    ],
]);
