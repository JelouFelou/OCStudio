<?php

class World {
    private $id;
    private $name;
    private $description;
    private $image;
    private $id_user;
    private $parent_id;
    private $id_status;

    public function __construct(string $name, string $description, string $image, int $id_user, ?int $id = null, ?int $parent_id = null, ?int $id_status = null) {
        $this->name = $name;
        $this->description = $description;
        $this->image = $image;
        $this->id_user = $id_user;
        $this->id = $id;
        $this->parent_id = $parent_id;
        $this->id_status = $id_status;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getDescription(): string { return $this->description; }
    public function getImage(): string { return $this->image; }
    public function getIdUser(): int { return $this->id_user; }
    public function getParentId(): ?int { return $this->parent_id; }
    public function getIdStatus(): ?int { return $this->id_status; }
}
