<?php

namespace App\Controller\Api;

use App\Dto\PasswordResetConfirmation;
use App\Dto\PasswordResetRequest;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\ResetPasswordExceptionInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;

/**
 * Réinitialisation de mot de passe en deux temps :
 *  1. POST /api/password/reset         -> envoie un email avec un lien contenant un token
 *  2. POST /api/password/reset/{token} -> vérifie le token et enregistre le nouveau mot de passe
 */
#[Route('/api/password/reset')]
class PasswordResetController extends AbstractController
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly ResetPasswordHelperInterface $resetPasswordHelper,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly string $frontendResetPasswordUrl,
        private readonly string $mailerSenderAddress,
    ) {
    }

    #[Route('', name: 'api_password_reset_request', methods: ['POST'])]
    public function request(Request $request): JsonResponse
    {
        $dto = $this->deserialize($request, PasswordResetRequest::class);

        if ($response = $this->validateOrError($dto)) {
            return $response;
        }

        $user = $this->userRepository->findOneBy(['email' => $dto->email]);

        // Réponse identique que le compte existe ou non, pour ne pas révéler les emails enregistrés
        $message = 'Si un compte existe pour cet email, un lien de réinitialisation vient de lui être envoyé.';

        if (null === $user) {
            return $this->json(['message' => $message]);
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
        } catch (ResetPasswordExceptionInterface) {
            // Une demande est déjà en cours pour cet utilisateur : on répond pareil, sans rien révéler
            return $this->json(['message' => $message]);
        }

        $email = (new Email())
            ->from($this->mailerSenderAddress)
            ->to((string) $user->getEmail())
            ->subject('Réinitialisation de votre mot de passe Slito')
            ->text(sprintf(
                "Bonjour %s,\n\nPour réinitialiser votre mot de passe, suivez ce lien :\n%s\n\nCe lien expire dans %d minutes. Si vous n'êtes pas à l'origine de cette demande, ignorez ce message.",
                $user->getFirstName(),
                rtrim($this->frontendResetPasswordUrl, '/').'/'.$resetToken->getToken(),
                (int) ceil($resetToken->getExpiresAt()->getTimestamp() - time()) / 60
            ));

        $this->mailer->send($email);

        return $this->json(['message' => $message]);
    }

    #[Route('/{token}', name: 'api_password_reset_confirm', methods: ['POST'])]
    public function confirm(string $token, Request $request): JsonResponse
    {
        $dto = $this->deserialize($request, PasswordResetConfirmation::class);

        if ($response = $this->validateOrError($dto)) {
            return $response;
        }

        try {
            $user = $this->resetPasswordHelper->validateTokenAndFetchUser($token);
        } catch (ResetPasswordExceptionInterface $e) {
            return $this->json(['error' => 'Ce lien de réinitialisation est invalide ou a expiré.'], Response::HTTP_BAD_REQUEST);
        }

        $this->resetPasswordHelper->removeResetRequest($token);

        $user->setPassword($this->passwordHasher->hashPassword($user, $dto->password));
        $this->entityManager->flush();

        return $this->json(['message' => 'Votre mot de passe a été modifié avec succès.']);
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

        $errors = [];
        foreach ($violations as $violation) {
            $errors[] = ['field' => $violation->getPropertyPath(), 'message' => $violation->getMessage()];
        }

        return $this->json(['violations' => $errors], Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
