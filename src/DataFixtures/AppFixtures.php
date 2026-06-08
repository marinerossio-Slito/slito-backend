<?php

namespace App\DataFixtures;

use App\Entity\Appointment;
use App\Entity\Artisan;
use App\Entity\ArtisanCategory;
use App\Entity\Business;
use App\Entity\CalendarEvent;
use App\Entity\Conversation;
use App\Entity\Customer;
use App\Entity\Document;
use App\Entity\Invoice;
use App\Entity\Message;
use App\Entity\Notification;
use App\Entity\Review;
use App\Entity\Service;
use App\Entity\Subscription;
use App\Entity\User;
use App\Enum\AppointmentStatus;
use App\Enum\CalendarEventType;
use App\Enum\Location;
use App\Enum\ReviewAuthorType;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Faker\Factory;
use Faker\Generator;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Jeu de données de démonstration (étape 9 : « Tests + données de démonstration »).
 *
 * Construit un petit écosystème cohérent et interconnecté : un administrateur,
 * des artisans (certains approuvés avec une fiche complète, d'autres encore en
 * attente de validation), des clients, des catégories et des prestations, des
 * rendez-vous à différents stades de leur cycle de vie, des avis, des
 * conversations, des notifications, un agenda et des abonnements Stripe.
 * De quoi explorer manuellement chaque fonctionnalité de la plateforme et
 * servir de socle stable aux tests fonctionnels (cf. tests/Functional).
 *
 * Tous les comptes de démonstration partagent le mot de passe « password »,
 * haché via le même service que celui utilisé à l'inscription (cf.
 * AuthController) afin de rester crédible vis-à-vis de la sécurité réelle.
 * Le compte administrateur est admin@slito-demo.fr ; les autres emails sont
 * dérivés du prénom et du nom générés (ex : jean.dupont@slito-demo.fr).
 */
class AppFixtures extends Fixture
{
    private const DEMO_PASSWORD = 'password';

    /**
     * Quelques prestations typiques par catégorie : nom, durée (minutes) et
     * fourchette de prix en euros. Chaque entreprise en pioche deux ou trois.
     *
     * @var array<string, list<array{name: string, duration: int, price: array{int, int}}>>
     */
    private const SERVICES_BY_CATEGORY = [
        'Plomberie' => [
            ['name' => 'Réparation de fuite', 'duration' => 60, 'price' => [60, 120]],
            ['name' => 'Débouchage de canalisation', 'duration' => 90, 'price' => [80, 150]],
            ['name' => 'Installation de sanitaires', 'duration' => 180, 'price' => [200, 450]],
        ],
        'Électricité' => [
            ['name' => 'Mise aux normes du tableau électrique', 'duration' => 240, 'price' => [300, 600]],
            ['name' => 'Installation de prises et interrupteurs', 'duration' => 90, 'price' => [90, 180]],
            ['name' => 'Dépannage électrique en urgence', 'duration' => 60, 'price' => [70, 140]],
        ],
        'Menuiserie' => [
            ['name' => 'Pose de parquet', 'duration' => 300, 'price' => [400, 900]],
            ['name' => 'Fabrication de meuble sur mesure', 'duration' => 480, 'price' => [600, 1500]],
            ['name' => 'Réparation de porte ou fenêtre', 'duration' => 90, 'price' => [80, 200]],
        ],
        'Peinture & Décoration' => [
            ['name' => 'Peinture intérieure', 'duration' => 240, 'price' => [250, 600]],
            ['name' => 'Pose de papier peint', 'duration' => 180, 'price' => [200, 450]],
            ['name' => 'Rénovation de façade', 'duration' => 480, 'price' => [800, 2000]],
        ],
        'Jardinage & Paysagisme' => [
            ['name' => 'Tonte et entretien de pelouse', 'duration' => 90, 'price' => [50, 100]],
            ['name' => 'Taille de haies et arbustes', 'duration' => 120, 'price' => [80, 160]],
            ['name' => 'Aménagement paysager', 'duration' => 360, 'price' => [500, 1200]],
        ],
        'Serrurerie' => [
            ['name' => 'Ouverture de porte claquée', 'duration' => 45, 'price' => [80, 150]],
            ['name' => 'Changement de serrure', 'duration' => 90, 'price' => [120, 250]],
            ['name' => 'Installation de porte blindée', 'duration' => 240, 'price' => [800, 1800]],
        ],
        'Coiffure à domicile' => [
            ['name' => 'Coupe et brushing', 'duration' => 60, 'price' => [35, 60]],
            ['name' => 'Coloration', 'duration' => 120, 'price' => [70, 120]],
            ['name' => 'Coiffure pour événement', 'duration' => 90, 'price' => [90, 150]],
        ],
        'Ménage & Repassage' => [
            ['name' => "Ménage complet d'appartement", 'duration' => 180, 'price' => [70, 120]],
            ['name' => 'Repassage à domicile', 'duration' => 120, 'price' => [40, 70]],
            ['name' => 'Nettoyage après travaux', 'duration' => 240, 'price' => [150, 300]],
        ],
    ];

    /**
     * Quelques formulations de nom d'entreprise par catégorie ; « %s » est
     * remplacé par le nom de famille de l'artisan.
     *
     * @var array<string, list<string>>
     */
    private const BUSINESS_NAME_TEMPLATES = [
        'Plomberie' => ['Plomberie %s', 'SARL Plomberie %s', '%s Plomberie & Sanitaires'],
        'Électricité' => ['Électricité %s', '%s Élec Services', 'Atelier Électrique %s'],
        'Menuiserie' => ['Menuiserie %s', 'Atelier Bois %s', '%s Agencement Bois'],
        'Peinture & Décoration' => ['Peinture %s', '%s Déco & Peinture', 'Atelier Couleurs %s'],
        'Jardinage & Paysagisme' => ['Jardins %s', '%s Paysage', 'Les Jardins de %s'],
        'Serrurerie' => ['Serrurerie %s', '%s Sécurité & Serrurerie', 'Dépannage Serrurerie %s'],
        'Coiffure à domicile' => ['Coiffure %s', '%s Coiffure à Domicile', 'Salon Mobile %s'],
        'Ménage & Repassage' => ["%s Services Ménagers", "Net'Maison %s", '%s Ménage & Repassage'],
    ];

    private readonly AsciiSlugger $slugger;
    private Generator $faker;
    private ObjectManager $manager;

    public function __construct(
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        $this->slugger = new AsciiSlugger();
    }

    public function load(ObjectManager $manager): void
    {
        $this->manager = $manager;

        // Recréé (et re-semé) à chaque chargement : la « démo » reste reproductible
        // d'une exécution à l'autre, ce qui est appréciable aussi bien pour explorer
        // les données à la main que pour écrire des tests qui s'appuient dessus.
        $this->faker = Factory::create('fr_FR');
        $this->faker->seed(20260608);

        $admin = $this->createAdmin();
        $categories = $this->createCategories();
        [$approvedArtisans, $pendingArtisans] = $this->createArtisans($categories);
        $customers = $this->createCustomers();

        $this->createSubscriptions($approvedArtisans);
        $this->createFavorites($customers, $approvedArtisans);

        $appointments = $this->createAppointments($customers, $approvedArtisans);
        $this->createReviews($appointments);
        $this->createInvoices($appointments);
        $this->createConversations($customers, $approvedArtisans);
        $this->createCalendarEvents($approvedArtisans, $appointments);
        $this->createNotifications($admin, $approvedArtisans, $pendingArtisans, $customers);
        $this->createDocuments($customers);

        $manager->flush();
    }

    // -----------------------------------------------------------------
    // Comptes & profils
    // -----------------------------------------------------------------

    private function createAdmin(): User
    {
        return $this->createUser('Alice', 'Admin', 'admin@slito-demo.fr', ['ROLE_ADMIN']);
    }

    /**
     * @param list<string> $roles Rôles supplémentaires (ROLE_USER est de toute façon
     *                            garanti par User::getRoles)
     */
    private function createUser(string $firstName, string $lastName, string $email, array $roles = []): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setPhone($this->faker->phoneNumber());
        $user->setRoles($roles);
        $user->setIsVerified(true);
        $user->setPassword($this->passwordHasher->hashPassword($user, self::DEMO_PASSWORD));

        $this->manager->persist($user);

        return $user;
    }

    /**
     * Génère un email plausible « prenom.nom@slito-demo.fr » à partir d'une identité
     * (en retirant les accents pour rester valide) ; les prénoms/noms étant tirés via
     * faker->unique(), l'adresse obtenue est garantie unique sur tout le chargement.
     */
    private function emailFor(string $firstName, string $lastName): string
    {
        $local = (string) $this->slugger->slug($firstName.' '.$lastName, '.')->lower();

        return $local.'@slito-demo.fr';
    }

    /**
     * @return list<ArtisanCategory>
     */
    private function createCategories(): array
    {
        $definitions = [
            ['name' => 'Plomberie', 'icon' => 'droplet'],
            ['name' => 'Électricité', 'icon' => 'bolt'],
            ['name' => 'Menuiserie', 'icon' => 'hammer'],
            ['name' => 'Peinture & Décoration', 'icon' => 'paint-roller'],
            ['name' => 'Jardinage & Paysagisme', 'icon' => 'leaf'],
            ['name' => 'Serrurerie', 'icon' => 'key'],
            ['name' => 'Coiffure à domicile', 'icon' => 'scissors'],
            ['name' => 'Ménage & Repassage', 'icon' => 'spray-can'],
        ];

        $categories = [];
        foreach ($definitions as $definition) {
            $category = new ArtisanCategory();
            $category->setName($definition['name']);
            $category->setIcon($definition['icon']);
            $category->setSlug((string) $this->slugger->slug($definition['name'])->lower());

            $this->manager->persist($category);
            $categories[] = $category;
        }

        return $categories;
    }

    /**
     * @param list<ArtisanCategory> $categories
     *
     * @return array{0: list<Artisan>, 1: list<Artisan>} respectivement les artisans
     *         approuvés (avec une entreprise complète) et ceux en attente de validation
     */
    private function createArtisans(array $categories): array
    {
        $approved = [];
        foreach ($categories as $category) {
            $approved[] = $this->createArtisan($category, true);
        }

        // Quelques candidatures fraîches, encore à examiner par un administrateur
        // (cf. AdminController::approveArtisan) : pas d'entreprise tant qu'elles
        // ne sont pas validées.
        $pending = [];
        for ($i = 0; $i < 3; ++$i) {
            $pending[] = $this->createArtisan(null, false);
        }

        return [$approved, $pending];
    }

    private function createArtisan(?ArtisanCategory $category, bool $approved): Artisan
    {
        $firstName = $this->faker->unique()->firstName();
        $lastName = $this->faker->unique()->lastName();

        $user = $this->createUser($firstName, $lastName, $this->emailFor($firstName, $lastName), ['ROLE_ARTISAN']);

        $artisan = new Artisan();
        $artisan->setSiret($this->faker->numerify('##############'));
        $artisan->setOfficeAddress($this->faker->address());
        $artisan->setIsApproved($approved);
        $artisan->setOwnershipDocument(sprintf('kbis-%s.pdf', (string) $this->slugger->slug($lastName)->lower()));
        $user->setArtisan($artisan);

        $this->manager->persist($artisan);

        if ($approved && null !== $category) {
            // Business::setArtisan gère les deux côtés de la relation bidirectionnelle
            // (cf. son implémentation) ; Artisan::setBusiness, lui, ne le fait pas.
            $this->createBusiness($category, $lastName)->setArtisan($artisan);
        }

        return $artisan;
    }

    // -----------------------------------------------------------------
    // Fiches (entreprises & prestations)
    // -----------------------------------------------------------------

    private function createBusiness(ArtisanCategory $category, string $artisanLastName): Business
    {
        $business = new Business();
        $business->setName($this->businessName($category->getName(), $artisanLastName));
        $business->setHeadline($this->faker->sentence(8));
        $business->setDescription($this->faker->paragraphs(3, true));
        $business->setCoverImage(null);
        $business->setWebsite($this->faker->boolean(50) ? 'https://www.'.$this->faker->domainName() : null);
        $business->setPaymentMethods($this->faker->randomElements(
            ['Carte bancaire', 'Espèces', 'Chèque', 'Virement', 'PayPal'],
            $this->faker->numberBetween(2, 4),
        ));
        $business->setContactNumber($this->faker->phoneNumber());
        $business->setOfficeAddress($this->faker->address());
        $business->setWorkingHours($this->randomWorkingHours());
        $business->setReplyDelay($this->faker->randomElement([
            "Répond généralement en moins d'une heure",
            'Répond généralement en moins de 24 heures',
            'Répond généralement sous 2 à 3 jours',
        ]));
        $business->setCategory($category);

        $this->manager->persist($business);

        foreach ($this->pickServiceTemplates($category->getName()) as $template) {
            $service = new Service();
            $service->setName($template['name']);
            $service->setDescription($this->faker->paragraph(3));
            $service->setDuration($template['duration']);
            $service->setPrice((string) $this->faker->numberBetween($template['price'][0], $template['price'][1]));
            $service->setLocation($this->faker->randomElement(Location::cases()));
            if ($this->faker->boolean(40)) {
                $service->setFaq($this->randomFaq());
            }

            $business->addService($service);
            $this->manager->persist($service);
        }

        return $business;
    }

    private function businessName(string $categoryName, string $lastName): string
    {
        $templates = self::BUSINESS_NAME_TEMPLATES[$categoryName] ?? ['%s'];

        return sprintf($this->faker->randomElement($templates), $lastName);
    }

    /**
     * @return list<array{name: string, duration: int, price: array{int, int}}>
     */
    private function pickServiceTemplates(string $categoryName): array
    {
        $templates = self::SERVICES_BY_CATEGORY[$categoryName] ?? [];

        return $this->faker->randomElements($templates, min(\count($templates), $this->faker->numberBetween(2, 3)));
    }

    /**
     * @return array<string, array{string, string}|null>
     */
    private function randomWorkingHours(): array
    {
        $weekday = ['09:00', '18:00'];

        return [
            'lundi' => $weekday,
            'mardi' => $weekday,
            'mercredi' => $weekday,
            'jeudi' => $weekday,
            'vendredi' => $weekday,
            'samedi' => $this->faker->boolean(70) ? ['09:00', '12:30'] : null,
            'dimanche' => null,
        ];
    }

    /**
     * @return list<array{question: string, answer: string}>
     */
    private function randomFaq(): array
    {
        $pairs = [
            ['question' => 'Intervenez-vous le week-end ?', 'answer' => 'Oui, sur rendez-vous et selon disponibilités.'],
            ['question' => 'Proposez-vous un devis gratuit ?', 'answer' => 'Oui, le premier devis est toujours gratuit et sans engagement.'],
            ['question' => 'Quels moyens de paiement acceptez-vous ?', 'answer' => 'Carte bancaire, espèces, chèque et virement.'],
            ['question' => 'Intervenez-vous en urgence ?', 'answer' => 'Oui, une intervention rapide est possible selon la nature de la demande.'],
        ];

        return $this->faker->randomElements($pairs, $this->faker->numberBetween(1, 3));
    }

    /**
     * @return list<Customer>
     */
    private function createCustomers(int $count = 14): array
    {
        $customers = [];
        for ($i = 0; $i < $count; ++$i) {
            $firstName = $this->faker->unique()->firstName();
            $lastName = $this->faker->unique()->lastName();

            $user = $this->createUser($firstName, $lastName, $this->emailFor($firstName, $lastName));

            $customer = new Customer();
            $customer->setHomeAddress($this->faker->boolean(80) ? $this->faker->address() : null);
            $user->setCustomer($customer);

            $this->manager->persist($customer);
            $customers[] = $customer;
        }

        return $customers;
    }

    // -----------------------------------------------------------------
    // Abonnements & favoris
    // -----------------------------------------------------------------

    /**
     * @param list<Artisan> $artisans Artisans approuvés (avec entreprise)
     */
    private function createSubscriptions(array $artisans): void
    {
        // Une partie des artisans a souscrit un abonnement, à différents stades
        // du cycle de vie Stripe (cf. SubscriptionService::syncFromStripeSubscription).
        $definitions = [
            ['status' => 'active', 'plan' => 'monthly', 'periodEndOffsetDays' => 18],
            ['status' => 'active', 'plan' => 'yearly', 'periodEndOffsetDays' => 200],
            ['status' => 'trialing', 'plan' => 'monthly', 'periodEndOffsetDays' => 5],
            ['status' => 'past_due', 'plan' => 'monthly', 'periodEndOffsetDays' => -3],
            ['status' => 'canceled', 'plan' => 'yearly', 'periodEndOffsetDays' => -40],
        ];

        foreach (array_slice($artisans, 0, \count($definitions)) as $index => $artisan) {
            $definition = $definitions[$index];

            $subscription = new Subscription();
            $subscription->setStripeSubscriptionId('sub_demo_'.$this->faker->bothify('??########'));
            $subscription->setStatus($definition['status']);
            $subscription->setPlan($definition['plan']);
            $subscription->setCurrentPeriodEnd(new \DateTimeImmutable(sprintf('%+d days', $definition['periodEndOffsetDays'])));
            $artisan->setSubscription($subscription);

            // Un Customer Stripe n'est créé qu'à la première démarche d'abonnement
            // (cf. Artisan::$stripeCustomerId) : un artisan abonné en a forcément un.
            $artisan->setStripeCustomerId('cus_demo_'.$this->faker->bothify('??########'));

            $this->manager->persist($subscription);
        }
    }

    /**
     * @param list<Customer> $customers
     * @param list<Artisan>  $artisans Artisans approuvés (avec entreprise)
     */
    private function createFavorites(array $customers, array $artisans): void
    {
        $businesses = array_values(array_filter(array_map(
            static fn (Artisan $artisan): ?Business => $artisan->getBusiness(),
            $artisans,
        )));

        foreach ($customers as $customer) {
            foreach ($this->faker->randomElements($businesses, $this->faker->numberBetween(0, 3)) as $business) {
                $customer->addFavorite($business);
            }
        }
    }

    // -----------------------------------------------------------------
    // Réservations (rendez-vous, avis, factures)
    // -----------------------------------------------------------------

    /**
     * @param list<Customer> $customers
     * @param list<Artisan>  $artisans Artisans approuvés (avec entreprise)
     *
     * @return list<Appointment>
     */
    private function createAppointments(array $customers, array $artisans): array
    {
        // Pondération réaliste : surtout des rendez-vous honorés ou en cours, et
        // une minorité d'annulations — cf. AppointmentStatus.
        $statusPool = [
            ...array_fill(0, 5, AppointmentStatus::COMPLETED),
            ...array_fill(0, 3, AppointmentStatus::CONFIRMED),
            ...array_fill(0, 3, AppointmentStatus::PENDING),
            ...array_fill(0, 1, AppointmentStatus::CANCELLED),
        ];

        $appointments = [];
        foreach ($artisans as $artisan) {
            $business = $artisan->getBusiness();
            $services = $business->getServices()->toArray();

            for ($i = $this->faker->numberBetween(4, 7); $i > 0; --$i) {
                $status = $this->faker->randomElement($statusPool);

                $appointment = new Appointment();
                $appointment->setDateTime($this->randomAppointmentDateTime($status));
                $appointment->setStatus($status);
                $appointment->setLocation($this->faker->randomElement(Location::cases()));
                if ($this->faker->boolean(60)) {
                    $appointment->setCustomerNote($this->faker->sentence(12));
                }
                $appointment->setService($this->faker->randomElement($services));

                $this->faker->randomElement($customers)->addAppointment($appointment);
                $business->addAppointment($appointment);

                $this->manager->persist($appointment);
                $appointments[] = $appointment;
            }
        }

        return $appointments;
    }

    private function randomAppointmentDateTime(AppointmentStatus $status): \DateTimeImmutable
    {
        $daysOffset = match ($status) {
            AppointmentStatus::COMPLETED => $this->faker->numberBetween(-120, -3),
            AppointmentStatus::CANCELLED => $this->faker->numberBetween(-45, 25),
            AppointmentStatus::CONFIRMED => $this->faker->numberBetween(1, 21),
            AppointmentStatus::PENDING => $this->faker->numberBetween(2, 35),
        };

        return (new \DateTimeImmutable(sprintf('%+d days', $daysOffset)))
            ->setTime($this->faker->numberBetween(8, 18), $this->faker->randomElement([0, 15, 30, 45]));
    }

    /**
     * @param list<Appointment> $appointments
     */
    private function createReviews(array $appointments): void
    {
        $customerComments = [
            'Travail soigné et ponctuel, je recommande sans hésiter.',
            'Très professionnel, le résultat est à la hauteur de mes attentes.',
            "Bon contact, mais un léger retard à l'arrivée.",
            'Prestation rapide et efficace, merci !',
            'Un peu plus cher que prévu, mais le travail est impeccable.',
        ];
        $artisanComments = [
            'Client très clair dans sa demande, accès facile au logement.',
            'Échange agréable, je recommande ce client.',
            'Rendez-vous honoré sans aucun problème particulier.',
        ];

        foreach ($appointments as $appointment) {
            if (AppointmentStatus::COMPLETED !== $appointment->getStatus()) {
                continue;
            }

            $customerUser = $appointment->getCustomer()->getUser();
            $artisanUser = $appointment->getBusiness()->getArtisan()->getUser();

            // La quasi-totalité des rendez-vous honorés donnent lieu à un avis client...
            if ($this->faker->boolean(85)) {
                $this->manager->persist($this->buildReview(
                    $appointment,
                    ReviewAuthorType::CUSTOMER,
                    $customerUser,
                    $artisanUser,
                    $this->faker->randomElement($customerComments),
                ));
            }

            // ... et environ un sur deux donne aussi lieu à un avis de l'artisan sur le client.
            if ($this->faker->boolean(45)) {
                $this->manager->persist($this->buildReview(
                    $appointment,
                    ReviewAuthorType::ARTISAN,
                    $artisanUser,
                    $customerUser,
                    $this->faker->randomElement($artisanComments),
                ));
            }
        }
    }

    private function buildReview(Appointment $appointment, ReviewAuthorType $authorType, User $author, User $target, string $comment): Review
    {
        $review = new Review();
        $review->setRating($this->faker->numberBetween(3, 5));
        $review->setPunctualityRating($this->faker->boolean(80) ? $this->faker->numberBetween(3, 5) : null);
        $review->setQualityRating($this->faker->boolean(80) ? $this->faker->numberBetween(3, 5) : null);
        $review->setComment($comment);
        $review->setAuthorType($authorType);
        $review->setAppointment($appointment);
        $review->setAuthor($author);
        $review->setTarget($target);
        // Avis laissé peu après le rendez-vous honoré.
        $review->setCreatedAt($appointment->getDateTime()->modify('+'.$this->faker->numberBetween(1, 5).' days'));

        return $review;
    }

    /**
     * @param list<Appointment> $appointments
     */
    private function createInvoices(array $appointments): void
    {
        $number = 1;
        foreach ($appointments as $appointment) {
            if (AppointmentStatus::COMPLETED !== $appointment->getStatus() || !$this->faker->boolean(70)) {
                continue;
            }

            $issuedAt = $appointment->getDateTime();

            $invoice = new Invoice();
            $invoice->setNumber(sprintf('FAC-%s-%04d', $issuedAt->format('Y'), $number++));
            $invoice->setAmount($appointment->getService()->getPrice());
            $invoice->setIssuedAt($issuedAt);
            $invoice->setCustomer($appointment->getCustomer());
            $invoice->setAppointment($appointment);

            $this->manager->persist($invoice);
        }
    }

    // -----------------------------------------------------------------
    // Messagerie
    // -----------------------------------------------------------------

    /**
     * @param list<Customer> $customers
     * @param list<Artisan>  $artisans Artisans approuvés (avec entreprise)
     */
    private function createConversations(array $customers, array $artisans): void
    {
        $openers = [
            'Bonjour, je souhaiterais avoir un devis pour une intervention rapidement, est-ce possible ?',
            'Bonjour, êtes-vous disponible la semaine prochaine pour un rendez-vous ?',
            "Bonjour, pouvez-vous m'indiquer vos tarifs pour ce type de prestation ?",
            "Bonjour, je vous contacte suite à la recommandation d'un proche.",
        ];
        $replies = [
            'Bonjour, merci pour votre message ! Je peux vous proposer un créneau dès cette semaine.',
            "Bonjour, oui tout à fait, dites-m'en un peu plus sur votre besoin.",
            'Bonjour, je reviens vers vous avec un devis dans la journée.',
            'Bonjour, je suis disponible jeudi en fin de matinée, cela vous conviendrait-il ?',
        ];
        $followUps = [
            'Parfait, merci beaucoup, à bientôt !',
            'Très bien, je confirme le rendez-vous de mon côté.',
            "D'accord, merci pour votre réactivité.",
            'Entendu, je vous recontacte si besoin.',
        ];

        foreach ($artisans as $artisan) {
            $business = $artisan->getBusiness();
            $artisanUser = $artisan->getUser();

            foreach ($this->faker->randomElements($customers, $this->faker->numberBetween(2, 4)) as $customer) {
                $createdAt = new \DateTimeImmutable(sprintf('-%d days', $this->faker->numberBetween(1, 60)));

                $conversation = new Conversation();
                $conversation->setCustomer($customer);
                $conversation->setBusiness($business);
                $conversation->setIsBlocked(false);
                $conversation->setCreatedAt($createdAt);

                $exchange = [
                    [$customer->getUser(), $this->faker->randomElement($openers)],
                    [$artisanUser, $this->faker->randomElement($replies)],
                ];
                if ($this->faker->boolean(60)) {
                    $exchange[] = [$customer->getUser(), $this->faker->randomElement($followUps)];
                }

                $sentAt = $createdAt;
                foreach ($exchange as $index => [$sender, $content]) {
                    $sentAt = $sentAt->modify('+'.$this->faker->numberBetween(10, 240).' minutes');
                    $isLast = $index === \count($exchange) - 1;

                    $message = new Message();
                    $message->setContent($content);
                    $message->setSentAt($sentAt);
                    // Tout est lu, sauf parfois le tout dernier message de l'échange.
                    $message->setIsRead(!$isLast || $this->faker->boolean(60));
                    $message->setSender($sender);
                    $conversation->addMessage($message);

                    $this->manager->persist($message);
                }

                $this->manager->persist($conversation);
            }
        }
    }

    // -----------------------------------------------------------------
    // Agenda
    // -----------------------------------------------------------------

    /**
     * @param list<Artisan>     $artisans Artisans approuvés (avec entreprise)
     * @param list<Appointment> $appointments
     */
    private function createCalendarEvents(array $artisans, array $appointments): void
    {
        $appointmentsByArtisan = [];
        foreach ($appointments as $appointment) {
            if (\in_array($appointment->getStatus(), [AppointmentStatus::CONFIRMED, AppointmentStatus::COMPLETED], true)) {
                $appointmentsByArtisan[spl_object_id($appointment->getBusiness()->getArtisan())][] = $appointment;
            }
        }

        $unavailabilityTitles = ['Congés', 'Formation professionnelle', 'Salon professionnel', 'Rendez-vous personnel'];

        foreach ($artisans as $artisan) {
            // Un événement d'agenda par rendez-vous confirmé ou honoré : matérialise
            // le créneau bloqué dans le planning de l'artisan.
            foreach ($appointmentsByArtisan[spl_object_id($artisan)] ?? [] as $appointment) {
                $start = $appointment->getDateTime();
                $service = $appointment->getService();

                $event = new CalendarEvent();
                $event->setTitle(sprintf('RDV - %s', $service->getName()));
                $event->setDescription(sprintf('Rendez-vous avec %s', (string) $appointment->getCustomer()));
                $event->setStartDate($start);
                $event->setEndDate($start->modify('+'.$service->getDuration().' minutes'));
                $event->setType(CalendarEventType::APPOINTMENT);
                $event->setIsAvailability(false);
                $artisan->addCalendarEvent($event);

                $this->manager->persist($event);
            }

            // Et quelques indisponibilités personnelles déclarées par l'artisan
            // (congés, formation...), qui bloquent ces créneaux à la réservation.
            for ($i = $this->faker->numberBetween(1, 3); $i > 0; --$i) {
                $start = (new \DateTimeImmutable(sprintf('+%d days', $this->faker->numberBetween(1, 45))))
                    ->setTime($this->faker->numberBetween(8, 14), 0);

                $event = new CalendarEvent();
                $event->setTitle($this->faker->randomElement($unavailabilityTitles));
                $event->setDescription($this->faker->boolean(50) ? $this->faker->sentence(10) : null);
                $event->setStartDate($start);
                $event->setEndDate($start->modify('+'.$this->faker->numberBetween(4, 72).' hours'));
                $event->setType(CalendarEventType::PERSONAL);
                $event->setIsAvailability(true);
                $artisan->addCalendarEvent($event);

                $this->manager->persist($event);
            }
        }
    }

    // -----------------------------------------------------------------
    // Notifications & documents
    // -----------------------------------------------------------------

    /**
     * @param list<Artisan>  $approvedArtisans
     * @param list<Artisan>  $pendingArtisans
     * @param list<Customer> $customers
     */
    private function createNotifications(User $admin, array $approvedArtisans, array $pendingArtisans, array $customers): void
    {
        // L'administrateur est notifié des candidatures fraîchement déposées,
        // qu'il devra examiner (cf. AdminController::approveArtisan).
        foreach ($pendingArtisans as $artisan) {
            $this->buildNotification(
                $admin,
                'artisan_application_received',
                sprintf("%s a soumis une demande d'inscription en tant qu'artisan, justificatif à l'appui.", (string) $artisan),
                $this->faker->boolean(35),
            );
        }

        // Les artisans sont notifiés de l'activité sur leur fiche : nouvelles
        // demandes de rendez-vous, avis laissés par leurs clients...
        foreach ([...$approvedArtisans, ...$pendingArtisans] as $artisan) {
            $artisanUser = $artisan->getUser();

            $this->buildNotification(
                $artisanUser,
                'appointment_requested',
                'Un client vient de demander un nouveau rendez-vous. Connectez-vous pour le confirmer ou le décliner.',
                $this->faker->boolean(55),
            );
            if ($this->faker->boolean(60)) {
                $this->buildNotification(
                    $artisanUser,
                    'new_review',
                    'Un client a laissé un avis sur votre prestation. Découvrez son retour depuis votre tableau de bord.',
                    $this->faker->boolean(50),
                );
            }
        }

        // Les clients sont notifiés du suivi de leurs réservations et de leurs échanges.
        foreach ($customers as $customer) {
            $user = $customer->getUser();

            $this->buildNotification(
                $user,
                'appointment_status_changed',
                "Le statut d'un de vos rendez-vous vient d'être mis à jour par l'artisan.",
                $this->faker->boolean(65),
            );
            if ($this->faker->boolean(50)) {
                $this->buildNotification(
                    $user,
                    'new_message',
                    'Vous avez reçu un nouveau message dans votre messagerie.',
                    $this->faker->boolean(40),
                );
            }
        }
    }

    private function buildNotification(User $user, string $type, string $content, bool $isRead): void
    {
        $notification = new Notification();
        $notification->setType($type);
        $notification->setContent($content);
        $notification->setIsRead($isRead);
        $notification->setCreatedAt(new \DateTimeImmutable(sprintf('-%d hours', $this->faker->numberBetween(1, 720))));
        $user->addNotification($notification);

        $this->manager->persist($notification);
    }

    /**
     * @param list<Customer> $customers
     */
    private function createDocuments(array $customers): void
    {
        $sampleFiles = [
            ['name' => 'carte-identite.pdf', 'mime' => 'application/pdf'],
            ['name' => 'justificatif-domicile.pdf', 'mime' => 'application/pdf'],
            ['name' => 'passeport.jpg', 'mime' => 'image/jpeg'],
        ];

        // Une partie des clients a déjà transmis un justificatif (vérification de compte).
        $picked = $this->faker->randomElements($customers, (int) ceil(\count($customers) / 3));
        foreach ($picked as $customer) {
            $sample = $this->faker->randomElement($sampleFiles);

            $document = new Document();
            $document->setOriginalName($sample['name']);
            $document->setFilename(sprintf('%s-%s', bin2hex(random_bytes(8)), $sample['name']));
            $document->setMimeType($sample['mime']);
            $document->setUploadedAt(new \DateTimeImmutable(sprintf('-%d days', $this->faker->numberBetween(2, 90))));
            $customer->addDocument($document);

            $this->manager->persist($document);
        }
    }
}
