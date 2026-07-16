<?php

class Story {
    private int $id;
    private string $publicId;
    private int $idUser;
    private int $idWorld;
    private ?int $idFolder;
    private string $title;
    private string $description;
    private string $storyDate;
    private string $image;
    private string $imageFit;
    private int $imageFocusX;
    private int $imageFocusY;
    private float $imageZoom;
    private string $cardImageFit;
    private int $cardImageFocusX;
    private int $cardImageFocusY;
    private float $cardImageZoom;
    private string $timelineBranchName;
    private string $timelineSplitDate;
    private bool $timelineSplitUnknown;
    private string $timelineMergeDate;
    private bool $timelineMergeUnknown;
    private ?float $timelinePositionX;
    private ?float $timelinePositionY;
    private string $status;
    private int $orderNumber;
    private bool $isHidden;
    private DateTime $createdAt;
    private DateTime $updatedAt;

    public function __construct(
        int $id = 0,
        string $publicId = '',
        int $idUser = 0,
        int $idWorld = 0,
        ?int $idFolder = null,
        string $title = '',
        string $description = '',
        string $storyDate = '',
        string $image = 'default_story.png',
        string $imageFit = 'cover',
        int $imageFocusX = 50,
        int $imageFocusY = 50,
        float $imageZoom = 1,
        string $cardImageFit = 'cover',
        int $cardImageFocusX = 50,
        int $cardImageFocusY = 50,
        float $cardImageZoom = 1,
        string $timelineBranchName = '',
        string $timelineSplitDate = '',
        bool $timelineSplitUnknown = false,
        string $timelineMergeDate = '',
        bool $timelineMergeUnknown = false,
        ?float $timelinePositionX = null,
        ?float $timelinePositionY = null,
        string $status = 'draft',
        int $orderNumber = 0,
        bool $isHidden = false,
        DateTime $createdAt = null,
        DateTime $updatedAt = null
    ) {
        $this->id = $id;
        $this->publicId = $publicId;
        $this->idUser = $idUser;
        $this->idWorld = $idWorld;
        $this->idFolder = $idFolder;
        $this->title = $title;
        $this->description = $description;
        $this->storyDate = $storyDate;
        $this->image = $image;
        $this->imageFit = in_array($imageFit, ['cover', 'contain'], true) ? $imageFit : 'cover';
        $this->imageFocusX = max(0, min(100, $imageFocusX));
        $this->imageFocusY = max(0, min(100, $imageFocusY));
        $this->imageZoom = max(1, min(6, $imageZoom));
        $this->cardImageFit = in_array($cardImageFit, ['cover', 'contain'], true) ? $cardImageFit : 'cover';
        $this->cardImageFocusX = max(0, min(100, $cardImageFocusX));
        $this->cardImageFocusY = max(0, min(100, $cardImageFocusY));
        $this->cardImageZoom = max(1, min(6, $cardImageZoom));
        $this->timelineBranchName = $timelineBranchName;
        $this->timelineSplitDate = $timelineSplitDate;
        $this->timelineSplitUnknown = $timelineSplitUnknown;
        $this->timelineMergeDate = $timelineMergeDate;
        $this->timelineMergeUnknown = $timelineMergeUnknown;
        $this->timelinePositionX = $timelinePositionX;
        $this->timelinePositionY = $timelinePositionY;
        $this->status = $status;
        $this->orderNumber = $orderNumber;
        $this->isHidden = $isHidden;
        $this->createdAt = $createdAt ?? new DateTime();
        $this->updatedAt = $updatedAt ?? new DateTime();
    }

    public function getId(): int { return $this->id; }
    public function getPublicId(): string { return $this->publicId; }
    public function getIdUser(): int { return $this->idUser; }
    public function getIdWorld(): int { return $this->idWorld; }
    public function getIdFolder(): ?int { return $this->idFolder; }
    public function getTitle(): string { return $this->title; }
    public function getDescription(): string { return $this->description; }
    public function getStoryDate(): string { return $this->storyDate; }
    public function getImage(): string { return $this->image; }
    public function getImageFit(): string { return $this->imageFit; }
    public function getImageFocusX(): int { return $this->imageFocusX; }
    public function getImageFocusY(): int { return $this->imageFocusY; }
    public function getImageZoom(): float { return $this->imageZoom; }
    public function getCardImageFit(): string { return $this->cardImageFit; }
    public function getCardImageFocusX(): int { return $this->cardImageFocusX; }
    public function getCardImageFocusY(): int { return $this->cardImageFocusY; }
    public function getCardImageZoom(): float { return $this->cardImageZoom; }
    public function getTimelineBranchName(): string { return $this->timelineBranchName; }
    public function getTimelineSplitDate(): string { return $this->timelineSplitDate; }
    public function isTimelineSplitUnknown(): bool { return $this->timelineSplitUnknown; }
    public function getTimelineMergeDate(): string { return $this->timelineMergeDate; }
    public function isTimelineMergeUnknown(): bool { return $this->timelineMergeUnknown; }
    public function getTimelinePositionX(): ?float { return $this->timelinePositionX; }
    public function getTimelinePositionY(): ?float { return $this->timelinePositionY; }
    public function getStatus(): string { return $this->status; }
    public function getOrderNumber(): int { return $this->orderNumber; }
    public function isHidden(): bool { return $this->isHidden; }
    public function getCreatedAt(): DateTime { return $this->createdAt; }
    public function getUpdatedAt(): DateTime { return $this->updatedAt; }

    public function setTitle(string $title): void { $this->title = $title; }
    public function setDescription(string $description): void { $this->description = $description; }
    public function setStoryDate(string $storyDate): void { $this->storyDate = $storyDate; }
    public function setImage(string $image): void { $this->image = $image; }
    public function setImageFit(string $imageFit): void { $this->imageFit = in_array($imageFit, ['cover', 'contain'], true) ? $imageFit : 'cover'; }
    public function setImageFocusX(int $imageFocusX): void { $this->imageFocusX = max(0, min(100, $imageFocusX)); }
    public function setImageFocusY(int $imageFocusY): void { $this->imageFocusY = max(0, min(100, $imageFocusY)); }
    public function setImageZoom(float $imageZoom): void { $this->imageZoom = max(1, min(6, $imageZoom)); }
    public function setCardImageFit(string $imageFit): void { $this->cardImageFit = in_array($imageFit, ['cover', 'contain'], true) ? $imageFit : 'cover'; }
    public function setCardImageFocusX(int $imageFocusX): void { $this->cardImageFocusX = max(0, min(100, $imageFocusX)); }
    public function setCardImageFocusY(int $imageFocusY): void { $this->cardImageFocusY = max(0, min(100, $imageFocusY)); }
    public function setCardImageZoom(float $imageZoom): void { $this->cardImageZoom = max(1, min(6, $imageZoom)); }
    public function setTimelineBranchName(string $name): void { $this->timelineBranchName = $name; }
    public function setTimelineSplitDate(string $date): void { $this->timelineSplitDate = $date; }
    public function setTimelineSplitUnknown(bool $unknown): void { $this->timelineSplitUnknown = $unknown; }
    public function setTimelineMergeDate(string $date): void { $this->timelineMergeDate = $date; }
    public function setTimelineMergeUnknown(bool $unknown): void { $this->timelineMergeUnknown = $unknown; }
    public function setTimelinePosition(?float $x, ?float $y): void { $this->timelinePositionX = $x; $this->timelinePositionY = $y; }
    public function setStatus(string $status): void { $this->status = $status; }
    public function setIdFolder(?int $idFolder): void { $this->idFolder = $idFolder; }
    public function setHidden(bool $hidden): void { $this->isHidden = $hidden; }
}
