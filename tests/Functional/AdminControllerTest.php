<?php

namespace App\Tests\Functional;

use App\Entity\Artisan;
use App\Entity\Customer;
use App\Entity\Notification;
use App\Entity\User;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Panel d'administration (étape 7 du cahier des charges) : KPIs plateforme,
 * gestion des catégories de métier, validation des artisans et bannissement des
 * comptes (cf. AdminController). Réservé à ROLE_ADMIN.
 */
class AdminControllerTest extends ApiTestCase
{
    public function testAdminSeesPlatformWideStats(): void
    {
        $this->loginAs($this->userByEmail('admin@slito-demo.fr'));

        $response = $this->jsonRequest('GET', '/api/admin/stats');

        self::assertResponseIsSuccessful();
        self::assertArrayHasKey('users', $response);
        self::assertArrayHasKey('artisans', $response);
        self::assertArrayHasKey('businesses', $response);
        self::assertArrayHasKey('appointments', $response);
        self::assertArrayHasKey('revenue', $response);
        self::assertArrayHasKey('reviews', $response);

        // Cf. AppFixtures : 8 catégories => 8 artisans approuvés (avec fiche) + 3 candidatures en attente
        self::assertSame(8, $response['artisans']['approved']);
        self::assertSame(3, $response['artisans']['pendingApproval']);
        self::assertSame(8, $response['businesses']['total']);
        self::assertGreaterThan(0, $response['users']['total']);
    }

    public function testAdminCanCreateANewCategory(): void
    {
        $this->loginAs($this->userByEmail('admin@slito-demo.fr'));

        $response = $this->jsonRequest('POST', '/api/admin/categories', [
            'name' => 'Vitrerie',
            'icon' => 'square',
        ]);

        self::assertSame(201, $this->statusCode());
        self::assertSame('Vitrerie', $response['name']);
        self::assertSame('vitrerie', $response['slug'], 'Le slug doit être déduit du nom (cf. AdminController::slugify).');
    }

    public function testCreateCategoryRejectsAnAlreadyUsedSlug(): void
    {
        $this->loginAs($this->userByEmail('admin@slito-demo.fr'));

        // « Plomberie » existe déjà dans le jeu de démonstration (cf. AppFixtures::createCategories)
        $response = $this->jsonRequest('POST', '/api/admin/categories', [
            'name' => 'Plomberie',
        ]);

        self::assertSame(409, $this->statusCode());
        self::assertArrayHasKey('error', $response);
    }

    public function testCreateCategoryRequiresAName(): void
    {
        $this->loginAs($this->userByEmail('admin@slito-demo.fr'));

        $response = $this->jsonRequest('POST', '/api/admin/categories', [
            'icon' => 'square',
        ]);

        self::assertSame(422, $this->statusCode());
        self::assertContains('name', array_column($response['violations'], 'field'));
    }

    public function testAdminCanApproveAPendingArtisanAndTriggersANotification(): void
    {
        $artisan = $this->demoEntity(Artisan::class, ['isApproved' => false]);
        $user = $artisan->getUser();
        self::assertNotNull($user);

        $this->loginAs($this->userByEmail('admin@slito-demo.fr'));

        $response = $this->jsonRequest('PATCH', \sprintf('/api/admin/users/%d', $user->getId()), [
            'isApproved' => true,
        ]);

        self::assertSame(200, $this->statusCode());
        self::assertTrue($response['artisan']['isApproved']);

        $notification = $this->entityManager()->getRepository(Notification::class)->findOneBy([
            'user' => $user,
            'type' => 'artisan_approved',
        ]);
        self::assertNotNull($notification, "Une notification 'artisan_approved' doit être envoyée à l'artisan tout juste validé.");
    }

    public function testAdminCanBanARegularUser(): void
    {
        $customer = $this->demoEntity(Customer::class);
        $user = $customer->getUser();
        self::assertNotNull($user);
        self::assertFalse($user->isBanned());

        $this->loginAs($this->userByEmail('admin@slito-demo.fr'));

        $response = $this->jsonRequest('PATCH', \sprintf('/api/admin/users/%d', $user->getId()), [
            'isBanned' => true,
        ]);

        self::assertSame(200, $this->statusCode());
        self::assertTrue($response['isBanned']);
    }

    public function testAdminCannotBanAnotherAdministrator(): void
    {
        $admin = $this->userByEmail('admin@slito-demo.fr');
        $secondAdmin = $this->createAdditionalAdmin();

        $this->loginAs($admin);

        $response = $this->jsonRequest('PATCH', \sprintf('/api/admin/users/%d', $secondAdmin->getId()), [
            'isBanned' => true,
        ]);

        self::assertSame(403, $this->statusCode());
        self::assertArrayHasKey('error', $response);
    }

    public function testUpdateUserRequiresAtLeastOneField(): void
    {
        $customer = $this->demoEntity(Customer::class);

        $this->loginAs($this->userByEmail('admin@slito-demo.fr'));

        $response = $this->jsonRequest('PATCH', \sprintf('/api/admin/users/%d', $customer->getUser()?->getId()), []);

        self::assertSame(400, $this->statusCode());
        self::assertArrayHasKey('error', $response);
    }

    public function testCannotApproveAUserWithoutAnArtisanProfile(): void
    {
        $customer = $this->demoEntity(Customer::class);

        $this->loginAs($this->userByEmail('admin@slito-demo.fr'));

        $response = $this->jsonRequest('PATCH', \sprintf('/api/admin/users/%d', $customer->getUser()?->getId()), [
            'isApproved' => true,
        ]);

        self::assertSame(422, $this->statusCode());
        self::assertArrayHasKey('error', $response);
    }

    public function testUpdateUserReturns404ForUnknownUser(): void
    {
        $this->loginAs($this->userByEmail('admin@slito-demo.fr'));

        $this->client->jsonRequest('PATCH', '/api/admin/users/999999', ['isBanned' => true]);

        self::assertSame(404, $this->statusCode());
    }

    /**
     * Le jeu de démonstration ne contient qu'un seul compte administrateur ; on en
     * crée ici un second, jetable, pour pouvoir exercer la règle « un admin ne
     * peut pas bannir un autre admin » sans se cibler lui-même.
     */
    private function createAdditionalAdmin(): User
    {
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $user = (new User())
            ->setEmail('second.admin@slito-demo.fr')
            ->setFirstName('Second')
            ->setLastName('Admin')
            ->setRoles(['ROLE_ADMIN'])
            ->setIsVerified(true);
        $user->setPassword($hasher->hashPassword($user, 'password'));

        $entityManager = $this->entityManager();
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
