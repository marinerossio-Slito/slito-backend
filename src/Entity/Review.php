<?php

namespace App\Entity;

use App\Enum\ReviewAuthorType;
use App\Repository\ReviewRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Avis bidirectionnel : un client note l'artisan ET l'artisan peut noter le client,
 * tous deux représentés par des User (authorType précise qui est l'auteur).
 *
 * Règle métier : un avis n'est possible qu'après une prestation terminée (COMPLETED).
 */
#[ORM\Entity(repositoryClass: ReviewRepository::class)]
class Review
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private ?int $rating = null;

    #[ORM\Column(nullable: true)]
    private ?int $punctualityRating = null;

    #[ORM\Column(nullable: true)]
    private ?int $qualityRating = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $comment = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(enumType: ReviewAuthorType::class)]
    private ?ReviewAuthorType $authorType = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Appointment $appointment = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $author = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $target = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRating(): ?int
    {
        return $this->rating;
    }

    public function setRating(int $rating): static
    {
        $this->rating = $rating;

        return $this;
    }

    public function getPunctualityRating(): ?int
    {
        return $this->punctualityRating;
    }

    public function setPunctualityRating(?int $punctualityRating): static
    {
        $this->punctualityRating = $punctualityRating;

        return $this;
    }

    public function getQualityRating(): ?int
    {
        return $this->qualityRating;
    }

    public function setQualityRating(?int $qualityRating): static
    {
        $this->qualityRating = $qualityRating;

        return $this;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    public function setComment(?string $comment): static
    {
        $this->comment = $comment;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getAuthorType(): ?ReviewAuthorType
    {
        return $this->authorType;
    }

    public function setAuthorType(ReviewAuthorType $authorType): static
    {
        $this->authorType = $authorType;

        return $this;
    }

    public function getAppointment(): ?Appointment
    {
        return $this->appointment;
    }

    public function setAppointment(?Appointment $appointment): static
    {
        $this->appointment = $appointment;

        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getTarget(): ?User
    {
        return $this->target;
    }

    public function setTarget(?User $target): static
    {
        $this->target = $target;

        return $this;
    }
}
