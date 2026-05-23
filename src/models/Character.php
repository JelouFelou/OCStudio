<?php

class Character {
    private $id;
    private $name;
    private $description;
    private $image;
    private $id_user;
    private $id_template;
    private $id_world;
    private $id_status;

    public function __construct($name, $description, $image, $id_user, $id = null, $id_template = null, $id_world = null, $id_status = null) {
        $this->name = $name;
        $this->description = $description;
        $this->image = $image;
        $this->id_user = $id_user;
        $this->id = $id;
        $this->id_template = $id_template;
        $this->id_world = $id_world;
        $this->id_status = $id_status;
    }

    public function getName(): string { return $this->name; }
    public function getDescription(): string { return $this->description; }
    public function getImage(): string { return $this->image; }
    public function getId(): ?int { return $this->id; }
    public function getIdUser(): int { return $this->id_user; }
    public function getIdTemplate(): ?int { return $this->id_template; }
    public function getIdWorld(): ?int { return $this->id_world; }
    public function getIdStatus(): ?int { return $this->id_status; }
}
