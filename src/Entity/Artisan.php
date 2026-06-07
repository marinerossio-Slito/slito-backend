<?php

namespace App\Entity;

use App\Repository\ArtisanRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Profil artisan : complète un User ayant le rôle ROLE_ARTISAN.
 *
 * Règle métier : un artisan ne peut s'inscrire qu'avec un justificatif certifié
 * (ownershipDocument), validé par un admin (isApproved) avant la création effective du compte.
 */
#[ORM\Entity(repositoryClass: ArtisanRepository::class)]
class Artisan
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\OneToOne(inversedBy: 'artisan', targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ?User $user = null;

    #[ORM\Column(length: 14)]
    private ?string $siret = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $officeAddress = null;

    #[ORM\Column]
    private bool $isApproved = false;

    /**
     * Chemin/référence vers le justificatif de propriété de l'entreprise.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $ownershipDocument = null;

    /**
     * Identifiant du « Customer » Stripe associé (cf. ARCHITECTURE.md « Paiement »).
     * Créé paresseusement à la première démarche d'abonnement plutôt qu'à l'inscription,
     * afin de ne pas coupler la création de compte à la disponibilité de l'API Stripe ;
     * il sert ensuite de clé de correspondance pour synchroniser l'abonnement via webhook.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $stripeCustomerId = null;

    #[ORM\OneToOne(inversedBy: 'artisan', targetEntity: Business::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinColumn(unique: true)]
    private ?Business $business = null;

    #[ORM\OneToOne(mappedBy: 'artisan', targetEntity: Subscription::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    private ?Subscription $subscription = null;

    /**
     * @var Collection<int, CalendarEvent>
     */
    #[ORM\OneToMany(targetEntity: CalendarEvent::class, mappedBy: 'artisan', orphanRemoval: true)]
    private Collection $calendarEvents;

    public function __construct()
    {
        $this->calendarEvents = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getSiret(): ?string
    {
        return $this->siret;
    }

    public function setSiret(string $siret): static
    {
        $this->siret = $siret;

        return $this;
    }

    public function getOfficeAddress(): ?string
    {
        return $this->officeAddress;
    }

    public function setOfficeAddress(?string $officeAddress): static
    {
        $this->officeAddress = $officeAddress;

        return $this;
    }

    public function isApproved(): bool
    {
        return $this->isApproved;
    }

    public function setIsApproved(bool $isApproved): static
    {
        $this->isApproved = $isApproved;

        return $this;
    }

    public function getOwnershipDocument(): ?string
    {
        return $this->ownershipDocument;
    }

    public function setOwnershipDocument(?string $ownershipDocument): static
    {
        $this->ownershipDocument = $ownershipDocument;

        return $this;
    }

    public function getStripeCustomerId(): ?string
    {
        return $this->stripeCustomerId;
    }

    public function setStripeCustomerId(?string $stripeCustomerId): static
    {
        $this->stripeCustomerId = $stripeCustomerId;

        return $this;
    }

    public function getBusiness(): ?Business
    {
        return $this->business;
    }

    public function setBusiness(?Business $business): static
    {
        $this->business = $business;

        return $this;
    }

    public function getSubscription(): ?Subscription
    {
        return $this->subscription;
    }

    public function setSubscription(?Subscription $subscription): static
    {
        // unset the owning side of the relation if necessary
        if ($subscription === null && $this->subscription !== null) {
            $this->subscription->setArtisan(null);
        }

        // set the owning side of the relation if necessary
        if ($subscription !== null && $subscription->getArtisan() !== $this) {
            $subscription->setArtisan($this);
        }

        $this->subscription = $subscription;

        return $this;
    }

    /**
     * @return Collection<int, CalendarEvent>
     */
    public function getCalendarEvents(): Collection
    {
        return $this->calendarEvents;
    }

    public function addCalendarEvent(CalendarEvent $calendarEvent): static
    {
        if (!$this->calendarEvents->contains($calendarEvent)) {
            $this->calendarEvents->add($calendarEvent);
            $calendarEvent->setArtisan($this);
        }

        return $this;
    }

    public function removeCalendarEvent(CalendarEvent $calendarEvent): static
    {
        if ($this->calendarEvents->removeElement($calendarEvent)) {
            if ($calendarEvent->getArtisan() === $this) {
                $calendarEvent->setArtisan(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->user;
    }
}
