<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'training_session_segment')]
#[ORM\UniqueConstraint(name: 'uniq_session_segment_position', columns: ['training_session_id', 'position'])]
class TrainingSessionSegment
{
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_INTERRUPTED = 'interrupted';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TrainingSession::class, inversedBy: 'segments')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TrainingSession $trainingSession;

    #[ORM\ManyToOne(targetEntity: Question::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Question $question;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $position;

    #[ORM\Column(length: 500)]
    private string $questionTitle;

    #[ORM\Column(length: 80)]
    private string $categoryLabel;

    #[ORM\Column]
    private int $startedAtMs;

    #[ORM\Column]
    private int $endedAtMs;

    #[ORM\Column(length: 20)]
    private string $status;

    public function __construct(
        TrainingSession $trainingSession,
        Question $question,
        int $position,
        int $startedAtMs,
        int $endedAtMs,
        string $status = self::STATUS_COMPLETED,
    ) {
        $this->trainingSession = $trainingSession;
        $this->question = $question;
        $this->position = $position;
        $this->questionTitle = $question->getTitle();
        $this->categoryLabel = $question->getCategoryLabel();
        $this->startedAtMs = $startedAtMs;
        $this->endedAtMs = $endedAtMs;
        $this->status = $status;
    }

    public function getId(): ?int { return $this->id; }
    public function getTrainingSession(): TrainingSession { return $this->trainingSession; }
    public function getQuestion(): ?Question { return $this->question; }
    public function getPosition(): int { return $this->position; }
    public function getQuestionTitle(): string { return $this->questionTitle; }
    public function getCategoryLabel(): string { return $this->categoryLabel; }
    public function getStartedAtMs(): int { return $this->startedAtMs; }
    public function getEndedAtMs(): int { return $this->endedAtMs; }
    public function getStatus(): string { return $this->status; }
}
