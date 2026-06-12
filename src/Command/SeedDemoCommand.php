<?php

namespace App\Command;

use App\Entity\Artisan;
use App\Entity\ArtisanCategory;
use App\Entity\Business;
use App\Entity\Customer;
use App\Entity\Service;
use App\Entity\User;
use App\Enum\Location;
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
 * Idempotent : si le compte artisan.demo@slito-demo.fr existe deja, la
 * commande ne fait rien. Appelee depuis docker/entrypoint.sh a chaque
 * demarrage du conteneur (sans danger de doublons sur les redemarrages).
 */
#[AsCommand(name: 'app:seed-demo', description: 'Cree des comptes de demonstration (artisan + client) si absents')]
class SeedDemoCommand extends Command
{
    public const DEMO_PASSWORD = 'DemoSlito2026';
    public const ARTISAN_EMAIL = 'artisan.demo@slito-demo.fr';
    public const CUSTOMER_EMAIL = 'client.demo@slito-demo.fr';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $userRepository = $this->entityManager->getRepository(User::class);
        if (null !== $userRepository->findOneBy(['email' => self::ARTISAN_EMAIL])) {
            $io->writeln('==> Comptes de demo deja presents, rien a faire.');

            return Command::SUCCESS;
        }

        // Categorie (recuperee si elle existe deja, sinon creee)
        $categoryRepository = $this->entityManager->getRepository(ArtisanCategory::class);
        $category = $categoryRepository->findOneBy(['slug' => 'menuiserie']);
        if (null === $category) {
            $category = new ArtisanCategory();
            $category->setName('Menuiserie');
            $category->setIcon('hammer');
            $category->setSlug('menuiserie');
            $this->entityManager->persist($category);
        }

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

        $this->entityManager->flush();

        $io->success(sprintf(
            "Comptes de demo crees :\n- Artisan : %s\n- Client  : %s\nMot de passe (les deux) : %s",
            self::ARTISAN_EMAIL,
            self::CUSTOMER_EMAIL,
            self::DEMO_PASSWORD,
        ));

        return Command::SUCCESS;
    }
}
