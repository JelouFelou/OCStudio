<?php

class CharacterStatus {
    private $id;
    private $name;
    private $color_hex;

    public function __construct($name, $color_hex, $id = null) {
        $this->name = $name;
        $this->color_hex = $color_hex;
        $this->id = $id;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getColorHex(): string { return $this->color_hex; }
}
