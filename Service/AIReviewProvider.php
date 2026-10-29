<?php
declare(strict_types=1);
namespace GDO\Badger\Service;

/** Implementations are installed and selected by the site operator. */
interface AIReviewProvider
{
    public function name(): string;

    /** Return model, decision (approve/reject/inconclusive), and rationale. */
    public function review(array $request, ?string $document): array;
}
