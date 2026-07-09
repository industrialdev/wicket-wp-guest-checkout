#!/usr/bin/env php
<?php

/**
 * Changelog generator for Wicket WordPress plugins.
 *
 * Prepends a section for the given version to CHANGELOG.md, built from the git
 * commits merged since the most recent tag. Intended to run in CI immediately
 * after the version bump and before the release commit, so the changelog entry
 * ships in the same commit the tag points at.
 *
 * Usage:
 *   php .ci/changelog.php 2.4.12                  # range = <last tag>..HEAD
 *   php .ci/changelog.php 2.4.12 2.4.8..HEAD      # explicit range override
 *
 * Every commit type is included (grouped into a section). The only commits
 * skipped are the bot's own "chore(release):" commits, which are the changelog
 * commits themselves and would be circular noise. Merge commits are ignored.
 */

class ChangelogGenerator
{
    private const FILE = 'CHANGELOG.md';
    private const MARKER = '<!-- new releases inserted below this line -->';

    private const HEADER = "# Changelog\n\n"
        . "All notable changes to this plugin are documented in this file.\n"
        . "This project adheres to [Semantic Versioning](https://semver.org/).\n\n"
        . self::MARKER . "\n";

    /** Conventional-commit type => changelog section heading. */
    private array $typeMap = [
        'feat' => 'Added',
        'fix' => 'Fixed',
        'perf' => 'Performance',
        'refactor' => 'Changed',
        'docs' => 'Documentation',
        'test' => 'Tests',
        'build' => 'Build',
        'ci' => 'CI',
        'chore' => 'Maintenance',
        'style' => 'Maintenance',
    ];

    /** Order sections appear in the changelog. Anything else is "Other". */
    private array $sectionOrder = [
        'Added',
        'Changed',
        'Fixed',
        'Performance',
        'Documentation',
        'Tests',
        'Build',
        'CI',
        'Maintenance',
        'Other',
    ];

    public function run(string $newVersion, ?string $rangeOverride = null): void
    {
        $range = ($rangeOverride !== null && $rangeOverride !== '') ? $rangeOverride : $this->commitRange();
        $subjects = $this->commitSubjects($range);
        $sections = $this->groupSubjects($subjects);
        $block = $this->renderBlock($newVersion, $sections);

        $this->prepend($block);

        fwrite(STDERR, "Wrote changelog entry for {$newVersion} (" . count($subjects) . " commits from range '{$range}').\n");
    }

    /** Range = <last tag>..HEAD, or the full history if there are no tags yet. */
    private function commitRange(): string
    {
        $prevTag = trim((string) shell_exec('git describe --tags --abbrev=0 HEAD 2>/dev/null'));

        return $prevTag !== '' ? $prevTag . '..HEAD' : 'HEAD';
    }

    /** @return string[] commit subject lines, newest first, merges excluded */
    private function commitSubjects(string $range): array
    {
        $cmd = 'git log ' . escapeshellarg($range) . ' --no-merges --pretty=format:%s';
        $out = (string) shell_exec($cmd);

        $subjects = array_filter(array_map('trim', explode("\n", $out)), static fn ($s) => $s !== '');

        // Skip the bot's own release commits.
        return array_values(array_filter($subjects, static fn ($s) => !preg_match('/^chore\(release\)/i', $s)));
    }

    /**
     * @param string[] $subjects
     * @return array<string, string[]> section heading => rendered lines
     */
    private function groupSubjects(array $subjects): array
    {
        $sections = [];

        foreach ($subjects as $subject) {
            if (preg_match('/^(\w+)(?:\(([^)]+)\))?(!)?:\s*(.+)$/', $subject, $m)) {
                $type = strtolower($m[1]);
                $scope = $m[2];
                $breaking = $m[3] === '!';
                $desc = $m[4];
                $section = $this->typeMap[$type] ?? 'Other';
            } else {
                // Non-conventional subject: keep it verbatim under "Other".
                $scope = '';
                $breaking = false;
                $desc = $subject;
                $section = 'Other';
            }

            $line = '- '
                . ($breaking ? '**BREAKING** ' : '')
                . ($scope !== '' ? '**' . $scope . ':** ' : '')
                . $desc;

            $sections[$section][] = $line;
        }

        return $sections;
    }

    /** @param array<string, string[]> $sections */
    private function renderBlock(string $newVersion, array $sections): string
    {
        $date = date('Y-m-d');
        $block = "## [{$newVersion}] - {$date}\n";

        if ($sections === []) {
            return $block . "\n_Maintenance release; no recorded changes._\n";
        }

        foreach ($this->sectionOrder as $heading) {
            if (empty($sections[$heading])) {
                continue;
            }
            $block .= "\n### {$heading}\n" . implode("\n", $sections[$heading]) . "\n";
        }

        return $block;
    }

    private function prepend(string $block): void
    {
        if (!file_exists(self::FILE)) {
            file_put_contents(self::FILE, self::HEADER);
        }

        $content = (string) file_get_contents(self::FILE);

        if (!str_contains($content, self::MARKER)) {
            // File exists but has no marker: put the header (with marker) on top.
            $content = self::HEADER . "\n" . ltrim($content);
        }

        $needle = self::MARKER;
        $insertAt = strpos($content, $needle) + strlen($needle);
        $newContent = substr($content, 0, $insertAt) . "\n\n" . rtrim($block) . "\n" . substr($content, $insertAt);

        if (file_put_contents(self::FILE, $newContent) === false) {
            fwrite(STDERR, "Error: unable to write " . self::FILE . "\n");
            exit(1);
        }
    }
}

global $argv;

if (empty($argv[1])) {
    fwrite(STDERR, "Usage: php .ci/changelog.php <new-version>\n");
    exit(1);
}

(new ChangelogGenerator())->run(trim($argv[1]), isset($argv[2]) ? trim($argv[2]) : null);
