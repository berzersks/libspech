# Security Policy

## Supported Versions

The following versions of **libspech** are currently supported with security updates:

| Version                 | Support Status | Notes                                          |
|-------------------------|----------------|------------------------------------------------|
| Latest (main branch)    | Supported      | Active development, recommended for production |
| Previous commits        | Limited        | Security fixes on request                      |
| Forks and modifications | Not supported  | Maintainer responsibility                      |

**Note**: This project is in active development. We recommend always using the latest version from the main branch.

## Security Considerations

### Network Security

**libspech** implements SIP/RTP communication protocols. Please be aware of the following security limitations:

#### Current Limitations

- **No encryption by default** - SIP signaling and RTP media streams are transmitted unencrypted
- **No SRTP support** - Secure Real-time Transport Protocol is not currently implemented
- **No TLS/SIPS support** - Transport Layer Security for SIP signaling is not available
- **No authentication validation** - MD5 Digest Authentication is used but credentials are transmitted over the network

#### Network Exposure

- **UDP ports** - The library requires open UDP ports for SIP (default 5060) and RTP media
- **Public IP exposure** - When used in production, ensure proper firewall configuration
- **DDoS vulnerability** - No built-in rate limiting or flood protection

### Recommended Security Practices

1. **Use VPN or private networks** - Deploy in trusted network environments
2. **Firewall configuration** - Restrict SIP/RTP ports to known IP ranges
3. **Credential management** - Store SIP credentials securely, never hardcode in source
4. **Network isolation** - Consider network segmentation for VoIP traffic
5. **Monitoring** - Implement logging and monitoring for suspicious activity
6. **Updates** - Keep Swoole and PHP versions up to date

### Code Security

#### Input Validation

The library performs parsing of SIP/SDP messages and RTP packets. While efforts have been made to handle malformed data,
please be aware:

- SIP message parsing may be vulnerable to malformed headers
- RTP packet processing assumes well-formed data
- No formal security audit has been performed

#### Dependencies

- **Swoole** - Core dependency, follow Swoole security advisories
- **bcg729** - Optional codec extension, GPL-3.0 licensed
- **opus** - Optional codec extension, BSD licensed
- **psampler** - Optional resampling extension

Keep all extensions updated to their latest versions.

## Known Security Issues

### Current Known Issues

1. **Cleartext communication** - All SIP and RTP traffic is unencrypted
2. **No certificate validation** - TLS/SRTP not implemented
3. **IPv4 only** - IPv6 not supported, limiting network flexibility
4. **Limited authentication** - Only MD5 Digest Authentication supported

### Planned Security Enhancements

- [ ] SRTP (Secure RTP) implementation
- [ ] TLS/SIPS support for encrypted signaling
- [ ] Certificate validation
- [ ] Enhanced authentication mechanisms
- [ ] Rate limiting and flood protection
- [ ] IPv6 support

## Reporting a Vulnerability

If you discover a security vulnerability in **libspech**, please follow responsible disclosure practices:

### How to Report

1. **Do NOT open a public issue** for security vulnerabilities
2. **Email the maintainer** at the GitHub profile contact or open a private security advisory
3. **Provide detailed information**:
    - Description of the vulnerability
    - Steps to reproduce
    - Potential impact
    - Suggested fix (if available)

### What to Expect

- **Initial response**: Within 7 days of report
- **Status updates**: Every 14 days until resolution
- **Fix timeline**: Depends on severity
    - Critical: 7-14 days
    - High: 14-30 days
    - Medium: 30-60 days
    - Low: 60-90 days

### Vulnerability Assessment

We will assess reported vulnerabilities based on:

- **Severity**: Impact on confidentiality, integrity, and availability
- **Exploitability**: Ease of exploitation and attack complexity
- **Scope**: Affected versions and configurations
- **Mitigation**: Availability of workarounds

### Disclosure Policy

- **Coordinated disclosure**: We prefer coordinated disclosure with a 90-day window
- **Public disclosure**: After fix is released or 90 days, whichever comes first
- **Credit**: Security researchers will be credited (unless they prefer anonymity)

## Codec and Media Handling

### Codec Support

**Clarification**: The library supports multiple codecs simultaneously through the media channel system:

- **Dynamic codec selection** - The media channel (`rtpChannels.php`) handles multiple codecs
- **Automatic detection** - Can identify codecs from RTP payload types when needed
- **Runtime configuration** - Codecs can be specified via `mountLineCodecSDP()`
- **Fallback mechanism** - Defaults to PCMU/PCMA if no codec is specified

The limitation is not "one codec per call" but rather:

- One codec is negotiated per call session during SDP exchange
- Multiple codecs can be offered, the remote endpoint selects one
- The implementation supports codec switching between calls
- Future enhancement may support mid-call codec switching (re-INVITE)

### Media Security

- **No SRTP** - RTP media is not encrypted
- **No authentication** - RTP packets are not authenticated
- **Replay attacks** - No protection against RTP replay attacks
- **Injection attacks** - Possible RTP packet injection if ports are exposed

## Compliance and Legal

### Data Privacy

When using **libspech** in production:

- **Call recording** - Ensure compliance with local wiretapping and recording laws
- **Data storage** - Audio files may contain sensitive personal information
- **Transmission** - Unencrypted transmission may violate data protection regulations (GDPR, etc.)
- **Consent** - Obtain proper consent before recording or processing voice data

### Telecommunications Regulations

- **Licensing** - Some jurisdictions require licenses for VoIP services
- **Emergency services** - E911/E112 support is not implemented
- **Lawful intercept** - Consider regulatory requirements for your jurisdiction

## Third-Party Security

### Swoole Security

Follow security advisories from the Swoole project:

- https://github.com/swoole/swoole-src/security

### Codec Libraries

- **bcg729**: Based on Belledonne Communications implementation (GPL-3.0)
- **opus**: Based on Xiph.Org Foundation library (BSD)
- Monitor respective projects for security updates

## Security Roadmap

### Short-term (Next Release)

- Improve input validation for SIP/SDP parsing
- Add configuration options for security hardening
- Document secure deployment practices

### Medium-term (3-6 Months)

- Implement SRTP support
- Add TLS/SIPS for encrypted signaling
- Rate limiting and flood protection

### Long-term (6-12 Months)

- Full security audit
- Penetration testing
- Compliance certifications (if applicable)

## Contact

For security concerns, please contact the project maintainer through:

- GitHub Security Advisories: https://github.com/berzersks/libspech/security/advisories
- GitHub Issues (for non-sensitive matters): https://github.com/berzersks/libspech/issues
- GitHub Discussions: https://github.com/berzersks/libspech/discussions

---

**Last Updated**: 2025-01-23
**Next Review**: 2025-04-23
