<?php

declare(strict_types=1);

namespace Poland\Ksef\Transport;

use DateTimeImmutable;
use Poland\Ksef\Error\KsefErrorCategory;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\KsefEnvironment;
use Poland\Ksef\KsefInvoiceMetadata;
use Poland\Ksef\KsefNumber;
use Poland\Ksef\Secret;
use Poland\Ksef\Transport\Dto\AccessTokens;
use Poland\Ksef\Transport\Dto\AuthChallenge;
use Poland\Ksef\Transport\Dto\AuthInitiated;
use Poland\Ksef\Transport\Dto\AuthStatus;
use Poland\Ksef\Transport\Dto\InvoiceMetadataPage;
use Poland\Ksef\Transport\Dto\InvoiceQuery;
use Poland\Ksef\Transport\Dto\OpenedSession;
use Poland\Ksef\Transport\Dto\PublicKeyCertificate;
use Poland\Ksef\Transport\Dto\SessionInvoicesPage;
use Poland\Ksef\Transport\Dto\SessionInvoiceStatus;
use Poland\Ksef\Transport\Dto\SessionStatus;
use Poland\Ksef\Transport\Dto\TokenInfo;

/**
 * A deterministic, scriptable stand-in for KSeF used ONLY by the test suite.
 *
 * It is not a mock of the HTTP layer — it simulates the API's state machine
 * (challenge, auth, sessions, invoice statuses, metadata pages) so the code
 * above the transport can be exercised end to end without a network. Every
 * behaviour a test needs is scripted explicitly through the public methods
 * below; nothing here guesses. `kind()` is `fake`, and the TransportGate
 * refuses to let it run against production.
 */
final class FakeKsefTransport implements KsefTransport
{
    /** @var list<array{operation: string, args: array<string,mixed>}> */
    public array $calls = [];

    /** @var list<PublicKeyCertificate> */
    private array $certificates = [];

    /** @var array<string, list<KsefException|\Closure>> operation => queued failures */
    private array $failures = [];

    /** @var list<int> */
    private array $authStatusSequence = [200];

    private int $authStatusIndex = 0;

    /** @var array<string, array{invoices: list<array<string,mixed>>, closed: bool}> */
    private array $sessions = [];

    /** @var array<string, list<int>> invoice reference => status codes in poll order */
    private array $invoiceStatusSequences = [];

    /** @var array<string,int> */
    private array $invoiceStatusIndex = [];

    /** @var list<InvoiceMetadataPage|\Closure> */
    private array $metadataPages = [];

    /** @var array<string,string> */
    private array $invoiceXmls = [];

    private int $counter = 0;

    private ?string $redeemedReference = null;

    public string $acceptedKsefTokenFingerprint = '';

    public bool $acceptAnyToken = true;

    public ?string $nextKsefNumber = null;

    /** @var array<string,string> ksef number handed out per invoice reference */
    public array $assignedKsefNumbers = [];

    public function __construct(
        private readonly KsefEnvironment $environment = KsefEnvironment::Test,
        private readonly ?DateTimeImmutable $now = null,
    ) {
        $this->certificates = self::defaultCertificates($this->now());
    }

    // --- scripting -----------------------------------------------------------

    /** @param list<PublicKeyCertificate> $certificates */
    public function withCertificates(array $certificates): self
    {
        $this->certificates = $certificates;

        return $this;
    }

    /** Make the next call to $operation throw; queued in order. */
    public function failNext(string $operation, KsefException|\Closure $failure): self
    {
        $this->failures[$operation][] = $failure;

        return $this;
    }

    /** @param list<int> $codes */
    public function authStatusSequence(array $codes): self
    {
        $this->authStatusSequence = $codes;
        $this->authStatusIndex = 0;

        return $this;
    }

    /** @param list<int> $codes status codes returned by successive polls of the NEXT sent invoice */
    public function nextInvoiceStatuses(array $codes): self
    {
        $this->pendingStatuses = $codes;

        return $this;
    }

    /** @var list<int>|null */
    private ?array $pendingStatuses = null;

    public function queueMetadataPage(InvoiceMetadataPage|\Closure $page): self
    {
        $this->metadataPages[] = $page;

        return $this;
    }

    public function withInvoiceXml(string $ksefNumber, string $xml): self
    {
        $this->invoiceXmls[$ksefNumber] = $xml;

        return $this;
    }

    /** @return list<array<string,mixed>> */
    public function sentInvoices(string $sessionReference): array
    {
        return $this->sessions[$sessionReference]['invoices'] ?? [];
    }

    public function callCount(string $operation): int
    {
        return count(array_filter($this->calls, static fn (array $c): bool => $c['operation'] === $operation));
    }

    // --- transport -----------------------------------------------------------

    public function kind(): string
    {
        return self::KIND_FAKE;
    }

    public function environment(): KsefEnvironment
    {
        return $this->environment;
    }

    public function publicKeyCertificates(): array
    {
        $this->record('publicKeyCertificates', []);

        return $this->certificates;
    }

    public function authChallenge(): AuthChallenge
    {
        $this->record('authChallenge', []);
        $now = $this->now();

        return new AuthChallenge('CHALLENGE-'.++$this->counter, $now, (int) $now->format('Uv'));
    }

    public function authWithKsefToken(string $challenge, string $contextNip, string $encryptedTokenBase64, string $publicKeyId): AuthInitiated
    {
        $this->record('authWithKsefToken', ['challenge' => $challenge, 'contextNip' => $contextNip, 'publicKeyId' => $publicKeyId, 'encryptedTokenLength' => strlen($encryptedTokenBase64)]);
        if (base64_decode($encryptedTokenBase64, true) === false) {
            throw new KsefException(KsefErrorCategory::ValidationError, 'authWithKsefToken', 'encryptedToken nie jest Base64', 400, 21405, environment: $this->environment->value);
        }
        $known = array_map(static fn (PublicKeyCertificate $c): string => $c->publicKeyId, $this->certificates);
        if (! in_array($publicKeyId, $known, true)) {
            throw new KsefException(KsefErrorCategory::ValidationError, 'authWithKsefToken', 'Przesłany identyfikator klucza jest nieznany lub wskazuje na wycofany klucz.', 400, 21470, environment: $this->environment->value);
        }
        $this->authStatusIndex = 0;
        $reference = 'AUTH-'.++$this->counter;

        return new AuthInitiated($reference, Secret::of('fake-authentication-token-'.$reference), $this->now()->modify('+10 minutes'));
    }

    public function authStatus(string $referenceNumber, Secret $authenticationToken): AuthStatus
    {
        $this->record('authStatus', ['referenceNumber' => $referenceNumber]);
        $code = $this->authStatusSequence[min($this->authStatusIndex, count($this->authStatusSequence) - 1)];
        $this->authStatusIndex++;
        $description = match ($code) {
            100 => 'Uwierzytelnianie w toku',
            200 => 'Uwierzytelnianie zakończone sukcesem',
            415 => 'Uwierzytelnianie zakończone niepowodzeniem',
            425 => 'Uwierzytelnienie unieważnione',
            450 => 'Uwierzytelnianie zakończone niepowodzeniem z powodu błędnego tokenu',
            460 => 'Uwierzytelnianie zakończone niepowodzeniem z powodu błędu certyfikatu',
            default => 'Uwierzytelnianie zakończone niepowodzeniem',
        };
        $details = match ($code) {
            415 => ['Brak przypisanych uprawnień'],
            450 => ['Nieprawidłowy token'],
            default => [],
        };

        return new AuthStatus($code, $description, $details, $code === 200 ? false : null, null, 'Token');
    }

    public function redeemTokens(Secret $authenticationToken): AccessTokens
    {
        $this->record('redeemTokens', []);
        $reference = $authenticationToken->reveal();
        if ($this->redeemedReference === $reference) {
            throw new KsefException(KsefErrorCategory::ValidationError, 'redeemTokens', 'Tokeny zostały już pobrane dla tego uwierzytelnienia.', 400, 21304, environment: $this->environment->value);
        }
        $this->redeemedReference = $reference;
        $now = $this->now();

        return new AccessTokens(
            new TokenInfo(Secret::of('eyJhbGciOiJSUzI1NiJ9.'.base64_encode(json_encode(['exp' => $now->modify('+15 minutes')->getTimestamp(), 'permissions' => ['InvoiceRead', 'InvoiceWrite'], 'fake' => true])).'.fakesig'), $now->modify('+15 minutes')),
            new TokenInfo(Secret::of('fake-refresh-'.++$this->counter), $now->modify('+7 days')),
        );
    }

    public function refreshAccessToken(Secret $refreshToken): TokenInfo
    {
        $this->record('refreshAccessToken', []);
        $now = $this->now();

        return new TokenInfo(Secret::of('eyJhbGciOiJSUzI1NiJ9.'.base64_encode(json_encode(['exp' => $now->modify('+15 minutes')->getTimestamp(), 'permissions' => ['InvoiceRead', 'InvoiceWrite'], 'refreshed' => true])).'.fakesig'), $now->modify('+15 minutes'));
    }

    public function revokeCurrentSession(Secret $accessToken): void
    {
        $this->record('revokeCurrentSession', []);
    }

    public function openOnlineSession(Secret $accessToken, array $formCode, string $encryptedSymmetricKeyBase64, string $initializationVectorBase64, string $publicKeyId): OpenedSession
    {
        $this->record('openOnlineSession', ['formCode' => $formCode, 'publicKeyId' => $publicKeyId]);
        if (($formCode['systemCode'] ?? null) !== 'FA (3)' || ($formCode['schemaVersion'] ?? null) !== '1-0E' || ($formCode['value'] ?? null) !== 'FA') {
            throw new KsefException(KsefErrorCategory::ValidationError, 'openOnlineSession', 'Wskazany kod formularza nie jest wspierany.', 400, 21405, environment: $this->environment->value);
        }
        if (strlen((string) base64_decode($initializationVectorBase64, true)) !== 16) {
            throw new KsefException(KsefErrorCategory::ValidationError, 'openOnlineSession', 'Nieprawidłowa długość wektora inicjalizującego.', 400, 21405, environment: $this->environment->value);
        }
        $reference = sprintf('%s-SO-%010X-%010X-%02X', $this->now()->format('Ymd'), ++$this->counter, $this->counter * 7919, $this->counter % 256);
        $this->sessions[$reference] = ['invoices' => [], 'closed' => false];

        return new OpenedSession($reference, $this->now()->modify('+12 hours'));
    }

    public function sendInvoice(Secret $accessToken, string $sessionReference, string $invoiceHashBase64, int $invoiceSize, string $encryptedInvoiceHashBase64, int $encryptedInvoiceSize, string $encryptedInvoiceContentBase64): string
    {
        $this->record('sendInvoice', ['sessionReference' => $sessionReference, 'invoiceHash' => $invoiceHashBase64, 'invoiceSize' => $invoiceSize]);
        if (! isset($this->sessions[$sessionReference])) {
            throw new KsefException(KsefErrorCategory::ValidationError, 'sendInvoice', 'Sesja nie istnieje.', 400, 21301, environment: $this->environment->value);
        }
        $encrypted = base64_decode($encryptedInvoiceContentBase64, true);
        if ($encrypted === false || strlen($encrypted) !== $encryptedInvoiceSize || base64_encode(hash('sha256', $encrypted, true)) !== $encryptedInvoiceHashBase64) {
            throw new KsefException(KsefErrorCategory::ValidationError, 'sendInvoice', 'Skrót lub rozmiar zaszyfrowanej faktury nie zgadza się z zawartością.', 400, 21405, environment: $this->environment->value);
        }
        $reference = sprintf('%s-EE-%010X-%010X-%02X', $this->now()->format('Ymd'), ++$this->counter, $this->counter * 104729, $this->counter % 256);
        $this->sessions[$sessionReference]['invoices'][] = [
            'reference' => $reference,
            'invoiceHash' => $invoiceHashBase64,
            'ordinal' => count($this->sessions[$sessionReference]['invoices']) + 1,
        ];
        $this->invoiceStatusSequences[$reference] = $this->pendingStatuses ?? [200];
        $this->pendingStatuses = null;
        $this->invoiceStatusIndex[$reference] = 0;

        return $reference;
    }

    public function closeOnlineSession(Secret $accessToken, string $sessionReference): void
    {
        $this->record('closeOnlineSession', ['sessionReference' => $sessionReference]);
        if (isset($this->sessions[$sessionReference])) {
            $this->sessions[$sessionReference]['closed'] = true;
        }
    }

    public function sessionStatus(Secret $accessToken, string $sessionReference): SessionStatus
    {
        $this->record('sessionStatus', ['sessionReference' => $sessionReference]);
        $session = $this->sessions[$sessionReference] ?? ['invoices' => [], 'closed' => false];
        $count = count($session['invoices']);

        return new SessionStatus(
            $session['closed'] ? 200 : 100,
            $session['closed'] ? 'Sesja interaktywna przetworzona pomyślnie' : 'Sesja interaktywna otwarta',
            [],
            $this->now(),
            $this->now(),
            $this->now()->modify('+12 hours'),
            $count,
            $count,
            0,
            $session['closed'] && $count > 0 ? [['referenceNumber' => 'UPO-'.$sessionReference, 'downloadUrl' => '/api/v2/sessions/'.$sessionReference.'/upo/UPO-'.$sessionReference]] : [],
        );
    }

    public function sessionInvoices(Secret $accessToken, string $sessionReference, ?string $continuationToken = null, int $pageSize = 50): SessionInvoicesPage
    {
        $this->record('sessionInvoices', ['sessionReference' => $sessionReference]);
        $out = [];
        foreach ($this->sessions[$sessionReference]['invoices'] ?? [] as $invoice) {
            $out[] = $this->status($sessionReference, $invoice['reference'], peek: true);
        }

        return new SessionInvoicesPage($out, null);
    }

    public function sessionInvoiceStatus(Secret $accessToken, string $sessionReference, string $invoiceReference): SessionInvoiceStatus
    {
        $this->record('sessionInvoiceStatus', ['sessionReference' => $sessionReference, 'invoiceReference' => $invoiceReference]);

        return $this->status($sessionReference, $invoiceReference, peek: false);
    }

    public function sessionInvoiceUpo(Secret $accessToken, string $sessionReference, string $invoiceReference): string
    {
        $this->record('sessionInvoiceUpo', ['sessionReference' => $sessionReference, 'invoiceReference' => $invoiceReference]);
        $number = $this->assignedKsefNumbers[$invoiceReference] ?? null;
        if ($number === null) {
            throw new KsefException(KsefErrorCategory::ValidationError, 'sessionInvoiceUpo', 'UPO nie jest jeszcze dostępne.', 400, 21305, environment: $this->environment->value);
        }

        return self::upoXml($sessionReference, $number, $this->now());
    }

    public function sessionUpo(Secret $accessToken, string $sessionReference, string $upoReference): string
    {
        $this->record('sessionUpo', ['sessionReference' => $sessionReference, 'upoReference' => $upoReference]);
        $first = $this->sessions[$sessionReference]['invoices'][0]['reference'] ?? null;

        return self::upoXml($sessionReference, $first !== null ? ($this->assignedKsefNumbers[$first] ?? '5265877635-20250916-0200A0D6723E-C2') : '5265877635-20250916-0200A0D6723E-C2', $this->now());
    }

    public function queryInvoiceMetadata(Secret $accessToken, InvoiceQuery $query, int $pageOffset, int $pageSize, string $sortOrder = 'Asc'): InvoiceMetadataPage
    {
        $this->record('queryInvoiceMetadata', ['query' => $query->toArray(), 'pageOffset' => $pageOffset, 'pageSize' => $pageSize, 'sortOrder' => $sortOrder]);
        $next = array_shift($this->metadataPages);
        if ($next === null) {
            // Nothing scripted: an honest empty page with a stable HWM.
            return new InvoiceMetadataPage([], false, false, $query->to ?? $this->now(), $pageOffset, $pageSize);
        }
        if ($next instanceof \Closure) {
            $next = $next($query, $pageOffset, $pageSize);
        }

        return $next;
    }

    public function invoiceXml(Secret $accessToken, string $ksefNumber): string
    {
        $this->record('invoiceXml', ['ksefNumber' => $ksefNumber]);
        if (! isset($this->invoiceXmls[$ksefNumber])) {
            throw new KsefException(KsefErrorCategory::ValidationError, 'invoiceXml', 'Faktura o podanym numerze KSeF nie istnieje.', 400, 21406, environment: $this->environment->value);
        }

        return $this->invoiceXmls[$ksefNumber];
    }

    public function rateLimits(Secret $accessToken): array
    {
        $this->record('rateLimits', []);

        return ['onlineSession' => ['perSecond' => 1, 'perMinute' => 10, 'perHour' => 100], 'fake' => true];
    }

    // --- internals -----------------------------------------------------------

    private function status(string $sessionReference, string $invoiceReference, bool $peek): SessionInvoiceStatus
    {
        $invoice = null;
        foreach ($this->sessions[$sessionReference]['invoices'] ?? [] as $candidate) {
            if ($candidate['reference'] === $invoiceReference) {
                $invoice = $candidate;
            }
        }
        if ($invoice === null) {
            throw new KsefException(KsefErrorCategory::ValidationError, 'sessionInvoiceStatus', 'Faktura nie istnieje w tej sesji.', 400, 21406, environment: $this->environment->value);
        }
        $sequence = $this->invoiceStatusSequences[$invoiceReference];
        $index = $this->invoiceStatusIndex[$invoiceReference];
        $code = $sequence[min($index, count($sequence) - 1)];
        if (! $peek) {
            $this->invoiceStatusIndex[$invoiceReference] = $index + 1;
        }

        $ksefNumber = null;
        $extensions = [];
        if ($code === 200) {
            $ksefNumber = $this->assignedKsefNumbers[$invoiceReference] ??= $this->nextKsefNumber ?? self::ksefNumberFor('5265877635', $this->now(), $invoice['ordinal'] + $this->counter);
            $this->nextKsefNumber = null;
        }
        if ($code === 440) {
            $extensions = ['originalSessionReferenceNumber' => '20250626-SO-2F14610000-242991F8C9-B4', 'originalKsefNumber' => '5265877635-20250626-010080DD2B5E-26'];
        }
        $description = match ($code) {
            100 => 'Faktura przyjęta do dalszego przetwarzania',
            150 => 'Trwa przetwarzanie',
            200 => 'Sukces',
            405 => 'Przetwarzanie anulowane z powodu błędu sesji',
            410 => 'Nieprawidłowy zakres uprawnień',
            430 => 'Błąd weryfikacji pliku faktury',
            435 => 'Błąd odszyfrowania pliku',
            440 => 'Duplikat faktury',
            450 => 'Błąd weryfikacji semantyki dokumentu faktury',
            550 => 'Operacja została anulowana przez system',
            default => 'Nieznany błąd ('.$code.')',
        };

        return new SessionInvoiceStatus(
            $invoice['ordinal'],
            'FV/FAKE/'.$invoice['ordinal'],
            $ksefNumber,
            $invoiceReference,
            $invoice['invoiceHash'],
            $code === 200 ? $this->now() : null,
            $this->now(),
            $code === 200 ? $this->now()->modify('+1 minute') : null,
            $code === 200 ? 'https://fake.invalid/upo/'.$invoiceReference : null,
            null,
            'Online',
            $code,
            $description,
            $code === 450 ? ['Wartość pola P_15 nie zgadza się z sumą pozycji.'] : [],
            $extensions,
        );
    }

    /** @param array<string,mixed> $args */
    private function record(string $operation, array $args): void
    {
        $this->calls[] = ['operation' => $operation, 'args' => $args];
        if (! empty($this->failures[$operation])) {
            $failure = array_shift($this->failures[$operation]);
            if ($failure instanceof \Closure) {
                $failure = $failure($operation, $args);
            }
            if ($failure instanceof KsefException) {
                throw $failure;
            }
        }
    }

    private function now(): DateTimeImmutable
    {
        return $this->now ?? new DateTimeImmutable();
    }

    /** A syntactically valid KSeF number with a correct CRC-8 for a NIP and date. */
    public static function ksefNumberFor(string $nip, DateTimeImmutable $date, int $sequence): string
    {
        $data = sprintf('%s-%s-%012X', $nip, $date->format('Ymd'), $sequence);

        return $data.'-'.KsefNumber::crc8($data);
    }

    /** @return list<PublicKeyCertificate> a self-signed RSA certificate for each usage, valid now */
    public static function defaultCertificates(DateTimeImmutable $now): array
    {
        static $cache = null;
        if ($cache === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            $csr = openssl_csr_new(['commonName' => 'Ministerstwo Finansów (FAKE)'], $key, ['digest_alg' => 'sha256']);
            $cert = openssl_csr_sign($csr, null, $key, 730, ['digest_alg' => 'sha256']);
            openssl_x509_export($cert, $pem);
            $der = base64_decode(preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem) ?? '', true) ?: '';
            openssl_pkey_export($key, $privatePem);
            $cache = ['der' => $der, 'privatePem' => $privatePem];
        }
        $b64 = base64_encode($cache['der']);
        $certificateId = base64_encode(hash('sha256', $cache['der'], true));

        return [
            new PublicKeyCertificate($b64, $certificateId, 'FAKEKEY-TOKEN-'.substr($certificateId, 0, 8), $now->modify('-1 day'), $now->modify('+1 year'), ['KsefTokenEncryption']),
            new PublicKeyCertificate($b64, $certificateId, 'FAKEKEY-SYM-'.substr($certificateId, 0, 8), $now->modify('-1 day'), $now->modify('+1 year'), ['SymmetricKeyEncryption']),
        ];
    }

    /** The private key matching defaultCertificates(), for tests that verify the wrap. */
    public static function fakePrivateKeyPem(): string
    {
        self::defaultCertificates(new DateTimeImmutable());
        $ref = new \ReflectionMethod(self::class, 'defaultCertificates');
        $statics = $ref->getStaticVariables();

        return (string) ($statics['cache']['privatePem'] ?? '');
    }

    public static function upoXml(string $sessionReference, string $ksefNumber, DateTimeImmutable $now): string
    {
        $nip = KsefNumber::sellerNip($ksefNumber) ?? '5265877635';

        return '<?xml version="1.0" encoding="utf-8"?>'."\n"
            .'<Potwierdzenie xmlns="http://upo.schematy.mf.gov.pl/KSeF/v4-3">'
            .'<NazwaPodmiotuPrzyjmujacego>Ministerstwo Finansów - ATRAPA (FakeKsefTransport)</NazwaPodmiotuPrzyjmujacego>'
            .'<NumerReferencyjnySesji>'.htmlspecialchars($sessionReference, ENT_XML1).'</NumerReferencyjnySesji>'
            .'<Uwierzytelnienie><IdKontekstu><Nip>'.$nip.'</Nip></IdKontekstu>'
            .'<SkrotDokumentuUwierzytelniajacego>kyqH+QUgP8ATWd/95IY632mP4uqibwG66Oqclq9+qno=</SkrotDokumentuUwierzytelniajacego></Uwierzytelnienie>'
            .'<NazwaStrukturyLogicznej>Schemat_FA(3)_v1-0E.xsd</NazwaStrukturyLogicznej>'
            .'<KodFormularza>FA (3)</KodFormularza>'
            .'<Dokument><NipSprzedawcy>'.$nip.'</NipSprzedawcy>'
            .'<NumerKSeFDokumentu>'.$ksefNumber.'</NumerKSeFDokumentu>'
            .'<NumerFaktury>FV/FAKE/1</NumerFaktury>'
            .'<DataWystawieniaFaktury>'.$now->format('Y-m-d').'</DataWystawieniaFaktury>'
            .'<DataPrzeslaniaDokumentu>'.$now->format('Y-m-d\TH:i:s.vP').'</DataPrzeslaniaDokumentu>'
            .'<DataNadaniaNumeruKSeF>'.$now->format('Y-m-d\TH:i:s.vP').'</DataNadaniaNumeruKSeF>'
            .'<SkrotDokumentu>GZMGNVzs3krF6URKgvaw77OOeG3nJ+WGziT5xguliQ8=</SkrotDokumentu>'
            .'<TrybWysylki>Online</TrybWysylki></Dokument>'
            .'</Potwierdzenie>';
    }
}
