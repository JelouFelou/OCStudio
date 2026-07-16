<?php

class World {
    private $id;
    private $public_id;
    private $name;
    private $description;
    private $image;
    private $id_user;
    private $parent_id;
    private $id_status;
    private $is_hidden;
    private $icon_color;
    private $background_effect;
    private $effect_symbols;
    private $effect_intensity;
    private $effect_size;
    private $effect_layer;

    public function __construct(string $name, string $description, string $image, int $id_user, ?int $id = null, ?int $parent_id = null, ?int $id_status = null, bool $is_hidden = false, ?string $public_id = null, ?string $icon_color = null, string $background_effect = 'none', string $effect_symbols = '', string $effect_intensity = 'medium', string $effect_size = 'medium', string $effect_layer = 'under') {
        $this->name = $name;
        $this->description = $description;
        $this->image = $image;
        $this->id_user = $id_user;
        $this->id = $id;
        $this->public_id = $public_id;
        $this->parent_id = $parent_id;
        $this->id_status = $id_status;
        $this->is_hidden = $is_hidden;
        $this->icon_color = $icon_color;
        $this->background_effect = $background_effect;
        $this->effect_symbols = $effect_symbols;
        $this->effect_intensity = $effect_intensity;
        $this->effect_size = $effect_size;
        $this->effect_layer = $effect_layer;
    }

    public function getId(): ?int { return $this->id; }
    public function getPublicId(): string { return $this->public_id ?: (string)$this->id; }
    public function getName(): string { return $this->name; }
    public function getDescription(): string { return $this->description; }
    public function getImage(): string { return $this->image; }
    public function getIdUser(): int { return $this->id_user; }
    public function getParentId(): ?int { return $this->parent_id; }
    public function getIdStatus(): ?int { return $this->id_status; }
    public function isHidden(): bool { return $this->is_hidden; }
    public function getIconColor(): string { return $this->icon_color ?: '#7B61FF'; }
    public function getBackgroundEffect(): string { return $this->background_effect ?: 'none'; }
    public function getEffectSymbols(): string { return $this->effect_symbols ?: ''; }
    public function getEffectIntensity(): string { return $this->effect_intensity ?: 'medium'; }
    public function getEffectSize(): string { return $this->effect_size ?: 'medium'; }
    public function getEffectLayer(): string { return $this->effect_layer ?: 'under'; }
}
