<?php

declare(strict_types=1);

namespace App\Controller;

use App\Chat\ChatFrame;
use App\Chat\ChatPanel;
use App\Chat\ChatPanelResult;
use App\Passkey\AppUser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Panel czatu (F6.3, D-61; docs/modules/czat.md, „Architektura”, „Oznaczenie AI”): `/chat` za passkeyem, formularz
 * z tokenem CSRF `chat`, `seq` i przełącznikiem „Szkic”. Pytanie idzie synchronicznie do `ChatTurn` w tym żądaniu;
 * odpowiedź to ramka Turbo `chat-turn` z całą odpowiedzią. Bez Messenger, workera, Mercure i transportu czatu.
 */
final class ChatController extends AbstractController
{
    private const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function __construct(private readonly ChatPanel $panel) {}

    #[Route('/chat', name: 'panel_chat', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $user = $this->user();
        $sessions = $this->panel->sessions($user->id());
        $selected = $request->query->getString('session');
        if ($selected === '' || $this->panel->owned($selected, $user->id()) === null) {
            $open = array_values(array_filter($sessions, static fn(array $session): bool => $session['closed_at'] === null));
            $selected = $open === [] ? null : $open[0]['id'];
        }
        return $this->page($sessions, $selected === null ? null : $this->panel->frame($selected));
    }

    #[Route('/chat/new', name: 'panel_chat_new', methods: ['POST'])]
    public function new(Request $request): Response
    {
        $this->csrf($request);
        try {
            $id = $this->panel->start($this->user()->id());
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
            return $this->redirectToRoute('panel_chat');
        }
        return $this->redirectToRoute('panel_chat', ['session' => $id]);
    }

    #[Route('/chat/{id}/close', name: 'panel_chat_close', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function close(string $id, Request $request): Response
    {
        $this->csrf($request);
        $this->panel->close($id, $this->user()->id());
        return $this->redirectToRoute('panel_chat');
    }

    #[Route('/chat/{id}/ask', name: 'panel_chat_ask', requirements: ['id' => self::UUID], methods: ['POST'])]
    public function ask(string $id, Request $request): Response
    {
        $this->csrf($request);
        $user = $this->user();
        if ($this->panel->owned($id, $user->id()) === null) {
            throw new NotFoundHttpException('Rozmowa nie istnieje.');
        }
        $result = $this->panel->ask($id, $user->id(), $request->request->getInt('seq'), $request->request->getString('question'), $request->request->getBoolean('draft'));
        return $this->frame($request, $id, $result);
    }

    /** Żądanie z ramki Turbo dostaje samą ramkę; zwykłe wysłanie formularza całą stronę z tą samą ramką. */
    private function frame(Request $request, string $id, ChatPanelResult $result): Response
    {
        $frame = $this->panel->frame($id, $result);
        if ($request->headers->get('Turbo-Frame') === ChatPanel::FRAME) {
            return $this->render('panel/_chat_frame.html.twig', ['frame' => $frame]);
        }
        return $this->page($this->panel->sessions($this->user()->id()), $frame);
    }

    /** @param list<array{id:string,title:?string,started_at:string,closed_at:?string,cost_usd:string}> $sessions */
    private function page(array $sessions, ?ChatFrame $frame): Response
    {
        return $this->render('panel/chat.html.twig', ['sessions' => $sessions, 'frame' => $frame]);
    }

    private function csrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('chat', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Nieprawidłowy token CSRF.');
        }
    }

    private function user(): AppUser
    {
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            throw new AccessDeniedHttpException('Brak zalogowanego użytkownika.');
        }
        return $user;
    }
}
