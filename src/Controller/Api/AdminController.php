<?php

namespace App\Controller\Api;

use App\Dto\AdminUpdateUserRequest;
use App\Dto\CreateCategoryRequest;
use App\Entity\ArtisanCategory;
use App\Entity\User;
use App\Enum\AppointmentStatus;
use App\Repository\AppointmentRepository;
use App\Repository\ArtisanCategoryRepository;
use App\Repository\ArtisanRepository;
use App\Repository\BusinessRepository;
use App\Repository\CustomerRepository;
use App\Repository\ReviewRepository;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Panel admin (étape 7 du cahier des charges) : KPIs plateforme, gestion des
 * catégories de métier et des comptes utilisateurs (validation des artisans,
 * bannissement). Réservé à ROLE_ADMIN (cf. security.yaml).
 */
#[Route('/api/admin')]
class AdminController extends AbstractController
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly CustomerRepository $customerRepository,
        private readonly ArtisanRepository $artisanRepository,
        private readonly BusinessRepository $businessRepository,
        private readonly AppointmentRepository $appointmentRepository,
        private readonly ReviewRepository $reviewRepository,
        private readonly ArtisanCategoryRepository $categoryRepository,
        private readonly NotificationService $notificationService,
    ) {
    }

    #[Route('/stats', name: 'api_admin_stats', methods: ['GET'])]
    public function stats(): JsonResponse
    {
        $appointmentsByStatus = [];
        foreach (AppointmentStatus::cases() as $status) {
            $appointmentsByStatus[$status->value] = $this->appointmentRepository->count(['status' => $status]);
        }

        return $this->json([
            'users' => [
                'total' => $this->userRepository->count([]),
                'customers' => $this->customerRepository->count([]),
                'artisans' => $this->artisanRepository->count([]),
                'banned' => $this->userRepository->count(['isBanned' => true]),
            ],
            'artisans' => [
                'approved' => $this->artisanRepository->count(['isApproved' => true]),
                'pendingApproval' => $this->artisanRepository->count(['isApproved' => false]),
            ],
            'businesses' => [
                'total' => $this->businessRepository->count([]),
            ],
            'appointments' => [
                'total' => array_sum($appointmentsByStatus),
                'byStatus' => $appointmentsByStatus,
            ],
            'revenue' => $this->appointmentRepository->getPlatformCompletedRevenue(),
            'reviews' => $this->reviewRepository->getPlatformStats(),
        ]);
    }

    #[Route('/categories', name: 'api_admin_category_create', methods: ['POST'])]
    public function createCategory(Request $request): JsonResponse
    {
        $dto = $this->deserialize($request, CreateCategoryRequest::class);
        if ($response = $this->validateOrError($dto)) {
            return $response;
        }

        $slug = null !== $dto->slug && '' !== trim($dto->slug) ? $dto->slug : $this->slugify($dto->name);
        if ('' === $slug) {
            return $this->json(['error' => "Impossible de déduire un identifiant (slug) à partir de ce nom : précisez-en un."], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (null !== $this->categoryRepository->findOneBy(['slug' => $slug])) {
            return $this->json(['error' => sprintf('Une catégorie avec le slug "%s" existe déjà.', $slug)], Response::HTTP_CONFLICT);
        }

        $category = (new ArtisanCategory())
            ->setName($dto->name)
            ->setIcon($dto->icon)
            ->setSlug($slug);

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $this->json($this->serializeCategory($category), Response::HTTP_CREATED);
    }

    #[Route('/users/{id<\d+>}', name: 'api_admin_user_update', methods: ['PATCH'])]
    public function updateUser(int $id, Request $request): JsonResponse
    {
        $user = $this->userRepository->find($id);
        if (null === $user) {
            throw $this->createNotFoundException('Utilisateur introuvable.');
        }

        $dto = $this->deserialize($request, AdminUpdateUserRequest::class);
        if ($response = $this->validateOrError($dto)) {
            return $response;
        }

        if (null === $dto->isBanned && null === $dto->isApproved) {
            return $this->json(['error' => 'Indiquez isBanned (suspendre/réactiver) et/ou isApproved (valider un artisan).'], Response::HTTP_BAD_REQUEST);
        }

        if (null !== $dto->isBanned) {
            if (\in_array('ROLE_ADMIN', $user->getRoles(), true)) {
                return $this->json(['error' => 'Un compte administrateur ne peut pas être banni.'], Response::HTTP_FORBIDDEN);
            }
            $user->setIsBanned($dto->isBanned);
        }

        if (null !== $dto->isApproved) {
            $artisan = $user->getArtisan();
            if (null === $artisan) {
                return $this->json(['error' => "Cet utilisateur n'a pas de profil artisan à valider."], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $wasApproved = $artisan->isApproved();
            $artisan->setIsApproved($dto->isApproved);

            if ($dto->isApproved && !$wasApproved) {
                $this->notificationService->notify(
                    $user,
                    'artisan_approved',
                    'Votre compte artisan a été validé',
                    'Votre justificatif a été vérifié par notre équipe : vous avez maintenant accès à votre tableau de bord et pouvez publier votre fiche.',
                );
            }
        }

        $this->entityManager->flush();

        return $this->json($this->serializeUser($user));
    }

    private function slugify(string $value): string
    {
        return (new AsciiSlugger())->slug($value)->lower()->toString();
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

    private function serializeCategory(ArtisanCategory $category): array
    {
        return [
            'id' => $category->getId(),
            'name' => $category->getName(),
            'icon' => $category->getIcon(),
            'slug' => $category->getSlug(),
        ];
    }

    private function serializeUser(User $user): array
    {
        $artisan = $user->getArtisan();

        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'roles' => $user->getRoles(),
            'isVerified' => $user->isVerified(),
            'isBanned' => $user->isBanned(),
            'artisan' => null !== $artisan ? [
                'id' => $artisan->getId(),
                'isApproved' => $artisan->isApproved(),
            ] : null,
        ];
    }
}
