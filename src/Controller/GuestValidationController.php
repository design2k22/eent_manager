<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GuestValidationController extends AbstractController
{
    #[Route('/validate/{id}', name: 'validate_guest', methods: ['GET'])]
    public function validateGuest(int $id, EntityManagerInterface $em): Response
    {
        // Rechercher l'invité par son ID
        $guest = $em->getRepository(Guest::class)->find($id);

        // Vérifier si l'invité existe
        if (!$guest) {
            return new Response('Erreur : invité introuvable.', Response::HTTP_NOT_FOUND);
        }

        // Vérifier si l'invité est déjà validé
        if ($guest->getStatus() === 'present') {
            return new Response('Cet invité a déjà été validé.', Response::HTTP_OK);
        }

        // Mettre à jour le statut de l'invité à "présent"
        $guest->setStatus('present');
        $em->flush();

        return new Response('Bienvenue, ' . $guest->getName() . '! Vous êtes maintenant marqué comme présent.', Response::HTTP_OK);
    }
}
