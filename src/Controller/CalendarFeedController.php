<?php

declare(strict_types=1);

namespace App\Controller;

use App\Calendar\CalendarFeed;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/** Kanał ICS obowiązków (D-77): bez sesji i passkeya, tylko z tokenem z `app:calendar:feed-link`; zły token daje 404. */
final class CalendarFeedController
{
    #[Route('/calendar-feed/{token}.ics', name: 'calendar_feed', methods: ['GET'], requirements: ['token' => '[A-Za-z0-9_-]{43}'])]
    public function __invoke(string $token, Request $request, CalendarFeed $feed, UrlGeneratorInterface $urls): Response
    {
        if ($feed->userForToken($token) === null) {
            throw new NotFoundHttpException();
        }
        return new Response($feed->render($urls->generate('panel_calendar', [], UrlGeneratorInterface::ABSOLUTE_URL)), Response::HTTP_OK, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Cache-Control' => 'no-store, private',
            'Content-Disposition' => 'inline; filename="kokpit-obowiazki.ics"',
        ]);
    }
}
