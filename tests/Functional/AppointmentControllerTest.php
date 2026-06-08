<?php

namespace App\Tests\Functional;

use App\Entity\Appointment;
use App\Entity\Artisan;
use App\Entity\Customer;
use App\Entity\Notification;
use App\Entity\Service;
use App\Enum\AppointmentStatus;

/**
 * Cycle de vie des rendez-vous (étape 5 du cahier des charges) : demande par le
 * client, acceptation/refus/clôture par l'artisan, annulation par l'une ou
 * l'autre partie, et consultation des listes (cf. AppointmentController).
 */
class AppointmentControllerTest extends ApiTestCase
{
    // -----------------------------------------------------------------
    // Création (POST /api/appointments)
    // -----------------------------------------------------------------

    public function testCustomerCanRequestAnAppointment(): void
    {
        $customer = $this->demoEntity(Customer::class);
        $service = $this->demoEntity(Service::class);
        $artisanUser = $service->getBusiness()?->getArtisan()?->getUser();
        self::assertNotNull($artisanUser);

        $this->loginAs($customer->getUser());

        $response = $this->jsonRequest('POST', '/api/appointments', [
            'serviceId' => $service->getId(),
            'dateTime' => $this->futureDateTime(10),
            'location' => 'HOME',
            'customerNote' => 'Merci de prévoir le matériel adapté.',
        ]);

        self::assertSame(201, $this->statusCode());
        self::assertSame('PENDING', $response['status']);
        self::assertSame('HOME', $response['location']);
        self::assertSame($service->getId(), $response['service']['id']);
        self::assertSame('Merci de prévoir le matériel adapté.', $response['customerNote']);

        // L'artisan doit être notifié de la nouvelle demande (cf. NotificationService)
        $notification = $this->entityManager()->getRepository(Notification::class)->findOneBy([
            'user' => $artisanUser,
            'type' => 'appointment_requested',
        ]);
        self::assertNotNull($notification, "Une notification 'appointment_requested' doit être créée pour l'artisan.");
    }

    public function testCreateAppointmentRejectsPastDateTime(): void
    {
        $customer = $this->demoEntity(Customer::class);
        $service = $this->demoEntity(Service::class);

        $this->loginAs($customer->getUser());

        $response = $this->jsonRequest('POST', '/api/appointments', [
            'serviceId' => $service->getId(),
            'dateTime' => (new \DateTimeImmutable('-3 days'))->format(\DateTimeInterface::ATOM),
            'location' => 'HOME',
        ]);

        self::assertSame(422, $this->statusCode());
        self::assertArrayHasKey('error', $response);
    }

    public function testCreateAppointmentRejectsUnknownService(): void
    {
        $customer = $this->demoEntity(Customer::class);
        $this->loginAs($customer->getUser());

        $response = $this->jsonRequest('POST', '/api/appointments', [
            'serviceId' => 999999,
            'dateTime' => $this->futureDateTime(5),
            'location' => 'HOME',
        ]);

        self::assertSame(404, $this->statusCode());
        self::assertArrayHasKey('error', $response);
    }

    public function testOnlyCustomersCanRequestAppointments(): void
    {
        $artisan = $this->demoEntity(Artisan::class, ['isApproved' => true]);
        $service = $this->demoEntity(Service::class);

        $this->loginAs($artisan->getUser());

        $this->client->jsonRequest('POST', '/api/appointments', [
            'serviceId' => $service->getId(),
            'dateTime' => $this->futureDateTime(5),
            'location' => 'HOME',
        ]);

        self::assertSame(403, $this->statusCode());
    }

    // -----------------------------------------------------------------
    // Changements de statut (PATCH /api/appointments/{id})
    // -----------------------------------------------------------------

    public function testArtisanCanConfirmAPendingAppointment(): void
    {
        $appointment = $this->demoEntity(Appointment::class, ['status' => AppointmentStatus::PENDING]);
        $artisanUser = $appointment->getBusiness()?->getArtisan()?->getUser();
        self::assertNotNull($artisanUser);

        $this->loginAs($artisanUser);

        $response = $this->jsonRequest('PATCH', \sprintf('/api/appointments/%d', $appointment->getId()), [
            'status' => 'CONFIRMED',
        ]);

        self::assertSame(200, $this->statusCode());
        self::assertSame('CONFIRMED', $response['status']);

        $customerUser = $appointment->getCustomer()?->getUser();
        $notification = $this->entityManager()->getRepository(Notification::class)->findOneBy([
            'user' => $customerUser,
            'type' => 'appointment_status_changed',
        ]);
        self::assertNotNull($notification, 'Le client doit être notifié du changement de statut décidé par son artisan.');
    }

    public function testArtisanCanDeclineAPendingAppointment(): void
    {
        $appointment = $this->demoEntity(Appointment::class, ['status' => AppointmentStatus::PENDING]);
        $artisanUser = $appointment->getBusiness()?->getArtisan()?->getUser();
        self::assertNotNull($artisanUser);

        $this->loginAs($artisanUser);

        $response = $this->jsonRequest('PATCH', \sprintf('/api/appointments/%d', $appointment->getId()), [
            'status' => 'CANCELLED',
        ]);

        self::assertSame(200, $this->statusCode());
        self::assertSame('CANCELLED', $response['status']);
    }

    public function testArtisanCanMarkAConfirmedAppointmentAsCompleted(): void
    {
        $appointment = $this->demoEntity(Appointment::class, ['status' => AppointmentStatus::CONFIRMED]);
        $artisanUser = $appointment->getBusiness()?->getArtisan()?->getUser();
        self::assertNotNull($artisanUser);

        $this->loginAs($artisanUser);

        $response = $this->jsonRequest('PATCH', \sprintf('/api/appointments/%d', $appointment->getId()), [
            'status' => 'COMPLETED',
        ]);

        self::assertSame(200, $this->statusCode());
        self::assertSame('COMPLETED', $response['status']);
    }

    public function testCustomerCannotConfirmTheirOwnAppointment(): void
    {
        $appointment = $this->demoEntity(Appointment::class, ['status' => AppointmentStatus::PENDING]);
        $customerUser = $appointment->getCustomer()?->getUser();
        self::assertNotNull($customerUser);

        $this->loginAs($customerUser);

        $response = $this->jsonRequest('PATCH', \sprintf('/api/appointments/%d', $appointment->getId()), [
            'status' => 'CONFIRMED',
        ]);

        // Transition refusée pour ce rôle (cf. AppointmentController::isTransitionAllowed)
        self::assertSame(422, $this->statusCode());
        self::assertArrayHasKey('error', $response);
    }

    public function testCustomerCanCancelTheirOwnPendingAppointment(): void
    {
        $appointment = $this->demoEntity(Appointment::class, ['status' => AppointmentStatus::PENDING]);
        $customerUser = $appointment->getCustomer()?->getUser();
        $artisanUser = $appointment->getBusiness()?->getArtisan()?->getUser();
        self::assertNotNull($customerUser);
        self::assertNotNull($artisanUser);

        $this->loginAs($customerUser);

        $response = $this->jsonRequest('PATCH', \sprintf('/api/appointments/%d', $appointment->getId()), [
            'status' => 'CANCELLED',
        ]);

        self::assertSame(200, $this->statusCode());
        self::assertSame('CANCELLED', $response['status']);

        // Une annulation décidée par le client doit aussi être signalée à l'artisan
        $notification = $this->entityManager()->getRepository(Notification::class)->findOneBy([
            'user' => $artisanUser,
            'type' => 'appointment_cancelled_by_customer',
        ]);
        self::assertNotNull($notification);
    }

    public function testCannotMarkAPendingAppointmentAsCompletedDirectly(): void
    {
        $appointment = $this->demoEntity(Appointment::class, ['status' => AppointmentStatus::PENDING]);
        $artisanUser = $appointment->getBusiness()?->getArtisan()?->getUser();
        self::assertNotNull($artisanUser);

        $this->loginAs($artisanUser);

        $response = $this->jsonRequest('PATCH', \sprintf('/api/appointments/%d', $appointment->getId()), [
            'status' => 'COMPLETED',
        ]);

        self::assertSame(422, $this->statusCode());
        self::assertArrayHasKey('error', $response);
    }

    public function testUnrelatedUserCannotUpdateAnAppointment(): void
    {
        $appointment = $this->demoEntity(Appointment::class, ['status' => AppointmentStatus::PENDING]);
        $customerUser = $appointment->getCustomer()?->getUser();

        // On choisit un autre client que celui du rendez-vous
        $otherCustomer = null;
        foreach ($this->entityManager()->getRepository(Customer::class)->findAll() as $candidate) {
            if ($candidate->getUser() !== $customerUser) {
                $otherCustomer = $candidate;
                break;
            }
        }
        self::assertNotNull($otherCustomer, 'Le jeu de démonstration doit contenir au moins deux clients.');

        $this->loginAs($otherCustomer->getUser());

        $this->client->jsonRequest('PATCH', \sprintf('/api/appointments/%d', $appointment->getId()), [
            'status' => 'CANCELLED',
        ]);

        self::assertSame(403, $this->statusCode());
    }

    // -----------------------------------------------------------------
    // Modification du contenu (PATCH /api/appointments/{id})
    // -----------------------------------------------------------------

    public function testCustomerCanEditDetailsOfAPendingAppointment(): void
    {
        $appointment = $this->demoEntity(Appointment::class, ['status' => AppointmentStatus::PENDING]);
        $customerUser = $appointment->getCustomer()?->getUser();
        self::assertNotNull($customerUser);

        $this->loginAs($customerUser);

        $newDateTime = $this->futureDateTime(15);
        $response = $this->jsonRequest('PATCH', \sprintf('/api/appointments/%d', $appointment->getId()), [
            'dateTime' => $newDateTime,
            'location' => 'WORKSHOP',
            'customerNote' => 'Nouvelle note mise à jour.',
        ]);

        self::assertSame(200, $this->statusCode());
        self::assertSame('WORKSHOP', $response['location']);
        self::assertSame('Nouvelle note mise à jour.', $response['customerNote']);
    }

    public function testCustomerCannotEditDetailsOnceConfirmed(): void
    {
        $appointment = $this->demoEntity(Appointment::class, ['status' => AppointmentStatus::CONFIRMED]);
        $customerUser = $appointment->getCustomer()?->getUser();
        self::assertNotNull($customerUser);

        $this->loginAs($customerUser);

        $response = $this->jsonRequest('PATCH', \sprintf('/api/appointments/%d', $appointment->getId()), [
            'location' => 'WORKSHOP',
        ]);

        self::assertSame(422, $this->statusCode());
        self::assertArrayHasKey('error', $response);
    }

    public function testCannotChangeStatusAndDetailsInTheSameRequest(): void
    {
        $appointment = $this->demoEntity(Appointment::class, ['status' => AppointmentStatus::PENDING]);
        $customerUser = $appointment->getCustomer()?->getUser();
        self::assertNotNull($customerUser);

        $this->loginAs($customerUser);

        $response = $this->jsonRequest('PATCH', \sprintf('/api/appointments/%d', $appointment->getId()), [
            'status' => 'CANCELLED',
            'location' => 'WORKSHOP',
        ]);

        self::assertSame(400, $this->statusCode());
        self::assertArrayHasKey('error', $response);
    }

    // -----------------------------------------------------------------
    // Listing (GET /api/appointments)
    // -----------------------------------------------------------------

    public function testListReturnsOnlyAppointmentsOfTheCurrentUser(): void
    {
        $customer = $this->demoEntity(Customer::class);
        $this->loginAs($customer->getUser());

        $response = $this->jsonRequest('GET', '/api/appointments');

        self::assertResponseIsSuccessful();
        foreach ($response as $appointment) {
            self::assertSame($customer->getId(), $appointment['customer']['id']);
        }
    }

    public function testListCanBeFilteredByStatus(): void
    {
        $customer = $this->demoEntity(Customer::class);
        $this->loginAs($customer->getUser());

        $response = $this->jsonRequest('GET', '/api/appointments?status=COMPLETED');

        self::assertResponseIsSuccessful();
        foreach ($response as $appointment) {
            self::assertSame('COMPLETED', $appointment['status']);
        }
    }

    public function testListRejectsAnInvalidStatusFilter(): void
    {
        $customer = $this->demoEntity(Customer::class);
        $this->loginAs($customer->getUser());

        $this->client->jsonRequest('GET', '/api/appointments?status=NOT_A_STATUS');

        self::assertSame(400, $this->statusCode());
    }

    private function futureDateTime(int $daysFromNow): string
    {
        return (new \DateTimeImmutable(\sprintf('+%d days', $daysFromNow)))
            ->setTime(10, 0)
            ->format(\DateTimeInterface::ATOM);
    }
}
