<?php

namespace App\Controller\Api;

use App\Dto\SendMessageRequest;
use App\Entity\Business;
use App\Entity\Conversation;
use App\Entity\Customer;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\BusinessRepository;
use App\Repository\ConversationRepository;
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
 * Messagerie entre clients et entreprises (étape 6 du cahier des charges) : chaque fil de
 * discussion (Conversation) relie un client et une entreprise. Un client peut démarrer une
 * conversation en envoyant un premier message à une entreprise (elle est créée à la volée) ;
 * l'un ou l'autre peut ensuite répondre tant que la conversation n'est pas bloquée (isBlocked).
 */
#[Route('/api')]
class MessagingController extends AbstractController
{
    public function __construct(
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly EntityManagerInterface $entityManager,
        private readonly ConversationRepository $conversationRepository,
        private readonly BusinessRepository $businessRepository,
    ) {
    }

    #[Route('/conversations', name: 'api_conversation_list', methods: ['GET'])]
    public function listConversations(#[CurrentUser] User $user): JsonResponse
    {
        $conversations = $this->findConversationsForUser($user);

        return $this->json(array_map(
            fn (Conversation $conversation): array => $this->serializeConversation($conversation, $user, withMessages: false),
            $conversations,
        ));
    }

    #[Route('/conversations/{id<\d+>}', name: 'api_conversation_show', methods: ['GET'])]
    public function showConversation(int $id, #[CurrentUser] User $user): JsonResponse
    {
        $conversation = $this->conversationRepository->find($id);
        if (null === $conversation || !$this->isParticipant($conversation, $user)) {
            throw $this->createNotFoundException('Conversation introuvable.');
        }

        return $this->json($this->serializeConversation($conversation, $user, withMessages: true));
    }

    #[Route('/messages', name: 'api_message_send', methods: ['POST'])]
    public function sendMessage(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $dto = $this->deserialize($request, SendMessageRequest::class);
        if ($response = $this->validateOrError($dto)) {
            return $response;
        }

        if (null === $dto->conversationId && null === $dto->businessId) {
            return $this->json(
                ['error' => 'Indiquez conversationId pour répondre dans une conversation existante, ou businessId pour en démarrer une nouvelle.'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $conversation = null !== $dto->conversationId
            ? $this->resolveExistingConversation($dto->conversationId, $user)
            : $this->resolveOrCreateConversation($dto->businessId, $user);

        if ($conversation->isBlocked()) {
            return $this->json(['error' => 'Cette conversation est bloquée : impossible d\'envoyer un message.'], Response::HTTP_FORBIDDEN);
        }

        $message = (new Message())
            ->setContent($dto->content)
            ->setAttachment($dto->attachment)
            ->setSender($user)
            ->setConversation($conversation);

        $this->entityManager->persist($message);
        $this->entityManager->flush();

        return $this->json($this->serializeMessage($message), Response::HTTP_CREATED);
    }

    /**
     * @return Conversation[]
     */
    private function findConversationsForUser(User $user): array
    {
        $conversations = [];

        if (null !== $customer = $user->getCustomer()) {
            array_push($conversations, ...$this->conversationRepository->findForCustomer($customer));
        }

        if (null !== $business = $user->getArtisan()?->getBusiness()) {
            array_push($conversations, ...$this->conversationRepository->findForBusiness($business));
        }

        usort(
            $conversations,
            static fn (Conversation $a, Conversation $b): int => $b->getCreatedAt() <=> $a->getCreatedAt(),
        );

        return $conversations;
    }

    private function resolveExistingConversation(int $conversationId, User $user): Conversation
    {
        $conversation = $this->conversationRepository->find($conversationId);
        if (null === $conversation || !$this->isParticipant($conversation, $user)) {
            throw $this->createNotFoundException('Conversation introuvable.');
        }

        return $conversation;
    }

    private function resolveOrCreateConversation(?int $businessId, User $user): Conversation
    {
        $customer = $user->getCustomer();
        if (null === $customer) {
            throw $this->createAccessDeniedException('Seul un client peut démarrer une conversation avec une entreprise.');
        }

        $business = $this->businessRepository->find($businessId);
        if (null === $business || !($business->getArtisan()?->isApproved() ?? false)) {
            throw $this->createNotFoundException('Entreprise introuvable.');
        }

        $conversation = $this->conversationRepository->findOneBy(['customer' => $customer, 'business' => $business]);
        if (null === $conversation) {
            $conversation = (new Conversation())
                ->setCustomer($customer)
                ->setBusiness($business);

            $this->entityManager->persist($conversation);
        }

        return $conversation;
    }

    private function isParticipant(Conversation $conversation, User $user): bool
    {
        return $conversation->getCustomer()?->getUser() === $user
            || $conversation->getBusiness()?->getArtisan()?->getUser() === $user;
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

    private function serializeConversation(Conversation $conversation, User $currentUser, bool $withMessages): array
    {
        $messages = $conversation->getMessages();
        $lastMessage = $messages->isEmpty() ? null : $messages->last();

        $unreadCount = 0;
        foreach ($messages as $message) {
            if (!$message->isRead() && $message->getSender() !== $currentUser) {
                ++$unreadCount;
            }
        }

        $data = [
            'id' => $conversation->getId(),
            'business' => $this->serializeBusinessRef($conversation->getBusiness()),
            'customer' => $this->serializeCustomerRef($conversation->getCustomer()),
            'isBlocked' => $conversation->isBlocked(),
            'createdAt' => $conversation->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            'lastMessage' => null !== $lastMessage ? $this->serializeMessage($lastMessage) : null,
            'unreadCount' => $unreadCount,
        ];

        if ($withMessages) {
            $data['messages'] = array_map($this->serializeMessage(...), $messages->toArray());
        }

        return $data;
    }

    private function serializeMessage(Message $message): array
    {
        return [
            'id' => $message->getId(),
            'content' => $message->getContent(),
            'sentAt' => $message->getSentAt()?->format(\DateTimeInterface::ATOM),
            'isRead' => $message->isRead(),
            'attachment' => $message->getAttachment(),
            'sender' => $this->serializeUserRef($message->getSender()),
        ];
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

    private function serializeCustomerRef(?Customer $customer): ?array
    {
        if (null === $customer) {
            return null;
        }

        $user = $customer->getUser();

        return [
            'id' => $customer->getId(),
            'firstName' => $user?->getFirstName(),
            'lastName' => $user?->getLastName(),
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
