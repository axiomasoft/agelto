<?php

declare(strict_types=1);

namespace App\Dto\Procurement\Process;

use App\Enums\Procurement\Process\SubjectKind;

final readonly class SubjectKey
{
    public function __construct(public SubjectKind $kind, public int|ProcessUuid $id)
    {
        $bigint = in_array($kind, [SubjectKind::Procurement, SubjectKind::Document, SubjectKind::DocumentVersion], true);
        if ($bigint !== is_int($id)) {
            throw new \InvalidArgumentException('Subject primary key type does not match its real owner table.');
        }
        if (is_int($id)) {
            ProcessValue::positive($id);
        }
    }

    public function key(): string
    {
        return $this->kind->value.':'.(is_int($this->id) ? $this->id : $this->id->value);
    }
}
