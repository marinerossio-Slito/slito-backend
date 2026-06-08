<?php

namespace App\Tests\Functional;

use App\Entity\Artisan;
use App\Entity\Business;
use App\Entity\Conversation;
use App\Entity\Customer;
use App\Entity\Message;

/**
 * Messagerie client <-> entreprise (étape 6 du cahier des charges) : listing des
 * conversations, détail avec historique, et envoi de messages — soit dans une
 * conversation existante, soit pour en démarrer une nouvelle (cf. MessagingController).
 */
class MessagingControllerTest extends ApiTestCase
{
    public function testCustomerSeesTheirConversationsInList(): void
    {
        $conversation = $this->demoEntity(Conversation::class);
        $customerUser = $conversation->getCustomer()?->getUser();
        self::assertNotNull($customerUser);

        $this->loginAs($customerUser);

        $response = $this->jsonRequest('GET', '/api/conversations');

        self::assertResponseIsSuccessful();
        self::assertNotEmpty($response);

        $ids = array_column($response, 'id');
        self::assertContains($conversation->getId(), $ids);

        foreach ($response as $item) {
            self::assertArrayHasKey('business', $item);
            self::assertArrayHasKey('lastMessage', $item);
            self::assertArrayHasKey('unreadCount', $item);
            self::assertArrayNotHasKey('messages', $item, 'La liste ne doit pas contenir le détail des messages.');
        }
    }

    public function testArtisanSeesConversationsOfTheirBusinessInList(): void
    {
        $conversation = $this->demoEntity(Conversation::class);
        $artisanUser = $conversation->getBusiness()?->getArtisan()?->getUser();
        self::assertNotNull($artisanUser);

        $this->loginAs($artisanUser);

        $response = $this->jsonRequest('GET', '/api/conversations');

        self::assertResponseIsSuccessful();
        $ids = array_column($response, 'id');
        self::assertContains($conversation->getId(), $ids);
    }

    public function testParticipantCanSeeConversationDetailWithMessages(): void
    {
        $conversation = $this->demoEntity(Conversation::class);
        $customerUser = $conversation->getCustomer()?->getUser();
        self::assertNotNull($customerUser);

        $this->loginAs($customerUser);

        $response = $this->jsonRequest('GET', \sprintf('/api/conversations/%d', $conversation->getId()));

        self::assertResponseIsSuccessful();
        self::assertSame($conversation->getId(), $response['id']);
        self::assertArrayHasKey('messages', $response);
        self::assertNotEmpty($response['messages'], 'Les conversations de démonstration contiennent plusieurs messages (cf. AppFixtures::createConversations).');
    }

    public function testNonParticipantCannotSeeAConversation(): void
    {
        $conversation = $this->demoEntity(Conversation::class);
        $customerUser = $conversation->getCustomer()?->getUser();

        $outsider = null;
        foreach ($this->entityManager()->getRepository(Customer::class)->findAll() as $candidate) {
            if ($candidate->getUser() !== $customerUser) {
                $outsider = $candidate;
                break;
            }
        }
        self::assertNotNull($outsider);

        $this->loginAs($outsider->getUser());

        $this->client->jsonRequest('GET', \sprintf('/api/conversations/%d', $conversation->getId()));

        self::assertSame(404, $this->statusCode());
    }

    public function testParticipantCanReplyInAnExistingConversation(): void
    {
        $conversation = $this->demoEntity(Conversation::class, ['isBlocked' => false]);
        $customerUser = $conversation->getCustomer()?->getUser();
        self::assertNotNull($customerUser);

        $this->loginAs($customerUser);

        $response = $this->jsonRequest('POST', '/api/messages', [
            'conversationId' => $conversation->getId(),
            'content' => 'Merci pour votre retour rapide, je confirme le rendez-vous.',
        ]);

        self::assertSame(201, $this->statusCode());
        self::assertSame('Merci pour votre retour rapide, je confirme le rendez-vous.', $response['content']);
        self::assertSame($customerUser->getId(), $response['sender']['id']);
    }

    public function testCustomerCanStartANewConversationWithABusiness(): void
    {
        $customer = $this->demoEntity(Customer::class);

        // On choisit une entreprise avec laquelle ce client n'a pas encore de conversation
        $conversationRepository = $this->entityManager()->getRepository(Conversation::class);
        \assert($conversationRepository instanceof \App\Repository\ConversationRepository);
        $existingBusinessIds = array_map(
            static fn (Conversation $conversation): ?int => $conversation->getBusiness()?->getId(),
            $conversationRepository->findForCustomer($customer),
        );

        $business = null;
        foreach ($this->entityManager()->getRepository(Business::class)->findAll() as $candidate) {
            if (($candidate->getArtisan()?->isApproved() ?? false) && !\in_array($candidate->getId(), $existingBusinessIds, true)) {
                $business = $candidate;
                break;
            }
        }
        self::assertNotNull($business, 'Le jeu de démonstration doit contenir une entreprise sans conversation avec ce client.');

        $this->loginAs($customer->getUser());

        $response = $this->jsonRequest('POST', '/api/messages', [
            'businessId' => $business->getId(),
            'content' => 'Bonjour, seriez-vous disponible la semaine prochaine pour un devis ?',
        ]);

        self::assertSame(201, $this->statusCode());
        self::assertSame($customer->getUser()->getId(), $response['sender']['id']);

        // La conversation doit désormais exister entre ce client et cette
        // entreprise. On vérifie directement en base plutôt que de ré-enchaîner
        // une seconde requête authentifiée (GET /api/conversations) : le
        // KernelBrowser de test ne garantit la persistance du jeton loginUser()
        // que pour la requête courante (cf. ApiTestCase::loginAs).
        $conversation = $conversationRepository->findOneBy(['customer' => $customer, 'business' => $business]);
        self::assertNotNull($conversation, 'La nouvelle conversation doit avoir été créée et associée à ce client et cette entreprise.');

        // On compte les messages par une requête dédiée plutôt que via
        // $conversation->getMessages() : comme ce test partage la même
        // EntityManager (et donc la même IdentityMap) entre la requête HTTP qui a
        // créé la conversation et cette vérification, l'objet récupéré ici est
        // l'instance même créée par le contrôleur — sa collection $messages, une
        // simple ArrayCollection au moment de sa création (cf. Conversation::__construct),
        // n'a jamais été synchronisée avec le message ajouté côté inverse
        // (Message::setConversation ne gère pas le côté propriétaire). Cela ne
        // reflète qu'un artefact de test (une vraie requête HTTP ultérieure
        // chargerait la collection depuis la base) ; on vérifie donc directement
        // ce que la base contient.
        $messagesCount = $this->entityManager()->getRepository(Message::class)->count(['conversation' => $conversation]);
        self::assertGreaterThan(0, $messagesCount, 'La conversation nouvellement créée doit contenir le message envoyé.');
    }

    public function testCannotSendAMessageWithoutTargetingAConversationOrABusiness(): void
    {
        $customer = $this->demoEntity(Customer::class);
        $this->loginAs($customer->getUser());

        $response = $this->jsonRequest('POST', '/api/messages', [
            'content' => 'Un message sans destinataire ?',
        ]);

        self::assertSame(400, $this->statusCode());
        self::assertArrayHasKey('error', $response);
    }

    public function testArtisanCannotStartAConversationWithABusiness(): void
    {
        // Démarrer une conversation est réservé aux clients (cf. MessagingController::resolveOrCreateConversation)
        $artisan = $this->demoEntity(Artisan::class, ['isApproved' => true]);
        $business = $this->demoEntity(Business::class);

        $this->loginAs($artisan->getUser());

        $this->client->jsonRequest('POST', '/api/messages', [
            'businessId' => $business->getId(),
            'content' => 'Je tente de démarrer une conversation en tant qu\'artisan.',
        ]);

        self::assertSame(403, $this->statusCode());
    }

    public function testCannotSendAnEmptyMessage(): void
    {
        $conversation = $this->demoEntity(Conversation::class, ['isBlocked' => false]);
        $customerUser = $conversation->getCustomer()?->getUser();
        self::assertNotNull($customerUser);

        $this->loginAs($customerUser);

        $response = $this->jsonRequest('POST', '/api/messages', [
            'conversationId' => $conversation->getId(),
            'content' => '',
        ]);

        self::assertSame(422, $this->statusCode());
        self::assertContains('content', array_column($response['violations'], 'field'));
    }
}
