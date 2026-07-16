<?php

class StoryFolder {
    private int $id;
    private int $idUser;
    private int $idWorld;
    private string $name;
    private ?int $parentId;
    private int $orderNumber;
    private DateTime $createdAt;
    private DateTime $updatedAt;

    public function __construct(
        int $id = 0,
        int $idUser = 0,
        int $idWorld = 0,
        string $name = '',
        ?int $parentId = null,
        int $orderNumber = 0,
        DateTime $createdAt = null,
        DateTime $updatedAt = null
    ) {
        $this->id = $id;
        $this->idUser = $idUser;
        $this->idWorld = $idWorld;
        $this->name = $name;
        $this->parentId = $parentId;
        $this->orderNumber = $orderNumber;
        $this->createdAt = $createdAt ?? new DateTime();
        $this->updatedAt = $updatedAt ?? new DateTime();
    }

    public function getId(): int { return $this->id; }
    public function getIdUser(): int { return $this->idUser; }
    public function getIdWorld(): int { return $this->idWorld; }
    public function getName(): string { return $this->name; }
    public function getParentId(): ?int { return $this->parentId; }
    public function getOrderNumber(): int { return $this->orderNumber; }
    public function getCreatedAt(): DateTime { return $this->createdAt; }
    public function getUpdatedAt(): DateTime { return $this->updatedAt; }

    public function setName(string $name): void { $this->name = $name; }
    public function setParentId(?int $parentId): void { $this->parentId = $parentId; }
    public function setOrderNumber(int $orderNumber): void { $this->orderNumber = $orderNumber; }
}
