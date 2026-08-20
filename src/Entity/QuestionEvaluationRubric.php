<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'question_evaluation_rubric')]
class QuestionEvaluationRubric
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(targetEntity: Question::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Question $question;

    #[ORM\Column(type: Types::TEXT)]
    private string $referenceAnswer;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $requiredPoints;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $commonMistakes;

    /** @param list<string> $requiredPoints @param list<string> $commonMistakes */
    public function __construct(Question $question, string $referenceAnswer, array $requiredPoints, array $commonMistakes = [])
    {
        $this->question = $question;
        $this->referenceAnswer = $referenceAnswer;
        $this->requiredPoints = $requiredPoints;
        $this->commonMistakes = $commonMistakes;
    }

    public function getId(): ?int { return $this->id; }
    public function getQuestion(): Question { return $this->question; }
    public function getReferenceAnswer(): string { return $this->referenceAnswer; }
    /** @return list<string> */
    public function getRequiredPoints(): array { return $this->requiredPoints; }
    /** @return list<string> */
    public function getCommonMistakes(): array { return $this->commonMistakes; }
}
