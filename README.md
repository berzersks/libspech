# libspech

[![License](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)

Real-time SIP/RTP VoIP library for PHP using Swoole coroutines. Place and receive calls from PHP, stream RTP audio,
handle DTMF, and record audio.

This README was updated on 2025-11-27.

## Overview

libspech provides:

- SIP user-agent features: register, call setup/teardown (INVITE/200/ACK/BYE), digest auth
- RTP/RTCP media: receive and send audio frames
- Event-driven API with callbacks for ringing, answer, hangup, and audio reception
- DTMF sending (RFC 2833)
- WAV helpers for captured PCM
- High-performance async I/O via Swoole

## Stack

- Language: PHP (no Composer in this repository)
- Runtime/extension: Swoole coroutines (PHP extension)
- Protocols: SIP, RTP/RTCP, SDP, DTMF (RFC 2833)
- Optional native codec extensions: `bcg729`, `opus`, `psampler`

Notes:

- There is no package manager used here; files are loaded via a custom autoloader in `plugins/autoloader.php` driven by
  `plugins/configInterface.json`.
- Some features (e.g., G.729/Opus encode/decode) rely on optional PHP extensions. See the Stubs section in the tree for
  IDE stubs.
- TODO: Document the minimum tested versions of PHP and Swoole. Existing code uses coroutines and UDP sockets from
  Swoole.

## Requirements

- Linux or macOS recommended
- PHP CLI with the Swoole extension enabled
- Optional: PHP extensions for codecs if you need them (`bcg729`, `opus`, `psampler`)

Alternative (prebuilt bundle): Some users rely on a third-party bundle that ships PHP + Swoole + codec extensions.
If you use such a bundle, follow its installation guide.
TODO: Verify and document the exact bundle/version officially supported by this project.

## Setup

1) Clone this repository.

2) Ensure PHP Swoole is available. Example check:

```bash
php -m | grep -i swoole
```

3) Run the demo entry point `example.php` (see below) after adjusting SIP credentials.

## Running the example

The repository includes a runnable example in `example.php`.

Edit the credentials and destination inside `example.php` and run:

```bash
php example.php
```

Minimal usage sketch:

```php
<?php
use libspech\Sip\trunkController;

include 'plugins/autoloader.php';

\Swoole\Coroutine\run(function () {
    $username = 'your_username';
    $password = 'your_password';
    $domain   = 'sip.example.com';
    $host     = gethostbyname($domain);

    $phone = new trunkController($username, $password, $host, 5060);

    if (!$phone->register(2)) {
        throw new \Exception('Failed to register');
    }

    // Offer a linear PCM line in SDP (optional)
    $phone->mountLineCodecSDP('L16/8000');

    $phone->onRinging(function () {
        echo "Ringing...\n";
    });

    $phone->onAnswer(function (trunkController $phone) {
        echo "Answered. Receiving media...\n";
        $phone->receiveMedia();
        \Swoole\Coroutine::sleep(10);
    });

    $phone->onReceiveAudio(function ($pcmData, $peer, trunkController $phone) {
        $phone->bufferAudio .= $pcmData; // capture raw PCM
    });

    $phone->onHangup(function (trunkController $phone) {
        $phone->saveBufferToWavFile('audio.wav', $phone->bufferAudio);
        echo "Saved audio.wav\n";
    });

    $phone->call('15555551234');
});
```

## Scripts and entry points

- No package.json or Composer scripts. Use PHP CLI directly.
- Demo entry point: `example.php`.
- Main library classes live under `plugins/Utils/libspech` and `plugins/Utils/sip` namespaces; the primary call
  controller is `libspech\Sip\trunkController`.

## Configuration and environment

- Credentials and settings are configured in your own code (e.g., inside `example.php`).
- No fixed environment variables are required by the library as committed.
- TODO: Document recommended configuration for NAT traversal (local/public IPs, port ranges) and any SIP proxy/outbound
  settings.

## Project structure

```
libspech/
├── example.php
├── plugins/
│   ├── autoloader.php                 # Simple autoloader driven by configInterface.json
│   ├── configInterface.json           # Lists directories to autoload
│   ├── Packet/
│   │   └── controller/
│   │       └── renderMessages.php     # SIP/SDP message helpers
│   └── Utils/
│       ├── cache/
│       │   ├── cache.php
│       │   └── rpcClient.php
│       ├── cli/cli.php                # CLI helpers
│       ├── libspech/trunkController.php  # Main call controller (namespace libspech\\Sip)
│       ├── network/network.php
│       └── sip/
│           ├── AdaptiveBuffer.php
│           ├── DtmfEvent.php
│           ├── mediaChannel.php
│           ├── rtpChannel.php
│           ├── rtpc.php
│           ├── sip.php
│           └── trunkController.php    # Legacy/alternate controller
├── stubs/                             # IDE stubs for optional extensions
│   ├── bcg729Channel.php
│   ├── opusChannel.php
│   └── psampler.php
├── LICENSE
├── README.md
├── SECURITY.md
├── audio.wav                          # Sample/output audio files (example usage)
└── music.wav
```

Namespace note: the class defined in `plugins/Utils/libspech/trunkController.php` uses the namespace `libspech\Sip`.
Use statements in user code should import `libspech\Sip\trunkController` as shown above.

## Codecs

Payloads implemented/used in the codebase include:

| Codec                  | Payload Type | Sample Rate | Status   | Notes/Extension                          |
|------------------------|--------------|-------------|----------|------------------------------------------|
| PCMU (G.711 µ-law)     | 0            | 8 kHz       | Built-in | No extra extension required              |
| PCMA (G.711 A-law)     | 8            | 8 kHz       | Built-in | No extra extension required              |
| G.729                  | 18           | 8 kHz       | Optional | Via bcg729; see stubs and your PHP build |
| Opus                   | 111          | 48 kHz      | Optional | Via opus extension                       |
| L16 (Linear PCM)       | 96           | 8 kHz       | Built-in | psampler can be used for resampling      |
| telephone-event (DTMF) | 101          | 8 kHz       | Built-in | RFC 2833 for DTMF signaling              |

Notes:

- Multiple codecs can be offered via SDP. Use `mountLineCodecSDP()` to adjust preferences.
- Payload type numbers can vary depending on negotiation; confirm with your provider.

## Usage notes

- Networking/NAT: Ensure the local IP/ports bound by the library are reachable by your SIP peer. STUN/NAT traversal is
  not included.
  TODO: Provide guidance or helpers for NATed environments.
- Security: Focus is on basic SIP over UDP. TLS/SRTP are not documented here.
  TODO: Clarify current TLS/SRTP support and requirements if any.

## Testing

- No automated tests are included in this repository.
- TODO: Add unit/integration tests for SIP message parsing, RTP timing, DTMF, and example call flows.

## License

This project is licensed under the MIT License. See `LICENSE` for details.

Third-party components may be under different licenses (Swoole, codec extensions). Review their LICENSE files before
production use.
