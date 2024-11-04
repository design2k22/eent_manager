<?php

namespace App\Controller;

use App\Entity\Event;
use App\Entity\Guest;
use App\Form\GuestType;
use Doctrine\ORM\EntityManagerInterface;
use Endroid\QrCode\Builder\Builder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Annotation\Route;

class InvitationController extends AbstractController
{
    #[Route('/invitation', name: 'app_invitation')]
    public function index(): Response
    {
        return $this->render('invitation/index.html.twig', [
            'controller_name' => 'InvitationController',
        ]);
    }

    #[Route('/event/{id}/invite', name: 'invite_guests')]
    public function inviteGuests(Event $event, Request $request, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        $guest = new Guest();
        $guest->setEvent($event);

        // Formulaire pour créer un invité
        $form = $this->createForm(GuestType::class, $guest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Génération du QR code pour cet invité
            $qrCodePath = $this->generateQrCode($guest);
            $guest->setQrCode($qrCodePath);

            // Enregistrer l'invité dans la base de données
            $em->persist($guest);
            $em->flush();

            // Envoyer l'invitation par email
            $this->sendInvitation($guest, $mailer);

            $this->addFlash('success', 'Invitation envoyée avec succès à ' . $guest->getEmail());

            // Redirection après l'envoi de l'invitation
            return $this->redirectToRoute('event_show', ['id' => $event->getId()]);
        }

        return $this->render('invitation/invite.html.twig', [
            'form' => $form->createView(),
            'event' => $event,
        ]);
    }

    private function generateQrCode(Guest $guest): string
    {
        $qrCode = Builder::create()
            ->data('http://localhost:8000/validate/' . $guest->getId())
            ->size(200)
            ->build();

        // Chemin pour enregistrer le QR code
        $qrCodePath = '/path/to/qr-codes/' . $guest->getId() . '.png';
        $qrCode->saveToFile($qrCodePath);

        return $qrCodePath;
    }

    private function sendInvitation(Guest $guest, MailerInterface $mailer)
    {
        $email = (new Email())
            ->from('no-reply@yourapp.com')
            ->to($guest->getEmail())
            ->subject('Votre invitation à l\'événement')
            ->html($this->renderView('emails/invitation.html.twig', [
                'guest' => $guest,
                'event' => $guest->getEvent(),
            ]))
            ->attachFromPath($guest->getQrCode(), 'qr-code.png');

        $mailer->send($email);
    }
}
