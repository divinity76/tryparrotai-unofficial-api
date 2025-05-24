# TryparrotaiUnofficialApi

An *unofficial* PHP library for generating voice/videos on [TryParrotAI](https://www.tryparrotai.com) through headless browser automation.

## Requirements

- PHP>=8
- Composer
- Google Chrome or Chromium installed
- `ffmpeg` available in your PATH (if you need more than 300 characters)

## Installation

```bash
composer require divinity76/tryparrotai-unofficial-api
```

## Usage

```php
<?php
require 'vendor/autoload.php';
// Replace with your TryParrotAI credentials
$username = 'your@email.com';
$password = 'yourpassword';
$api = new \Divinity76\TryparrotaiUnofficialApi\TryparrotaiUnofficialApi($username, $password);

// Generate a voice video
$result = $api->createVoice('Elon Musk', 'Hello world, this is a generated voice using TryParrotAI.');
$tempPath = $result['path'];
$permanentPath = __DIR__ . "/test.mp4";
copy($result['path'], $permanentPath);
echo 'Video saved to: ' . $permanentPath . PHP_EOL;
```

## Available Voices

The following voice names are supported out of the box:

- Donald
- Joe Biden
- Obama
- Andrew Tate
- Steve Jobs
- Ben Shapiro
- Jordan Peterson
- Joe Rogan
- Elon Musk
- Mark Zuckerberg
- Mia Khalifa
- Mr Beast
- Kanye West
- Bill Gates
- Kim Kardashian
- Will Smith
- Justin Bieber
- AOC Alexandria Cortez
- Michael Jackson
- Snoop Dogg
- Ted Cruz
- Kathy Griffin
- Gary Vaynerchuk
- Chucky The Doll
- Candace Owens
- Jake Paul
- David Goggins

If you need to use a custom voice ID, pass the ID directly instead of the name.

## Configuration

- **Chromium binary path**  
  By default, the library attempts to auto-detect the Chrome/Chromium executable on your system. Override this with:
  ```php
  $api = new TryparrotaiUnofficialApi($username, $password, '/path/to/chromium');
  ```
  Or set the `CHROME_PATH` environment variable.

## Notes

- Make sure `ffmpeg` is installed and available in your system `PATH`.
- With text above 300 characters, chunks are concatenated using FFmpeg.
