<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

test('расширения наблюдаемости БД доступны', function () {
    $extensions = DB::table('pg_extension')->pluck('extname');

    expect($extensions)->toContain('pg_stat_statements');
    expect($extensions)->toContain('pgstattuple');

    $row = DB::selectOne('SELECT count(*) AS count FROM pg_stat_statements');

    expect($row)->not->toBeNull();
});
