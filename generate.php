<?php

namespace PHPWatch\WingetManifestGenerator;

use DateTime;

final class ManifestGenerator {

    private const string BASE_PATH = __DIR__;
    private ?string $newVersion = null;

    private readonly bool $threadSafety;
    private readonly string $version;

    public static function getHelp(): string {
        $help = [];
        $help[] = '== PHP Winget Manifest Builder ==';
        $help[] = '';
        $help[] = 'Builds Winget-compatible manifest files to install PHP binaries.';
        $help[] = 'Usage: generate.php <PHP-Version>';
        $help[] = '';
        $help[] = ' - <PHP-Version>: The PHP version for which to build the manifest.';
        $help[] = '';

        $help[] = 'Examples:';
        $help[] = ' - php generate.php 8.3';
        $help[] = ' - php generate.php 8.2';
        $help[] = ' - php generate.php 7.4';
        $help[] = ' - php generate.php 7.4 nts';
        $help[] = ' - php generate.php 7.4 ts';

        return implode(PHP_EOL, $help);
    }

    public function __construct(string $version, bool $threadSafety = false) {
        if (!preg_match('/^\d\.\d$/', $version)) {
            throw new \InvalidArgumentException('PHP Version must match N.N format', 2);
        }
        $this->version = $version;
        $this->threadSafety = $threadSafety;
    }

    public function run(): void {
        $matches = $this->getAndParseInfo();
        $this->saveManifests($matches);
    }

    public function showNewVersion(): void {
        if ($this->newVersion) {
            echo $this->newVersion;
        }
    }

    public function setNewVersionToEnv(): void {
        if ($this->newVersion) {
            putenv('PHP_NEW_VERSION=' . $this->version);
        }
        else {
            putenv('PHP_NEW_VERSION=0');
        }
    }

    private function getAndParseInfo(): array {
        $return = [
            'version' => $this->version,
        ];

        $source = 'https://downloads.php.net/~windows/releases/releases.json';
        $sourceJSON = file_get_contents($source);

        $releases = json_decode($sourceJSON, flags: JSON_THROW_ON_ERROR);

        if (!isset($releases->{$this->version})) {
            throw new \RuntimeException('Version ' . $this->version . ' not found');
        }

        $releases = $releases->{$this->version};

        $return['fullversion'] = $releases->version;

        $releaseKey = '/^';
        $releaseKey .= $this->threadSafety ? 'ts' : 'nts';
        $releaseKey .= '-(?:vs17|vs16|VC15|vc15)-(?<arch>x86|x64)$/';

        $parsedReleases = [];
        $releaseDate = null;

        foreach ($releases as $key => $release) {
            if (preg_match($releaseKey, $key, $matches)) {
                $parsedReleases[$matches['arch']] = $release;
                if (!$releaseDate && isset($release->mtime)) {
                    $releaseDate = $release->mtime;
                }
            }
        }

        if (empty($releaseDate)) {
            throw new \RuntimeException('Unable to parse release date.');
        }

        $return['date'] = date('Y-m-d', strtotime($releaseDate));


        if (empty($parsedReleases['x64']->zip->path) || empty($parsedReleases['x64']->zip->sha256)) {
            throw new \RuntimeException('Unable to parse x64 URL and hash');
        }

        if (empty($parsedReleases['x86']->zip->path) || empty($parsedReleases['x86']->zip->sha256)) {
            throw new \RuntimeException('Unable to parse x86 URL and hash');
        }

        $return['x64'] = [
            'url' => 'https://downloads.php.net/~windows/releases/' . $parsedReleases['x64']->zip->path,
            'hash' => $parsedReleases['x64']->zip->sha256,
        ];
        $return['x86'] = [
            'url' => 'https://downloads.php.net/~windows/releases/' . $parsedReleases['x86']->zip->path,
            'hash' => $parsedReleases['x86']->zip->sha256,
        ];

        return $return;
    }

    private function saveManifests(array $data): void {
        $versionParts = explode('.', $data['version']);
        $replacements = [
            '%version%' => $this->version,
            '%releasedate%' => $data['date'],
            '%fullversion%' => $data['fullversion'],
            '%url-x64%' => $data['x64']['url'],
            '%hash-x64%' => $data['x64']['hash'],
            '%url-x86%' => $data['x86']['url'],
            '%hash-x86%' => $data['x86']['hash'],
            '%versionmin%' => str_replace('.', '', $this->version),
            '%versionmajor%' => $versionParts[0],
            '%versionminor%' => $versionParts[1],
            '%ts%' => $this->threadSafety ? 'PHP' : 'PHP-NTS',
            '%ts-suffix%' => $this->threadSafety ? '' : ' - Non-thread safe',
        ];

        // manifests/p/PHP/PHP/8/3/8.3.14/PHP.PHP.8.3.installer.yaml
        $folder = self::BASE_PATH . '/manifests/p/PHP/(ts)/%versionmajor%/%versionminor%/%fullversion%';
        $folder = strtr(
            $folder,
            $replacements + [
                '(ts)' => $this->threadSafety ? 'PHP' : 'PHP-NTS',
            ]
        );

        $files = [
            'PHP.(ts).(version).installer.yaml' => $folder . '/' . 'PHP.(ts).(version).installer.yaml',
            'PHP.(ts).(version).locale.en-US.yaml' => $folder . '/' . 'PHP.(ts).(version).locale.en-US.yaml',
            'PHP.(ts).(version).yaml' => $folder . '/' . 'PHP.(ts).(version).yaml',
            'winget-commit-message.md' => __DIR__ . '/winget-commit-message.md',
            'winget-pr-template.md' => __DIR__ . '/winget-pr-template.md',
        ];

        $isNewVersion = !is_dir($folder);
        if ($isNewVersion) {
            $this->newVersion = $data['fullversion'];
        }

        if (!is_dir($folder) && !mkdir($folder, recursive: true) && !is_dir($folder)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $folder));
        }

        foreach ($files as $fileName => $targetFile) {
            $fileContents = file_get_contents(self::BASE_PATH . '/templates/' . $fileName);

            $targetFile = strtr(
                $targetFile,
                $replacements + [
                    '(version)' => $this->version,
                    '(ts)' => $this->threadSafety ? 'PHP' : 'PHP-NTS',
                ]
            );

            $fileContents = strtr($fileContents, $replacements);
            file_put_contents($targetFile, $fileContents);

            if (!file_exists($targetFile)) {
                throw new \RuntimeException('Unable to write to file: '. $targetFile);
            }
        }
    }

    public function saveNewVersionToFile(string $file): void {
        if (file_exists($file)) {
            unlink($file);
        }
        if ($this->newVersion) {
            file_put_contents($file, $this->newVersion);
        }
    }

    private function getDownloadUrlInfo(string $url): string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_MAXREDIRS => 2,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_OPTIONS => CURLSSLOPT_NATIVE_CA,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            CURLOPT_ENCODING => '',
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TCP_KEEPALIVE => 1,
            CURLOPT_USERAGENT => 'ayesh/curl-fetcher',
        ]);

        $contents = curl_exec($ch);
        if (!$contents) {
            throw new \RuntimeException('Curl error: ' . curl_error($ch), curl_errno($ch));
        }

        if (strlen($contents) < (20 * 1024 * 1024)) {
            throw new \RuntimeException('Downloaded content suspiciously too small (< 20MB), giving up');
        }

        return hash('sha256', $contents);
    }


}

if (empty($argv[1])) {
    echo ManifestGenerator::getHelp();
    exit();
}

$ver = $argv[1];
$ts = true;

if (isset($argv[2])) {
    if ($argv[2] !== 'ts' && $argv[2] !== 'nts') {
        echo 'Second argument must be "ts" or "nts"'.PHP_EOL;
        exit(1);
    }

    $ts = $argv[2] === 'ts';
}

try {
    $runner = new ManifestGenerator($ver, $ts);
    $runner->run();
    $runner->showNewVersion();
    $runner->setNewVersionToEnv();
    $runner->saveNewVersionToFile(__DIR__ . '/NEW_VERSION');
}
catch (\Exception $exception) {
    echo $exception->getMessage();
    exit($exception->getCode() === 0 ? 255 : $exception->getCode());
}

