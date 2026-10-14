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
}
