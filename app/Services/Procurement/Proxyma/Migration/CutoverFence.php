<?php

declare(strict_types=1);

namespace App\Services\Procurement\Proxyma\Migration;

use Illuminate\Database\Connection;
use Proxyma\Infrastructure\Eloquent\PermitRecord;

final class CutoverFence
{
    public static function install(Connection $db): void
    {
        $table = (new PermitRecord)->getTable();
        if (! $db->getSchemaBuilder()->hasTable($table)) {
            throw new \DomainException('Package schema must exist before cutover.');
        }
        if (! $db->selectOne("SELECT 1 FROM pg_trigger WHERE tgrelid=?::regclass AND tgname='prc_pxm_first_claim'", [$table])) {
            $db->statement("CREATE TRIGGER prc_pxm_first_claim BEFORE INSERT OR UPDATE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION prc_pxm_new_claim_fence()");
        }
    }
}
