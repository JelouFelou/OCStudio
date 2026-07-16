<?php

class Filter {
    private $id;
    private $name;
    private $slug;
    private $is_active;

    public function __construct($name, $id = null, ?string $slug = null, bool $is_active = true) {
        $this->name = $name;
        $this->id = $id;
        $this->slug = $slug ?: $this->slugify($name);
        $this->is_active = $is_active;
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getSlug(): string { return $this->slug; }
    public function getIsActive(): bool { return $this->is_active; }

    public function getIdUser(): ?int { return null; }
    public function getIsPublic(): bool { return true; }

    private function slugify(string $value): string
    {
        $value = trim(mb_strtolower($value));
        $map = [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
            'ó' => 'o', 'ś' => 's', 'ż' => 'z', 'ź' => 'z',
        ];
        $value = strtr($value, $map);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: 'tag';
        return trim($value, '-') ?: 'tag';
    }
}
