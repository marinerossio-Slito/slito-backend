<?php

namespace App\Entity;

use App\Repository\SubscriptionRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Abonnement payant d'un artisan, géré via Stripe.
 *
 * Important : aucune donnée bancaire ne transite par notre serveur, on ne stocke
 * que les identifiants Stripe (stripeSubscriptionId) et un statut/plan.
 */
#[ORM\Entity(repositoryClass: SubscriptionRepository::class)]
class Subscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private ?string $stripeSubscriptionId = null;

    /**
     * Statut Stripe (ex : active, past_due, canceled...).
     */
    #[ORM\Column(length: 30)]
    private ?string $status = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $currentPeriodEnd = null;

    /**
     * Identifiant du plan/prix Stripe souscrit (ex : "monthly", "yearly").
     */
    #[ORM\Column(length: 50)]
    private ?string $plan = null;

    #[ORM\OneToOne(inversedBy: 'subscription')]
    #[ORM\JoinColumn(nullable: false, unique: true)]
    private ?Artisan $artisan = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getStripeSubscriptionId(): ?string
    {
        return $this->stripeSubscriptionId;
    }

    public function setStripeSubscriptionId(string $stripeSubscriptionId): static
    {
        $this->stripeSubscriptionId = $stripeSubscriptionId;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getCurrentPeriodEnd(): ?\DateTimeImmutable
    {
        return $this->currentPeriodEnd;
    }

    public function setCurrentPeriodEnd(?\DateTimeImmutable $currentPeriodEnd): static
    {
        $this->currentPeriodEnd = $currentPeriodEnd;

        return $this;
    }

    public function getPlan(): ?string
    {
        return $this->plan;
    }

    public function setPlan(string $plan): static
    {
        $this->plan = $plan;

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
}
