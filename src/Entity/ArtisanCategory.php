<?php

namespace App\Entity;

use App\Repository\ArtisanCategoryRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Catégorie de métier (ex : plombier, électricien...). Gérable par l'admin.
 */
#[ORM\Entity(repositoryClass: ArtisanCategoryRepository::class)]
class ArtisanCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100)]
    private ?string $name = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $icon = null;

    #[ORM\Column(length: 120, unique: true)]
    private ?string $slug = null;

    /**
     * @var Collection<int, Business>
     */
    #[ORM\OneToMany(targetEntity: Business::class, mappedBy: 'category')]
    private Collection $businesses;

    public function __construct()
    {
        $this->businesses = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    /**
     * @return Collection<int, Business>
     */
    public function getBusinesses(): Collection
    {
        return $this->businesses;
    }

    public function addBusiness(Business $business): static
    {
        if (!$this->businesses->contains($business)) {
            $this->businesses->add($business);
            $business->setCategory($this);
        }

        return $this;
    }

    public function removeBusiness(Business $business): static
    {
        if ($this->businesses->removeElement($business)) {
            if ($business->getCategory() === $this) {
                $business->setCategory(null);
            }
        }

        return $this;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
