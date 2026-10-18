<?php

namespace App\Security;

use Monolog\LogRecord;

final class Redactor
{
    /** @var list<string> */ private array $secrets = [];
    public function registerSecret(string $value): void
    {
        if (strlen($value) >= 4 && !in_array($value, $this->secrets, true)) {
            $this->secrets[] = $value;
        }
    }
    public function text(string $text): string
    {
        if ($this->secrets !== []) {
            $text = str_replace($this->secrets, '[REDACTED]', $text);
        }
        $patterns = [
            '~-----BEGIN [^-]+-----.*?-----END [^-]+-----~s',
            '~\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b~',
            '~\bBearer\s+[^\s"<>]+~i',
            '~\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]){11,30}\b~',
            // PESEL: 11 cyfr bez sąsiedniej litery, żeby nie maskować cyfr w UUID (np. `…-a36971627276`).
            '~(?<![0-9A-Za-z])\d{11}(?![0-9A-Za-z])~',
            '~(?<!\d)(?:\d[ ]?){26}(?!\d)~',
            '~\b[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}\b~',
            '~(?<=/passkey/enrol/)[A-Za-z0-9_-]{43}~',
            '~([?&](?:token|code|key|secret)=)[^&\s]+~i',
            '~\b(?:token|password|secret|authorization|cookie|pesel|birth_date|auth_data|revenue_n2|iban|account)\s*[:=]\s*[^\s,;]+~i',
        ];
        foreach ($patterns as $pattern) {
            $text = preg_replace($pattern, '[REDACTED]', $text) ?? throw new \RuntimeException('Błąd maskowania.');
        }
        return $text;
    }
    public function redact(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->text($value);
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = is_string($key) && preg_match('/authorization|cookie|token|password|secret|private.?key|pesel|birth.?date|auth.?data|revenue.?n2|iban|account/i', $key) ? '[REDACTED]' : $this->redact($item);
            }
            return $result;
        }
        if ($value instanceof \Throwable) {
            return ['exception' => $value::class, 'message' => $this->text($value->getMessage())];
        }
        if (is_object($value)) {
            return '[REDACTED]';
        }
        return $value;
    }
    public function __invoke(LogRecord $record): LogRecord
    {
        $context = $this->redact($record->context);
        $extra = $this->redact($record->extra);
        if (!is_array($context) || !is_array($extra)) {
            throw new \LogicException('Błąd maskowania logu.');
        }
        return $record->with(message: $this->text($record->message), context: $context, extra: $extra);
    }
}
