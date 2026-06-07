<?php

namespace App\Controller\Api;

use App\Dto\CreateReviewRequest;
use App\Entity\Review;
use App\Entity\User;
use App\Enum\AppointmentStatus;
use App\Enum\ReviewAuthorType;
use App\Repository\AppointmentRepository;
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
 * Avis bidirectionnels (étape 6 du cahier des charges) : un avis ne peut être laissé
 * qu'une fois la prestation terminée (COMPLETED), par l'une des deux parties au rendez-vous
 * (le client note l'artisan, ou l'artisan note le client), une seule fois chacune.
 */
#[Route('/api/reviews')]
class ReviewController extends AbstractController
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $entityManager,
        private readonly AppointmentRepository $appointmentRepository,
        private readonly ReviewRepository $reviewRepository,
    ) {
    }

    #[Route('', name: 'api_review_create', methods: ['POST'])]
    public function create(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $dto = $this->deserialize($request, CreateReviewRequest::class);
        if ($response = $this->validateOrError($dto)) {
            return $response;
        }

        $appointment = $this->appointmentRepository->find($dto->appointmentId);
        if (null === $appointment) {
            throw $this->createNotFoundException('Rendez-vous introuvable.');
        }

        if (AppointmentStatus::COMPLETED !== $appointment->getStatus()) {
            return $this->json(
                ['error' => "Un avis ne peut être laissé qu'une fois la prestation terminée."],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $customerUser = $appointment->getCustomer()?->getUser();
        $artisanUser = $appointment->getBusiness()?->getArtisan()?->getUser();

        $authorType = match ($user) {
            $customerUser => ReviewAuthorType::CUSTOMER,
            $artisanUser => ReviewAuthorType::ARTISAN,
            default => null,
        };

        if (null === $authorType) {
            throw $this->createAccessDeniedException('Seuls le client et l\'artisan concernés par ce rendez-vous peuvent laisser un avis.');
        }

        $target = ReviewAuthorType::CUSTOMER === $authorType ? $artisanUser : $customerUser;
        if (null === $target) {
            throw $this->createNotFoundException('Destinataire de l\'avis introuvable.');
        }

        if (null !== $this->reviewRepository->findOneBy(['appointment' => $appointment, 'author' => $user])) {
            return $this->json(['error' => 'Vous avez déjà laissé un avis pour ce rendez-vous.'], Response::HTTP_CONFLICT);
        }

        $review = (new Review())
            ->setRating($dto->rating)
            ->setPunctualityRating($dto->punctualityRating)
            ->setQualityRating($dto->qualityRating)
            ->setComment($dto->comment)
            ->setAuthorType($authorType)
            ->setAppointment($appointment)
            ->setAuthor($user)
            ->setTarget($target);

        $this->entityManager->persist($review);
        $this->entityManager->flush();

        return $this->json($this->serializeReview($review), Response::HTTP_CREATED);
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

    private function serializeReview(Review $review): array
    {
        return [
            'id' => $review->getId(),
            'rating' => $review->getRating(),
            'punctualityRating' => $review->getPunctualityRating(),
            'qualityRating' => $review->getQualityRating(),
            'comment' => $review->getComment(),
            'createdAt' => $review->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'authorType' => $review->getAuthorType()?->value,
            'appointmentId' => $review->getAppointment()?->getId(),
            'author' => $this->serializeUserRef($review->getAuthor()),
            'target' => $this->serializeUserRef($review->getTarget()),
        ];
    }

    private function serializeUserRef(?User $user): ?array
    {
        if (null === $user) {
            return null;
        }

        return [
            'id' => $user->getId(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
        ];
    }
}
