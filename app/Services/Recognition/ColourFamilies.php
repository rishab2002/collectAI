<?php

namespace App\Services\Recognition;

/**
 * Catalog colour text → colour family ("Metalflake Dark Red" → red).
 *
 * The vocabulary is recognizer/data/colour_families.json, the same file the
 * recognizer uses to name the colour of the pixels in a photo. One file for
 * both sides is the point: a colour only counts as evidence if the photo and
 * the catalog land on the same word.
 */
final class ColourFamilies
{
    /** @var array<string, string>|null word => family, longest words first */
    private ?array $words = null;

    public static function path(): string
    {
        return base_path('recognizer/data/colour_families.json');
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_column($this->data()['families'], 'name');
    }

    /**
     * The family of a catalog colour, or null when it names none of them.
     *
     * The earliest family word in the text wins, because the body colour comes
     * first ("Red w/ White Stripes" is a red car); on a tie the longer word
     * wins ("hot pink" over "pink").
     */
    public function familyOf(?string $colour): ?string
    {
        $text = ' '.preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower((string) $colour)).' ';

        if (trim($text) === '') {
            return null;
        }

        $best = null;

        foreach ($this->words() as $word => $family) {
            $at = strpos($text, ' '.$word.' ');

            if ($at !== false && ($best === null || $at < $best[0])) {
                $best = [$at, $family];
            }
        }

        return $best[1] ?? null;
    }

    /**
     * @return array<string, string>
     */
    private function words(): array
    {
        if ($this->words === null) {
            $this->words = [];

            foreach ($this->data()['families'] as $family) {
                foreach ($family['words'] as $word) {
                    $this->words[$word] = $family['name'];
                }
            }

            uksort($this->words, fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        }

        return $this->words;
    }

    /**
     * @return array<string, mixed>
     */
    private function data(): array
    {
        static $data = null;

        return $data ??= json_decode(file_get_contents(self::path()), true, flags: JSON_THROW_ON_ERROR);
    }
}
