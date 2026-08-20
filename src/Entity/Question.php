<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'question')]
class Question
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private string $category;

    #[ORM\Column(length: 80)]
    private string $categoryLabel;

    #[ORM\Column(length: 7)]
    private string $categoryColor;

    #[ORM\Column(length: 500)]
    private string $title;

    #[ORM\Column(length: 30)]
    private string $level;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $memory = 0;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $repeats = 0;

    #[ORM\Column(type: 'text')]
    private string $hint;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastAnsweredAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $category,
        string $categoryLabel,
        string $categoryColor,
        string $title,
        string $level,
        string $hint,
    ) {
        $this->category = $category;
        $this->categoryLabel = $categoryLabel;
        $this->categoryColor = $categoryColor;
        $this->title = $title;
        $this->level = $level;
        $this->hint = $hint;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getCategory(): string { return $this->category; }
    public function getCategoryLabel(): string { return $this->categoryLabel; }
    public function getCategoryColor(): string { return $this->categoryColor; }
    public function getTitle(): string { return $this->title; }
    public function getLevel(): string { return $this->level; }
    public function getMemory(): int { return $this->memory; }
    public function getRepeats(): int { return $this->repeats; }
    public function getHint(): string { return $this->hint; }
    public function getLastAnsweredAt(): ?\DateTimeImmutable { return $this->lastAnsweredAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }

    public function markAnswered(int $memory): void
    {
        $this->memory = max(0, min(100, $memory));
        ++$this->repeats;
        $this->lastAnsweredAt = new \DateTimeImmutable();
    }
}
