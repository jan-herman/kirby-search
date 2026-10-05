<?php

namespace JanHerman\Search;

use Kirby\Cms\App as Kirby;
use Kirby\Cms\Collection;
use Kirby\Cms\Page;
use Kirby\Cms\User;
use Kirby\Toolkit\A;
use Kirby\Toolkit\Str;

class Search
{
	public static function search(
		Kirby $kirby,
		Collection $collection,
		string|null $query = null,
		string|array $params = []
	): Collection {
		if (is_string($params) === true) {
			$params = ['fields' => Str::split($params, '|')];
		}

		$collection = clone $collection;
		$query      = trim($query ?? '');
		$options    = [
			'fields'    => [],
			'minlength' => 2,
			'score'     => [],
			'words'     => false,
			...$params
		];
		$query = self::transliterate($query);

		// empty or too short search query
		if (Str::length($query) < $options['minlength']) {
			return $collection->limit(0);
		}

		$words = preg_replace('/(\s)/u', ',', $query);
		$words = Str::split($words, ',', $options['minlength']);

		if (empty($options['stopwords']) === false) {
			$words = array_diff($words, $options['stopwords']);
		}

		// returns an empty collection if there is no search word
		if (empty($words) === true) {
			return $collection->limit(0);
		}

		$words = A::map(
			$words,
			fn ($value) => Str::wrap(preg_quote($value), $options['words'] ? '\b' : '')
		);

		$exact = preg_quote($query);

		if ($options['words']) {
			$exact = '(\b' . $exact . '\b)';
		}

		$query   = Str::lower($query);
		$preg    = '!(' . implode('|', $words) . ')!iu';
		$scores  = [];

		$results = $collection->filter(function ($item) use ($query, $exact, $preg, $options, &$scores) {
			$data   = $item->content()->toArray();
			$keys   = array_keys($data);
			$keys[] = 'id';

			if ($item instanceof User) {
				$keys[] = 'name';
				$keys[] = 'email';
				$keys[] = 'role';
			} elseif ($item instanceof Page) {
				// apply the default score for pages
				$options['score'] = [
					'id'    => 64,
					'title' => 64,
					...$options['score']
				];
			}

			if (empty($options['fields']) === false) {
				$fields = array_map('strtolower', $options['fields']);
				$keys   = array_intersect($keys, $fields);
			}

			$scoring = [
				'hits'  => 0,
				'score' => 0
			];

			foreach ($keys as $key) {
				$score = $options['score'][$key] ?? 1;
				$value = $data[$key] ?? (string)$item->$key();
				$value = self::transliterate($value);

				$lowerValue = Str::lower($value);

				// check for exact matches
				if ($query == $lowerValue) {
					$scoring['score'] += 16 * $score;
					$scoring['hits']  += 1;

				// check for exact beginning matches
				} elseif (
					$options['words'] === false &&
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

	private static function transliterate(string $string): string
	{
		return strtr($string, [
			'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ã' => 'A', 'Ä' => 'A', 'Å' => 'A',
			'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a',
			'Ą' => 'A', 'ą' => 'a', 'Ć' => 'C', 'ć' => 'c',
			'Ā' => 'A', 'ā' => 'a', 'Ă' => 'A', 'ă' => 'a',
			'Æ' => 'AE', 'æ' => 'ae', 'Ç' => 'C', 'ç' => 'c', 'Č' => 'C', 'č' => 'c',
			'Ð' => 'D', 'ð' => 'd', 'Ď' => 'D', 'ď' => 'd', 'Đ' => 'D', 'đ' => 'd',
			'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Ě' => 'E',
			'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ě' => 'e',
			'Ę' => 'E', 'ę' => 'e', 'Ğ' => 'G', 'ğ' => 'g', 'İ' => 'I',
			'Ē' => 'E', 'ē' => 'e', 'Ė' => 'E', 'ė' => 'e', 'Ģ' => 'G', 'ģ' => 'g',
			'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I', 'ı' => 'i',
			'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'Ł' => 'L', 'ł' => 'l',
			'Ī' => 'I', 'ī' => 'i', 'Į' => 'I', 'į' => 'i', 'Ķ' => 'K', 'ķ' => 'k',
			'Ļ' => 'L', 'ļ' => 'l', 'Ľ' => 'L', 'ľ' => 'l',
			'Ñ' => 'N', 'ñ' => 'n', 'Ň' => 'N', 'ň' => 'n',
			'Ń' => 'N', 'ń' => 'n',
			'Ņ' => 'N', 'ņ' => 'n',
			'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Õ' => 'O', 'Ö' => 'O', 'Ø' => 'O',
			'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o',
			'Ő' => 'O', 'ő' => 'o', 'Ś' => 'S', 'ś' => 's', 'Ş' => 'S', 'ş' => 's',
			'Œ' => 'OE', 'œ' => 'oe', 'Ř' => 'R', 'ř' => 'r', 'Š' => 'S', 'š' => 's',
			'ẞ' => 'SS', 'ß' => 'ss', 'Ť' => 'T', 'ť' => 't', 'Þ' => 'TH', 'þ' => 'th',
			'Ș' => 'S', 'ș' => 's', 'Ț' => 'T', 'ț' => 't', 'Ţ' => 'T', 'ţ' => 't',
			'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ů' => 'U',
			'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ů' => 'u',
			'Ű' => 'U', 'ű' => 'u', 'Ź' => 'Z', 'ź' => 'z', 'Ż' => 'Z', 'ż' => 'z',
			'Ū' => 'U', 'ū' => 'u', 'Ų' => 'U', 'ų' => 'u',
			'Ý' => 'Y', 'Ÿ' => 'Y', 'ý' => 'y', 'ÿ' => 'y', 'Ž' => 'Z', 'ž' => 'z',
			'ﬁ' => 'fi', 'ﬂ' => 'fl', 'ﬃ' => 'ffi', 'ﬄ' => 'ffl',
			"\u{0300}" => '', "\u{0301}" => '', "\u{0302}" => '', "\u{0303}" => '',
			"\u{0304}" => '', "\u{0306}" => '', "\u{0307}" => '', "\u{0308}" => '',
			"\u{030A}" => '', "\u{030B}" => '', "\u{030C}" => '', "\u{031B}" => '',
			"\u{0326}" => '', "\u{0327}" => '', "\u{0328}" => '',
		]);
	}
}
