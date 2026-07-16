<?php

class Character {
    private $id;
    private $public_id;
    private $name;
    private $intro;
    private $description;
    private $image;
    private $id_user;
    private $id_template;
    private $id_world;
    private $id_status;
    private $image_display_mode;
    private $image_fit;
    private $image_focus_x;
    private $image_focus_y;
    private $image_zoom;
    private $is_hidden;
    private $is_main_character;
    private $is_pinned;

    public function __construct($name, $description, $image, $id_user, $id = null, $id_template = null, $id_world = null, $id_status = null, $image_display_mode = 'square', $image_fit = 'cover', $image_focus_x = 50, $image_focus_y = 50, $image_zoom = 1, $intro = '', $is_hidden = false, ?string $public_id = null, $is_main_character = false, $is_pinned = false) {
        $this->name = $name;
        $this->intro = $intro;
        $this->description = $description;
        $this->image = $image;
        $this->id_user = $id_user;
        $this->id = $id;
        $this->public_id = $public_id;
        $this->id_template = $id_template;
        $this->id_world = $id_world;
        $this->id_status = $id_status;
        $this->image_display_mode = $image_display_mode ?: 'square';
        $this->image_fit = $image_fit ?: 'cover';
        $this->image_focus_x = (int)$image_focus_x;
        $this->image_focus_y = (int)$image_focus_y;
        $this->image_zoom = (float)$image_zoom;
        $this->is_hidden = (bool)$is_hidden;
        $this->is_main_character = (bool)$is_main_character;
        $this->is_pinned = (bool)$is_pinned;
    }

    public function getName(): string { return $this->name; }
    public function getIntro(): string { return $this->intro; }
    public function getDescription(): string { return $this->description; }
    public function getImage(): string { return $this->image; }
    public function getId(): ?int { return $this->id; }
    public function getPublicId(): string { return $this->public_id ?: (string)$this->id; }
    public function getIdUser(): int { return $this->id_user; }
    public function getIdTemplate(): ?int { return $this->id_template; }
    public function getIdWorld(): ?int { return $this->id_world; }
    public function getIdStatus(): ?int { return $this->id_status; }
    public function getImageDisplayMode(): string { return $this->image_display_mode; }
    public function getImageFit(): string { return $this->image_fit; }
    public function getImageFocusX(): int { return $this->image_focus_x; }
    public function getImageFocusY(): int { return $this->image_focus_y; }
    public function getImageZoom(): float { return $this->image_zoom; }
    public function isHidden(): bool { return $this->is_hidden; }
    public function isMainCharacter(): bool { return $this->is_main_character; }
    public function isPinned(): bool { return $this->is_pinned; }
}
