<?php

namespace JanHerman\Search;

use Kirby\Cms\App as Kirby;
use Kirby\Cms\Page;
use Kirby\Cms\User;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Collection;
use Kirby\Toolkit\Str;
use Fuse\Fuse;

class Search
{
    protected Kirby $kirby;
    protected Collection $collection;
    protected string|null $query;
    protected string|array $params;
    protected array $options;

    /**
     * Constructor
     *
     * @param Kirby $kirby Kirby CMS instance
     * @param Collection $collection Collection to search
     * @param string|null $query Search query
     * @param string|array $params Search parameters (field list or options)
     */
    public function __construct (Kirby $kirby, Collection $collection, string|null $query = null, string|array $params = [])
    {
        $this->kirby      = $kirby;
        $this->collection = clone $collection;
        $this->query      = trim($query ?? '');
        $this->params     = $params;

        if (is_string($this->params) === true) {
            $this->params = ['fields' => Str::split($this->params, '|')];
        }

        $default_options = [
            'fields'    => [],
            'minlength' => 2,
            'score'     => [],
            'words'     => false,
            'stopwords' => [],
        ];

        $this->options = array_merge($default_options, $this->params);
    }

    /**
     * Sets default scoring weights for Page objects
     *
     * @return void
     */
    private function setDefaultScoreForPages (): void
    {
        $this->options['score'] = array_merge(
            ['id' => 64, 'title' => 64],
            $this->options['score']
        );
    }

    /**
     * Returns normalized and lowercased field names to search
     *
     * @return array
     */
    public function keys(): array
    {
        return array_map('strtolower', $this->options['fields']);
    }

    /**
     * Transliterates a UTF-8 string
     *
     * @param string $string
     * @return string
     */
    private function transliterate (string $string): string
    {
        if (class_exists('\Normalizer')) {
            $string = \Normalizer::normalize($string, \Normalizer::FORM_D);
            return preg_replace('/\p{Mn}/u', '', $string); // Remove combining marks
        }

        $find = array(
            'á', 'č', 'ď', 'é', 'ě', 'í', 'ň', 'ó', 'ř', 'š', 'ť', 'ú', 'ů', 'ý', 'ž',
            'Á', 'Č', 'Ď', 'É', 'Ě', 'Í', 'Ň', 'Ó', 'Ř', 'Š', 'Ť', 'Ú', 'Ů', 'Ý', 'Ž'
        );

        $replace = array(
            'a', 'c', 'd', 'e', 'e', 'i', 'n', 'o', 'r', 's', 't', 'u', 'u', 'y', 'z',
            'A', 'C', 'D', 'E', 'E', 'I', 'N', 'O', 'R', 'S', 'T', 'U', 'U', 'Y', 'Z'
        );

        return str_replace($find, $replace, $string);
    }

    /**
     * Performs exact/keyword-based search with scoring
     *
     * @return Collection
     */
    public function search (): Collection
    {
        $query = $this->transliterate($this->query);

		// empty or too short search query
		if (Str::length($query) < $this->options['minlength']) {
			return $this->collection->limit(0);
		}

		$words = preg_replace('/(\s)/u', ',', $query);
		$words = Str::split($words, ',', $this->options['minlength']);

		if (empty($this->options['stopwords']) === false) {
			$words = array_diff($words, $this->options['stopwords']);
		}

		// returns an empty collection if there is no search word
		if (empty($words) === true) {
			return $this->collection->limit(0);
		}

		$words = A::map(
			$words,
			fn ($value) => Str::wrap(preg_quote($value), $this->options['words'] ? '\b' : '')
		);

		$exact = preg_quote($query);

		if ($this->options['words']) {
			$exact = '(\b' . $exact . '\b)';
		}

		$query   = Str::lower($query);
		$preg    = '!(' . implode('|', $words) . ')!iu';
		$scores  = [];

		$results = $this->collection->filter(function ($item) use ($query, $exact, $preg, &$scores) {
			$data   = $item->content()->toArray();
			$keys   = array_keys($data);
			$keys[] = 'id';

			if ($item instanceof User) {
				$keys[] = 'name';
				$keys[] = 'email';
				$keys[] = 'role';
			} elseif ($item instanceof Page) {
				// apply the default score for pages
				$this->setDefaultScoreForPages();
			}

			if (empty($this->options['fields']) === false) {
				$fields = $this->keys();
				$keys   = array_intersect($keys, $fields);
			}

			$scoring = [
				'hits'  => 0,
				'score' => 0
			];

			foreach ($keys as $key) {
				$score = $this->options['score'][$key] ?? 1;
				$value = $data[$key] ?? (string) $item->$key();
                $value = $this->transliterate($value);

				$lowerValue = Str::lower($value);

				// check for exact matches
				if ($query == $lowerValue) {
					$scoring['score'] += 16 * $score;
					$scoring['hits']  += 1;

					// check for exact beginning matches
				} elseif (
					$this->options['words'] === false &&
					Str::startsWith($lowerValue, $query) === true
				) {
					$scoring['score'] += 8 * $score;
					$scoring['hits']  += 1;

					// check for exact query matches
				} elseif ($matches = preg_match_all('!' . $exact . '!ui', $value, $r)) {
					$scoring['score'] += 2 * $score;
					$scoring['hits']  += $matches;
				}

				// check for any match
				if ($matches = preg_match_all($preg, $value, $r)) {
					$scoring['score'] += $matches * $score;
					$scoring['hits']  += $matches;
				}
			}

			$scores[$item->id()] = $scoring;

			return $scoring['hits'] > 0;
		});

		return $results->sort(
			fn ($item) => $scores[$item->id()]['score'],
			'desc'
		);
    }

    /**
     * Converts a Kirby collection to an array of associative arrays
     * formatted for use with Fuse.js
     *
     * @param Collection $collection
     * @return array
     */
    private function collectionToFuseList (Collection $collection): array
    {
        $items = $collection->toArray(function ($item) {
            $data   = $item->content()->toArray();
            $keys   = array_keys($data);
            $keys[] = 'id';

            if ($item instanceof User) {
                $keys[] = 'name';
                $keys[] = 'email';
                $keys[] = 'role';
            } elseif ($item instanceof Page) {
                // apply the default score for pages
				$this->setDefaultScoreForPages();
            }

            if (empty($this->options['fields']) === false) {
				$fields = $this->keys();
				$keys   = array_intersect($keys, $fields);
                $keys[] = 'id';
			}

            $output = [];
            foreach ($keys as $key) {
                $output[$key] = $data[$key] ?? (string) $item->$key();
            }

            return $output;
        });

        return array_values($items);
    }

    /**
     * Performs fuzzy search using the Fuse.js port
     *
     * @return Collection
     */
    public function fuzzySearch (): Collection
    {
        $query = $this->query;

        // remove stopwords (use preg_replace to remove only whole words)
        if (empty($this->options['stopwords']) === false) {
            $stopwords_pattern = '/\b(' . implode('|', array_map('preg_quote', $this->options['stopwords'])) . ')\b/i';
            $query = trim(preg_replace($stopwords_pattern, '', $query));
        }

        // empty or too short search query
        if (Str::length($query) < $this->options['minlength']) {
            return $this->collection->limit(0);
        }

        // prepare collection for Fuse
        $items = $this->collectionToFuseList($this->collection);

        // prepare keys for Fuse
        $keys = $this->keys();

        if (empty($keys) === true) {
            $keys = array_unique(array_keys(array_merge(...$items)));
        }

        $keys = A::map(
            $keys,
            fn ($key) => [
                'name'   => $key,
                'weight' => $this->options['score'][$key] ?? 1,
            ]
        );

        // fuse options
        $default_fuse_options = [
            'includeScore'       => true, // temp
            'minMatchCharLength' => 2,
        ];
        $custom_fuse_options = option('jan-herman.fuzzy-search.fuse', []);
        $fuse_options = array_merge($default_fuse_options, $custom_fuse_options, [
            'keys' => $keys,
        ]);

        // set the threshold to 0.0 (exact match) if we are searching for whole words
        if ($this->options['words'] === true) {
            $fuse_options['threshold'] = 0.0;
        }

        // search
        $fuse = new Fuse($items, $fuse_options);
        $results = $fuse->search($query);

        if (empty($results)) {
            return $this->collection->limit(0);
        }

        // filter collection by the search results
        $results_ids = A::map($results, fn ($result) => $result['item']['id']);

        return $this->collection->find($results_ids);
    }
}
