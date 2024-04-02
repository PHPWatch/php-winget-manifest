<?php

namespace PHPWatch\WingetManifestGenerator;

use Cassandra\Date;
use DateTime;

final class ManifestGenerator {

    private readonly string $version;
    private const string BASE_PATH = __DIR__;
    private const string MATCH_RELEASE_DATE = '~<h4 id="php-%version%-.*?\((?<date>20\d\d-...-\d\d).*?\)</h4>~s';
    private const string MATCH_RELEASE_VERSION = '~<h3 id="php-%version%" name="php-%version%" class="summary entry-title">PHP %version% \((?<version>.*?)\)</h3>~s';
    private const string MATCH_DOWNLOAD_URL_X64 = '~<a href="(?<url>/downloads/releases/php-%version%\.\d\d?-Win32-(?:vs16|VC15)-x64\.zip)">Zip</a>.*?<span class="md5sum">sha256:\s(?<sha256>[a-z\d]{64})</span>~s';
    private const string MATCH_DOWNLOAD_URL_X86 = '~<a href="(?<url>/downloads/releases/php-%version%\.\d\d?-Win32-(?:vs16|VC15)-x86\.zip)">Zip</a>.*?<span class="md5sum">sha256:\s(?<sha256>[a-z\d]{64})</span>~s';

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

        return implode(PHP_EOL, $help);
    }

    public function __construct(string $version) {
        if (!preg_match('/^\d\.\d$/', $version)) {
            throw new \InvalidArgumentException('PHP Version must match N.N format', 2);
        }
        $this->version = $version;
    }

    public function run(): void {
        $matches = $this->getAndParseInfo();
        $this->saveManifests($matches);
    }

    private function getAndParseInfo(): array {
        $return = [
            'version' => $this->version,
        ];

        $source = 'https://windows.php.net/download/';
        $sourceHtml = file_get_contents($source);

        $version = preg_quote($this->version, '~');

        preg_match(str_replace('%version%', $version, self::MATCH_RELEASE_VERSION), $sourceHtml, $matchesVersion);
        if (empty($matchesVersion['version'])) {
            throw new \RuntimeException('Unable to parse version date.');
        }

        $return['fullversion'] = $matchesVersion['version'];


        preg_match(str_replace('%version%', $version, self::MATCH_RELEASE_DATE), $sourceHtml, $matchesDate);


        if (empty($matchesDate['date'])) {
            throw new \RuntimeException('Unable to parse release date.');
        }

        $return['date'] = (DateTime::createFromFormat('Y-M-d', $matchesDate['date']))->format('Y-m-d');

        preg_match(str_replace('%version%', $version, self::MATCH_DOWNLOAD_URL_X64), $sourceHtml, $matchesUrlx64);

        if (empty($matchesUrlx64['url']) || empty($matchesUrlx64['sha256'])) {
            throw new \RuntimeException('Unable to parse x64 URL and hash');
        }

        $return['x64'] = [
            'url' => 'https://windows.php.net' . $matchesUrlx64['url'],
            'hash' => $matchesUrlx64['sha256'],
        ];

        preg_match(str_replace('%version%', $version, self::MATCH_DOWNLOAD_URL_X86), $sourceHtml, $matchesUrlx86);

        if (empty($matchesUrlx86['url']) || empty($matchesUrlx86['sha256'])) {
            throw new \RuntimeException('Unable to parse x86 URL and hash');
        }

        $return['x86'] = [
            'url' => 'https://windows.php.net' . $matchesUrlx86['url'],
            'hash' => $matchesUrlx86['sha256'],
        ];

        return $return;
    }

    private function saveManifests(array $data): void {
        $folder = self::BASE_PATH . '/' . $this->version;
        $files = [
            'PHP.PHP.(version).installer.yaml',
            'PHP.PHP.(version).locale.en-US.yaml',
            'PHP.PHP.(version).yaml',
        ];

        if (!is_dir($folder) && !mkdir($folder, recursive: true) && !is_dir($folder)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $folder));
        }

        $replacements = [
            '%version%' => $this->version,
            '%releasedate%' => $data['date'],
            '%fullversion%' => $data['fullversion'],
            '%url-x64%' => $data['x64']['url'],
            '%hash-x64%' => $data['x64']['hash'],
            '%url-x86%' => $data['x86']['url'],
            '%hash-x86%' => $data['x86']['hash'],
            '%versionmin%' => str_replace('.', '', $this->version),
        ];

        foreach ($files as $fileName) {
            $targetFile = self::BASE_PATH . '/' . $this->version . '/' . str_replace('(version)', $this->version, $fileName);
            $fileContents = file_get_contents(self::BASE_PATH . '/templates/' . $fileName);
            $fileContents = strtr($fileContents, $replacements);
            file_put_contents($targetFile, $fileContents);

            if (!file_exists($targetFile)) {
                throw new \RuntimeException('Unable to write to file: '. $targetFile);
            }
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

try {
    $runner = new ManifestGenerator($ver);
    $runner->run();
}
catch (\Exception $exception) {
    echo $exception->getMessage();
    exit($exception->getCode() === 0 ? 255 : $exception->getCode());
}

