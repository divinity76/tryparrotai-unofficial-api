<?php

declare(strict_types=1);

namespace Divinity76\TryparrotaiUnofficialApi;

class TryparrotaiUnofficialApi
{
    private \HeadlessChromium\Page $page;
    private $browser;
    private $factory;
    public function __construct(
        #[\SensitiveParameter]
        private string $username,
        #[\SensitiveParameter]
        private string $password,
        private ?string $chromiumPath = null,
    ) {
        $this->init();
    }


    private function init(): void
    {
        $this->flatDB_handle = tmpfile();
        $this->flatDB_path = stream_get_meta_data($this->flatDB_handle)['uri'];
        if (empty($this->chromiumPath)) {
            $this->chromiumPath = $this->getChromeBinaryPath();
        }
        $this->factory = $factory = new \HeadlessChromium\BrowserFactory($this->chromiumPath);
        $this->browser = $browser = $factory->createBrowser(
            [
                'headless' => true,
                'customFlags' => [
                    // docker compatibility flags
                    '--no-sandbox',
                    '--disable-gpu-sandbox',
                    '--disable-setuid-sandbox',
                    '--disable-dev-shm-usage',
                    '--disable-breakpad',
                    '--disable-crash-reporter',
                    '--no-crashpad',
                ],
            ],
        );
        $this->page = $browser->createPage();

        $this->login();
    }
    function __destruct()
    {
        fclose($this->flatDB_handle);
        try {
            $this->logout();
        } catch (\Throwable $e) {
            // ignore
        }
        try {
            $this->page->close();
        } catch (\Throwable $e) {
            // ignore
        }
    }
    private function logout(): void
    {
        $this->page->navigate('https://www.tryparrotai.com/app/my-profile')->waitForNavigation(
            \HeadlessChromium\Page::LOAD,
        );
        $this->evaluate(
            'window.confirm=function(){return true;}',
        );
        // wait for logout to complete
        $this->waitForFalse(function () {
            return $this->evaluate(
                'document.querySelectorAll("a[title=Dashboard]").length>0',
            );
        });
    }
    private function login(): void
    {
        $url = 'https://www.tryparrotai.com/login';
        $page = $this->page;
        $page->navigate($url)->waitForNavigation(
            \HeadlessChromium\Page::LOAD,
        );
        $page->mouse()->find(
            'input[type=email]',
        )->click();
        $page->keyboard()->typeText($this->username);
        $page->mouse()->find(
            'input[type=password]',
        )->click();
        $page->keyboard()->typeText($this->password);
        $page->mouse()->find(
            'button[type=submit]',
        )->click();
        // $$("a[title=Dashboard]").length > 0: sucessful login...
        // <div role="status" aria-live="polite" class="go3958317564">No user found with this email. Please check and try again.</div>
        // Logging you in...
        // Welcome back!
        $this->waitForFalse(function () {
            $data = $this->evaluate(
                'statuses=[];document.querySelectorAll("div[role=status]").forEach(e=>statuses.push(e.innerText));
                hasDashboard=document.querySelectorAll("a[title=Dashboard]").length>0;
                ({statuses,hasDashboard})',
            );
            if ($data['hasDashboard']) {
                return false;
            }
            foreach ($data['statuses'] as $status) {
                if (str_contains($status, 'Logging you in...') || str_contains($status, 'Welcome back!')) {
                    continue;
                }
                throw new \RuntimeException('Login failed: ' . $status);
            }
        });
    }
    private function waitForFalse(callable $callback, float $timeout = 30, float $retryInterval = 0.1): void
    {
        $startTime = microtime(true);
        while (true) {
            if ($callback() === false) {
                return;
            }
            if (microtime(true) - $startTime > $timeout) {
                throw new \RuntimeException('Timeout waiting for callback to return false.');
            }
            $this->fsleep($retryInterval);
        }
    }

    /**
     * Attempts to locate the Chrome/Chromium binary on the host system.
     *
     * @return string Absolute path to the browser executable
     * @throws \RuntimeException If no suitable binary can be found
     */
    private function getChromeBinaryPath(): string
    {
        // Allow override via environment or server variable
        $override = $_SERVER['CHROME_PATH'] ?? getenv('CHROME_PATH');
        if ($override && is_executable($override)) {
            return $override;
        }

        switch (PHP_OS_FAMILY) {
            case 'Darwin':
                $path = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
                if (is_executable($path)) {
                    return $path;
                }
                break;
            case 'Windows':
                $candidates = [];
                // Check both ProgramFiles(x86) and ProgramFiles
                foreach (
                    [
                        'ProgramFiles',
                        'ProgramFiles(x86)',
                    ] as $envKey
                ) {
                    $dir = getenv($envKey);
                    if ($dir) {
                        $candidates[] = $dir . '\\Google\\Chrome\\Application\\chrome.exe';
                    }
                }
                foreach ($candidates as $candidate) {
                    if (is_executable($candidate)) {
                        return $candidate;
                    }
                }
                break;
            default:
                // Common Linux/Unix install locations
                $paths = [
                    '/usr/bin/google-chrome-stable',
                    '/usr/bin/google-chrome',
                    '/usr/bin/chromium-browser',
                    '/usr/bin/chromium',
                    '/snap/bin/google-chrome',
                    '/snap/bin/chromium',
                ];
                foreach ($paths as $path) {
                    if (is_executable($path)) {
                        return $path;
                    }
                }
                break;
        }
        throw new \RuntimeException('Chrome/Chromium binary not found on your system.');
    }

    private static function fsleep(float $seconds): void
    {
        $fullSeconds = (int) $seconds;
        $nanoseconds = (int) (($seconds - $fullSeconds) * 1e9);
        time_nanosleep($fullSeconds, $nanoseconds);
    }

    public function createVoice(
        string $voice,
        string $text
    ) {
        $this->flatDB_records = [];
        rewind($this->flatDB_handle);
        ftruncate($this->flatDB_handle, 0);
        $voiceId = $this->getVoiceId($voice);
        $page = $this->page;
        $page->navigate('https://www.tryparrotai.com/app/create')->waitForNavigation(
            \HeadlessChromium\Page::LOAD,
        );
        $splitIntoChunks = function (string $text, int $maxLen = 300): array {
            $chunks = [];

            // keep going until we've consumed all of $text
            while (mb_strlen($text) > 0) {
                // if what's left is short enough, take it all and stop
                if (mb_strlen($text) <= $maxLen) {
                    $chunks[] = trim($text);
                    break;
                }

                // look a little past the limit in case the dot is exactly at position $maxLen
                $slice = mb_substr($text, 0, $maxLen + 1);

                // try to find a dot first
                $dotPos = mb_strrpos($slice, '.');
                if ($dotPos !== false && $dotPos < $maxLen) {
                    // split just *after* the dot
                    $splitPos = $dotPos + 1;
                } else {
                    // no suitable dot: try the last space
                    $spacePos = mb_strrpos($slice, ' ');
                    if ($spacePos !== false && $spacePos < $maxLen) {
                        $splitPos = $spacePos;
                    } else {
                        // no dot or space in range → hard split
                        $splitPos = $maxLen;
                    }
                }

                // extract and trim the chunk
                $chunk = mb_substr($text, 0, $splitPos);
                $chunks[] = trim($chunk);

                // remove it from the front and strip any leading whitespace
                $text = mb_substr($text, $splitPos);
                $text = ltrim($text);
            }

            return $chunks;
        };
        $chunks = $splitIntoChunks($text);
        var_dump($chunks);
        $this->evaluate(
            <<<'JS'
                [...document.querySelectorAll("span")].filter(e=>e.innerText==='Remove "made with Parrot" watermark')[0].click()
            JS
        );
        $fullVoiceFileHandle = tmpfile();
        $fullVoiceFilePath = stream_get_meta_data($fullVoiceFileHandle)['uri'];
        $ffmpegConcatTxtFileHandle = tmpfile();
        $ffmpegConcatTxtFilePath = stream_get_meta_data($ffmpegConcatTxtFileHandle)['uri'];
        $ffmpegInputTxtString = '';
        foreach ($chunks as $chunkId => $chunk) {
            $url = 'https://www.tryparrotai.com/app/create?' .
                http_build_query(array(
                    'text' => $chunk,
                    'vid' => $voiceId,
                ));
            $page->navigate($url)->waitForNavigation(
                \HeadlessChromium\Page::LOAD,
            );
            $this->evaluate(
                <<<'JS'
                [...document.querySelectorAll("span")].filter(e=>e.innerText==='Remove "made with Parrot" watermark')[0].click()
            JS
            );

            $this->waitForFalse(function () use ($page) {
                try {
                    $page->mouse()->findElement(
                        new \HeadlessChromium\Dom\Selector\XPathSelector(
                            '//button[contains(text(), "Generate")]',
                        ),
                    )->click();
                } catch (\Throwable $e) {
                    // means we successfully clicked the button
                    return false;
                }
            }, retryInterval: 1);
            // then we need to wait for the video to appear
            // this will take a while...
            $chunkUrl = null;
            $this->waitForFalse(function () use ($page, &$chunkUrl) {
                $data = $this->evaluate(
                    <<<'JS'
                    document.querySelectorAll("video")?.[0]?.src?.trim()
                    JS
                );
                if (!empty($data)) {
                    $chunkUrl = $data;
                    return false;
                }
            }, timeout: 60 * 3, retryInterval: 1);
            /** @var string $chunkUrl */
            $chunkMP4 = $this->curlGet($chunkUrl);
            if ($chunkMP4 === false) {
                throw new \RuntimeException('Failed to download chunk: ' . $chunkUrl);
            }
            $this->flatDbStore($chunkMP4);
            $lastRecord = $this->flatDB_records[array_key_last($this->flatDB_records)];
            $ffmpegInputTxtStringNew = "file 'subfile,,start," . $lastRecord[self::FLAT_DB_RECORD_INDEX_OFFSET] . ",end," . ($lastRecord[self::FLAT_DB_RECORD_INDEX_OFFSET] + $lastRecord[self::FLAT_DB_RECORD_INDEX_SIZE]) . ",,:" . $this->flatDB_path . "'\n";
            //var_dump($ffmpegInputTxtStringNew);
            $ffmpegInputTxtString .= $ffmpegInputTxtStringNew;
        }
        fwrite($ffmpegConcatTxtFileHandle, $ffmpegInputTxtString);
        $generate_final_file = true;
        if ($generate_final_file) {
            $cmd = implode(' ', [
                'ffmpeg',
                '-protocol_whitelist file,subfile',
                '-fflags +genpts',
                '-f concat',
                '-safe 0',
                '-i ' . escapeshellarg($ffmpegConcatTxtFilePath),
                '-c copy',
                //'-bsf:v h264_mp4toannexb',
                '-f mp4',
                '-y',
                escapeshellarg($fullVoiceFilePath),
            ]);
            //var_dump($cmd);
            passthru($cmd, $returnVar);
            if ($returnVar !== 0) {
                throw new \RuntimeException('ffmpeg command failed with return code: ' . $returnVar);
            }
        }
        fclose($ffmpegConcatTxtFileHandle);
        rewind($fullVoiceFileHandle);
        return ["path" => $fullVoiceFilePath, "handle" => $fullVoiceFileHandle];
    }
    private function getVoiceId(string $voice): string
    {
        $voiceMap = $this->getVoiceMap();
        if (!isset($voiceMap[$voice])) {
            return $voice;
        }
        return $voiceMap[$voice];
    }
    private function getVoiceMap(): array
    {
        /*
        arr={};
[...document.querySelectorAll("div")].forEach(function(ele){
    if(ele.id && ele.textContent){
        title=ele.querySelector("span[title]")?.title;
        if(title)
            arr[title] = ele.id;
    }
});
JSON.stringify(arr);
*/
        return array(
            'Donald' => '1',
            'Joe Biden' => '22',
            'Obama' => '33',
            'Andrew Tate' => '16',
            'Steve Jobs' => '19',
            'Ben Shapiro' => '35',
            'Jordan Peterson' => '21',
            'Joe Rogan' => '2',
            'Elon Musk' => '4',
            'Mark Zuckerberg' => '5',
            'Mia Khalifa' => '54',
            'Mr Beast' => '17',
            'Kanye West' => '11',
            'Bill Gates' => '7',
            'Kim Kardashian' => '9',
            'Will Smith' => '10',
            'Justin Bieber' => '50',
            'AOC Alexandria Cortez' => '32',
            'Michael Jackson' => '12',
            'Snoop Dogg' => '48',
            'Ted Cruz' => '36',
            'Kathy Griffin' => '34',
            'Gary Vaynerchuk' => '6',
            'Chucky The Doll' => '56',
            'Candace Owens' => '37',
            'Jake Paul' => '53',
            'David Goggins' => '52',
        );
    }
    private function evaluate(string $script): mixed
    {
        // there are random timeouts in the page evaluation.. probably an issue in the chrome-php/chrome library...
        try {
            return $this->page->evaluate($script)->getReturnValue();
        } catch (\HeadlessChromium\Exception\OperationTimedOut $e) {
            try {
                return $this->page->evaluate($script)->getReturnValue();
            } catch (\HeadlessChromium\Exception\OperationTimedOut $e) {
                return $this->page->evaluate($script)->getReturnValue();
            }
        }
    }
    private $ch;
    private function curlGet(string $url)
    {
        if (empty($this->ch)) {
            $this->ch = curl_init();
            curl_setopt_array($this->ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'curl/' . (curl_version()['version_number']) . ' (TryparrotaiUnofficialApi)',
                CURLOPT_ENCODING => '',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_SSL_VERIFYSTATUS => false,
            ]);
        }
        curl_setopt($this->ch, CURLOPT_URL, $url);
        $response = curl_exec($this->ch);
        if ($response === false) {
            throw new \RuntimeException('cURL error: ' . curl_error($this->ch));
        }
        $httpCode = curl_getinfo($this->ch, CURLINFO_HTTP_CODE);
        if ($httpCode !== 200) {
            throw new \RuntimeException('cURL error: HTTP code ' . $httpCode . ' for URL: ' . $url);
        }
        return $response;
    }
    private $flatDB_handle;
    private $flatDB_path;
    private const FLAT_DB_RECORD_INDEX_OFFSET = 0;
    private const FLAT_DB_RECORD_INDEX_SIZE = 1;
    private $flatDB_records = [];
    private function flatDbStore(string $bytes): void
    {
        $start_pos = ftell($this->flatDB_handle);
        $size = strlen($bytes);
        if (fwrite($this->flatDB_handle, $bytes) !== $size) {
            throw new \RuntimeException('Failed to write to flat DB file.');
        }
        $this->flatDB_records[] = [
            self::FLAT_DB_RECORD_INDEX_OFFSET => $start_pos,
            self::FLAT_DB_RECORD_INDEX_SIZE => $size,
        ];
    }
}
