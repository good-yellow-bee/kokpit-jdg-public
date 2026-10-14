<?php

declare(strict_types=1);

namespace App\Xml;

/**
 * JPK_ST(1) z `fixed_asset` (węzeł `EWP`, pola F_1–F_16 w docs/modules/jpk.md).
 * Do czasu dostarczenia XSD JPK_ST(1) do `resources/xsd` budowa kończy się `XsdMissingException`.
 */
final readonly class JpkStBuilder
{
    public const SCHEMA = 'Schemat_JPK_ST(1)_v1-0.xsd';
    public const SCHEMA_VERSION = 'JPK_ST(1)';
    public function __construct(private XsdCatalog $catalog) {}
    public function build(int $year, ?string $until = null): JpkDocument
    {
        $this->catalog->path(self::SCHEMA);
        throw new XsdMissingException(sprintf('Generator JPK_ST(1) za %d czeka na schemat w resources/xsd.', $year));
    }
}
