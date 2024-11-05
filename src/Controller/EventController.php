<?php

namespace App\Controller;

use App\Entity\Event;
use App\Entity\Guest;
use App\Form\GuestType;
use App\Form\EventType;
use App\Form\CsvUploadType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Dompdf\Dompdf;
use Dompdf\Options;

class EventController extends AbstractController
{
    #[Route('/event/create', name: 'event_create', methods: ['GET', 'POST'])]
    public function create(Request $request, EntityManagerInterface $em): Response
    {
        $event = new Event();
        $form = $this->createForm(EventType::class, $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($event);
            $em->flush();
            $this->addFlash('success', 'L\'événement a été créé avec succès !');
            return $this->redirectToRoute('event_list');
        }

        return $this->render('event/create.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/event/{id}/edit', name: 'event_edit')]
    public function edit(Request $request, Event $event, EntityManagerInterface $em): Response
    {
        $form = $this->createForm(EventType::class, $event);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->flush();
            $this->addFlash('success', 'L\'événement a été mis à jour avec succès.');
            return $this->redirectToRoute('event_list');
        }

        return $this->render('event/edit.html.twig', [
            'event' => $event,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/event/{id}/delete', name: 'event_delete')]
    public function delete(Event $event, EntityManagerInterface $em): Response
    {
        $em->remove($event);
        $em->flush();
        $this->addFlash('success', 'L\'événement a été supprimé avec succès.');
        return $this->redirectToRoute('event_list');
    }

    #[Route('/events', name: 'event_list', methods: ['GET'])]
    public function list(EntityManagerInterface $em): Response
    {
        $events = $em->getRepository(Event::class)->findAll();
        return $this->render('event/list.html.twig', [
            'events' => $events,
        ]);
    }

    #[Route('/event/{id}/invite', name: 'event_invite', methods: ['GET', 'POST'])]
    public function invite(Event $event, Request $request, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        $guest = new Guest();
        $guest->setEvent($event);

        $form = $this->createForm(GuestType::class, $guest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($guest);
            $em->flush();
            $this->sendInvitationEmail($mailer, $guest);
            $this->addFlash('success', 'Invitation envoyée à ' . $guest->getName());
            return $this->redirectToRoute('event_list');
        }

        return $this->render('event/invite.html.twig', [
            'event' => $event,
            'form' => $form->createView(),
        ]);
    }

    private function generateInvitationPdf(Guest $guest): string
    {
        // Instancier Dompdf avec des options
        $options = new Options();
        $options->set('defaultFont', 'Arial');
        $dompdf = new Dompdf($options);

        // Récupérer l'événement et construire le contenu HTML pour le PDF
        $event = $guest->getEvent();
        $link = $this->generateUrl('event_confirm', ['id' => $guest->getId()], UrlGeneratorInterface::ABSOLUTE_URL);
        $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($link);

        // Contenu HTML pour l'invitation
        $html = "
    <!DOCTYPE html>
    <html lang='fr'>
    <head>
        <meta charset='UTF-8'>
        <title>Invitation à l'événement</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 0; padding: 20px; background: #f9f9f9; }
            .invitation-container { background-color: #ffffff; border-radius: 20px; box-shadow: 0 10px 20px rgba(0,0,0,0.1); overflow: hidden; }
            .header { background: linear-gradient(135deg, #FF6B6B, #4ECDC4); color: white; padding: 30px; text-align: center; }
            .content { padding: 20px; }
            .highlight { color: #FF6B6B; font-weight: bold; }
            .qr-code { text-align: center; margin-top: 20px; }
            .qr-code img { border: 3px solid #FFD93D; border-radius: 10px; padding: 10px; }
            .footer { text-align: center; font-style: italic; color: #333; margin-top: 20px; }
        </style>
    </head>
    <body>
        <div class='invitation-container'>
            <div class='header'>
                <h1>🎉 Invitation à l'Événement 🎉</h1>
            </div>
            <div class='content'>
                <h2>Cher(e) {$guest->getName()},</h2>
                <p>Vous êtes invité(e) à l'événement : <strong>{$event->getName()}</strong>.</p>
                <p>Date : {$event->getDate()->format('Y-m-d H:i')}</p>
                <div class='qr-code'>
                    <p>Scannez le code QR pour confirmer votre présence 📲</p>
                    <img src='{$qrCodeUrl}' alt='Code QR de l'événement' />
                </div>
            </div>
            <div class='footer'>
                <p>Nous avons hâte de partager ce moment avec vous ! 🎈✨</p>
            </div>
        </div>
    </body>
    </html>";

        // Charger le contenu HTML dans Dompdf
        $dompdf->loadHtml($html);

        // (Optionnel) Configurer le format et l'orientation du papier
        $dompdf->setPaper('A4', 'portrait');

        // Rendre le PDF
        $dompdf->render();

        // Générer un nom de fichier unique pour le PDF
        $fileName = 'invitation_' . $guest->getId() . '.pdf';

        // Sauvegarder le fichier PDF sur le serveur
        file_put_contents($fileName, $dompdf->output());

        return $fileName; // Retourner le chemin du fichier PDF
    }
    private function sendInvitationEmail(MailerInterface $mailer, Guest $guest): void
    {
        $event = $guest->getEvent();
        $link = $this->generateUrl('event_confirm', ['id' => $guest->getId()], UrlGeneratorInterface::ABSOLUTE_URL);

        // Génération de l'URL pour le QR Code
        $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($link);

        // Construction du contenu HTML de l'e-mail
        $emailContent = "Votre invitation est jointe en format PDF.";

        // Générer le PDF
        $pdfFilePath = $this->generateInvitationPdf($guest);

        $email = (new Email())
            ->from('no-reply@eventmanager.com')
            ->to($guest->getEmail())
            ->subject("Invitation pour l'événement " . $event->getName())
            ->html($emailContent)
            ->attachFromPath($pdfFilePath); // Ajouter le PDF en pièce jointe

        $mailer->send($email);

        // (Optionnel) Supprimer le fichier PDF après envoi pour éviter d'encombrer le serveur
        //unlink($pdfFilePath);
    }


    #[Route('/event/guest/{id}/confirm', name: 'event_confirm', methods: ['GET'])]
    public function confirmGuest(Guest $guest): Response
    {
        // Logique pour gérer la confirmation de l'invité (e.g., mise à jour du statut)
        $guest->setStatus('confirmed'); // Exemple de mise à jour du statut
        $em = $this->getDoctrine()->getManager();
        $em->persist($guest);
        $em->flush();

        return $this->redirectToRoute('event_guests', ['id' => $guest->getEvent()->getId()]);
    }

    #[Route('/event/{id}/guests', name: 'event_guests', methods: ['GET', 'POST'])]
    public function guests(Event $event, Request $request, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        $guest = new Guest();
        $guest->setEvent($event);

        $form = $this->createForm(GuestType::class, $guest);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em->persist($guest);
            $em->flush();

            // Envoyer l'e-mail d'invitation après l'ajout de l'invité
            $this->sendInvitationEmail($mailer, $guest);

            $this->addFlash('success', 'Invité ajouté et invitation envoyée à ' . $guest->getName());
            return $this->redirectToRoute('event_guests', ['id' => $event->getId()]);
        }

        // Formulaire d'import CSV
        $csvForm = $this->createForm(CsvUploadType::class);
        $csvForm->handleRequest($request);

        if ($csvForm->isSubmitted() && $csvForm->isValid()) {
            /** @var UploadedFile $file */
            $file = $csvForm->get('file')->getData();
            if ($file) {
                try {
                    // Importer les invités depuis le fichier CSV
                    $guestsImported = $this->importCsv($file, $event, $em);
                    $this->addFlash('success', 'Invités importés depuis le fichier CSV.');

                    // Envoyer des e-mails d'invitation pour chaque invité importé
                    foreach ($guestsImported as $importedGuest) {
                        $this->sendInvitationEmail($mailer, $importedGuest);
                    }
                } catch (FileException $e) {
                    $this->addFlash('error', 'Une erreur est survenue lors de l\'importation du fichier CSV.');
                }
                return $this->redirectToRoute('event_guests', ['id' => $event->getId()]);
            }
        }

        return $this->render('event/guests.html.twig', [
            'event' => $event,
            'guests' => $event->getGuests(),
            'form' => $form->createView(),
            'csvForm' => $csvForm->createView(),
        ]);
    }


    #[Route('/event/guest/{id}/delete', name: 'guest_delete', methods: ['POST'])]
    public function deleteGuest(Guest $guest, EntityManagerInterface $em): Response
    {
        $eventId = $guest->getEvent()->getId();
        $em->remove($guest);
        $em->flush();
        $this->addFlash('success', 'Invité supprimé.');
        return $this->redirectToRoute('event_guests', ['id' => $eventId]);
    }

    private function importCsv(UploadedFile $file, Event $event, EntityManagerInterface $em): array
    {
        $guests = []; // Initialisez le tableau pour stocker les invités
        if (($handle = fopen($file->getPathname(), 'r')) !== false) {
            while (($data = fgetcsv($handle, 1000, ',')) !== false) {
                if (count($data) < 2) {
                    continue; // Ignorez les lignes qui ne contiennent pas au moins 2 colonnes
                }

                $guest = new Guest();
                $guest->setName($data[0]);
                $guest->setEmail($data[1]);
                $guest->setEvent($event);
                $em->persist($guest);
                $guests[] = $guest; // Ajoutez l'invité à la liste
            }
            fclose($handle);
            $em->flush();
        }
        return $guests; // Retournez le tableau des invités (même s'il est vide)
    }

    #[Route('/event/{id}/guest/import-csv', name: 'guest_import_csv', methods: ['GET', 'POST'])]
    public function importCsvForm(Request $request, Event $event, EntityManagerInterface $em, MailerInterface $mailer): Response
    {
        $form = $this->createForm(CsvUploadType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile $file */
            $file = $form->get('csvFile')->getData();
            if ($file) {
                try {
                    // Importation des invités
                    $guests = $this->importCsv($file, $event, $em);

                    // Envoi des invitations
                    foreach ($guests as $guest) {
                        $this->sendInvitationEmail($mailer, $guest);
                    }

                    $this->addFlash('success', 'Invités importés et invitations envoyées avec succès.');
                } catch (FileException $e) {
                    $this->addFlash('error', 'Une erreur est survenue lors de l\'importation du fichier CSV.');
                }
                return $this->redirectToRoute('event_guests', ['id' => $event->getId()]);
            }
        }

        return $this->render('event/import_csv.html.twig', [
            'form' => $form->createView(),
            'event' => $event,
        ]);
    }

    #[Route('/event/invitation/{name}', name: 'view_invitation')]
    public function viewInvitation(string $name): Response
    {
        // Générer le lien de confirmation et l'URL du code QR
        $confirmationLink = $this->generateUrl('event_confirm', ['id' => $name], UrlGeneratorInterface::ABSOLUTE_URL);
        $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . urlencode($confirmationLink);

        // Rendre le template avec les variables nécessaires
        return $this->render('event/invitation_template.html.twig', [
            'name' => $name,
            'qr_code_url' => $qrCodeUrl,
            'confirmation_link' => $confirmationLink,
        ]);
    }

}
