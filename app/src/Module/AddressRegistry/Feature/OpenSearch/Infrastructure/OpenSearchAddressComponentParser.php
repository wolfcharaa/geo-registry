<?php

declare(strict_types=1);

namespace App\Module\AddressRegistry\Feature\OpenSearch\Infrastructure;

use function array_reverse;
use function array_values;
use function implode;
use function is_scalar;
use function mb_strtolower;
use function preg_match;

use const PREG_OFFSET_CAPTURE;

use function preg_replace;
use function preg_split;
use function str_replace;
use function strtr;
use function substr;
use function trim;

final class OpenSearchAddressComponentParser
{
    /**
     * @param list<mixed> $values
     * @return array{street_key: list<string>, house_key: list<string>, building_key: list<string>, structure_key: list<string>}
     */
    public function componentSets(array $values): array
    {
        $sets = [
            'street_key' => [],
            'house_key' => [],
            'building_key' => [],
            'structure_key' => [],
        ];

        foreach ($values as $value) {
            if (!is_scalar($value)) {
                continue;
            }

            $components = $this->components((string) $value);
            foreach ($sets as $field => $_) {
                $component = $components[$field];
                if ($component !== null) {
                    $sets[$field][$component] = $component;
                }
            }
        }

        return [
            'street_key' => array_values($sets['street_key']),
            'house_key' => array_values($sets['house_key']),
            'building_key' => array_values($sets['building_key']),
            'structure_key' => array_values($sets['structure_key']),
        ];
    }

    /**
     * @return array{street_key: ?string, house_key: ?string, building_key: ?string, structure_key: ?string}
     */
    public function components(string $value): array
    {
        $value = $this->normalizeText($value);

        return [
            'street_key' => $this->streetKey($value),
            'house_key' => $this->markerKey($value, '(?:д|дом)'),
            'building_key' => $this->markerKey($value, '(?:к|корп|корпус)'),
            'structure_key' => $this->markerKey($value, '(?:стр|строение|соор|сооружение)'),
        ];
    }

    public function markerValueKey(string $value): ?string
    {
        $value = mb_strtolower(strtr($value, ['ё' => 'е']), 'UTF-8');
        $value = str_replace('-', '/', $value);
        $value = (string) preg_replace('/\s+/u', '', $value);
        $value = (string) preg_replace('/[^\p{L}\d\/]+/u', '', $value);

        return $value !== '' ? $value : null;
    }

    private function normalizeText(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    private function streetKey(string $value): ?string
    {
        if (preg_match('/(?:^|[,\s])(?:д|дом)\.?\s*\d+(?:[\/-]\d+)?(?:[а-яa-z])?/iu', $value, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return $this->trailingStreetKey(trim(substr($value, 0, $match[0][1])));
    }

    private function trailingStreetKey(string $value): ?string
    {
        $segments = preg_split('/,+/u', $value);
        if ($segments === false) {
            return null;
        }

        foreach (array_reverse($segments) as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            $key = $this->streetSegmentKey($segment);
            if ($key !== null) {
                return $key;
            }
        }

        return null;
    }

    private function markerKey(string $value, string $marker): ?string
    {
        if (preg_match('/(?:^|[,\s])' . $marker . '\.?\s*(\d+(?:[\/-]\d+)?(?:[а-яa-z])?)/iu', $value, $match) !== 1) {
            return null;
        }

        return $this->markerValueKey($match[1] ?? '');
    }

    private function streetSegmentKey(string $segment): ?string
    {
        $value = mb_strtolower(strtr($segment, ['ё' => 'е']), 'UTF-8');
        $value = str_replace(['пр-д', 'пр д', 'б-р'], ['проезд', 'проезд', 'бульвар'], $value);
        $value = (string) preg_replace('/\b(\d+)[\s-]*(?:я|й|ая|ый|ой|ое|е)\b/iu', '$1', $value);
        $value = (string) preg_replace('/[^\p{L}\d]+/u', ' ', $value);
        $words = preg_split('/\s+/u', trim($value));
        if ($words === false) {
            return null;
        }

        $skip = [
            'рф' => true,
            'российская' => true,
            'федерация' => true,
            'г' => true,
            'город' => true,
            'москва' => true,
            'внутригородская' => true,
            'территория' => true,
            'муниципальный' => true,
            'округ' => true,
            'район' => true,
            'ул' => true,
            'улица' => true,
            'проспект' => true,
            'пр' => true,
            'проезд' => true,
            'прд' => true,
            'переулок' => true,
            'пер' => true,
            'площадь' => true,
            'пл' => true,
            'шоссе' => true,
            'ш' => true,
            'бульвар' => true,
            'бр' => true,
            'набережная' => true,
            'наб' => true,
            'аллея' => true,
            'линия' => true,
            'тупик' => true,
            'вал' => true,
            'дом' => true,
            'д' => true,
        ];

        $tokens = [];
        foreach ($words as $word) {
            if ($word === '' || isset($skip[$word]) || preg_match('/^\p{L}$/u', $word) === 1) {
                continue;
            }

            $tokens[] = $word;
        }

        $key = implode('', $tokens);

        return $key !== '' ? $key : null;
    }
}
