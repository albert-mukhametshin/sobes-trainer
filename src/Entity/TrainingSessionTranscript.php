<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'training_session_transcript')]
class TrainingSessionTranscript
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: TrainingSessionSegment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TrainingSessionSegment $segment;

    #[ORM\Column(length: 120)]
    private string $model;

    #[ORM\Column(type: Types::TEXT)]
    private string $text;

    /** @var list<array{text: string, startMs: int, endMs: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $words;

    /** @var list<array{startMs: int, endMs: int, durationMs: int}> */
    #[ORM\Column(type: Types::JSON)]
    private array $pauses;

    /** @var array<string, int|float> */
    #[ORM\Column(type: Types::JSON)]
    private array $metrics;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param list<array{text: string, startMs: int, endMs: int}> $words @param list<array{startMs: int, endMs: int, durationMs: int}> $pauses @param array<string, int|float> $metrics */
    public function __construct(TrainingSessionSegment $segment, string $model, string $text, array $words, array $pauses, array $metrics)
    {
        $this->segment = $segment;
        $this->model = $model;
        $this->text = $text;
        $this->words = $words;
        $this->pauses = $pauses;
        $this->metrics = $metrics;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSegment(): TrainingSessionSegment { return $this->segment; }
    public function getModel(): string { return $this->model; }
    public function getText(): string { return $this->text; }
    /** @return list<array{text: string, startMs: int, endMs: int}> */
    public function getWords(): array { return $this->words; }
    /** @return list<array{startMs: int, endMs: int, durationMs: int}> */
    public function getPauses(): array { return $this->pauses; }
    /** @return array<string, int|float> */
    public function getMetrics(): array { return $this->metrics; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
