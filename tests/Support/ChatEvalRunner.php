<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Chat\ChatToolRegistry;
use App\Connector\Claude\ChatRequestProfile;
use Brick\Math\BigDecimal;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Yaml\Yaml;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Zestaw ewaluacyjny czatu (D-114): przypadki z `tests/Fixtures/Claude/eval/cases.yaml` na fikcyjnym zbiorze, ta sama
 * tura `ChatTurn`, executor i nazwane zapytania co w panelu. Offline odtwarza nagrania z tego katalogu przez
 * `MockHttpClient`; live woła Claude API workspace testowego. Ocena: udane wywołania narzędzi z oczekiwanymi polami,
 * odrzucone wywołania z `replay`, liczby obecne w wynikach narzędzi albo w snapshocie prawa i w odpowiedzi.
 *
 * @phpstan-type EvalCall array{name:string,input:array<string,mixed>,step?:int,error?:string}
 * @phpstan-type EvalCase array{id:string,category:string,question:string,tools:list<EvalCall>,no_tools:bool,replay:list<EvalCall>,numbers:list<string>}
 * @phpstan-type EvalProfile array{model:string,effort:string,tool_set:string,system_prompt_sha256:string}
 * @phpstan-type EvalResult array{id:string,category:string,status:string,failures:list<string>,calls:list<array{name:string,input:array<string,mixed>,error:?string}>,requests:int,cost_usd:string,unsupported_numbers:list<string>}
 */
final class ChatEvalRunner
{
    public const DIRECTORY = __DIR__ . '/../Fixtures/Claude/eval';
    public const NOW = '2026-10-14T10:00:00Z';
    /** Próg trafności przebiegu live; offline wymaga wszystkich przypadków. */
    public const MIN_LIVE_PASS_RATIO = '0.90';
    /** Fikcyjny snapshot prawa zestawu: liczby przypadków kategorii `law`. */
    public const SNAPSHOT = ['items' => [
        ['name' => 'Stawka VAT podstawowa (fictional)', 'value' => '23%', 'legal_source' => 'art. 41 ust. 1 ustawy o VAT'],
        ['name' => 'Stawka ryczałtu dla usług IT (fictional)', 'value' => '12%', 'legal_source' => 'art. 12 ust. 1 ustawy o ryczałcie'],
        ['name' => 'Limit sprzedaży zwolnienia z VAT (fictional)', 'value' => '240000 zł', 'legal_source' => 'art. 113 ust. 1 ustawy o VAT'],
    ], 'note' => 'fikcyjny snapshot zestawu ewaluacyjnego'];
    private const NUMBER = '/\d{1,3}(?:[ \x{00A0}\x{202F}]\d{3})+(?:,\d+)?|\d+(?:[.,]\d+)?/u';

    private ?string $session = null;

    public function __construct(private readonly Connection $db, private readonly MockClock $clock, private readonly ChatRequestProfile $profile, private readonly ChatToolRegistry $registry, private readonly string $systemPrompt) {}

    /** @return EvalProfile odcisk konfiguracji czatu; zmiana któregokolwiek pola wymaga nowego przebiegu zestawu */
    public function profile(): array
    {
        return ['model' => $this->profile->model, 'effort' => $this->profile->effort, 'tool_set' => $this->registry->toolSet(), 'system_prompt_sha256' => hash('sha256', $this->systemPrompt)];
    }

    /** @return list<EvalCase> */
    public static function cases(): array
    {
        $data = Yaml::parseFile(self::DIRECTORY . '/cases.yaml');
        $cases = [];
        foreach (is_array($data) && is_array($data['cases'] ?? null) ? $data['cases'] : throw new \LogicException('Brak listy cases.') as $case) {
            if (!is_array($case) || !is_string($case['id'] ?? null) || !is_string($case['category'] ?? null) || !is_string($case['question'] ?? null)) {
                throw new \LogicException('Niepoprawny przypadek zestawu.');
            }
            $numbers = $case['numbers'] ?? [];
            $cases[] = [
                'id' => $case['id'],
                'category' => $case['category'],
                'question' => $case['question'],
                'tools' => self::calls($case['tools'] ?? []),
                'no_tools' => ($case['no_tools'] ?? false) === true,
                'replay' => self::calls($case['replay'] ?? []),
                'numbers' => is_array($numbers) ? array_map(static fn(mixed $number): string => is_string($number) ? $number : throw new \LogicException('Liczba przypadku musi być napisem.'), array_values($numbers)) : [],
            ];
        }
        return $cases;
    }

    /** @return array<string,mixed> */
    public static function manifest(): array
    {
        return ClaudeTestTools::decode((string) file_get_contents(self::DIRECTORY . '/manifest.json'));
    }

    /** @return list<array<string,mixed>> odpowiedzi Messages API nagrania przypadku w kolejności żądań */
    public static function recording(string $id): array
    {
        $file = self::DIRECTORY . '/' . $id . '.json';
        if (!is_file($file)) {
            throw new \LogicException(sprintf('Brak nagrania przypadku %s.', $id));
        }
        return ClaudeTestTools::objects(ClaudeTestTools::decode((string) file_get_contents($file)), 'responses');
    }

    /** Fikcyjny zbiór czatu, zapłacone obowiązki za wrzesień i snapshot prawa zestawu; zwraca id właściciela. */
    public function seed(): string
    {
        $ids = ChatTestTools::seed($this->db, $this->clock);
        ChatTestTools::obligation($this->db, $this->clock, 'test', 'ryczalt_advance', '2026-10-20', '291.00', 'done', ['period_start' => '2026-09-01', 'done_at' => '2026-10-15T08:00:00Z']);
        ChatTestTools::obligation($this->db, $this->clock, 'test', 'zus', '2026-10-20', '1800.00', 'done', ['period_start' => '2026-09-01', 'done_at' => '2026-10-15T08:00:00Z']);
        ClaudeTestTools::lawSnapshot($this->db, $this->clock, '2026-01-01', self::SNAPSHOT);
        return $ids['owner'];
    }

    /**
     * Jedno pytanie w nowej rozmowie przez `ChatTurn` nad podanym klientem HTTP; budżet czatu poza oceną (przebieg ma
     * własny budżet `chat.live_run_budget_usd`).
     *
     * @param EvalCase $case
     * @return EvalResult
     */
    public function run(array $case, HttpClientInterface $http, string $owner): array
    {
        $session = $this->session = ClaudeTestTools::sessions($this->db, $this->clock, $this->profile, $this->registry, $this->systemPrompt)->start($owner);
        $turn = ClaudeTestTools::turn($this->db, $this->clock, $http, ['registry' => $this->registry, 'profile' => $this->profile, 'prompt' => $this->systemPrompt, 'budget' => ClaudeTestTools::budget($this->db, $this->clock, '1000.00', '1000.00')]);
        $result = $turn->ask($session, $case['question']);
        $uses = [];
        $outcomes = [];
        foreach ($this->db->fetchAllAssociative('SELECT role, content::text AS content FROM chat_message WHERE chat_session_id = ? ORDER BY seq', [$session]) as $row) {
            foreach (ClaudeTestTools::decodeList(is_string($row['content']) ? $row['content'] : '[]') as $block) {
                if (!is_array($block)) {
                    continue;
                }
                if (($block['type'] ?? null) === 'tool_use' && is_string($block['id'] ?? null) && is_string($block['name'] ?? null)) {
                    $uses[$block['id']] = ['name' => $block['name'], 'input' => is_array($block['input'] ?? null) ? self::stringKeys($block['input']) : []];
                }
                if (($block['type'] ?? null) === 'tool_result' && is_string($block['tool_use_id'] ?? null)) {
                    $outcomes[$block['tool_use_id']] = ['content' => is_string($block['content'] ?? null) ? $block['content'] : '', 'error' => ($block['is_error'] ?? false) === true];
                }
            }
        }
        $calls = [];
        $sources = json_encode(self::SNAPSHOT, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach ($uses as $id => $use) {
            $outcome = $outcomes[$id] ?? ['content' => '', 'error' => true];
            $error = null;
            if ($outcome['error']) {
                $decoded = json_decode($outcome['content'], true);
                $error = is_array($decoded) && is_string($decoded['error'] ?? null) ? $decoded['error'] : 'error';
            } else {
                $sources .= "\n" . $outcome['content'];
            }
            $calls[] = $use + ['error' => $error];
        }
        $cost = $this->db->fetchOne('SELECT cost_usd::text FROM chat_session WHERE id = ?', [$session]);
        $failures = self::failures($case, $calls, $result->error?->value, $result->text, $sources);
        return [
            'id' => $case['id'],
            'category' => $case['category'],
            'status' => $failures === [] ? 'pass' : 'fail',
            'failures' => $failures,
            'calls' => $calls,
            'requests' => $result->requests,
            'cost_usd' => is_string($cost) ? $cost : '0',
            'unsupported_numbers' => self::unsupported($result->text, $sources . "\n" . $case['question']),
        ];
    }

    /** Rozmowa ostatniego `run`; nagranie live czyta z niej odpowiedzi. */
    public function lastSession(): string
    {
        return $this->session ?? throw new \LogicException('Brak rozmowy zestawu.');
    }

    /**
     * @param EvalCase $case
     * @return EvalResult
     */
    public static function skipped(array $case, string $reason): array
    {
        return ['id' => $case['id'], 'category' => $case['category'], 'status' => 'skipped', 'failures' => [$reason], 'calls' => [], 'requests' => 0, 'cost_usd' => '0', 'unsupported_numbers' => []];
    }

    /**
     * Raport przebiegu jako JSON i Markdown w `var/chat-eval/<mode>.{json,md}` oraz `cmd/chat-eval-<mode>.txt` paczki
     * dowodów; zwraca treść Markdown.
     *
     * @param list<EvalResult> $results
     * @param EvalProfile $profile
     */
    public static function report(string $mode, array $profile, array $results): string
    {
        $count = static fn(string $status): int => count(array_filter($results, static fn(array $result): bool => $result['status'] === $status));
        $cost = array_reduce($results, static fn(BigDecimal $sum, array $result): BigDecimal => $sum->plus($result['cost_usd']), BigDecimal::zero());
        $summary = ['mode' => $mode, 'profile' => $profile, 'cases' => count($results), 'pass' => $count('pass'), 'fail' => $count('fail'), 'skipped' => $count('skipped'), 'pass_ratio' => self::ratio($results), 'cost_usd' => (string) $cost];
        $lines = [sprintf('# Zestaw ewaluacyjny czatu: %s (D-114)', $mode), '', sprintf('Profil: `%s`, effort `%s`, `tool_set` `%s`, prompt sha256 `%s`.', $profile['model'], $profile['effort'], $profile['tool_set'], $profile['system_prompt_sha256']), '', sprintf('Wynik: %d/%d pass, %d fail, %d pominiętych; trafność %s; koszt %s USD.', $summary['pass'], $summary['cases'], $summary['fail'], $summary['skipped'], $summary['pass_ratio'], $summary['cost_usd']), '', '| Przypadek | Kategoria | Wynik | Narzędzia | Uwagi |', '| --- | --- | --- | --- | --- |'];
        foreach ($results as $result) {
            $tools = implode(', ', array_map(static fn(array $call): string => $call['name'] . ($call['error'] === null ? '' : ' (' . $call['error'] . ')'), $result['calls']));
            $notes = implode('; ', $result['failures']) . ($result['unsupported_numbers'] === [] ? '' : ' liczby spoza źródeł: ' . implode(', ', $result['unsupported_numbers']));
            $lines[] = sprintf('| `%s` | %s | %s | %s | %s |', $result['id'], $result['category'], $result['status'], $tools === '' ? '–' : $tools, $notes === '' ? '–' : str_replace('|', '/', trim($notes)));
        }
        $markdown = implode("\n", $lines) . "\n";
        $directory = dirname(__DIR__, 2) . '/var/chat-eval';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Nie można zapisać raportu zestawu.');
        }
        file_put_contents($directory . '/' . $mode . '.json', json_encode($summary + ['results' => $results], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
        file_put_contents($directory . '/' . $mode . '.md', $markdown);
        EvidenceArtefacts::write('cmd/chat-eval-' . $mode . '.txt', $markdown);
        return $markdown;
    }

    /** @param list<EvalResult> $results udział `pass` wśród wszystkich przypadków, z czterema miejscami po przecinku */
    public static function ratio(array $results): string
    {
        $pass = count(array_filter($results, static fn(array $result): bool => $result['status'] === 'pass'));
        return $results === [] ? '0' : (string) BigDecimal::of($pass)->dividedBy(count($results), 4, \Brick\Math\RoundingMode::DOWN);
    }

    /**
     * @param EvalCase $case
     * @param list<array{name:string,input:array<string,mixed>,error:?string}> $calls
     * @return list<string>
     */
    private static function failures(array $case, array $calls, ?string $error, string $answer, string $sources): array
    {
        $failures = [];
        if ($error !== null) {
            $failures[] = 'tura zakończona błędem ' . $error;
        }
        $succeeded = array_values(array_filter($calls, static fn(array $call): bool => $call['error'] === null));
        foreach ($case['tools'] as $expected) {
            $matched = array_filter($succeeded, static fn(array $call): bool => self::matches($expected, $call));
            if ($matched === []) {
                $failures[] = sprintf('brak udanego %s %s', $expected['name'], json_encode($expected['input'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            }
        }
        if ($case['no_tools'] && $succeeded !== []) {
            $failures[] = 'narzędzie mimo no_tools: ' . implode(', ', array_column($succeeded, 'name'));
        }
        foreach ($case['replay'] as $expected) {
            foreach ($calls as $call) {
                if (self::matches($expected, $call) && $call['error'] !== ($expected['error'] ?? null)) {
                    $failures[] = sprintf('%s: oczekiwane odrzucenie %s, jest %s', $expected['name'], $expected['error'] ?? '–', $call['error'] ?? 'wynik');
                }
            }
        }
        $given = self::numbers($answer);
        $grounded = self::numbers($sources);
        foreach ($case['numbers'] as $number) {
            if (!self::contains($grounded, BigDecimal::of($number))) {
                $failures[] = sprintf('liczba %s poza wynikami narzędzi i snapshotem', $number);
            }
            if (!self::contains($given, BigDecimal::of($number))) {
                $failures[] = sprintf('liczba %s poza odpowiedzią', $number);
            }
        }
        return $failures;
    }

    /**
     * @param EvalCall $expected
     * @param array{name:string,input:array<string,mixed>,error:?string} $call
     */
    private static function matches(array $expected, array $call): bool
    {
        if ($expected['name'] !== $call['name']) {
            return false;
        }
        foreach ($expected['input'] as $key => $value) {
            if (!array_key_exists($key, $call['input']) || $call['input'][$key] !== $value) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> liczby odpowiedzi bez pokrycia w wynikach narzędzi, snapshocie ani pytaniu (tylko raport, R-41) */
    private static function unsupported(string $answer, string $sources): array
    {
        $grounded = self::numbers($sources);
        $out = [];
        foreach (self::numbers($answer) as $number) {
            if (!self::contains($grounded, $number)) {
                $out[] = (string) $number;
            }
        }
        return array_values(array_unique($out));
    }

    /** @return list<BigDecimal> liczby z tekstu; zapis polski („1 926,76”) i JSON („1926.76”) dają tę samą wartość */
    public static function numbers(string $text): array
    {
        preg_match_all(self::NUMBER, $text, $matches);
        return array_map(static fn(string $token): BigDecimal => BigDecimal::of(str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], $token)), $matches[0]);
    }

    /** @param list<BigDecimal> $numbers */
    private static function contains(array $numbers, BigDecimal $number): bool
    {
        foreach ($numbers as $candidate) {
            if ($candidate->isEqualTo($number)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<EvalCall> */
    private static function calls(mixed $calls): array
    {
        $out = [];
        foreach (is_array($calls) ? $calls : [] as $call) {
            if (!is_array($call) || !is_string($call['name'] ?? null) || !is_array($call['input'] ?? null)) {
                throw new \LogicException('Niepoprawne wywołanie w zestawie.');
            }
            $entry = ['name' => $call['name'], 'input' => self::stringKeys($call['input'])];
            if (is_int($call['step'] ?? null)) {
                $entry['step'] = $call['step'];
            }
            if (is_string($call['error'] ?? null)) {
                $entry['error'] = $call['error'];
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * @param array<mixed> $value
     * @return array<string,mixed>
     */
    private static function stringKeys(array $value): array
    {
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $item;
        }
        return $out;
    }
}
