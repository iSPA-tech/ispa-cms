<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Credentials;

use Cms\Core\Workflows\Runtime\Runners\Integration\Http\DnsResolver;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\HttpHostAllowlist;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\IpRangeGuard;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\MailManager;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * Builds a mailer from an `smtp` credential payload (Laravel on-demand mailer). Bound in the container so tests can
 * swap it for an array mailer.
 *
 * The server name is resolved first and every address must be public (IpRangeGuard), so neither a send nor the "Test"
 * button can reach an internal address or port. The connection is pinned to the checked address; the name stays the
 * TLS peer name, so certificate checks are unchanged.
 *
 * @package Cms\Core\Services\Workflow\Credentials
 * @since   2026-11-24
 */
class SmtpMailerFactory
{
    /**
     * @param array<string, mixed> $smtp host, port, username, password, encryption (ssl|tls|none)
     */
    public function make(array $smtp): Mailer
    {
        [$host, $ip, $port] = $this->checkedServer($smtp);
        $mailer = app(MailManager::class)->build($this->config($smtp));

        $transport = $mailer->getSymfonyTransport();
        $stream = $transport instanceof EsmtpTransport ? $transport->getStream() : null;
        if (!$stream instanceof SocketStream) {
            throw new WorkflowCredentialException('The SMTP connection could not be prepared.');
        }
        $options = $stream->getStreamOptions();
        $options['ssl']['peer_name'] = $host;
        $stream->setHost(str_contains($ip, ':') ? '[' . $ip . ']' : $ip)->setPort($port)->setStreamOptions($options);

        return $mailer;
    }

    /**
     * Resolve the server name and refuse it unless every address is public.
     *
     * @param  array<string, mixed> $smtp
     * @return array{0: string, 1: string, 2: int} name, address to connect to, port
     *
     * @throws WorkflowCredentialException
     */
    private function checkedServer(array $smtp): array
    {
        $host = HttpHostAllowlist::normalizeHost((string) ($smtp['host'] ?? ''));
        $port = (int) ($smtp['port'] ?? 587);
        if ($host === '' || $port < 1 || $port > 65535) {
            throw new WorkflowCredentialException('The SMTP server or port is not valid.');
        }
        $addresses = app(DnsResolver::class)->resolve($host);
        if ($addresses === []) {
            throw new WorkflowCredentialException("The SMTP server {$host} could not be resolved.");
        }
        foreach ($addresses as $address) {
            if (!IpRangeGuard::isPublic($address)) {
                throw new WorkflowCredentialException("The SMTP server {$host} points to a private or reserved address.");
            }
        }
        $ipv4 = array_values(array_filter($addresses, static fn (string $a): bool => filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));

        return [$host, $ipv4[0] ?? $addresses[0], $port];
    }

    /**
     * Open and close an SMTP connection with the credential, to check it.
     *
     * @param array<string, mixed> $smtp
     */
    public function check(array $smtp): void
    {
        $mailer = $this->make($smtp);
        $transport = $mailer->getSymfonyTransport();
        if ($transport instanceof EsmtpTransport) {
            $transport->start();
            $transport->stop();
        }
    }

    /**
     * @param  array<string, mixed> $smtp
     * @return array<string, mixed>
     */
    private function config(array $smtp): array
    {
        $port = (int) ($smtp['port'] ?? 587);
        $encryption = strtolower((string) ($smtp['encryption'] ?? ''));

        return [
            'transport' => 'smtp',
            'name' => 'workflow-credential',
            'scheme' => $encryption === 'ssl' || ($encryption === '' && $port === 465) ? 'smtps' : 'smtp',
            'host' => (string) ($smtp['host'] ?? ''),
            'port' => $port,
            'username' => ($smtp['username'] ?? '') !== '' ? (string) $smtp['username'] : null,
            'password' => ($smtp['password'] ?? '') !== '' ? (string) $smtp['password'] : null,
            'timeout' => 15,
        ];
    }
}
