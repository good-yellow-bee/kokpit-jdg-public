<?php

namespace App\Security;

use Symfony\Bundle\FrameworkBundle\Secrets\AbstractVault;

final readonly class Vault
{
    public function __construct(private AbstractVault $vault, private Redactor $redactor, private string $appEnvironment) {}
    public function get(string $name): string
    {
        if ($this->appEnvironment !== 'prod' && str_contains($name, '_PROD_')) {
            throw new \LogicException('Sekret produkcyjny niedozwolony.');
        }
        $value = $this->vault->reveal($name);
        if ($value === null) {
            throw new \RuntimeException('Brak wymaganego sekretu w sejfie.');
        }
        $this->redactor->registerSecret($value);
        return $value;
    }
}
