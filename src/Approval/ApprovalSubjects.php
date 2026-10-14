<?php

declare(strict_types=1);

namespace App\Approval;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Rejestr przedmiotów po `subject_type`. Przy `APP_ENV=prod` do F3 pusty, bo przedmioty `dummy` są tylko w dev i test (D-32). */
final readonly class ApprovalSubjects
{
    /** @var array<string,ApprovalSubject> */
    private array $subjects;

    /** @param iterable<ApprovalSubject> $subjects */
    public function __construct(#[AutowireIterator('app.approval_subject')] iterable $subjects)
    {
        $map = [];
        foreach ($subjects as $subject) {
            $map[$subject->subjectType()] = $subject;
        }
        $this->subjects = $map;
    }

    public function get(string $subjectType): ?ApprovalSubject
    {
        return $this->subjects[$subjectType] ?? null;
    }

    public function require(string $subjectType): ApprovalSubject
    {
        return $this->get($subjectType) ?? throw new \DomainException('Brak przedmiotu zatwierdzenia ' . $subjectType . '.');
    }
}
