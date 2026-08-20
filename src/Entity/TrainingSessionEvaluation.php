<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'training_session_evaluation')]
class TrainingSessionEvaluation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: TrainingSessionSegment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private TrainingSessionSegment $segment;

    #[ORM\Column(length: 50)]
    private string $provider;

    #[ORM\Column(length: 120)]
    private string $model;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $attempts;

    #[ORM\Column(type: Types::SMALLINT)]
    private int $totalScore;

    /** @var array<string, int> */
    #[ORM\Column(type: Types::JSON)]
    private array $criteria;

    #[ORM\Column(type: Types::TEXT)]
    private string $verdict;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $strengths;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $recommendations;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $missingTopics;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /** @param array<string, int> $criteria @param list<string> $strengths @param list<string> $recommendations @param list<string> $missingTopics */
    public function __construct(TrainingSessionSegment $segment, string $provider, string $model, int $attempts, int $totalScore, array $criteria, string $verdict, array $strengths, array $recommendations, array $missingTopics)
    {
        $this->segment = $segment;
        $this->provider = $provider;
        $this->model = $model;
        $this->attempts = $attempts;
        $this->totalScore = max(0, min(100, $totalScore));
        $this->criteria = $criteria;
        $this->verdict = $verdict;
        $this->strengths = $strengths;
        $this->recommendations = $recommendations;
        $this->missingTopics = $missingTopics;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }
    public function getSegment(): TrainingSessionSegment { return $this->segment; }
    public function getProvider(): string { return $this->provider; }
    public function getModel(): string { return $this->model; }
    public function getAttempts(): int { return $this->attempts; }
    public function getTotalScore(): int { return $this->totalScore; }
    /** @return array<string, int> */
    public function getCriteria(): array { return $this->criteria; }
    public function getVerdict(): string { return $this->verdict; }
    /** @return list<string> */
    public function getStrengths(): array { return $this->strengths; }
    /** @return list<string> */
    public function getRecommendations(): array { return $this->recommendations; }
    /** @return list<string> */
    public function getMissingTopics(): array { return $this->missingTopics; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
}
