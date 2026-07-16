<?php

class StoryCharacter {
    private int $id;
    private int $idStory;
    private int $idCharacter;
    private ?int $idVariant;
    private ?int $pseudonymFieldId;
    private int $orderNumber;
    private DateTime $createdAt;

    public function __construct(
        int $id = 0,
        int $idStory = 0,
        int $idCharacter = 0,
        ?int $idVariant = null,
        ?int $pseudonymFieldId = null,
        int $orderNumber = 0,
        DateTime $createdAt = null
    ) {
        $this->id = $id;
        $this->idStory = $idStory;
        $this->idCharacter = $idCharacter;
        $this->idVariant = $idVariant;
        $this->pseudonymFieldId = $pseudonymFieldId;
        $this->orderNumber = $orderNumber;
        $this->createdAt = $createdAt ?? new DateTime();
    }

    public function getId(): int { return $this->id; }
    public function getIdStory(): int { return $this->idStory; }
    public function getIdCharacter(): int { return $this->idCharacter; }
    public function getIdVariant(): ?int { return $this->idVariant; }
    public function getPseudonymFieldId(): ?int { return $this->pseudonymFieldId; }
    public function getOrderNumber(): int { return $this->orderNumber; }
    public function getCreatedAt(): DateTime { return $this->createdAt; }

    public function setPseudonymFieldId(?int $pseudonymFieldId): void { $this->pseudonymFieldId = $pseudonymFieldId; }
    public function setIdVariant(?int $idVariant): void { $this->idVariant = $idVariant; }
    public function setOrderNumber(int $orderNumber): void { $this->orderNumber = $orderNumber; }
}
