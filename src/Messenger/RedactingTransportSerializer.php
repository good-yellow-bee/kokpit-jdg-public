<?php

declare(strict_types=1);

namespace App\Messenger;

use App\Security\Redactor;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\SerializedMessageStamp;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Scheduler\Messenger\ScheduledStamp;

final readonly class RedactingTransportSerializer implements SerializerInterface
{
    public function __construct(private Redactor $redactor) {}
    public function encode(Envelope $envelope): array
    {
        $values = get_object_vars($envelope->getMessage());
        if (array_keys($values) !== ['id'] || !is_string($values['id']) || strlen($values['id']) > 128 || !preg_match('/^[a-zA-Z0-9_.:-]+$/D', $values['id'])) {
            throw new \InvalidArgumentException('Wiadomości mogą zawierać tylko identyfikator.');
        }
        // ScheduledStamp (Scheduler dokleja go do wiadomości z RedispatchMessage) niesie wyzwalacz, którego domyślny
        // serializer nie odtworzy; worker padałby na każdej wiadomości z harmonogramu (D-98). Handlery go nie czytają.
        $encoded = Serializer::create()->encode($envelope->withoutAll(SerializedMessageStamp::class)->withoutAll(ScheduledStamp::class));
        foreach ($encoded['headers'] ?? [] as $key => $value) {
            if (str_starts_with($key, 'X-Message-Stamp-')) {
                $encoded['headers'][$key] = json_encode($this->redactor->redact(json_decode($value, true, flags: JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
            }
        }
        return $encoded;
    }
    public function decode(array $encodedEnvelope): Envelope
    {
        return Serializer::create()->decode($encodedEnvelope);
    }
}
