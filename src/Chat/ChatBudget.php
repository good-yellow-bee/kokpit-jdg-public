<?php

declare(strict_types=1);

namespace App\Chat;

use App\Connector\Claude\ClaudeError;
use App\Connector\Claude\ClaudeException;
use App\Time\BusinessDate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Koszt i budżet czatu (docs/modules/czat.md, „Budżet i koszt”): `cost_usd` z `usage` i cennika `chat.pricing`
 * (brick/math, 6 miejsc, NUMERIC(12,6)); sumy dzienne i miesięczne z `chat_message.cost_usd` w oknach Europe/Warsaw.
 * Próg `chat.budget_alert_ratio` → push `budget_alert` raz na okno; 100 % → push i odmowa przed wywołaniem API.
 */
final readonly class ChatBudget
{
    public const LINK = '/chat';
    private const MTOK = 1000000;
    private const SCALE = 6;

    /**
     * @param array{threshold_tokens:int,standard:array<string,string>,long:array<string,string>} $pricing
     */
    public function __construct(private Connection $db, private ClockInterface $clock, private BusinessDate $dates, private string $dailyUsd, private string $monthlyUsd, private string $alertRatio, private array $pricing)
    {
        foreach (['standard', 'long'] as $tier) {
            foreach (['input', 'output', 'cache_read', 'cache_write'] as $kind) {
                BigDecimal::of($pricing[$tier][$kind] ?? throw new \InvalidArgumentException(sprintf('chat.pricing.%s.%s jest wymagane.', $tier, $kind)));
            }
        }
        if ($pricing['threshold_tokens'] < 1) {
            throw new \InvalidArgumentException('chat.pricing.threshold_tokens musi być dodatni.');
        }
    }

    /**
     * Koszt jednego żądania; prompt (wejście i cache) powyżej progu liczy się według stawek `long`.
     *
     * @param array{input_tokens:int,output_tokens:int,cache_creation_input_tokens:int,cache_read_input_tokens:int} $usage
     */
    public function cost(array $usage): BigDecimal
    {
        $prompt = $usage['input_tokens'] + $usage['cache_read_input_tokens'] + $usage['cache_creation_input_tokens'];
        $prices = $prompt > $this->pricing['threshold_tokens'] ? $this->pricing['long'] : $this->pricing['standard'];
        $total = BigDecimal::of($prices['input'])->multipliedBy($usage['input_tokens'])
            ->plus(BigDecimal::of($prices['output'])->multipliedBy($usage['output_tokens']))
            ->plus(BigDecimal::of($prices['cache_read'])->multipliedBy($usage['cache_read_input_tokens']))
            ->plus(BigDecimal::of($prices['cache_write'])->multipliedBy($usage['cache_creation_input_tokens']));
        return $total->dividedBy(self::MTOK, self::SCALE, RoundingMode::HALF_UP);
    }

    /** Odmowa przed wywołaniem API przy wyczerpanym budżecie dziennym albo miesięcznym, z pushem. */
    public function assertAvailable(): void
    {
        if ($this->alerts()) {
            throw new ClaudeException(ClaudeError::BudgetExceeded);
        }
    }

    /** Push `budget_alert` przy progu alertu i przy 100 % (raz na okno); zwraca, czy budżet jest wyczerpany. */
    public function alerts(): bool
    {
        $midnight = $this->dates->midnight();
        $month = $midnight->modify('first day of this month');
        $exceeded = false;
        foreach ([['daily', $midnight, $this->dailyUsd, $midnight->format('Y-m-d')], ['monthly', $month, $this->monthlyUsd, $month->format('Y-m')]] as [$window, $start, $limit, $label]) {
            $spent = $this->spentSince($start);
            $budget = BigDecimal::of($limit);
            if ($spent->isGreaterThanOrEqualTo($budget)) {
                $exceeded = true;
                $this->push(sprintf('chat:budget:%s:%s:exceeded', $window, $label), sprintf('budget_alert: budżet czatu (%s, %s) wyczerpany: %s z %s USD; czat odrzuca pytania; %s', $window, $label, $spent, $budget, self::LINK));
            } elseif ($spent->isGreaterThanOrEqualTo($budget->multipliedBy($this->alertRatio))) {
                $this->push(sprintf('chat:budget:%s:%s:alert', $window, $label), sprintf('budget_alert: czat zużył %s z %s USD budżetu (%s, %s); %s', $spent, $budget, $window, $label, self::LINK));
            }
        }
        return $exceeded;
    }

    public function spentSince(\DateTimeImmutable $start): BigDecimal
    {
        $sum = $this->db->fetchOne('SELECT COALESCE(sum(cost_usd), 0)::text FROM chat_message WHERE created_at >= ?', [$start->format('Y-m-d\TH:i:sP')]);
        return BigDecimal::of(is_string($sum) ? $sum : '0');
    }

    private function push(string $dedupeKey, string $body): void
    {
        $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        $this->db->executeStatement(
            'INSERT INTO notification (id,channel,category,dedupe_key,body,deep_link,scheduled_for,attempts,created_at) VALUES (?,?,?,?,?,?,?,0,?) ON CONFLICT (dedupe_key) DO NOTHING',
            [UuidV7::generate($this->clock->now()), 'push', 'budget_alert', $dedupeKey, $body, self::LINK, $now, $now],
        );
    }
}
