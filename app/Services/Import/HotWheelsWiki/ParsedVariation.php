<?php

namespace App\Services\Import\HotWheelsWiki;

/**
 * One row of a casting page's ==Versions== table: a single purchasable unit.
 *
 * Every field is the source's own wording, untouched. In particular `color`
 * keeps compound values like "Metalflake Yellow" whole rather than splitting a
 * finish from a colour — the wiki states one string, and deciding that
 * "Metalflake" is a finish is an inference, which the settled rules route
 * through submission_proposals rather than the importer.
 */
readonly class ParsedVariation
{
    public function __construct(
        public ?string $color = null,
        public ?string $tampo = null,
        public ?string $baseColor = null,
        public ?string $windowTint = null,
        public ?string $interiorColor = null,
        public ?string $rimType = null,
        public ?string $toyNumber = null,
    ) {}

    /**
     * The attribute set the variations uniqueness index covers, so two rows that
     * the database would treat as one can be spotted before insert.
     *
     * @return array<string, string|null>
     */
    public function attributes(): array
    {
        return [
            // Never set by the importer: the wiki has no finish column, and
            // deriving one from the colour string would be inference.
            'finish' => null,
            'color' => $this->color,
            'rim_type' => $this->rimType,
            'tampo' => $this->tampo,
            'interior_color' => $this->interiorColor,
            'window_tint' => $this->windowTint,
            'base_color' => $this->baseColor,
            'toy_number' => $this->toyNumber,
        ];
    }

    public function signature(): string
    {
        return implode('|', array_map(strval(...), $this->attributes()));
    }
}
