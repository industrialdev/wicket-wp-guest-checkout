#!/usr/bin/env php
<?php

/**
 * Version bumper for Wicket WordPress plugins.
 *
 * Source of truth for the current version is composer.json. The new version is
 * written to composer.json and the main plugin file's header (the root *.php
 * that contains a "Plugin Name:" header), which is auto-detected.
 *
 * Usage:
 *   php .ci/version-bump.php patch      # 2.4.10 -> 2.4.11
 *   php .ci/version-bump.php minor      # 2.4.10 -> 2.5.0
 *   php .ci/version-bump.php major      # 2.4.10 -> 3.0.0
 *   php .ci/version-bump.php 2.4.11     # set an explicit version
 *   php .ci/version-bump.php            # prompt interactively
 *
 * On success the resolved version is printed to STDOUT as the last line, so CI
 * can capture it with: NEW_VERSION="$(php .ci/version-bump.php patch | tail -1)"
 */

class VersionBumper
{
    private string $currentVersion;
    private array $filesToUpdate = [];

    public function __construct()
    {
        if (!$this->getCurrentVersion()) {
            exit(1);
        }

        $this->filesToUpdate = ['composer.json'];

        $mainFile = $this->detectMainPluginFile();
        if ($mainFile !== null) {
            $this->filesToUpdate[] = $mainFile;
        } else {
            fwrite(STDERR, "Warning: could not auto-detect main plugin file (no root *.php with a 'Plugin Name:' header).\n");
        }
    }

    private function getCurrentVersion(): bool
    {
        if (!file_exists('composer.json')) {
            fwrite(STDERR, "Error: composer.json not found in current directory.\n");
            return false;
        }

        $composerJson = json_decode(file_get_contents('composer.json'), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            fwrite(STDERR, 'Error: Unable to parse composer.json: ' . json_last_error_msg() . "\n");
            return false;
        }

        if (!isset($composerJson['version'])) {
            fwrite(STDERR, "Error: No version field found in composer.json\n");
            return false;
        }

        $this->currentVersion = $composerJson['version'];
        return true;
    }

    /**
     * Find the main plugin file: a *.php in the current directory whose header
     * contains "Plugin Name:".
     */
    private function detectMainPluginFile(): ?string
    {
        foreach (glob('*.php') ?: [] as $file) {
            $head = file_get_contents($file, false, null, 0, 4096);
            if ($head !== false && preg_match('/Plugin Name:\s*\S/i', $head)) {
                return $file;
            }
        }

        return null;
    }

    private function validateNewVersion(string $newVersion): bool
    {
        $semverPattern = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*)(?:\.(?:0|[1-9]\d*|\d*[a-zA-Z-][0-9a-zA-Z-]*))*))?(?:\+([0-9a-zA-Z-]+(?:\.[0-9a-zA-Z-]+)*))?$/';

        if (!preg_match($semverPattern, $newVersion)) {
            fwrite(STDERR, "Error: Invalid version format. Please use semantic versioning (e.g., 1.2.3)\n");
            return false;
        }

        return true;
    }

    /**
     * Given the current version and a bump level, compute the next version.
     * Pre-release / build metadata on the current version is dropped.
     */
    private function computeBump(string $level): ?string
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)/', $this->currentVersion, $m)) {
            fwrite(STDERR, "Error: current version '{$this->currentVersion}' is not plain X.Y.Z; cannot compute a {$level} bump.\n");
            return null;
        }

        [$major, $minor, $patch] = [(int) $m[1], (int) $m[2], (int) $m[3]];

        switch ($level) {
            case 'major':
                return ($major + 1) . '.0.0';
            case 'minor':
                return $major . '.' . ($minor + 1) . '.0';
            case 'patch':
                return $major . '.' . $minor . '.' . ($patch + 1);
        }

        return null;
    }

    private function resolveNewVersion(?string $arg): ?string
    {
        if ($arg === null || $arg === '') {
            fwrite(STDERR, 'Enter new version (semver) or bump level [patch|minor|major]: ');
            $arg = trim((string) fgets(STDIN));
        }

        if (in_array($arg, ['patch', 'minor', 'major'], true)) {
            return $this->computeBump($arg);
        }

        return $arg;
    }

    private function updateVersionInFile(string $filePath, string $newVersion): bool
    {
        if (!file_exists($filePath)) {
            fwrite(STDERR, "Warning: File not found: {$filePath}\n");
            return false;
        }

        $content = file_get_contents($filePath);
        if ($content === false) {
            fwrite(STDERR, "Error: Unable to read file: {$filePath}\n");
            return false;
        }

        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $updated = false;

        switch ($extension) {
            case 'json':
                $pattern = '/"version":\s*"' . preg_quote($this->currentVersion, '/') . '"/';
                $newContent = preg_replace($pattern, '"version": "' . $newVersion . '"', $content, -1, $count);
                $updated = $count > 0;
                break;
            case 'php':
                $newContent = $content;
                $versionPatternPart = '[0-9a-zA-Z\\.-]+';

                $docblockPattern = '/(^\s*\*\s*Version:\s*)' . $versionPatternPart . '/m';
                $tempContent = preg_replace($docblockPattern, '${1}' . $newVersion, $content, -1, $count1);

                if ($count1 > 0) {
                    $newContent = $tempContent;
                    $updated = true;
                } else {
                    $plainHeaderPattern = '/(Version:\s*)' . $versionPatternPart . '/i';
                    $tempContent = preg_replace($plainHeaderPattern, '${1}' . $newVersion, $content, -1, $count2);
                    if ($count2 > 0) {
                        $newContent = $tempContent;
                        $updated = true;
                    }
                }
                break;
            default:
                $pattern = '/' . preg_quote($this->currentVersion, '/') . '/';
                $newContent = preg_replace($pattern, $newVersion, $content, -1, $count);
                $updated = $count > 0;
        }

        if ($newContent === null) {
            fwrite(STDERR, "Error: Pattern replacement failed in {$filePath}\n");
            return false;
        }

        if (!$updated) {
            fwrite(STDERR, "Warning: No version string found in {$filePath}\n");
            return false;
        }

        if (file_put_contents($filePath, $newContent) === false) {
            fwrite(STDERR, "Error: Unable to write to file: {$filePath}\n");
            return false;
        }

        return true;
    }

    public function run(): void
    {
        global $argv;

        fwrite(STDERR, "Current version: {$this->currentVersion}\n");

        $newVersion = $this->resolveNewVersion($argv[1] ?? null);

        if ($newVersion === null || !$this->validateNewVersion($newVersion)) {
            exit(1);
        }

        if ($newVersion === $this->currentVersion) {
            fwrite(STDERR, "Error: new version equals current version ({$newVersion}); nothing to do.\n");
            exit(1);
        }

        fwrite(STDERR, "New version: {$newVersion}\n");

        $successCount = 0;
        foreach ($this->filesToUpdate as $file) {
            if ($this->updateVersionInFile($file, $newVersion)) {
                fwrite(STDERR, "Updated version in {$file}\n");
                $successCount++;
            }
        }

        if ($successCount === 0) {
            fwrite(STDERR, "Error: No files were updated\n");
            exit(1);
        }

        if ($successCount !== count($this->filesToUpdate)) {
            fwrite(STDERR, "{$successCount} out of " . count($this->filesToUpdate) . " files were updated\n");
        }

        fwrite(STDERR, "Version bump completed: {$this->currentVersion} -> {$newVersion}\n");

        // Machine-readable result on STDOUT (last line) for CI capture.
        echo $newVersion . "\n";
    }
}

$bumper = new VersionBumper();
$bumper->run();
