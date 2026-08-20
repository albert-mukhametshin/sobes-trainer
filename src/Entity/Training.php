<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'training')]
class Training
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 120)]
    private string $name;

    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $bestScore = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    /** @var Collection<int, Question> */
    #[ORM\ManyToMany(targetEntity: Question::class)]
    #[ORM\JoinTable(name: 'training_question')]
    #[ORM\JoinColumn(name: 'training_id', referencedColumnName: 'id', onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(name: 'question_id', referencedColumnName: 'id', onDelete: 'RESTRICT')]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $questions;

    public function __construct(string $name)
    {
        $this->name = $name;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = $this->createdAt;
        $this->questions = new ArrayCollection();
    }

    public function getId(): ?int { return $this->id; }
    public function getName(): string { return $this->name; }
    public function getBestScore(): ?int { return $this->bestScore; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    /** @return Collection<int, Question> */
    public function getQuestions(): Collection { return $this->questions; }

    /** @param iterable<Question> $questions */
    public function update(string $name, iterable $questions): void
    {
        $this->name = $name;
        $this->questions->clear();
        foreach ($questions as $question) {
            $this->questions->add($question);
        }
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function registerScore(int $score): void
    {
        $score = max(0, min(100, $score));
        if ($this->bestScore === null || $score > $this->bestScore) {
            $this->bestScore = $score;
        }
        $this->updatedAt = new \DateTimeImmutable();
    }
}
