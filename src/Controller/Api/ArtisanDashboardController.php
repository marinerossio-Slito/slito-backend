<?php

namespace App\Controller\Api;

use App\Dto\UpsertBusinessRequest;
use App\Entity\Appointment;
use App\Entity\Artisan;
use App\Entity\Business;
use App\Entity\CalendarEvent;
use App\Entity\Customer;
use App\Entity\User;
use App\Enum\AppointmentStatus;
use App\Repository\AppointmentRepository;
use App\Repository\ArtisanCategoryRepository;
use App\Repository\CalendarEventRepository;
use App\Repository\ReviewRepository;
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
 * Espace artisan (étape 7 du cahier des charges) : KPIs d'activité, agenda, gestion
 * de la fiche entreprise et base clients. Réservé aux artisans dont le compte a été
 * validé par un administrateur (isApproved), conformément à la règle de permissions
 * « ROLE_ARTISAN : tout le dashboard ... — réservé après isApproved ».
 */
#[Route('/api/artisan')]
class ArtisanDashboardController extends AbstractController
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $entityManager,
        private readonly AppointmentRepository $appointmentRepository,
        private readonly CalendarEventRepository $calendarEventRepository,
        private readonly ReviewRepository $reviewRepository,
        private readonly ArtisanCategoryRepository $categoryRepository,
    ) {
    }

    #[Route('/dashboard', name: 'api_artisan_dashboard', methods: ['GET'])]
    public function dashboard(#[CurrentUser] User $user): JsonResponse
    {
        $artisan = $this->requireApprovedArtisan($user);
        $business = $artisan->getBusiness();

        $appointments = null !== $business ? $this->appointmentRepository->findForArtisan($artisan) : [];

        $appointmentsByStatus = [];
        foreach (AppointmentStatus::cases() as $status) {
            $appointmentsByStatus[$status->value] = 0;
        }
        foreach ($appointments as $appointment) {
            ++$appointmentsByStatus[$appointment->getStatus()->value];
        }

        return $this->json([
            'business' => $this->serializeBusinessRef($business),
            'appointments' => [
                'total' => count($appointments),
                'byStatus' => $appointmentsByStatus,
            ],
            // Note : le « nombre de vues » mentionné au cahier des charges nécessiterait un
            // mécanisme de suivi d'audience (tracking des consultations de fiche), absent du
            // modèle de données actuel ; il n'est donc pas inclus ici.
            'revenue' => null !== $business ? $this->appointmentRepository->getCompletedRevenue($business) : 0.0,
            'rating' => $this->reviewRepository->getRatingStats($user),
        ]);
    }

    #[Route('/calendar', name: 'api_artisan_calendar', methods: ['GET'])]
    public function calendar(#[CurrentUser] User $user): JsonResponse
    {
        $artisan = $this->requireApprovedArtisan($user);

        $events = $this->calendarEventRepository->findBy(['artisan' => $artisan], ['startDate' => 'ASC']);

        $activeAppointments = array_values(array_filter(
            $this->appointmentRepository->findForArtisan($artisan),
            static fn (Appointment $appointment): bool => \in_array(
                $appointment->getStatus(),
                [AppointmentStatus::PENDING, AppointmentStatus::CONFIRMED],
                true,
            ),
        ));

        return $this->json([
            'events' => array_map($this->serializeCalendarEvent(...), $events),
            'appointments' => array_map($this->serializeAppointmentRef(...), $activeAppointments),
        ]);
    }

    #[Route('/business', name: 'api_artisan_business_upsert', methods: ['PUT'])]
    public function upsertBusiness(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $artisan = $this->requireApprovedArtisan($user);

        $dto = $this->deserialize($request, UpsertBusinessRequest::class);
        if ($response = $this->validateOrError($dto)) {
            return $response;
        }

        $category = $this->categoryRepository->find($dto->categoryId);
        if (null === $category) {
            return $this->json(['error' => 'Catégorie introuvable.'], Response::HTTP_NOT_FOUND);
        }

        $business = $artisan->getBusiness();
        $isNew = null === $business;
        $business ??= new Business();

        $business
            ->setName($dto->name)
            ->setCategory($category)
            ->setHeadline($dto->headline)
            ->setSpecialty($dto->specialty)
            ->setDescription($dto->description)
            ->setCoverImage($dto->coverImage)
            ->setWebsite($dto->website)
            ->setPaymentMethods($dto->paymentMethods)
            ->setContactNumber($dto->contactNumber)
            ->setOfficeAddress($dto->officeAddress)
            ->setWorkingHours($dto->workingHours)
            ->setReplyDelay($dto->replyDelay);

        if ($isNew) {
            $business->setArtisan($artisan);
            $artisan->setBusiness($business);
            $this->entityManager->persist($business);
        }

        $this->entityManager->flush();

        return $this->json($this->serializeBusinessDetail($business), $isNew ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    #[Route('/clients', name: 'api_artisan_clients', methods: ['GET'])]
    public function clients(#[CurrentUser] User $user): JsonResponse
    {
        $artisan = $this->requireApprovedArtisan($user);
        $business = $artisan->getBusiness();

        if (null === $business) {
            return $this->json([]);
        }

        $rows = $this->appointmentRepository->findClientsForBusiness($business);

        return $this->json(array_map(
            fn (array $row): array => [
                'customer' => $this->serializeCustomerRef($row[0]),
                'appointmentsCount' => (int) $row['appointmentsCount'],
                'lastAppointmentAt' => $row['lastAppointmentAt'] instanceof \DateTimeInterface
                    ? $row['lastAppointmentAt']->format(\DateTimeInterface::ATOM)
                    : $row['lastAppointmentAt'],
            ],
            $rows,
        ));
    }

    private function requireApprovedArtisan(User $user): Artisan
    {
        $artisan = $user->getArtisan();
        if (null === $artisan || !$artisan->isApproved()) {
            throw $this->createAccessDeniedException("Cet espace est réservé aux artisans dont le compte a été validé par un administrateur.");
        }

        return $artisan;
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

    private function serializeBusinessRef(?Business $business): ?array
    {
        if (null === $business) {
            return null;
        }

        return [
            'id' => $business->getId(),
            'name' => $business->getName(),
        ];
    }

    private function serializeBusinessDetail(Business $business): array
    {
        return [
            'id' => $business->getId(),
            'name' => $business->getName(),
            'headline' => $business->getHeadline(),
            'specialty' => $business->getSpecialty(),
            'description' => $business->getDescription(),
            'coverImage' => $business->getCoverImage(),
            'website' => $business->getWebsite(),
            'paymentMethods' => $business->getPaymentMethods(),
            'contactNumber' => $business->getContactNumber(),
            'officeAddress' => $business->getOfficeAddress(),
            'workingHours' => $business->getWorkingHours(),
            'replyDelay' => $business->getReplyDelay(),
            'category' => null !== $business->getCategory() ? [
                'id' => $business->getCategory()->getId(),
                'name' => $business->getCategory()->getName(),
                'slug' => $business->getCategory()->getSlug(),
            ] : null,
        ];
    }

    private function serializeCalendarEvent(CalendarEvent $event): array
    {
        return [
            'id' => $event->getId(),
            'title' => $event->getTitle(),
            'description' => $event->getDescription(),
            'startDate' => $event->getStartDate()?->format(\DateTimeInterface::ATOM),
            'endDate' => $event->getEndDate()?->format(\DateTimeInterface::ATOM),
            'type' => $event->getType()?->value,
            'isAvailability' => $event->isAvailability(),
        ];
    }

    private function serializeAppointmentRef(Appointment $appointment): array
    {
        $customerUser = $appointment->getCustomer()?->getUser();

        return [
            'id' => $appointment->getId(),
            'dateTime' => $appointment->getDateTime()?->format(\DateTimeInterface::ATOM),
            'status' => $appointment->getStatus()->value,
            'service' => $appointment->getService()?->getName(),
            'customer' => null !== $customerUser ? [
                'firstName' => $customerUser->getFirstName(),
                'lastName' => $customerUser->getLastName(),
            ] : null,
        ];
    }

    private function serializeCustomerRef(Customer $customer): array
    {
        $user = $customer->getUser();

        return [
            'id' => $customer->getId(),
            'firstName' => $user?->getFirstName(),
            'lastName' => $user?->getLastName(),
            'email' => $user?->getEmail(),
        ];
    }
}
