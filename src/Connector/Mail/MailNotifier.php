<?php

declare(strict_types=1);

namespace App\Connector\Mail;

use Symfony\Component\Mime\Email;

final readonly class MailNotifier
{
    public function __construct(private GuardedMailTransport $transport, private string $from, private string $to) {}
    public function send(string $category, int $count = 1, string $link = '/'): void
    {
        if (!preg_match('/^[a-z_]+$/D', $category) || $count < 1 || !str_starts_with($link, '/') || str_starts_with($link, '//')) {
            throw new \InvalidArgumentException('Niepoprawne powiadomienie.');
        }
        $this->transport->send((new Email())->from($this->from)->to($this->to)->subject('Kokpit: powiadomienie')->text(sprintf('%s: %d; %s', $category, $count, $link)));
    }

    /**
     * Przegląd tygodnia (D-82): tylko klucze `[a-z_]` i liczby, bez kwot i nazw (D-19).
     *
     * @param array<string,int> $counts
     */
    public function digest(string $week, array $counts, string $link = '/'): void
    {
        if (!preg_match('/^\d{4}-W\d{2}$/D', $week) || $counts === [] || !str_starts_with($link, '/') || str_starts_with($link, '//')) {
            throw new \InvalidArgumentException('Niepoprawny przegląd.');
        }
        $lines = [];
        foreach ($counts as $key => $count) {
            if (!preg_match('/^[a-z_]+$/D', $key) || $count < 0) {
                throw new \InvalidArgumentException('Niepoprawny przegląd.');
            }
            $lines[] = sprintf('%s: %d', $key, $count);
        }
        $this->transport->send((new Email())->from($this->from)->to($this->to)->subject('Kokpit: przegląd tygodnia ' . $week)->text(implode("\n", $lines) . "\n" . $link));
    }
}
