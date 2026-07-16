<?php

class Template {
    private $id;
    private $public_id;
    private $name;
    private $description;
    private $id_user;
    private $fields;
    private $is_hidden;
    private string $date_calendar_type;
    private string $date_settings;
    private string $current_world_date;

    public function __construct($name, $description, $id_user, $id = null, $fields = [], $is_hidden = false, ?string $public_id = null, string $date_calendar_type = 'real', string $date_settings = '', string $current_world_date = '') {
        $this->name = $name;
        $this->description = $description;
        $this->id_user = $id_user;
        $this->id = $id;
        $this->public_id = $public_id;
        $this->fields = $fields;
        $this->is_hidden = (bool)$is_hidden;
        $this->date_calendar_type = $date_calendar_type;
        $this->date_settings = $date_settings;
        $this->current_world_date = $current_world_date;
    }

    public function getId(): ?int { return $this->id; }
    public function getPublicId(): string { return $this->public_id ?: (string)$this->id; }
    public function getName(): string { return $this->name; }
    public function getDescription(): string { return $this->description; }
    public function getFields(): array { return $this->fields; }
    public function isHidden(): bool { return $this->is_hidden; }
    public function getDateCalendarType(): string { return $this->date_calendar_type; }
    public function getDateSettings(): string { return $this->date_settings; }
    public function getCurrentWorldDate(): string { return $this->current_world_date; }
}
