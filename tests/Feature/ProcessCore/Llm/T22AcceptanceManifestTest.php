<?php

declare(strict_types=1);

use Tests\Support\ProcessCore\BaselineVerifier;

pest()->group('t13', 't14', 't15', 't22', 't30', 't35', 'p314');

test('acceptance manifest связывает current contracts и каждый обязательный T scenario с owning test file', function (): void {
    $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/ProcessCore/Llm/acceptance-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    expect($manifest['format'])->toBe('structured-ai-acceptance/v1')
        ->and(BaselineVerifier::violations(base_path(), $manifest['inputs']))->toBe([])
        ->and(array_keys($manifest['scenario_tests']))->toBe(['T13', 'T14', 'T15', 'T22', 'T30', 'T35']);
    $paths = array_column($manifest['inputs'], 'path');
    expect($paths)->toContain('resources/ai/prompts/contracts/screening-v1.txt', 'resources/ai/prompts/contracts/page-discovery-v1.txt',
        'resources/ai/prompts/contracts/page-extraction-v1.txt', 'tests/Fixtures/ProcessCore/Llm/extraction-v1.json',
        'tests/Fixtures/ProcessCore/Llm/assets-v1.json', 'tests/Fixtures/Llm/step-json-schemas.json');
    foreach ($manifest['scenario_tests'] as $scenario => $tests) {
        expect($tests)->not->toBeEmpty();
        foreach ($tests as $path) {
            expect($paths)->toContain($path);
            $source = file_get_contents(base_path($path));
            expect($source)->toContain("'".strtolower($scenario)."'")
                ->and(preg_match('/\btest\(/', $source))->toBe(1);
        }
    }
});

test('изменённые или отсутствующие fixture bytes не принимаются как current contract evidence', function (string $fault): void {
    $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/ProcessCore/Llm/acceptance-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    $entry = $manifest['inputs'][0];
    if ($fault === 'changed') {
        $entry['sha256'] = str_repeat('0', 64);
    } else {
        $entry['path'] = 'tests/Fixtures/ProcessCore/Llm/nonexistent-p314.json';
    }
    expect(BaselineVerifier::violations(base_path(), [$entry]))->toBe([$entry['path']]);
})->with(['changed', 'missing']);

test('eval inventory описывает legacy labels и family split без заявления paid quality или production cap', function (): void {
    $manifest = json_decode(file_get_contents(base_path('tests/Fixtures/ProcessCore/Llm/acceptance-v1.json')), true, flags: JSON_THROW_ON_ERROR);
    $corpus = json_decode(file_get_contents(base_path('estimates/manifest.json')), true, flags: JSON_THROW_ON_ERROR)['fixtures'];
    $split = json_decode(file_get_contents(base_path('estimates/eval-splits.json')), true, flags: JSON_THROW_ON_ERROR);
    $entries = array_column($manifest['eval_inventory'], null, 'code');
    expect(array_keys($entries))->toBe(array_column($corpus, 'code'))
        ->and($manifest['quality'])->toBe([
            'status' => 'not_measured', 'paid_holdout_executed' => false, 'production_caps_approved' => false,
            'source_bytes_verified' => false, 'gold_contract' => 'legacy_not_canonical',
            'required_independent_documents' => 60, 'required_per_primary_format' => 10,
        ]);
    $families = [];
    foreach ($corpus as $fixture) {
        $entry = $entries[$fixture['code']];
        $subset = in_array($fixture['code'], $split['holdout'], true) ? 'holdout'
            : (in_array($fixture['code'], $split['tune'], true) ? 'tune' : 'unassigned');
        expect($entry)->toBe([
            'code' => $fixture['code'], 'family' => $fixture['cluster_id'], 'split' => $subset,
            'declared_input_sha256' => $fixture['sha256'], 'source_format' => $fixture['source_format'],
            'gold_path' => $fixture['gold_path'], 'human_verified' => $fixture['is_verified'],
        ]);
        if ($subset !== 'unassigned') {
            expect($families[$fixture['cluster_id']] ?? $subset)->toBe($subset);
            $families[$fixture['cluster_id']] = $subset;
        }
    }
    expect(array_intersect($split['tune'], $split['holdout']))->toBe([])
        ->and($manifest['eval_counts'])->toBe([
            'documents' => count($corpus), 'extraction_gold' => count(array_filter(array_column($corpus, 'gold_path'))),
            'human_verified' => count(array_filter(array_column($corpus, 'is_verified'))),
            'tune' => count($split['tune']), 'holdout' => count($split['holdout']),
        ]);
});
