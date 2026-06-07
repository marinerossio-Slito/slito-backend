<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Centralise la création des notifications adressées aux utilisateurs : enregistrement
 * en base (consultable depuis l'app) et envoi d'un email transactionnel.
 *
 * Le SMS mentionné dans le cahier des charges n'est pas implémenté ici : il nécessiterait
 * un fournisseur tiers (Twilio, Vonage...) et des identifiants dédiés, hors périmètre du squelette.
 */
class NotificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly string $mailerSenderAddress,
    ) {
    }

    public function notify(User $user, string $type, string $subject, string $content): Notification
    {
        $notification = (new Notification())
            ->setType($type)
            ->setContent($content)
            ->setUser($user);

        $this->entityManager->persist($notification);
        $this->entityManager->flush();

        $email = (new Email())
            ->from($this->mailerSenderAddress)
            ->to((string) $user->getEmail())
            ->subject($subject)
            ->text($content);

        $this->mailer->send($email);

        return $notification;
    }
}
