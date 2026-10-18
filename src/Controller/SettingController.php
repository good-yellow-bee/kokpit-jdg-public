<?php

declare(strict_types=1);

namespace App\Controller;

use App\Messenger\Message\ObligationRecomputeMessage;
use App\Passkey\AppUser;
use App\Setting\SettingKeys;
use App\Setting\SettingStore;
use App\Time\BusinessDate;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class SettingController extends AbstractController
{
    #[Route('/settings', name: 'panel_settings', methods: ['GET'])]
    public function index(SettingStore $settings, BusinessDate $date): Response
    {
        $current = [];
        foreach (SettingKeys::KEYS as $key) {
            $current[$key] = $settings->current($key);
        }
        return $this->render('panel/settings.html.twig', ['current' => $current,'today' => $date->today(),'error' => null]);
    }
    #[Route('/settings/{key}', name: 'panel_setting_change', methods: ['POST'], requirements: ['key' => 'company\.identity|jpk_st\.enabled|pit\.kasowy|company\.tax_micro_account|company\.zus_account|zus\.parameters|zus\.variant|ryczalt\.advance_frequency|vat\.standard_rate|white_list\.pre_payment_threshold_pln|ryczalt\.service_map|ledger\.auto_entries_enabled|ksef\.sales_transition_on|ryczalt\.eligibility|freshbooks\.sales_enabled|ksef\.fa3_review|pit_28\.review|chat\.law_snapshot|vat\.excess_policy|wht\.rates|opening\.vat_carry_forward_pln|opening\.revenue_ytd|opening\.zus_paid_ytd|opening\.last_invoice_numbers'])]
    public function change(string $key, Request $request, SettingStore $settings, BusinessDate $date, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('setting', $request->request->getString('_token'))) {
            throw new AccessDeniedHttpException('Nieprawidłowy token CSRF.');
        }
        $user = $this->getUser();
        if (!$user instanceof AppUser) {
            throw new AccessDeniedHttpException('Brak zalogowanego użytkownika.');
        }
        $validFrom = $request->request->getString('valid_from');
        $note = trim($request->request->getString('note'));
        try {
            if ($key === SettingKeys::COMPANY_IDENTITY) {
                $fields = [];
                foreach (['nip','tax_office_code','first_name','last_name','email','phone','vat_eu_number'] as $field) {
                    $fields[$field] = trim($request->request->getString($field));
                }
                // Rachunki firmowe do samosprawdzenia w białej liście: jeden numer w wierszu.
                $fields['bank_accounts'] = array_values(array_filter(array_map('trim', preg_split('/\R/', $request->request->getString('bank_accounts')) ?: []), static fn(string $line): bool => $line !== ''));
                // Formularz zmienia część JPK; część FA(3) przechodzi bez zmian z wiersza obowiązującego.
                $previous = $settings->value($key, $validFrom !== '' ? $validFrom : null);
                $value = array_merge(is_array($previous) ? $previous : [], $fields);
            } elseif (in_array($key, [SettingKeys::JPK_ST_ENABLED, SettingKeys::PIT_KASOWY, SettingKeys::LEDGER_AUTO_ENTRIES, SettingKeys::FRESHBOOKS_SALES_ENABLED], true)) {
                $value = $request->request->getString('value') === '1';
            } elseif (in_array($key, [SettingKeys::RYCZALT_SERVICE_MAP, SettingKeys::KSEF_FA3_REVIEW, SettingKeys::PIT_28_REVIEW, SettingKeys::CHAT_LAW_SNAPSHOT, SettingKeys::WHT_RATES, SettingKeys::OPENING_REVENUE_YTD, SettingKeys::OPENING_ZUS_PAID_YTD, SettingKeys::OPENING_LAST_INVOICE_NUMBERS], true)) {
                try {
                    $value = json_decode($request->request->getString('value'), true, 8, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    throw new \InvalidArgumentException($key . ' to JSON.');
                }
            } elseif ($key === SettingKeys::RYCZALT_ELIGIBILITY) {
                $watch = trim($request->request->getString('watch_limit_eur'));
                $quarterly = trim($request->request->getString('quarterly_limit_eur'));
                $value = ['limit_eur' => trim($request->request->getString('limit_eur')),'watch_limit_eur' => $watch === '' ? null : $watch,'quarterly_limit_eur' => $quarterly === '' ? null : $quarterly];
            } elseif ($key === SettingKeys::ZUS_PARAMETERS) {
                $brackets = [];
                for ($i = 1; $i <= 3; $i++) {
                    $upTo = trim($request->request->getString('bracket' . $i . '_up_to'));
                    $brackets[] = ['revenue_up_to_pln' => $upTo === '' ? null : $upTo,'base_pln' => trim($request->request->getString('bracket' . $i . '_base')),'amount_pln' => trim($request->request->getString('bracket' . $i . '_amount'))];
                }
                $value = ['social_base_pln' => trim($request->request->getString('social_base_pln')),'social_pln' => trim($request->request->getString('social_pln')),'other_pln' => trim($request->request->getString('other_pln')),'health_brackets' => $brackets,'health_bracket_manual' => $request->request->getInt('health_bracket_manual')];
            } else {
                $value = trim($request->request->getString('value'));
            }
            $legalSource = trim($request->request->getString('legal_source'));
            $settings->set($key, $value, $validFrom, $user->id(), $note !== '' ? $note : null, $legalSource !== '' ? $legalSource : null);
        } catch (\InvalidArgumentException | \DomainException $exception) {
            $current = [];
            foreach (SettingKeys::KEYS as $item) {
                $current[$item] = $settings->current($item);
            }
            return $this->render('panel/settings.html.twig', ['current' => $current,'today' => $date->today(),'error' => $exception->getMessage()], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }
        // Zmiana `setting` przelicza obowiązki (docs/compliance-calendar.md, „Przebieg”, krok 1).
        $bus->dispatch(new ObligationRecomputeMessage('obligations.recompute'));
        $this->addFlash('notice', sprintf('Zapisano %s od %s.', $key, $validFrom));
        return $this->redirectToRoute('panel_settings');
    }
}
