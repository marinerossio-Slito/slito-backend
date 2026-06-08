<?php

namespace App\Tests\Functional;

use App\DataFixtures\AppFixtures;
use App\Entity\User;
use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Socle commun des tests fonctionnels de l'API (étape 9 du cahier des charges :
 * « Tests + données de démonstration »).
 *
 * Chaque test recharge le jeu de données de démonstration (cf. AppFixtures) au
 * tout début de setUp(). C'est sûr et peu coûteux grâce à DAMADoctrineTestBundle
 * (cf. config/packages/dama_doctrine_test_bundle.yaml et phpunit.dist.xml) : son
 * extension PHPUnit ouvre une transaction avant même que setUp() ne s'exécute et
 * l'annule avant le test suivant (cf. PHPUnitExtension::executionStarted /
 * testPrepared), si bien que chaque test démarre d'un état strictement identique
 * et reproductible (le générateur Faker des fixtures est re-semé à chaque
 * chargement), sans jamais laisser de trace ni dans la base de test, ni — a
 * fortiori — dans celle de développement.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = static::createClient();

        // Par défaut, KernelBrowser redémarre le noyau (donc reconstruit le
        // conteneur, et avec lui security.untracked_token_storage) entre deux
        // requêtes. Cela rendrait loginUser() inopérant dès qu'un test enchaîne
        // plusieurs requêtes authentifiées (ex. créer une ressource puis lister) :
        // le jeton posé sur le conteneur d'une requête se perd à la suivante. On
        // désactive donc ce redémarrage, comme le recommande Symfony pour les
        // scénarios de tests fonctionnels combinant connexion et appels multiples.
        $this->client->disableReboot();

        $this->reloadFixtures();
    }

    private function reloadFixtures(): void
    {
        $entityManager = $this->entityManager();

        $purger = new ORMPurger($entityManager);
        $executor = new ORMExecutor($entityManager, $purger);
        $executor->execute([static::getContainer()->get(AppFixtures::class)]);
    }

    protected function entityManager(): EntityManagerInterface
    {
        $manager = static::getContainer()->get('doctrine')->getManager();
        \assert($manager instanceof EntityManagerInterface);

        return $manager;
    }

    /**
     * Authentifie le client de test comme l'utilisateur donné, sans passer par
     * POST /api/login (qui émettrait un vrai JWT) : c'est l'approche recommandée
     * par Symfony pour les tests fonctionnels d'API protégées, y compris derrière
     * un firewall stateless (cf. KernelBrowser::loginUser — le jeton est déposé
     * directement dans le stockage de sécurité partagé par tous les firewalls).
     */
    protected function loginAs(User $user): static
    {
        $this->client->loginUser($user);

        return $this;
    }

    /**
     * Récupère un compte de démonstration par email (cf. AppFixtures) : tous
     * partagent le mot de passe « password », haché de la même façon qu'à
     * l'inscription (cf. UserPasswordHasherInterface dans AuthController).
     */
    protected function userByEmail(string $email): User
    {
        $user = static::getContainer()->get('doctrine')->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user, \sprintf('Compte de démonstration introuvable : "%s".', $email));

        return $user;
    }

    /**
     * Pioche une entité du jeu de données de démonstration selon des critères, et
     * échoue explicitement si rien ne correspond : les tests qui en dépendent (par
     * exemple « un artisan approuvé », « un rendez-vous terminé »...) ont ainsi un
     * message clair si AppFixtures venait à changer, plutôt qu'une erreur de
     * nullité plus loin dans le test.
     *
     * @template T of object
     *
     * @param class-string<T>      $entityClass
     * @param array<string, mixed> $criteria
     *
     * @return T
     */
    protected function demoEntity(string $entityClass, array $criteria = []): object
    {
        $entity = $this->entityManager()->getRepository($entityClass)->findOneBy($criteria);
        self::assertInstanceOf($entityClass, $entity, \sprintf(
            'Entité de démonstration introuvable : %s avec les critères %s (cf. AppFixtures).',
            $entityClass,
            json_encode($criteria),
        ));

        return $entity;
    }

    /**
     * Effectue une requête JSON (Content-Type/Accept application/json, corps
     * encodé) et renvoie directement le corps de la réponse décodé.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<mixed>
     */
    protected function jsonRequest(string $method, string $uri, array $payload = []): array
    {
        $this->client->jsonRequest($method, $uri, $payload);

        return $this->decodeResponse();
    }

    /**
     * @return array<mixed>
     */
    protected function decodeResponse(): array
    {
        $content = $this->client->getResponse()->getContent();
        self::assertIsString($content);

        if ('' === $content) {
            return [];
        }

        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    protected function statusCode(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }
}
