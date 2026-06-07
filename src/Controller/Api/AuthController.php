<?php

namespace App\Controller\Api;

use App\Dto\RegisterArtisanRequest;
use App\Dto\RegisterCustomerRequest;
use App\Entity\Artisan;
use App\Entity\Customer;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Route('/api')]
class AuthController extends AbstractController
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
    ) {
    }

    /**
     * Inscription d'un client. Le compte est immédiatement créé ;
     * la vérification de l'email (isVerified) se fait dans un second temps.
     */
    #[Route('/register/customer', name: 'api_register_customer', methods: ['POST'])]
    public function registerCustomer(Request $request): JsonResponse
    {
        $dto = $this->deserialize($request, RegisterCustomerRequest::class);

        if ($response = $this->validateOrError($dto)) {
            return $response;
        }

        if (null !== $this->userRepository->findOneBy(['email' => $dto->email])) {
            return $this->json(['error' => 'Un compte existe déjà avec cet email.'], Response::HTTP_CONFLICT);
        }

        $user = (new User())
            ->setEmail($dto->email)
            ->setFirstName($dto->firstName)
            ->setLastName($dto->lastName)
            ->setPhone($dto->phone)
            ->setRoles(['ROLE_CUSTOMER']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $dto->password));

        $customer = (new Customer())
            ->setUser($user)
            ->setHomeAddress($dto->homeAddress);

        $this->entityManager->persist($user);
        $this->entityManager->persist($customer);
        $this->entityManager->flush();

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'isVerified' => $user->isVerified(),
        ], Response::HTTP_CREATED);
    }

    /**
     * Inscription d'un artisan. Le compte est créé avec isApproved = false :
     * il reste en attente de validation manuelle de son justificatif par un admin
     * avant de pouvoir accéder au dashboard (règle métier du cahier des charges).
     */
    #[Route('/register/artisan', name: 'api_register_artisan', methods: ['POST'])]
    public function registerArtisan(Request $request): JsonResponse
    {
        $dto = $this->deserialize($request, RegisterArtisanRequest::class);

        if ($response = $this->validateOrError($dto)) {
            return $response;
        }

        if (null !== $this->userRepository->findOneBy(['email' => $dto->email])) {
            return $this->json(['error' => 'Un compte existe déjà avec cet email.'], Response::HTTP_CONFLICT);
        }

        $user = (new User())
            ->setEmail($dto->email)
            ->setFirstName($dto->firstName)
            ->setLastName($dto->lastName)
            ->setPhone($dto->phone)
            ->setRoles(['ROLE_ARTISAN']);
        $user->setPassword($this->passwordHasher->hashPassword($user, $dto->password));

        $artisan = (new Artisan())
            ->setUser($user)
            ->setSiret($dto->siret)
            ->setOfficeAddress($dto->officeAddress)
            ->setOwnershipDocument($dto->ownershipDocument)
            ->setIsApproved(false);

        $this->entityManager->persist($user);
        $this->entityManager->persist($artisan);
        $this->entityManager->flush();

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
            'isApproved' => $artisan->isApproved(),
            'message' => 'Inscription enregistrée. Votre compte sera activé après validation de votre justificatif par un administrateur.',
        ], Response::HTTP_CREATED);
    }

    /**
     * Cette route n'est jamais exécutée : les requêtes POST /api/login sont interceptées
     * en amont par le firewall `api_login` (authentification json_login), qui vérifie
     * l'email/mot de passe et renvoie le JWT via lexik_jwt_authentication.
     */
    #[Route('/login', name: 'api_login', methods: ['POST'])]
    public function login(): never
    {
        throw new \LogicException('Cette route est interceptée par le firewall de sécurité (json_login) avant d\'atteindre le contrôleur.');
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
}
