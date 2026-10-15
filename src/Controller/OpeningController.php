<?php

declare(strict_types=1);

namespace App\Controller;

use App\Ledger\OpeningBalance;
use App\Passkey\AppUser;
use App\Passkey\StepUp;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Formularz „Otwarcie” (docs/compliance-calendar.md, „Bilans otwarcia”, kroki 1–7; D-65): data przejścia, klucze
 * `opening.*` z plikami dowodów i „Potwierdź” ze świeżym step-upem. Kroki 6 (kursory, obowiązki zapłacone) robisz
 * w kalendarzu i synchronizacji; formularz tylko o nich przypomina.
 */
final class OpeningController extends AbstractController
{
    private const EVIDENCE_TYPES = ['application/pdf','application/xml','text/xml','text/csv','text/plain','image/jpeg','image/png'];

    public function __construct(private readonly SettingStore $settings) {}

    #[Route('/opening', name: 'panel_opening', methods: ['GET'])]
    public function index(): Response
    {
        return $this->page(null);
    }

    #[Route('/opening/confirm', name: 'panel_opening_confirm', methods: ['POST'])]
    public function confirm(Request $request, StepUp $stepUp, OpeningBalance $opening): Response
    {
        if (!$this->isCsrfTokenValid('opening', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Nieprawidłowy token CSRF.');
        }
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            throw new AccessDeniedHttpException('Brak zalogowanego użytkownika.');
        }
        $credential = $stepUp->require();
        $evidence = [];
        foreach (OpeningBalance::EVIDENCE as $key => $field) {
            $file = $request->files->get($field);
            if ($file instanceof UploadedFile && $file->isValid() && in_array($file->getMimeType(), self::EVIDENCE_TYPES, true)) {
                $bytes = file_get_contents($file->getPathname());
                $evidence[$key] = ['bytes' => $bytes === false ? '' : $bytes,'media_type' => (string) $file->getMimeType()];
            }
        }
        $reason = trim($request->request->getString('reason'));
        try {
            $values = [
                SettingKeys::OPENING_VAT_CARRY_FORWARD => trim($request->request->getString('vat_carry_forward_pln')),
                SettingKeys::OPENING_REVENUE_YTD => self::pairs($request->request->getString('revenue_ytd')),
                SettingKeys::OPENING_ZUS_PAID_YTD => ['social_pln' => trim($request->request->getString('zus_social_pln')),'health_pln' => trim($request->request->getString('zus_health_pln'))],
                SettingKeys::OPENING_LAST_INVOICE_NUMBERS => self::pairs($request->request->getString('last_invoice_numbers')),
            ];
            $opening->confirm($user->id(), $credential, $request->request->getString('transition_date'), $values, $evidence, $reason === '' ? null : $reason);
        } catch (\InvalidArgumentException | \DomainException $exception) {
            return $this->page($exception->getMessage());
        }
        $this->addFlash('notice', 'Potwierdzono bilans otwarcia.');
        return $this->redirectToRoute('panel_opening');
    }

    /** @return array<string,string> wiersze „klucz = wartość” (stawka = kwota, seria = numer) */
    private static function pairs(string $text): array
    {
        $result = [];
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }
            $parts = explode('=', $line, 2);
            if (count($parts) !== 2) {
                throw new \InvalidArgumentException('Wiersz w formacie „klucz = wartość”.');
            }
            $result[trim($parts[0])] = trim($parts[1]);
        }
        return $result;
    }

    private function page(?string $error): Response
    {
        $current = [];
        foreach (SettingKeys::OPENING_KEYS as $key) {
            $current[$key] = $this->settings->current($key);
        }
        return $this->render('panel/opening.html.twig', ['current' => $current,'confirmed' => $this->settings->openingConfirmed(),'error' => $error], new Response(status: $error === null ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
