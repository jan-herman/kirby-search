<?php

use Kirby\Cms\App as Kirby;
use JanHerman\Search\Search;

@include_once __DIR__ . '/vendor/autoload.php';

Kirby::plugin('jan-herman/search', [
    'components' => [
        'search' => [Search::class, 'search'],
    ],
]);
