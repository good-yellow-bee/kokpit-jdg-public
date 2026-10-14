<?php

declare(strict_types=1);

namespace App\Chat;

use App\Connector\Claude\ChatRequestProfile;
use App\Setting\SettingKeys;
use App\Time\BusinessDate;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Uid\UuidV7;

/**
 * Rozmowy czatu (docs/modules/czat.md, „Historia rozmowy”, „Wiedza podatkowa”): model, effort, `tool_set`, hash
 * promptu systemowego i `law_snapshot_from` zapisane na starcie. Zmiana konfiguracji nie zmienia otwartej rozmowy:
 * `resolve` otwiera nową, a stara zostaje bez zmian. Snapshot prawa to dokładnie wiersz `setting` z `valid_from`
 * rozmowy, więc późniejsze wiersze nie zmieniają stałego prefiksu.
 */
final readonly class ChatSessions
{
    public function __construct(private Connection $db, private ClockInterface $clock, private BusinessDate $dates, private ChatRequestProfile $profile, private ChatToolRegistry $registry, private string $systemPrompt)
    {
        if (trim($systemPrompt) === '') {
            throw new \InvalidArgumentException('chat.system_prompt jest wymagany.');
        }
    }

    public function start(string $userId): string
    {
        $validFrom = $this->db->fetchOne('SELECT valid_from::text FROM setting WHERE key = ? AND valid_from <= ? ORDER BY valid_from DESC LIMIT 1', [SettingKeys::CHAT_LAW_SNAPSHOT, $this->dates->today()]);
        if (!is_string($validFrom)) {
            throw new \DomainException('Brak ustawienia chat.law_snapshot: rozmowa nie startuje bez snapshotu prawa (D-60).');
        }
        $id = (string) UuidV7::generate($this->clock->now());
        $now = $this->clock->now()->format('Y-m-d\TH:i:s.uP');
        $this->db->insert('chat_session', ['id' => $id, 'app_user_id' => $userId, 'model' => $this->profile->model, 'effort' => $this->profile->effort, 'tool_set' => $this->registry->toolSet(), 'system_prompt_sha256' => $this->systemPromptSha(), 'law_snapshot_from' => $validFrom, 'started_at' => $now, 'pseudonym_map' => '{}', 'created_at' => $now, 'updated_at' => $now]);
        return $id;
    }

    /** Otwarta rozmowa zgodna z bieżącą konfiguracją albo nowa rozmowa. */
    public function resolve(?string $sessionId, string $userId): string
    {
        if ($sessionId !== null) {
            $row = $this->find($sessionId);
            if ($row !== null && $row['app_user_id'] === $userId && $row['closed_at'] === null && $this->matches($row)) {
                return $sessionId;
            }
        }
        return $this->start($userId);
    }

    /**
     * @param array{model:string,effort:string,tool_set:string,system_prompt_sha256:string} $row
     */
    public function matches(array $row): bool
    {
        return $row['model'] === $this->profile->model && $row['effort'] === $this->profile->effort && $row['tool_set'] === $this->registry->toolSet() && $row['system_prompt_sha256'] === $this->systemPromptSha();
    }

    /**
     * @return array{id:string,app_user_id:string,model:string,effort:string,tool_set:string,system_prompt_sha256:string,law_snapshot_from:string,closed_at:?string,pseudonym_map:string}|null
     */
    public function find(string $sessionId): ?array
    {
        $row = $this->db->fetchAssociative('SELECT id::text AS id, app_user_id::text AS app_user_id, model, effort, tool_set, system_prompt_sha256, law_snapshot_from::text AS law_snapshot_from, closed_at::text AS closed_at, pseudonym_map::text AS pseudonym_map FROM chat_session WHERE id = ?', [$sessionId]);
        if ($row === false) {
            return null;
        }
        $out = [];
        foreach (['id', 'app_user_id', 'model', 'effort', 'tool_set', 'system_prompt_sha256', 'law_snapshot_from', 'pseudonym_map'] as $key) {
            $out[$key] = is_string($row[$key]) ? $row[$key] : throw new \LogicException('Niepoprawny wiersz rozmowy.');
        }
        $out['closed_at'] = is_string($row['closed_at']) ? $row['closed_at'] : null;
        return $out;
    }

    public function systemPrompt(): string
    {
        return $this->systemPrompt;
    }

    public function systemPromptSha(): string
    {
        return hash('sha256', $this->systemPrompt);
    }

    /** Tekst snapshotu prawa rozmowy: dokładnie wiersz z `valid_from` zapisanym w `law_snapshot_from`. */
    public function lawSnapshot(string $validFrom): string
    {
        $value = $this->db->fetchOne('SELECT value::text FROM setting WHERE key = ? AND valid_from = ?', [SettingKeys::CHAT_LAW_SNAPSHOT, $validFrom]);
        if (!is_string($value)) {
            throw new \DomainException('Brak wiersza chat.law_snapshot rozmowy.');
        }
        $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
        return sprintf("Snapshot prawa chat.law_snapshot, valid_from %s. Kwoty, stawki, progi i terminy podajesz tylko z tego snapshotu albo z wyników narzędzi; inaczej mówisz, że nie wiesz.\n%s", $validFrom, json_encode($decoded, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
}
