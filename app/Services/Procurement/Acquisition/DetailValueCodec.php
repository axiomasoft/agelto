<?php

declare(strict_types=1);

namespace App\Services\Procurement\Acquisition;

use App\Dto\Organization\OrganizationIdentifierData;
use App\Dto\Procurement\Acquisition\DetailProgress;
use App\Dto\Procurement\Acquisition\DetailRequest;
use App\Dto\Procurement\Acquisition\DetailResult;
use App\Dto\Procurement\Acquisition\ProcurementSnapshot;
use App\Dto\Procurement\Acquisition\SnapshotPart;
use App\Dto\Procurement\Acquisition\StatusProgress;
use App\Dto\Procurement\Acquisition\StatusResult;
use App\Dto\Procurement\Details\AwardData;
use App\Dto\Procurement\Details\BidData;
use App\Dto\Procurement\Details\FinancingRowData;
use App\Dto\Procurement\Details\JournalEntryData;
use App\Dto\Procurement\Details\OrganizationData;
use App\Dto\Procurement\Details\PartyData;
use App\Dto\Procurement\Details\ProtocolData;
use App\Dto\Procurement\Details\PurchaseItemRowData;
use App\Dto\Procurement\Details\SupplierResultsData;
use App\Dto\Procurement\Document\DocumentReference;
use App\Dto\Procurement\Network\SafeUrl;
use App\Dto\Procurement\Platform\CursorEnvelope;
use App\Dto\Procurement\Platform\PlatformCode;
use App\Dto\Procurement\Platform\SourceIdentifierType;
use App\Dto\Procurement\Platform\SourceIdentifierValue;
use App\Dto\Procurement\Platform\SourceIdentity;
use App\Dto\Procurement\Process\ArtifactRef;
use App\Dto\Procurement\Process\ContentHash;
use App\Enums\Organization\ProcurementPartyRole;
use App\Enums\Procurement\AwardStatus;
use App\Enums\Procurement\BidStatus;
use App\Enums\Procurement\Platform\Completeness;
use App\Enums\Procurement\Platform\SnapshotConsistency;
use Illuminate\Support\Carbon;

/** Closed JSON boundary. No unserialize, arbitrary class hydration or coercion. */
final readonly class DetailValueCodec
{
    private const array TYPES = [DetailProgress::class, DetailRequest::class, DetailResult::class,
        StatusProgress::class, StatusResult::class,
        ProcurementSnapshot::class, SnapshotPart::class, PartyData::class, OrganizationData::class,
        PurchaseItemRowData::class, FinancingRowData::class, JournalEntryData::class, SupplierResultsData::class,
        BidData::class, AwardData::class, ProtocolData::class, SourceIdentity::class, PlatformCode::class,
        SourceIdentifierType::class, SourceIdentifierValue::class, ArtifactRef::class, ContentHash::class,
        DocumentReference::class, OrganizationIdentifierData::class];

    /** Trusted registry descriptor, never obtained from the payload. @param class-string|null $extensionType */
    public function __construct(private ?string $extensionType = null) {}

    public function encode(object $value): string
    {
        $json = json_encode($this->pack($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($json) > 2097152) {
            throw new \InvalidArgumentException('Detail payload exceeds bound.');
        }

        return $json;
    }

    public function decode(string $json): object
    {
        if (strlen($json) > 2097152) {
            throw new \InvalidArgumentException('Detail payload exceeds bound.');
        }
        $value = $this->unpack(json_decode($json, true, 32, JSON_THROW_ON_ERROR));
        if (! is_object($value)) {
            throw new \InvalidArgumentException('Detail object required.');
        }

        return $value;
    }

    private function pack(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return ['type' => $value instanceof Carbon ? Carbon::class : \DateTimeImmutable::class, 'value' => $value->format('Y-m-d\TH:i:s.uP')];
        }
        if ($value instanceof SafeUrl) {
            return ['type' => SafeUrl::class, 'value' => $value->reveal()];
        }
        if ($value instanceof CursorEnvelope) {
            return ['type' => CursorEnvelope::class, 'value' => [$value->codecId, $value->schemaVersion, $value->serializedPayload]];
        }
        if ($value instanceof \BackedEnum) {
            return ['type' => $value::class, 'value' => $value->value];
        }
        if (is_array($value)) {
            return ['array' => array_map($this->pack(...), $value)];
        }
        if (is_object($value)) {
            if (! in_array($value::class, self::TYPES, true) && $value::class !== $this->extensionType) {
                throw new \InvalidArgumentException('Unsupported detail value.');
            }

            return ['type' => $value::class, 'value' => array_map($this->pack(...), get_object_vars($value))];
        }

        return $value;
    }

    private function unpack(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_keys($value) === ['array'] && is_array($value['array'])) {
            return array_map($this->unpack(...), $value['array']);
        }
        if (count($value) !== 2 || ! isset($value['type']) || ! array_key_exists('value', $value) || ! is_string($value['type'])) {
            throw new \InvalidArgumentException('Invalid detail value schema.');
        }
        $type = $value['type'];
        $data = $value['value'];
        if (in_array($type, [Carbon::class, \DateTimeImmutable::class], true)) {
            if (! is_string($data) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}[+-]\d{2}:\d{2}$/D', $data)) {
                throw new \InvalidArgumentException('Invalid detail instant.');
            }

            return new $type($data);
        }
        if ($type === SafeUrl::class) {
            if (! is_string($data)) {
                throw new \InvalidArgumentException('Invalid URL.');
            }

            return new SafeUrl($data);
        }
        if ($type === CursorEnvelope::class) {
            if (! is_array($data) || array_keys($data) !== [0, 1, 2] || ! is_string($data[0]) || ! is_int($data[1]) || ! is_string($data[2])) {
                throw new \InvalidArgumentException('Invalid cursor.');
            }

            return new CursorEnvelope($data[0], $data[1], $data[2]);
        }
        if (in_array($type, [ProcurementPartyRole::class, BidStatus::class, AwardStatus::class, Completeness::class, SnapshotConsistency::class], true)) {
            if (! is_string($data)) {
                throw new \InvalidArgumentException('Invalid enum.');
            }

            return $type::from($data);
        }
        if ((! in_array($type, self::TYPES, true) && $type !== $this->extensionType) || ! is_array($data)) {
            throw new \InvalidArgumentException('Unregistered detail type.');
        }
        if (! class_exists($type)) {
            throw new \InvalidArgumentException('Missing detail type.');
        }
        $class = new \ReflectionClass($type);
        if (! $class->isReadOnly()) {
            throw new \InvalidArgumentException('Immutable detail type required.');
        }
        $params = $class->getConstructor()?->getParameters() ?? [];
        $keys = array_keys($data);
        $expected = array_map(fn (\ReflectionParameter $p): string => $p->getName(), $params);
        sort($keys);
        sort($expected);
        if ($keys !== $expected) {
            throw new \InvalidArgumentException('Detail fields mismatch.');
        }
        $args = [];
        foreach ($params as $p) {
            $v = $this->unpack($data[$p->getName()]);
            $t = $p->getType();
            if (! $t instanceof \ReflectionNamedType) {
                throw new \LogicException('Unsupported detail schema type.');
            }
            $name = $t->getName();
            $valid = $v === null ? $t->allowsNull() : match ($name) {
                'string' => is_string($v), 'int' => is_int($v), 'bool' => is_bool($v), 'float' => is_float($v) || is_int($v),
                'array' => is_array($v), 'object' => is_object($v) && $v::class === $this->extensionType,
                default => $v instanceof $name,
            };
            if (! $valid) {
                throw new \InvalidArgumentException('Invalid detail field type.');
            }
            $args[] = $v;
        }

        return $class->newInstanceArgs($args);
    }
}
