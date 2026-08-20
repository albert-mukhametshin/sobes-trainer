<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'training_session')]
class TrainingSession
{
    public const STATUS_RECORDING = 'recording';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const ANALYSIS_NOT_STARTED = 'not_started';
    public const ANALYSIS_QUEUED = 'queued';
    public const ANALYSIS_TRANSCRIBING = 'transcribing';
    public const ANALYSIS_EVALUATING = 'evaluating';
    public const ANALYSIS_COMPLETED = 'completed';
    public const ANALYSIS_FAILED = 'failed';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Training::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Training $training;

    #[ORM\Column(length: 20)]
    private string $status = self::STATUS_RECORDING;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $elapsedSeconds = 0;

    #[ORM\Column(type: Types::SMALLINT, options: ['default' => 0])]
    private int $answeredCount = 0;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $score = null;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $audioFilename = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $audioMimeType = null;

    #[ORM\Column(nullable: true)]
    private ?int $audioSize = null;

    #[ORM\Column(length: 20, options: ['default' => self::ANALYSIS_NOT_STARTED])]
    private string $analysisStatus = self::ANALYSIS_NOT_STARTED;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $analysisScore = null;

    #[ORM\Column(length: 80, nullable: true)]
    private ?string $analysisErrorCode = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $analysisErrorMessage = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $analysisStartedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $analysisCompletedAt = null;

    /** @var Collection<int, TrainingSessionSegment> */
    #[ORM\OneToMany(mappedBy: 'trainingSession', targetEntity: TrainingSessionSegment::class, cascade: ['persist'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $segments;

    public function __construct(Training $training)
    {
        $this->training = $training;
        $this->startedAt = new \DateTimeImmutable();
        $this->segments = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getTraining(): Training { return $this->training; }
    public function getStatus(): string { return $this->status; }
    public function getStartedAt(): \DateTimeImmutable { return $this->startedAt; }
    public function getFinishedAt(): ?\DateTimeImmutable { return $this->finishedAt; }
    public function getElapsedSeconds(): int { return $this->elapsedSeconds; }
    public function getAnsweredCount(): int { return $this->answeredCount; }
    public function getScore(): ?int { return $this->score; }
    public function getAudioFilename(): ?string { return $this->audioFilename; }
    public function getAudioMimeType(): ?string { return $this->audioMimeType; }
    public function getAudioSize(): ?int { return $this->audioSize; }
    public function getAnalysisStatus(): string { return $this->analysisStatus; }
    public function getAnalysisScore(): ?int { return $this->analysisScore; }
    public function getAnalysisErrorCode(): ?string { return $this->analysisErrorCode; }
    public function getAnalysisErrorMessage(): ?string { return $this->analysisErrorMessage; }
    public function getAnalysisStartedAt(): ?\DateTimeImmutable { return $this->analysisStartedAt; }
    public function getAnalysisCompletedAt(): ?\DateTimeImmutable { return $this->analysisCompletedAt; }

    /** @return Collection<int, TrainingSessionSegment> */
    public function getSegments(): Collection { return $this->segments; }

    public function addSegment(TrainingSessionSegment $segment): void
    {
        $this->segments->add($segment);
    }

    public function attachAudio(string $filename, string $mimeType, int $size): void
    {
        $this->audioFilename = $filename;
        $this->audioMimeType = $mimeType;
        $this->audioSize = $size;
    }

    public function finish(int $elapsedSeconds, int $answeredCount, bool $cancelled = false): void
    {
        $total = max(1, $this->training->getQuestions()->count());
        $this->elapsedSeconds = max(0, $elapsedSeconds);
        $this->answeredCount = max(0, min($total, $answeredCount));
        $this->score = (int) round(($this->answeredCount / $total) * 100);
        $this->status = $cancelled ? self::STATUS_CANCELLED : self::STATUS_COMPLETED;
        $this->finishedAt = new \DateTimeImmutable();
        if (!$cancelled) {
            $this->training->registerScore($this->score);
        }
    }

    public function queueAnalysis(): void
    {
        if ($this->status !== self::STATUS_COMPLETED || $this->audioFilename === null) {
            throw new \LogicException('Анализ доступен только для завершённой тренировки с аудиозаписью.');
        }

        $this->analysisStatus = self::ANALYSIS_QUEUED;
        $this->analysisScore = null;
        $this->analysisErrorCode = null;
        $this->analysisErrorMessage = null;
        $this->analysisStartedAt = new \DateTimeImmutable();
        $this->analysisCompletedAt = null;
    }

    public function markAnalysisTranscribing(): void
    {
        $this->analysisStatus = self::ANALYSIS_TRANSCRIBING;
    }

    public function markAnalysisEvaluating(): void
    {
        $this->analysisStatus = self::ANALYSIS_EVALUATING;
    }

    public function completeAnalysis(int $score): void
    {
        $this->analysisStatus = self::ANALYSIS_COMPLETED;
        $this->analysisScore = max(0, min(100, $score));
        $this->analysisErrorCode = null;
        $this->analysisErrorMessage = null;
        $this->analysisCompletedAt = new \DateTimeImmutable();
    }

    public function failAnalysis(string $code, string $message): void
    {
        $this->analysisStatus = self::ANALYSIS_FAILED;
        $this->analysisErrorCode = mb_substr($code, 0, 80);
        $this->analysisErrorMessage = $message;
        $this->analysisCompletedAt = new \DateTimeImmutable();
    }
}
