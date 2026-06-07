<?php

namespace App\Controller\Api;

use App\Dto\CreateAppointmentRequest;
use App\Dto\UpdateAppointmentRequest;
use App\Entity\Appointment;
use App\Entity\Business;
use App\Entity\Service;
use App\Entity\User;
use App\Enum\AppointmentStatus;
use App\Enum\Location;
use App\Repository\AppointmentRepository;
use App\Repository\ServiceRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Cycle de vie d'un rendez-vous (étape 5 du cahier des charges) :
 *  - le client crée une demande -> status PENDING ;
 *  - l'artisan l'accepte (-> CONFIRMED) ou la refuse (-> CANCELLED) ;
 *  - une fois la prestation réalisée, l'artisan la marque comme terminée (-> COMPLETED) ;
 *  - client et artisan peuvent annuler un RDV qui n'est pas encore terminé.
 * Tout changement de statut déclenche une notification au client (et, pour une annulation
 * côté client, à l'artisan), via NotificationService.
 */
#[Route('/api/appointments')]
class AppointmentController extends AbstractController
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $entityManager,
        private readonly AppointmentRepository $appointmentRepository,
        private readonly ServiceRepository $serviceRepository,
        private readonly NotificationService $notificationService,
    ) {
    }

    #[Route('', name: 'api_appointment_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $customer = $user->getCustomer();
        if (null === $customer) {
            throw $this->createAccessDeniedException('Seul un client peut faire une demande de rendez-vous.');
        }

        $dto = $this->deserialize($request, CreateAppointmentRequest::class);
        if ($response = $this->validateOrError($dto)) {
            return $response;
        }

        $service = $this->serviceRepository->find($dto->serviceId);
        $business = $service?->getBusiness();
        $artisan = $business?->getArtisan();

        if (null === $service || null === $business || null === $artisan || !$artisan->isApproved()) {
            return $this->json(['error' => 'Prestation introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $dateTime = $this->parseDateTime($dto->dateTime);
        if ($dateTime <= new \DateTimeImmutable()) {
            return $this->json(['error' => 'La date du rendez-vous doit être dans le futur.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $appointment = (new Appointment())
            ->setDateTime($dateTime)
            ->setStatus(AppointmentStatus::PENDING)
            ->setLocation(Location::from($dto->location))
            ->setCustomerNote($dto->customerNote)
            ->setCustomer($customer)
            ->setService($service)
            ->setBusiness($business);

        $this->entityManager->persist($appointment);
        $this->entityManager->flush();

        $artisanUser = $artisan->getUser();
        if (null !== $artisanUser) {
            $this->notificationService->notify(
                $artisanUser,
                'appointment_requested',
                'Nouvelle demande de rendez-vous',
                sprintf(
                    '%s %s a demandé un rendez-vous le %s pour « %s ». Connectez-vous pour l\'accepter ou la refuser.',
                    $user->getFirstName(),
                    $user->getLastName(),
                    $dateTime->format('d/m/Y à H:i'),
                    $service->getName(),
                ),
            );
        }

        return $this->json($this->serializeAppointment($appointment), Response::HTTP_CREATED);
    }

    #[Route('/{id<\d+>}', name: 'api_appointment_update', methods: ['PATCH'])]
    public function update(int $id, Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $appointment = $this->appointmentRepository->find($id);
        if (null === $appointment) {
            throw $this->createNotFoundException('Rendez-vous introuvable.');
        }

        $isCustomer = $appointment->getCustomer()?->getUser() === $user;
        $isArtisan = $appointment->getBusiness()?->getArtisan()?->getUser() === $user;

        if (!$isCustomer && !$isArtisan) {
            throw $this->createAccessDeniedException('Vous n\'êtes pas autorisé à modifier ce rendez-vous.');
        }

        $dto = $this->deserialize($request, UpdateAppointmentRequest::class);
        if ($response = $this->validateOrError($dto)) {
            return $response;
        }

        $wantsStatusChange = null !== $dto->status;
        $wantsContentChange = null !== $dto->dateTime || null !== $dto->location || null !== $dto->customerNote;

        if ($wantsStatusChange && $wantsContentChange) {
            return $this->json(
                ['error' => 'Changez soit le statut (accepter/refuser/annuler/terminer), soit les détails du rendez-vous, mais pas les deux dans la même requête.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        if (!$wantsStatusChange && !$wantsContentChange) {
            return $this->json(['error' => 'Aucune modification fournie.'], Response::HTTP_BAD_REQUEST);
        }

        if ($wantsStatusChange) {
            return $this->applyStatusChange($appointment, AppointmentStatus::from($dto->status), $isCustomer, $isArtisan);
        }

        return $this->applyContentChange($appointment, $dto, $isCustomer);
    }

    #[Route('', name: 'api_appointment_list', methods: ['GET'])]
    public function list(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $statusFilter = $this->parseStatusFilter($request);

        $appointments = [];

        if (null !== $customer = $user->getCustomer()) {
            array_push($appointments, ...$this->appointmentRepository->findForCustomer($customer, $statusFilter));
        }

        if (null !== $artisan = $user->getArtisan()) {
            array_push($appointments, ...$this->appointmentRepository->findForArtisan($artisan, $statusFilter));
        }

        usort($appointments, static fn (Appointment $a, Appointment $b): int => $b->getDateTime() <=> $a->getDateTime());

        return $this->json(array_map($this->serializeAppointment(...), $appointments));
    }

    /**
     * Applique un changement de statut si la transition est autorisée pour le rôle de l'auteur,
     * puis notifie la ou les parties concernées.
     */
    private function applyStatusChange(Appointment $appointment, AppointmentStatus $newStatus, bool $isCustomer, bool $isArtisan): JsonResponse
    {
        $previousStatus = $appointment->getStatus();

        if (!$this->isTransitionAllowed($previousStatus, $newStatus, $isCustomer, $isArtisan)) {
            return $this->json([
                'error' => sprintf(
                    'Transition de %s vers %s non autorisée pour votre rôle.',
                    $previousStatus->value,
                    $newStatus->value,
                ),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $appointment->setStatus($newStatus);
        $this->entityManager->flush();

        $this->notifyStatusChange($appointment, $newStatus, $isArtisan);

        return $this->json($this->serializeAppointment($appointment));
    }

    /**
     * Modification des détails d'une demande (date, lieu, note) : réservée au client,
     * et seulement tant que la demande n'a pas encore été traitée par l'artisan.
     */
    private function applyContentChange(Appointment $appointment, UpdateAppointmentRequest $dto, bool $isCustomer): JsonResponse
    {
        if (!$isCustomer) {
            return $this->json(['error' => 'Seul le client à l\'origine de la demande peut en modifier les détails.'], Response::HTTP_FORBIDDEN);
        }

        if (AppointmentStatus::PENDING !== $appointment->getStatus()) {
            return $this->json(
                ['error' => 'Les détails du rendez-vous ne peuvent être modifiés que tant que la demande est en attente de confirmation.'],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        if (null !== $dto->dateTime) {
            $dateTime = $this->parseDateTime($dto->dateTime);
            if ($dateTime <= new \DateTimeImmutable()) {
                return $this->json(['error' => 'La date du rendez-vous doit être dans le futur.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
            $appointment->setDateTime($dateTime);
        }

        if (null !== $dto->location) {
            $appointment->setLocation(Location::from($dto->location));
        }

        if (null !== $dto->customerNote) {
            $appointment->setCustomerNote($dto->customerNote);
        }

        $this->entityManager->flush();

        return $this->json($this->serializeAppointment($appointment));
    }

    /**
     * Règles de transition autorisées selon le rôle de l'auteur de la modification.
     */
    private function isTransitionAllowed(AppointmentStatus $from, AppointmentStatus $to, bool $isCustomer, bool $isArtisan): bool
    {
        return match (true) {
            // L'artisan accepte ou refuse une demande en attente
            $isArtisan && AppointmentStatus::PENDING === $from && AppointmentStatus::CONFIRMED === $to => true,
            $isArtisan && AppointmentStatus::PENDING === $from && AppointmentStatus::CANCELLED === $to => true,
            // L'artisan marque une prestation confirmée comme terminée
            $isArtisan && AppointmentStatus::CONFIRMED === $from && AppointmentStatus::COMPLETED === $to => true,
            // Client ou artisan peuvent annuler un RDV qui n'a pas encore eu lieu
            ($isCustomer || $isArtisan)
                && AppointmentStatus::CANCELLED === $to
                && \in_array($from, [AppointmentStatus::PENDING, AppointmentStatus::CONFIRMED], true) => true,
            default => false,
        };
    }

    private function notifyStatusChange(Appointment $appointment, AppointmentStatus $newStatus, bool $changedByArtisan): void
    {
        $customerUser = $appointment->getCustomer()?->getUser();
        $artisanUser = $appointment->getBusiness()?->getArtisan()?->getUser();
        $serviceName = $appointment->getService()?->getName() ?? 'la prestation';
        $when = $appointment->getDateTime()?->format('d/m/Y à H:i') ?? '';

        $labels = [
            AppointmentStatus::CONFIRMED->value => 'confirmé',
            AppointmentStatus::CANCELLED->value => 'annulé',
            AppointmentStatus::COMPLETED->value => 'marqué comme terminé',
        ];
        $label = $labels[$newStatus->value] ?? mb_strtolower($newStatus->value);

        // Tout changement décidé par l'artisan est notifié au client (règle du cahier des charges)
        if ($changedByArtisan && null !== $customerUser) {
            $this->notificationService->notify(
                $customerUser,
                'appointment_status_changed',
                sprintf('Votre rendez-vous a été %s', $label),
                sprintf('Votre rendez-vous du %s pour « %s » a été %s.', $when, $serviceName, $label),
            );
        }

        // Une annulation décidée par le client est aussi signalée à l'artisan
        if (!$changedByArtisan && AppointmentStatus::CANCELLED === $newStatus && null !== $artisanUser) {
            $this->notificationService->notify(
                $artisanUser,
                'appointment_cancelled_by_customer',
                'Un rendez-vous a été annulé par le client',
                sprintf('Le rendez-vous du %s pour « %s » a été annulé par le client.', $when, $serviceName),
            );
        }
    }

    private function parseDateTime(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            throw new BadRequestHttpException('Le format de date est invalide (ISO 8601 attendu, ex : 2026-07-01T14:30:00+02:00).');
        }
    }

    private function parseStatusFilter(Request $request): ?AppointmentStatus
    {
        $value = $request->query->get('status');
        if (null === $value || '' === $value) {
            return null;
        }

        $status = \is_string($value) ? AppointmentStatus::tryFrom($value) : null;
        if (null === $status) {
            throw new BadRequestHttpException('Le filtre status doit être PENDING, CONFIRMED, CANCELLED ou COMPLETED.');
        }

        return $status;
    }

    private function deserialize(Request $request, string $class): object
    {
        try {
            return $this->serializer->deserialize($request->getContent(), $class, 'json');
        } catch (SerializerExceptionInterface) {
            throw new BadRequestHttpException('Le corps de la requête doit être un JSON valide.');
        }
    }

    private function validateOrError(object $dto): ?JsonResponse
    {
        $violations = $this->validator->validate($dto);
        if (0 === count($violations)) {
            return null;
        }

        return $this->json(['violations' => $this->formatViolations($violations)], Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * @return list<array{field: string, message: string}>
     */
    private function formatViolations(ConstraintViolationListInterface $violations): array
    {
        $errors = [];
        foreach ($violations as $violation) {
            $errors[] = [
                'field' => $violation->getPropertyPath(),
                'message' => $violation->getMessage(),
            ];
        }

        return $errors;
    }

    private function serializeAppointment(Appointment $appointment): array
    {
        $service = $appointment->getService();
        $business = $appointment->getBusiness();
        $customer = $appointment->getCustomer();
        $customerUser = $customer?->getUser();

        return [
            'id' => $appointment->getId(),
            'dateTime' => $appointment->getDateTime()?->format(\DateTimeInterface::ATOM),
            'status' => $appointment->getStatus()->value,
            'location' => $appointment->getLocation()?->value,
            'customerNote' => $appointment->getCustomerNote(),
            'service' => $this->serializeService($service),
            'business' => $this->serializeBusiness($business),
            'customer' => null !== $customer ? [
                'id' => $customer->getId(),
                'firstName' => $customerUser?->getFirstName(),
                'lastName' => $customerUser?->getLastName(),
            ] : null,
        ];
    }

    private function serializeService(?Service $service): ?array
    {
        if (null === $service) {
            return null;
        }

        return [
            'id' => $service->getId(),
            'name' => $service->getName(),
            'duration' => $service->getDuration(),
            'price' => $service->getPrice(),
        ];
    }

    private function serializeBusiness(?Business $business): ?array
    {
        if (null === $business) {
            return null;
        }

        return [
            'id' => $business->getId(),
            'name' => $business->getName(),
        ];
    }
}
