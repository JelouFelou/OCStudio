<?php

class Filter {
    private $id;
    private $name;
    private $id_user;
    private $is_public;

    public function __construct($name, $id_user = null, $is_public = false, $id = null) {
        $this->name = $name;
        $this->id_user = $id_user;
        $this->is_public = $is_public;
        $this->id = $id;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getIdUser(): ?int { return $this->id_user; }
    public function getIsPublic(): bool { return $this->is_public; }
}
