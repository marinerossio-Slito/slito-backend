<?php

namespace App\Entity;

use App\Enum\CalendarEventType;
use App\Repository\CalendarEventRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Événement de l'agenda d'un artisan : rendez-vous ou événement personnel/indisponibilité.
 */
#[ORM\Entity(repositoryClass: CalendarEventRepository::class)]
class CalendarEvent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $startDate = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $endDate = null;

    #[ORM\Column(enumType: CalendarEventType::class)]
    private ?CalendarEventType $type = null;

    /**
     * Marque une plage d'indisponibilité de l'artisan (ne peut pas être réservée).
     */
    #[ORM\Column]
    private bool $isAvailability = false;

    #[ORM\ManyToOne(inversedBy: 'calendarEvents')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Artisan $artisan = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getStartDate(): ?\DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeImmutable $startDate): static
    {
        $this->startDate = $startDate;

        return $this;
    }

    public function getEndDate(): ?\DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(\DateTimeImmutable $endDate): static
    {
        $this->endDate = $endDate;

        return $this;
    }

    public function getType(): ?CalendarEventType
    {
        return $this->type;
    }

    public function setType(CalendarEventType $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function isAvailability(): bool
    {
        return $this->isAvailability;
    }

    public function setIsAvailability(bool $isAvailability): static
    {
        $this->isAvailability = $isAvailability;

        return $this;
    }

    public function getArtisan(): ?Artisan
    {
        return $this->artisan;
    }

    public function setArtisan(?Artisan $artisan): static
    {
        $this->artisan = $artisan;

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->title;
    }
}
