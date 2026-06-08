<?php

namespace App\Tests\Functional;

use App\Entity\Artisan;
use App\Entity\Customer;

/**
 * Authentification (étape 2 du cahier des charges) : inscription client/artisan,
 * connexion JSON -> JWT (cf. firewall api_login dans security.yaml), et protection
 * des routes par rôle (cf. access_control).
 */
class AuthControllerTest extends ApiTestCase
{
    public function testRegisterCustomerCreatesAccount(): void
    {
        $response = $this->jsonRequest('POST', '/api/register/customer', [
            'email' => 'nouvelle.cliente@example.com',
            'password' => 'mot-de-passe-sur',
            'firstName' => 'Nouvelle',
            'lastName' => 'Cliente',
            'phone' => '0612345678',
            'homeAddress' => '12 rue de la Paix, Paris',
        ]);

        self::assertSame(201, $this->statusCode());
        self::assertSame('nouvelle.cliente@example.com', $response['email']);
        self::assertSame(['ROLE_CUSTOMER', 'ROLE_USER'], $response['roles']);
        self::assertFalse($response['isVerified']);
        self::assertArrayHasKey('id', $response);

        $user = $this->userByEmail('nouvelle.cliente@example.com');
        self::assertNotNull($user->getCustomer());
        self::assertSame('12 rue de la Paix, Paris', $user->getCustomer()->getHomeAddress());
    }

    public function testRegisterCustomerRejectsAlreadyUsedEmail(): void
    {
        // admin@slito-demo.fr existe déjà dans le jeu de démonstration (cf. AppFixtures::createAdmin)
        $response = $this->jsonRequest('POST', '/api/register/customer', [
            'email' => 'admin@slito-demo.fr',
            'password' => 'mot-de-passe-sur',
            'firstName' => 'Test',
            'lastName' => 'Doublon',
        ]);

        self::assertSame(409, $this->statusCode());
        self::assertArrayHasKey('error', $response);
    }

    public function testRegisterCustomerRejectsInvalidPayload(): void
    {
        $response = $this->jsonRequest('POST', '/api/register/customer', [
            'email' => 'pas-un-email',
            'password' => 'court',
            'firstName' => '',
            'lastName' => '',
        ]);

        self::assertSame(422, $this->statusCode());
        self::assertArrayHasKey('violations', $response);

        $fields = array_column($response['violations'], 'field');
        self::assertContains('email', $fields);
        self::assertContains('password', $fields);
        self::assertContains('firstName', $fields);
        self::assertContains('lastName', $fields);
    }

    public function testRegisterArtisanCreatesUnapprovedAccount(): void
    {
        $response = $this->jsonRequest('POST', '/api/register/artisan', [
            'email' => 'nouvel.artisan@example.com',
            'password' => 'mot-de-passe-sur',
            'firstName' => 'Nouvel',
            'lastName' => 'Artisan',
            'siret' => '12345678901234',
            'officeAddress' => '5 avenue des Métiers, Lyon',
            'ownershipDocument' => 'kbis-nouvel-artisan.pdf',
        ]);

        self::assertSame(201, $this->statusCode());
        self::assertSame(['ROLE_ARTISAN', 'ROLE_USER'], $response['roles']);
        self::assertFalse($response['isApproved']);
        self::assertArrayHasKey('message', $response);

        $user = $this->userByEmail('nouvel.artisan@example.com');
        self::assertNotNull($user->getArtisan());
        self::assertFalse($user->getArtisan()->isApproved());
    }

    public function testRegisterArtisanRejectsInvalidSiret(): void
    {
        $response = $this->jsonRequest('POST', '/api/register/artisan', [
            'email' => 'siret.invalide@example.com',
            'password' => 'mot-de-passe-sur',
            'firstName' => 'Siret',
            'lastName' => 'Invalide',
            'siret' => '123', // doit comporter exactement 14 chiffres
            'ownershipDocument' => 'kbis.pdf',
        ]);

        self::assertSame(422, $this->statusCode());
        self::assertContains('siret', array_column($response['violations'], 'field'));
    }

    public function testLoginReturnsJwtTokenForValidCredentials(): void
    {
        // Tous les comptes de démonstration partagent le mot de passe « password » (cf. AppFixtures)
        $this->client->jsonRequest('POST', '/api/login', [
            'email' => 'admin@slito-demo.fr',
            'password' => 'password',
        ]);

        self::assertResponseIsSuccessful();

        $response = $this->decodeResponse();
        self::assertArrayHasKey('token', $response);
        self::assertNotEmpty($response['token']);
    }

    public function testLoginRejectsWrongPassword(): void
    {
        $this->client->jsonRequest('POST', '/api/login', [
            'email' => 'admin@slito-demo.fr',
            'password' => 'ce-nest-pas-le-bon-mot-de-passe',
        ]);

        self::assertSame(401, $this->statusCode());
    }

    public function testLoginRejectsUnknownEmail(): void
    {
        $this->client->jsonRequest('POST', '/api/login', [
            'email' => 'personne@nulle-part.fr',
            'password' => 'password',
        ]);

        self::assertSame(401, $this->statusCode());
    }

    public function testAnonymousAccessToProtectedRouteIsRejected(): void
    {
        $this->client->jsonRequest('GET', '/api/appointments');

        self::assertSame(401, $this->statusCode());
    }

    public function testCustomerCannotAccessAdminRoutes(): void
    {
        $customer = $this->demoEntity(Customer::class);
        $this->loginAs($customer->getUser());

        $this->client->jsonRequest('GET', '/api/admin/stats');

        self::assertSame(403, $this->statusCode());
    }

    public function testCustomerCannotAccessArtisanRoutes(): void
    {
        $customer = $this->demoEntity(Customer::class);
        $this->loginAs($customer->getUser());

        $this->client->jsonRequest('GET', '/api/artisan/dashboard');

        self::assertSame(403, $this->statusCode());
    }

    public function testApprovedArtisanCannotAccessAdminRoutes(): void
    {
        $artisan = $this->demoEntity(Artisan::class, ['isApproved' => true]);
        $this->loginAs($artisan->getUser());

        $this->client->jsonRequest('GET', '/api/admin/stats');

        self::assertSame(403, $this->statusCode());
    }
}
