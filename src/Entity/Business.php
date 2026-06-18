<?php

namespace App\Entity;

use App\Repository\BusinessRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Fiche entreprise d'un artisan : présentation publique consultée par les clients.
 */
#[ORM\Entity(repositoryClass: BusinessRepository::class)]
class Business
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $headline = null;

    /**
     * Dénomination libre choisie par l'artisan (ex : "Ébéniste d'art",
     * "Spécialiste piscines enterrées"). Complète la catégorie principale et
     * est prise en compte dans la recherche par mots-clés.
     */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $specialty = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $coverImage = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $website = null;

    /**
     * @var list<string>|null Moyens de paiement acceptés
     */
    #[ORM\Column(nullable: true)]
    private ?array $paymentMethods = null;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $contactNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $officeAddress = null;

    /**
     * @var array|null Horaires d'ouverture (jour => créneaux)
     */
    #[ORM\Column(nullable: true)]
    private ?array $workingHours = null;

    /**
     * Délai de réponse moyen affiché sur la fiche (ex : "moins de 24h").
     */
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $replyDelay = null;

    #[ORM\ManyToOne(targetEntity: ArtisanCategory::class, inversedBy: 'businesses')]
    private ?ArtisanCategory $category = null;

    #[ORM\OneToOne(mappedBy: 'business', targetEntity: Artisan::class)]
    private ?Artisan $artisan = null;

    /**
     * @var Collection<int, Service>
     */
    #[ORM\OneToMany(targetEntity: Service::class, mappedBy: 'business', orphanRemoval: true)]
    private Collection $services;

    /**
     * @var Collection<int, Appointment>
     */
    #[ORM\OneToMany(targetEntity: Appointment::class, mappedBy: 'business')]
    private Collection $appointments;

    /**
     * @var Collection<int, Customer> Clients ayant ajouté cette entreprise à leurs favoris
     */
    #[ORM\ManyToMany(targetEntity: Customer::class, mappedBy: 'favorites')]
    private Collection $favoritedBy;

    public function __construct()
    {
        $this->services = new ArrayCollection();
        $this->appointments = new ArrayCollection();
        $this->favoritedBy = new ArrayCollection();
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

    public function getHeadline(): ?string
    {
        return $this->headline;
    }

    public function setHeadline(?string $headline): static
    {
        $this->headline = $headline;

        return $this;
    }

    public function getSpecialty(): ?string
    {
        return $this->specialty;
    }

    public function setSpecialty(?string $specialty): static
    {
        $this->specialty = $specialty;

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

    public function getCoverImage(): ?string
    {
        return $this->coverImage;
    }

    public function setCoverImage(?string $coverImage): static
    {
        $this->coverImage = $coverImage;

        return $this;
    }

    public function getWebsite(): ?string
    {
        return $this->website;
    }

    public function setWebsite(?string $website): static
    {
        $this->website = $website;

        return $this;
    }

    public function getPaymentMethods(): ?array
    {
        return $this->paymentMethods;
    }

    public function setPaymentMethods(?array $paymentMethods): static
    {
        $this->paymentMethods = $paymentMethods;

        return $this;
    }

    public function getContactNumber(): ?string
    {
        return $this->contactNumber;
    }

    public function setContactNumber(?string $contactNumber): static
    {
        $this->contactNumber = $contactNumber;

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

    public function getWorkingHours(): ?array
    {
        return $this->workingHours;
    }

    public function setWorkingHours(?array $workingHours): static
    {
        $this->workingHours = $workingHours;

        return $this;
    }

    public function getReplyDelay(): ?string
    {
        return $this->replyDelay;
    }

    public function setReplyDelay(?string $replyDelay): static
    {
        $this->replyDelay = $replyDelay;

        return $this;
    }

    public function getCategory(): ?ArtisanCategory
    {
        return $this->category;
    }

    public function setCategory(?ArtisanCategory $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function getArtisan(): ?Artisan
    {
        return $this->artisan;
    }

    public function setArtisan(?Artisan $artisan): static
    {
        // unset the owning side of the relation if necessary
        if ($artisan === null && $this->artisan !== null) {
            $this->artisan->setBusiness(null);
        }

        // set the owning side of the relation if necessary
        if ($artisan !== null && $artisan->getBusiness() !== $this) {
            $artisan->setBusiness($this);
        }

        $this->artisan = $artisan;

        return $this;
    }

    /**
     * @return Collection<int, Service>
     */
    public function getServices(): Collection
    {
        return $this->services;
    }

    public function addService(Service $service): static
    {
        if (!$this->services->contains($service)) {
            $this->services->add($service);
            $service->setBusiness($this);
        }

        return $this;
    }

    public function removeService(Service $service): static
    {
        if ($this->services->removeElement($service)) {
            if ($service->getBusiness() === $this) {
                $service->setBusiness(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Appointment>
     */
    public function getAppointments(): Collection
    {
        return $this->appointments;
    }

    public function addAppointment(Appointment $appointment): static
    {
        if (!$this->appointments->contains($appointment)) {
            $this->appointments->add($appointment);
            $appointment->setBusiness($this);
        }

        return $this;
    }

    public function removeAppointment(Appointment $appointment): static
    {
        if ($this->appointments->removeElement($appointment)) {
            if ($appointment->getBusiness() === $this) {
                $appointment->setBusiness(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, Customer>
     */
    public function getFavoritedBy(): Collection
    {
        return $this->favoritedBy;
    }

    public function __toString(): string
    {
        return (string) $this->name;
    }
}
