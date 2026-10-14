<?php

declare(strict_types=1);

namespace App\Architecture;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/** @implements Rule<Node> */
final class ArchitectureRules implements Rule
{
    public function __construct(private \PHPStan\Reflection\ReflectionProvider $reflection) {}
    public function getNodeType(): string
    {
        return Node::class;
    }
    public function processNode(Node $node, Scope $scope): array
    {
        $file = str_replace('\\', '/', $scope->getFile());
        $errors = [];
        if (str_contains($file, '/src/Connector/') && (($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && in_array(strtolower($node->name->toString()), ['getenv','putenv'], true)) || ($node instanceof Node\Expr\Variable && in_array($node->name, ['_ENV','_SERVER'], true)))) {
            $errors[] = RuleErrorBuilder::message('Konektor czyta konfigurację i sekrety przez usługi.')->identifier('architecture.connectorEnvironment')->build();
        }
        if ((str_contains($file, '/src/Tax/') || str_contains($file, '/src/Ledger/')) && ($node instanceof Node\Scalar\Float_ || $node instanceof Node\Expr\Cast\Double || $node instanceof Node\Expr\BinaryOp\Div || ($node instanceof Node\Identifier && $node->toString() === 'float') || ($node instanceof Node\Expr\FuncCall && $node->name instanceof Node\Name && strtolower($node->name->toString()) === 'floatval'))) {
            $errors[] = RuleErrorBuilder::message('Kwoty wymagają brick/math, bez float.')->identifier('architecture.moneyFloat')->build();
        }
        if ($node instanceof Node\Expr\MethodCall && $node->name instanceof Node\Identifier) {
            foreach ($scope->getType($node->var)->getObjectClassNames() as $class) {
                if ($this->reflection->hasClass($class) && $this->reflection->getClass($class)->hasMethod($node->name->toString()) && array_any($this->reflection->getClass($class)->getMethod($node->name->toString(), $scope)->getAttributes(), static fn($attribute) => $attribute->getName() === OfficialWrite::class) && !str_contains($file, '/src/Approval/')) {
                    $errors[] = RuleErrorBuilder::message('Zapis urzędowy może wywołać tylko handler bramki.')->identifier('architecture.officialWrite')->build();
                }
            }
        }
        return $errors;
    }
}
