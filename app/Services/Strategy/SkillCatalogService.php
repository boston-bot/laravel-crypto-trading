<?php

namespace App\Services\Strategy;

use Illuminate\Support\Facades\File;

class SkillCatalogService
{
    /**
     * @return array<int, string>
     */
    public function listLocalSkills(): array
    {
        $skillsPath = base_path('.agents/skills');
        if (! File::isDirectory($skillsPath)) {
            return [];
        }

        $skillDirectories = File::directories($skillsPath);
        $skills = [];

        foreach ($skillDirectories as $directory) {
            $skillFile = $directory.'/SKILL.md';
            if (! File::exists($skillFile)) {
                continue;
            }

            $contents = File::get($skillFile);
            $name = $this->extractName($contents) ?? basename($directory);
            $skills[] = $name;
        }

        sort($skills);

        return $skills;
    }

    private function extractName(string $contents): ?string
    {
        if (preg_match('/^name:\s*([^\n]+)$/mi', $contents, $matches) === 1) {
            return trim($matches[1], " \t\n\r\0\x0B\"'");
        }

        return null;
    }
}

