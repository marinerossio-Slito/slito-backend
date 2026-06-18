<?php

namespace App\Command;

use App\Entity\Appointment;
use App\Entity\Artisan;
use App\Entity\ArtisanCategory;
use App\Entity\Business;
use App\Entity\CalendarEvent;
use App\Entity\Conversation;
use App\Entity\Customer;
use App\Entity\Invoice;
use App\Entity\Message;
use App\Entity\Review;
use App\Entity\Service;
use App\Entity\User;
use App\Enum\AppointmentStatus;
use App\Enum\CalendarEventType;
use App\Enum\Location;
use App\Enum\ReviewAuthorType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cree un petit jeu de donnees de demonstration (compte artisan avec une
 * fiche entreprise approuvee + compte client), utile pour faire visiter la
 * demo en ligne sans avoir a s'inscrire.
 *
 * En plus des comptes, un historique d'activite est simule sur le compte
 * artisan : plusieurs rendez-vous passes et a venir, des factures (chiffre
 * d'affaires) et des avis clients (note moyenne), afin que le tableau de bord
 * ne soit pas vide lors d'une demo.
 *
 * Idempotent : si le compte artisan.demo@slito-demo.fr existe deja, les
 * comptes ne sont pas recrees ; si l'entreprise a deja des rendez-vous,
 * l'historique n'est pas regenere. Appelee depuis docker/entrypoint.sh a
 * chaque demarrage du conteneur (sans danger de doublons sur les redemarrages).
 */
#[AsCommand(name: 'app:seed-demo', description: 'Cree des comptes de demonstration (artisan + client) et un historique d\'activite si absents')]
class SeedDemoCommand extends Command
{
    public const DEMO_PASSWORD = 'DemoSlito2026';
    public const ARTISAN_EMAIL = 'artisan.demo@slito-demo.fr';
    public const CUSTOMER_EMAIL = 'client.demo@slito-demo.fr';

    /** Photo de couverture de demo (menuisier au travail, Unsplash). */
    public const DEMO_COVER_IMAGE = 'https://images.unsplash.com/photo-1601058268499-e52658b8bb88?w=1200&q=80';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Catalogue de metiers : on s'assure que toutes les categories existent
        // (cree les manquantes). Cela alimente l'autocompletion de la recherche
        // meme si une seule categorie a une entreprise de demo.
        [$categoryMap, $categoriesCreated] = $this->ensureCategories();
        $categoriesRemoved = $this->removeObsoleteCategories();

        $userRepository = $this->entityManager->getRepository(User::class);
        $artisanUser = $userRepository->findOneBy(['email' => self::ARTISAN_EMAIL]);

        $accountsJustCreated = false;
        if (null === $artisanUser) {
            $artisanUser = $this->createDemoAccounts($categoryMap['menuiserie']);
            $accountsJustCreated = true;
        }

        $business = $artisanUser->getArtisan()?->getBusiness();

        $historyJustSeeded = false;
        if (null !== $business) {
            $hasHistory = !$accountsJustCreated
                && $this->entityManager->getRepository(Appointment::class)->count(['business' => $business]) > 0;

            if (!$hasHistory) {
                $this->seedActivity($artisanUser, $business);
                $historyJustSeeded = true;
            }
        }

        // Backfill : pour les bases de demo creees avant l'ajout de la
        // messagerie (historique deja present mais aucune conversation), on
        // ajoute la conversation de demo. Sur une base neuve, seedActivity s'en
        // est deja charge : ce bloc est alors ignore (garde ci-dessous).
        $conversationJustSeeded = false;
        if (null !== $business && !$accountsJustCreated && !$historyJustSeeded) {
            $hasConversation = $this->entityManager->getRepository(Conversation::class)->count(['business' => $business]) > 0;

            if (!$hasConversation) {
                $mainCustomer = $this->entityManager->getRepository(User::class)
                    ->findOneBy(['email' => self::CUSTOMER_EMAIL])?->getCustomer();

                if (null !== $mainCustomer) {
                    $this->seedConversation($artisanUser, $business, $mainCustomer);
                    $conversationJustSeeded = true;
                }
            }
        }

        // Backfill : ajoute une photo de couverture de demo si l'entreprise
        // n'en a pas encore (bases de demo creees avant l'ajout de ce champ).
        // Sur une base neuve, createDemoAccounts l'a deja definie.
        $coverJustSeeded = false;
        if (null !== $business && null === $business->getCoverImage()) {
            $business->setCoverImage(self::DEMO_COVER_IMAGE);
            $coverJustSeeded = true;
        }

        if (!$accountsJustCreated && !$historyJustSeeded && !$conversationJustSeeded && !$coverJustSeeded && 0 === $categoriesCreated && 0 === $categoriesRemoved) {
            $io->writeln('==> Comptes, historique, messagerie, photo et categories de demo deja a jour, rien a faire.');

            return Command::SUCCESS;
        }

        $this->entityManager->flush();

        $io->success(sprintf(
            "Demo prete :\n- Artisan : %s\n- Client  : %s\nMot de passe (tous les comptes) : %s%s%s%s%s%s",
            self::ARTISAN_EMAIL,
            self::CUSTOMER_EMAIL,
            self::DEMO_PASSWORD,
            $historyJustSeeded ? "\nHistorique d'activite ajoute (rendez-vous, factures, avis)." : '',
            $conversationJustSeeded ? "\nConversation de demo ajoutee a la messagerie." : '',
            $coverJustSeeded ? "\nPhoto de couverture de demo ajoutee." : '',
            $categoriesCreated > 0 ? sprintf("\n%d categorie(s) de metier ajoutee(s) au catalogue.", $categoriesCreated) : '',
            $categoriesRemoved > 0 ? sprintf("\n%d categorie(s) hors batiment retiree(s) du catalogue.", $categoriesRemoved) : '',
        ));

        return Command::SUCCESS;
    }

    /**
     * Cree le compte artisan (fausse entreprise, deja approuvee) avec sa fiche
     * entreprise et ses prestations, ainsi qu'un compte client de demo.
     */
    private function createDemoAccounts(ArtisanCategory $category): User
    {
        // --- Compte artisan (fausse entreprise, deja approuvee) ---
        $artisanUser = new User();
        $artisanUser->setEmail(self::ARTISAN_EMAIL);
        $artisanUser->setFirstName('Camille');
        $artisanUser->setLastName('Bernard');
        $artisanUser->setPhone('06 00 00 00 01');
        $artisanUser->setRoles(['ROLE_ARTISAN']);
        $artisanUser->setIsVerified(true);
        $artisanUser->setPassword($this->passwordHasher->hashPassword($artisanUser, self::DEMO_PASSWORD));
        $this->entityManager->persist($artisanUser);

        $artisan = new Artisan();
        $artisan->setSiret('12345678900014');
        $artisan->setOfficeAddress('12 rue de la Demonstration, 75011 Paris');
        $artisan->setIsApproved(true);
        $artisan->setOwnershipDocument('kbis-demo.pdf');
        $artisanUser->setArtisan($artisan);
        $this->entityManager->persist($artisan);

        $business = new Business();
        $business->setName('Atelier Bernard - Menuiserie');
        $business->setHeadline('Menuiserie sur mesure, du devis a la pose');
        $business->setCoverImage(self::DEMO_COVER_IMAGE);
        $business->setDescription(
            "Entreprise de demonstration creee pour presenter Slito : agencement interieur, ".
            "pose de parquet et fabrication de meubles sur mesure. Intervient sur Paris et sa proche banlieue."
        );
        $business->setPaymentMethods(['Carte bancaire', 'Especes', 'Virement']);
        $business->setContactNumber('01 23 45 67 89');
        $business->setOfficeAddress('12 rue de la Demonstration, 75011 Paris');
        $business->setWorkingHours([
            'lundi' => ['09:00', '18:00'],
            'mardi' => ['09:00', '18:00'],
            'mercredi' => ['09:00', '18:00'],
            'jeudi' => ['09:00', '18:00'],
            'vendredi' => ['09:00', '18:00'],
            'samedi' => ['09:00', '12:30'],
            'dimanche' => null,
        ]);
        $business->setReplyDelay('Repond generalement en moins de 24 heures');
        $business->setCategory($category);
        $business->setArtisan($artisan);
        $this->entityManager->persist($business);

        $services = [
            ['name' => 'Pose de parquet', 'description' => 'Pose de parquet flottant ou colle, toutes essences.', 'duration' => 300, 'price' => '650.00', 'location' => Location::HOME],
            ['name' => 'Fabrication de meuble sur mesure', 'description' => "Conception et fabrication de meubles sur mesure adaptes a votre interieur.", 'duration' => 480, 'price' => '900.00', 'location' => Location::WORKSHOP],
            ['name' => 'Reparation de porte ou fenetre', 'description' => 'Reparation, ajustement ou remplacement de portes et fenetres en bois.', 'duration' => 90, 'price' => '120.00', 'location' => Location::HOME],
        ];
        foreach ($services as $definition) {
            $service = new Service();
            $service->setName($definition['name']);
            $service->setDescription($definition['description']);
            $service->setDuration($definition['duration']);
            $service->setPrice($definition['price']);
            $service->setLocation($definition['location']);
            $business->addService($service);
            $this->entityManager->persist($service);
        }

        // --- Compte client de demo ---
        $customerUser = new User();
        $customerUser->setEmail(self::CUSTOMER_EMAIL);
        $customerUser->setFirstName('Lea');
        $customerUser->setLastName('Martin');
        $customerUser->setPhone('06 00 00 00 02');
        $customerUser->setRoles([]);
        $customerUser->setIsVerified(true);
        $customerUser->setPassword($this->passwordHasher->hashPassword($customerUser, self::DEMO_PASSWORD));
        $this->entityManager->persist($customerUser);

        $customer = new Customer();
        $customer->setHomeAddress('5 avenue des Tests, 75012 Paris');
        $customerUser->setCustomer($customer);
        $this->entityManager->persist($customer);

        return $artisanUser;
    }

    /**
     * Simule un historique d'activite sur le compte artisan : rendez-vous
     * passes (termines, avec facture et avis), un rendez-vous annule, des
     * rendez-vous a venir (confirmes et en attente) ainsi qu'une
     * indisponibilite dans l'agenda.
     */
    private function seedActivity(User $artisanUser, Business $business): void
    {
        [$lea, $thomas, $sophie, $nicolas, $manon] = $this->ensureDemoCustomers();

        $parquet = $this->findServiceByName($business, 'Pose de parquet');
        $meuble = $this->findServiceByName($business, 'Fabrication de meuble sur mesure');
        $reparation = $this->findServiceByName($business, 'Reparation de porte ou fenetre');

        // Pondere vers des rendez-vous termines (historique) avec quelques
        // rendez-vous a venir (agenda) pour donner l'impression d'une activite reelle.
        $appointmentsData = [
            ['offset' => -58, 'hour' => 10, 'minute' => 0, 'service' => $parquet, 'customer' => $lea, 'location' => Location::HOME, 'status' => AppointmentStatus::COMPLETED, 'note' => 'Pose de parquet dans le salon, environ 25m2.', 'rating' => 5, 'comment' => "Travail soigne et ponctuel, le parquet est magnifique. Je recommande !"],
            ['offset' => -50, 'hour' => 14, 'minute' => 0, 'service' => $meuble, 'customer' => $thomas, 'location' => Location::WORKSHOP, 'status' => AppointmentStatus::COMPLETED, 'note' => 'Bibliotheque sur mesure pour le salon.', 'rating' => 5, 'comment' => "Tres professionnel, le meuble correspond exactement a ce qu'on avait imagine."],
            ['offset' => -41, 'hour' => 9, 'minute' => 30, 'service' => $reparation, 'customer' => $sophie, 'location' => Location::HOME, 'status' => AppointmentStatus::COMPLETED, 'note' => "Porte d'entree qui coince, besoin d'un reglage.", 'rating' => 4, 'comment' => 'Bon contact, intervention rapide et efficace.'],
            ['offset' => -33, 'hour' => 15, 'minute' => 0, 'service' => $parquet, 'customer' => $nicolas, 'location' => Location::HOME, 'status' => AppointmentStatus::COMPLETED, 'note' => null, 'rating' => 5, 'comment' => 'Resultat impeccable, je suis ravi du rendu final.'],
            ['offset' => -22, 'hour' => 11, 'minute' => 0, 'service' => $meuble, 'customer' => $manon, 'location' => Location::WORKSHOP, 'status' => AppointmentStatus::COMPLETED, 'note' => 'Table basse sur mesure en chene.', 'rating' => 4, 'comment' => 'Tres bon travail, livraison un peu plus tardive que prevu mais le resultat est superbe.'],
            ['offset' => -14, 'hour' => 16, 'minute' => 30, 'service' => $reparation, 'customer' => $lea, 'location' => Location::HOME, 'status' => AppointmentStatus::COMPLETED, 'note' => null, 'rating' => 5, 'comment' => 'Rapide, efficace, merci !'],
            ['offset' => -6, 'hour' => 10, 'minute' => 0, 'service' => $parquet, 'customer' => $thomas, 'location' => Location::HOME, 'status' => AppointmentStatus::COMPLETED, 'note' => 'Pose de parquet dans deux chambres.', 'rating' => 5, 'comment' => 'Un grand merci, le travail est vraiment soigne.'],
            ['offset' => -20, 'hour' => 14, 'minute' => 0, 'service' => $meuble, 'customer' => $sophie, 'location' => Location::WORKSHOP, 'status' => AppointmentStatus::CANCELLED, 'note' => 'Finalement annule, changement de projet.', 'rating' => null, 'comment' => null],
            ['offset' => 4, 'hour' => 9, 'minute' => 0, 'service' => $parquet, 'customer' => $manon, 'location' => Location::HOME, 'status' => AppointmentStatus::CONFIRMED, 'note' => 'Pose de parquet dans la chambre, environ 15m2.', 'rating' => null, 'comment' => null],
            ['offset' => 11, 'hour' => 14, 'minute' => 0, 'service' => $meuble, 'customer' => $nicolas, 'location' => Location::WORKSHOP, 'status' => AppointmentStatus::CONFIRMED, 'note' => 'Creation d\'un meuble TV sur mesure.', 'rating' => null, 'comment' => null],
            ['offset' => 2, 'hour' => 8, 'minute' => 30, 'service' => $reparation, 'customer' => $lea, 'location' => Location::HOME, 'status' => AppointmentStatus::PENDING, 'note' => 'Fenetre du salon qui ne ferme plus correctement, intervention rapide si possible.', 'rating' => null, 'comment' => null],
        ];

        // Quelques avis reciproques de l'artisan sur ses clients (indices dans
        // $appointmentsData), pour le realisme de l'historique.
        $artisanReviews = [
            0 => 'Client tres clair dans sa demande, acces facile au logement.',
            3 => 'Echange agreable, je recommande ce client.',
            6 => 'Rendez-vous honore sans aucun probleme particulier.',
        ];

        $invoiceSeq = 1;
        foreach ($appointmentsData as $index => $data) {
            $dateTime = (new \DateTimeImmutable())
                ->modify(sprintf('%+d days', $data['offset']))
                ->setTime($data['hour'], $data['minute']);

            $appointment = new Appointment();
            $appointment->setDateTime($dateTime);
            $appointment->setStatus($data['status']);
            $appointment->setLocation($data['location']);
            $appointment->setCustomerNote($data['note']);
            $appointment->setService($data['service']);
            $data['customer']->addAppointment($appointment);
            $business->addAppointment($appointment);
            $this->entityManager->persist($appointment);

            if (AppointmentStatus::COMPLETED !== $data['status']) {
                continue;
            }

            // Facture correspondant a la prestation effectuee (alimente le
            // chiffre d'affaires affiche sur le tableau de bord).
            $invoice = new Invoice();
            $invoice->setNumber(sprintf('FAC-DEMO-%s-%04d', $dateTime->format('Y'), $invoiceSeq++));
            $invoice->setAmount($data['service']->getPrice());
            $invoice->setIssuedAt($dateTime);
            $invoice->setCustomer($data['customer']);
            $invoice->setAppointment($appointment);
            $this->entityManager->persist($invoice);

            // Avis du client sur l'artisan (alimente la note moyenne).
            $review = new Review();
            $review->setRating($data['rating']);
            $review->setPunctualityRating($data['rating']);
            $review->setQualityRating($data['rating']);
            $review->setComment($data['comment']);
            $review->setAuthorType(ReviewAuthorType::CUSTOMER);
            $review->setAppointment($appointment);
            $review->setAuthor($data['customer']->getUser());
            $review->setTarget($artisanUser);
            $review->setCreatedAt($dateTime->modify('+2 days'));
            $this->entityManager->persist($review);

            // Et, pour certains rendez-vous, un avis de l'artisan sur le client.
            if (isset($artisanReviews[$index])) {
                $artisanReview = new Review();
                $artisanReview->setRating(5);
                $artisanReview->setComment($artisanReviews[$index]);
                $artisanReview->setAuthorType(ReviewAuthorType::ARTISAN);
                $artisanReview->setAppointment($appointment);
                $artisanReview->setAuthor($artisanUser);
                $artisanReview->setTarget($data['customer']->getUser());
                $artisanReview->setCreatedAt($dateTime->modify('+2 days'));
                $this->entityManager->persist($artisanReview);
            }
        }

        // Une indisponibilite dans l'agenda, pour un planning qui semble vivant.
        $closure = new CalendarEvent();
        $closure->setTitle('Fermeture exceptionnelle - inventaire atelier');
        $closure->setDescription("Atelier ferme pour l'inventaire annuel, pas de rendez-vous possible ce jour-la.");
        $closure->setStartDate((new \DateTimeImmutable())->modify('+18 days')->setTime(9, 0));
        $closure->setEndDate((new \DateTimeImmutable())->modify('+18 days')->setTime(17, 0));
        $closure->setType(CalendarEventType::PERSONAL);
        $closure->setIsAvailability(true);
        $artisanUser->getArtisan()?->addCalendarEvent($closure);
        $this->entityManager->persist($closure);

        // Une conversation client <-> artisan, pour que la messagerie ne soit
        // pas vide lors de la demo. Le dernier message (cote client) reste non
        // lu : l'artisan voit ainsi une notification de message non lu.
        $this->seedConversation($artisanUser, $business, $lea);
    }

    /**
     * Cree une conversation de demonstration entre un client et l'entreprise,
     * avec un echange realiste de quelques messages.
     */
    private function seedConversation(User $artisanUser, Business $business, Customer $customer): void
    {
        $createdAt = (new \DateTimeImmutable())->modify('-16 days')->setTime(18, 12);

        $conversation = new Conversation();
        $conversation->setCustomer($customer);
        $conversation->setBusiness($business);
        $conversation->setIsBlocked(false);
        $conversation->setCreatedAt($createdAt);

        // [expediteur, contenu]. Le dernier message vient du client et restera
        // non lu (cf. plus bas) pour simuler une demande en attente de reponse.
        $exchange = [
            [$customer->getUser(), "Bonjour, suite a la pose du parquet je suis ravie du resultat ! La fenetre du salon ferme toujours mal, seriez-vous disponible pour y jeter un oeil ?"],
            [$artisanUser, "Bonjour Lea, merci beaucoup pour votre retour ! Bien sur, je peux passer la semaine prochaine. Quel jour vous conviendrait le mieux ?"],
            [$customer->getUser(), "Mardi matin serait parfait si possible. Merci beaucoup !"],
        ];

        $sentAt = $createdAt;
        $lastIndex = \count($exchange) - 1;
        foreach ($exchange as $index => [$sender, $content]) {
            $sentAt = $sentAt->modify(sprintf('+%d minutes', 35 * ($index + 1)));

            $message = new Message();
            $message->setContent($content);
            $message->setSentAt($sentAt);
            // Tout est lu sauf le dernier message (du client) : l'artisan a donc
            // un message non lu a traiter.
            $message->setIsRead($index !== $lastIndex);
            $message->setSender($sender);
            $conversation->addMessage($message);
            $this->entityManager->persist($message);
        }

        $this->entityManager->persist($conversation);
    }

    /**
     * Cree (si absents) quatre clients de demo supplementaires, et renvoie un
     * tableau de 5 Customer : le client de demo principal puis ces quatre
     * nouveaux, dans cet ordre.
     *
     * @return list<Customer>
     */
    private function ensureDemoCustomers(): array
    {
        $userRepository = $this->entityManager->getRepository(User::class);

        /** @var Customer $mainCustomer Toujours present : cree par createDemoAccounts(). */
        $mainCustomer = $userRepository->findOneBy(['email' => self::CUSTOMER_EMAIL])->getCustomer();

        $definitions = [
            ['email' => 'thomas.petit@slito-demo.fr', 'firstName' => 'Thomas', 'lastName' => 'Petit', 'phone' => '06 00 00 00 03', 'address' => '8 rue de la Roquette, 75011 Paris'],
            ['email' => 'sophie.durand@slito-demo.fr', 'firstName' => 'Sophie', 'lastName' => 'Durand', 'phone' => '06 00 00 00 04', 'address' => '20 boulevard Voltaire, 75011 Paris'],
            ['email' => 'nicolas.lefebvre@slito-demo.fr', 'firstName' => 'Nicolas', 'lastName' => 'Lefebvre', 'phone' => '06 00 00 00 05', 'address' => '15 rue du Faubourg Saint-Antoine, 75012 Paris'],
            ['email' => 'manon.girard@slito-demo.fr', 'firstName' => 'Manon', 'lastName' => 'Girard', 'phone' => '06 00 00 00 06', 'address' => '45 avenue Daumesnil, 75012 Paris'],
        ];

        $customers = [$mainCustomer];
        foreach ($definitions as $definition) {
            $user = $userRepository->findOneBy(['email' => $definition['email']]);
            if (null === $user) {
                $user = new User();
                $user->setEmail($definition['email']);
                $user->setFirstName($definition['firstName']);
                $user->setLastName($definition['lastName']);
                $user->setPhone($definition['phone']);
                $user->setRoles([]);
                $user->setIsVerified(true);
                $user->setPassword($this->passwordHasher->hashPassword($user, self::DEMO_PASSWORD));
                $this->entityManager->persist($user);

                $customer = new Customer();
                $customer->setHomeAddress($definition['address']);
                $user->setCustomer($customer);
                $this->entityManager->persist($customer);
            } else {
                $customer = $user->getCustomer();
            }

            $customers[] = $customer;
        }

        return $customers;
    }

    /**
     * S'assure que toutes les categories de metier existent (cree les
     * manquantes, par slug). Renvoie la table slug => categorie ainsi que le
     * nombre de categories nouvellement creees (pour le compte-rendu).
     *
     * @return array{0: array<string, ArtisanCategory>, 1: int}
     */
    private function ensureCategories(): array
    {
        $repository = $this->entityManager->getRepository(ArtisanCategory::class);

        // Uniquement des metiers du batiment / de la renovation.
        // Les icones sont des noms (mappes vers des emojis cote front, cf.
        // categoryIcon.ts), coherents avec la categorie Menuiserie historique.
        $definitions = [
            ['name' => 'Maçonnerie', 'slug' => 'maconnerie', 'icon' => 'brick'],
            ['name' => 'Plomberie', 'slug' => 'plomberie', 'icon' => 'droplet'],
            ['name' => 'Électricité', 'slug' => 'electricite', 'icon' => 'bolt'],
            ['name' => 'Menuiserie', 'slug' => 'menuiserie', 'icon' => 'hammer'],
            ['name' => 'Peinture & Décoration', 'slug' => 'peinture-decoration', 'icon' => 'paint-roller'],
            ['name' => 'Serrurerie', 'slug' => 'serrurerie', 'icon' => 'key'],
        ];

        $map = [];
        $created = 0;
        foreach ($definitions as $definition) {
            $category = $repository->findOneBy(['slug' => $definition['slug']]);
            if (null === $category) {
                $category = new ArtisanCategory();
                $category->setName($definition['name']);
                $category->setSlug($definition['slug']);
                $category->setIcon($definition['icon']);
                $this->entityManager->persist($category);
                ++$created;
            }

            $map[$definition['slug']] = $category;
        }

        return [$map, $created];
    }

    /**
     * Supprime les categories hors batiment seedees par une version anterieure
     * de la demo (coiffure, menage, jardinage). Par securite, une categorie
     * encore reliee a une entreprise n'est pas supprimee.
     *
     * @return int Nombre de categories supprimees.
     */
    private function removeObsoleteCategories(): int
    {
        $repository = $this->entityManager->getRepository(ArtisanCategory::class);
        $businessRepository = $this->entityManager->getRepository(Business::class);

        $obsoleteSlugs = ['jardinage-paysagisme', 'coiffure-a-domicile', 'menage-repassage'];

        $removed = 0;
        foreach ($obsoleteSlugs as $slug) {
            $category = $repository->findOneBy(['slug' => $slug]);
            if (null === $category) {
                continue;
            }

            if ($businessRepository->count(['category' => $category]) > 0) {
                continue;
            }

            $this->entityManager->remove($category);
            ++$removed;
        }

        return $removed;
    }

    private function findServiceByName(Business $business, string $name): Service
    {
        foreach ($business->getServices() as $service) {
            if ($service->getName() === $name) {
                return $service;
            }
        }

        throw new \RuntimeException(sprintf('Service "%s" introuvable pour la demo.', $name));
    }
}
