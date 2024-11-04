<?php

namespace App\Controller;

use App\Entity\Event;
use App\Entity\Guest;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class GuestController extends AbstractController
{
    #[Route('/event/{id}/guests', name: 'guest_list')]
    public function listGuests(Event $event): Response
    {
        $guests = $event->getGuests();

        return $this->render('guest/list.html.twig', [
            'event' => $event,
            'guests' => $guests,
        ]);
    }
}
