<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SyncBoostSkillsCommand extends Command
{
    protected $signature = 'boost:sync-skills
                            {--update : Run boost:update after syncing}';

    protected $description = 'Copy skills from resources/boost/skills/{name}/SKILL.md to .ai/skills for Boost 2.0';

    public function handle(): int
    {
        $sourceDir = base_path('resources/boost/skills');
        $targetBase = base_path('.ai/skills');

        if (! is_dir($sourceDir)) {
            $this->error("Source directory not found: {$sourceDir}");

            return self::FAILURE;
        }

        $skillDirs = collect(File::glob($sourceDir.'/*', GLOB_ONLYDIR))
            ->filter(fn (string $path): bool => File::exists($path.'/SKILL.md'))
            ->values()
            ->all();
        $count = 0;

        foreach ($skillDirs as $skillPath) {
            $name = basename($skillPath);
            $sourceFile = $skillPath.'/SKILL.md';
            $targetDir = $targetBase.'/'.$name;
            $targetFile = $targetDir.'/SKILL.md';

            if (! File::isDirectory($targetDir)) {
                File::makeDirectory($targetDir, 0755, true);
            }

            File::copy($sourceFile, $targetFile);
            $this->line("  Synced: {$name}");
            $count++;
        }

        $this->info("Synced {$count} skill(s) to .ai/skills");

        if ($this->option('update')) {
            $boostPath = base_path('boost.json');
            $previousSkills = [];
            if (File::exists($boostPath)) {
                $config = json_decode(File::get($boostPath), true);
                $previousSkills = $config['skills'] ?? [];
            }

            $this->call('boost:update');

            // boost:update overwrites boost.json with only discovered skills (Roster-dependent).
            // Restore union with previous so we don't lose e.g. inertia-vue-development, tailwindcss-development.
            if ($previousSkills !== [] && File::exists($boostPath)) {
                $config = json_decode(File::get($boostPath), true);
                $currentSkills = $config['skills'] ?? [];
                $merged = array_values(array_unique(array_merge($currentSkills, $previousSkills)));
                sort($merged);
                $config['skills'] = $merged;
                File::put($boostPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
                $this->info('Restored previous skills in boost.json (union with discovered).');
            }
        }

        return self::SUCCESS;
    }
}
