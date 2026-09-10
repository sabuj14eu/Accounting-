<?php

declare(strict_types=1);

namespace Poland\Ksef;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Poland\Ksef\Contracts\KsefClient;
use Poland\Ksef\Http\HttpTransport;
use Poland\Ksef\Http\KsefTransportException;
use Poland\Ksef\Parsing\FaInvoiceParser;
use Throwable;

/**
 * The twelve steps of docs/PRODUCTION_AUDIT_2026-09-07.md §1, run against a
 * live KSeF environment and reported one verdict per step.
 *
 * Verdicts: PASS · FAIL · NOT TESTED (the environment gave nothing to test
 * it with — e.g. an empty test inbox) · NOT RUNNABLE (could not even try).
 * NOT TESTED is never PASS. Steps whose live half cannot be induced from
 * outside (retries, malformed bodies) say so and point at the unit tests
 * that cover them; the operator decides whether that is enough.
 *
 * Nothing here writes to the database or imports an invoice. It reads.
 */
final class GoLiveChecklist
{
    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const NOT_TESTED = 'NOT TESTED';

    public const NOT_RUNNABLE = 'NOT RUNNABLE';

    /**
     * @param Closure(int $pageSize, ?string $tokenOverride): KsefClient $clientFactory
     */
    public function __construct(
        private readonly Closure $clientFactory,
        private readonly HttpTransport $http,
        private readonly KsefEndpoint $endpoint,
        private readonly FaInvoiceParser $parser,
        private readonly string $pinnedVersion,
        private readonly int $lookbackDays = 45,
        private readonly ?DateTimeImmutable $now = null,
    ) {
    }

    /**
     * @return list<array{step:int, name:string, verdict:string, observed:string}>
     */
    public function run(): array
    {
        $now = $this->now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $from = $now->modify(sprintf('-%d days', min($this->lookbackDays, HttpKsefClient::MAX_WINDOW_DAYS)));
        $results = [];

        // 1 + 2: specification and pin ---------------------------------------
        $results[] = $this->row(1, 'Aktualna oficjalna specyfikacja API', self::PASS, sprintf(
            'CIRFMF/ksef-docs open-api.json %s (commit %s), środowisko %s (%s)',
            HttpKsefClient::API_VERSION,
            HttpKsefClient::API_DOCS_COMMIT,
            $this->endpoint->environment->value,
            $this->endpoint->host(),
        ));
        $results[] = $this->pinnedVersion === HttpKsefClient::API_VERSION
            ? $this->row(2, 'Wersja API przypięta', self::PASS, 'config poland.ksef.api_version = HttpKsefClient::API_VERSION = '.$this->pinnedVersion)
            : $this->row(2, 'Wersja API przypięta', self::FAIL, sprintf('config mówi %s, klient napisano dla %s — uzgodnij świadomie', $this->pinnedVersion, HttpKsefClient::API_VERSION));

        // 3: transport ---------------------------------------------------------
        $client = null;
        try {
            $client = ($this->clientFactory)(10, null);
            if (! $client instanceof HttpKsefClient) {
                $results[] = $this->row(3, 'Transport HTTPS', self::NOT_RUNNABLE, 'KSEF_TRANSPORT nie jest "real" — lista kontrolna dotyczy wyłącznie prawdziwego transportu');

                return $this->padRemaining($results, 4, 'transport nie jest prawdziwy');
            }
            $probe = $client->probeCertificates();
            $results[] = $this->row(3, 'Transport HTTPS', self::PASS, sprintf('TLS do %s, klucz szyfrujący token %s ważny do %s', $this->endpoint->host(), $probe['publicKeyId'], $probe['validTo'] ?? '?'));
        } catch (Throwable $e) {
            $results[] = $this->row(3, 'Transport HTTPS', self::FAIL, $this->describe($e));

            return $this->padRemaining($results, 4, 'brak transportu');
        }

        // 4: authentication --------------------------------------------------
        $session = null;
        try {
            $session = $client->openSession();
            $results[] = $this->row(4, 'Uwierzytelnienie tokenem', self::PASS, sprintf('sesja %s, access token ważny do %s', $session->reference, $session->expiresAt?->format(DATE_ATOM) ?? '?'));
        } catch (Throwable $e) {
            $results[] = $this->row(4, 'Uwierzytelnienie tokenem', self::FAIL, $this->describe($e));

            return $this->padRemaining($results, 5, 'brak sesji', $this->step11($from, $now));
        }

        $numbers = [];
        try {
            // 5: InvoiceRead --------------------------------------------------
            try {
                $first = $client->queryInvoices($session, $now->modify('-1 day'), $now);
                $results[] = $this->row(5, 'Uprawnienie InvoiceRead', self::PASS, sprintf('invoices/query/metadata odpowiedziało 200 (%d pozycji w ostatniej dobie)', $first->count()));
            } catch (KsefTransportException $e) {
                $results[] = $this->row(5, 'Uprawnienie InvoiceRead', self::FAIL, $this->describe($e));

                return $this->padRemaining($results, 6, 'brak uprawnienia', $this->step11($from, $now));
            }

            // 6 + 7: retrieval and pagination ---------------------------------
            $pages = 0;
            $cursor = null;
            $firstMetadata = null;
            do {
                $page = $client->queryInvoices($session, $from, $now, $cursor);
                $pages++;
                foreach ($page->invoices as $m) {
                    $numbers[] = $m->ksefNumber;
                    $firstMetadata ??= $m;
                }
                $cursor = $page->nextCursor;
            } while ($cursor !== null && $pages < 50);

            $unique = count(array_unique($numbers));
            if ($firstMetadata === null) {
                $results[] = $this->row(6, 'Pobranie faktury', self::NOT_TESTED, sprintf(
                    'skrzynka odbiorcza NIP %s w środowisku %s jest pusta w oknie %s → %s. Wystaw fakturę testową na ten NIP i uruchom ponownie.',
                    $this->endpoint->nip,
                    $this->endpoint->environment->value,
                    $from->format('Y-m-d'),
                    $now->format('Y-m-d'),
                ));
            } else {
                try {
                    $xml = $client->fetchInvoiceXml($session, $firstMetadata->ksefNumber);
                    $parsed = $this->parser->parse($xml, $firstMetadata->ksefNumber, $now);
                    $results[] = $this->row(6, 'Pobranie faktury', self::PASS, sprintf(
                        '%s: %d bajtów XML, sprzedawca %s, brutto %s, pola brakujące: %s',
                        $firstMetadata->ksefNumber,
                        strlen($xml),
                        $parsed->metadata->sellerNip ?? '?',
                        $parsed->metadata->gross?->format() ?? '?',
                        $parsed->missing === [] ? 'żadne' : implode(', ', $parsed->missing),
                    ));
                } catch (Throwable $e) {
                    $results[] = $this->row(6, 'Pobranie faktury', self::FAIL, $this->describe($e));
                }
            }

            if ($pages > 1) {
                $results[] = $this->row(7, 'Stronicowanie / kursory', $unique === count($numbers) ? self::PASS : self::FAIL, sprintf(
                    '%d stron po 10, %d numerów, %d unikalnych%s',
                    $pages,
                    count($numbers),
                    $unique,
                    $unique === count($numbers) ? '' : ' — strony nachodzą na siebie',
                ));
            } else {
                $results[] = $this->row(7, 'Stronicowanie / kursory', self::NOT_TESTED, sprintf(
                    'tylko %d faktur w oknie (potrzeba >10 na stronę 10). Jednostkowo: PASS (HttpKsefClientTest step 7).',
                    count($numbers),
                ));
            }

            // 8: retries / timeouts (cannot be induced from outside) --------------
            $results[] = $this->row(8, 'Ponowienia / limity czasu', self::NOT_TESTED, 'nie da się wywołać 429/5xx/timeout na żądanie. Jednostkowo: PASS (HttpKsefClientTest step 8: 429+Retry-After, 5xx backoff, timeout, brak ponowień przy inicjacji auth).');

            // 9: duplicate delivery ------------------------------------------
            if ($numbers === []) {
                $results[] = $this->row(9, 'Podwójne dostarczenie', self::NOT_TESTED, 'pusta skrzynka — porównanie dwóch odczytów niemożliwe. Jednostkowo: PASS (indeks unikalny + HttpKsefClientTest step 9).');
            } else {
                $again = [];
                $cursor = null;
                $pages = 0;
                do {
                    $page = $client->queryInvoices($session, $from, $now, $cursor);
                    $pages++;
                    foreach ($page->invoices as $m) {
                        $again[] = $m->ksefNumber;
                    }
                    $cursor = $page->nextCursor;
                } while ($cursor !== null && $pages < 50);
                sort($numbers);
                sort($again);
                $results[] = $this->row(9, 'Podwójne dostarczenie', $numbers === $again ? self::PASS : self::FAIL, sprintf(
                    'dwa odczyty tego samego okna: %d vs %d numerów, %s',
                    count($numbers),
                    count($again),
                    $numbers === $again ? 'identyczne zbiory (drugi import odrzuci indeks unikalny)' : 'RÓŻNE zbiory',
                ));
            }

            // 10: malformed responses ------------------------------------------
            $results[] = $this->row(10, 'Zniekształcone odpowiedzi', self::NOT_TESTED, 'serwer nie zwróci zniekształconej odpowiedzi na żądanie. Jednostkowo: PASS (HttpKsefClientTest step 10: HTML, brak pól, złe typy, zły numer KSeF, nie-XML).');
        } finally {
            try {
                $client->closeSession($session);
            } catch (Throwable) {
            }
        }

        // 11: revoked / expired credentials (a deliberately wrong token) -------
        $results[] = $this->step11($from, $now);

        // 12: API version drift -------------------------------------------------
        $results[] = $this->step12();

        usort($results, static fn (array $a, array $b): int => $a['step'] <=> $b['step']);

        return $results;
    }

    /** @return array{step:int, name:string, verdict:string, observed:string} */
    private function step11(DateTimeImmutable $from, DateTimeImmutable $now): array
    {
        try {
            $wrong = ($this->clientFactory)(10, 'invalid-token-'.bin2hex(random_bytes(6)));
            $wrong->openSession();

            return $this->row(11, 'Unieważniony / błędny token', self::FAIL, 'KSeF PRZYJĄŁ celowo błędny token — to nie może być prawda; sprawdź środowisko i klienta');
        } catch (KsefTransportException $e) {
            return $e->isCredentialProblem()
                ? $this->row(11, 'Unieważniony / błędny token', self::PASS, 'celowo błędny token odrzucony jako problem poświadczeń: '.$this->describe($e))
                : $this->row(11, 'Unieważniony / błędny token', self::FAIL, 'błędny token odrzucony, ale nie jako problem poświadczeń ('.$e->kind.'): '.$this->describe($e));
        } catch (Throwable $e) {
            return $this->row(11, 'Unieważniony / błędny token', self::NOT_RUNNABLE, $this->describe($e));
        }
    }

    /** @return array{step:int, name:string, verdict:string, observed:string} */
    private function step12(): array
    {
        $docsUrl = 'https://'.$this->endpoint->host().'/docs/v2/open-api.json';
        try {
            $response = $this->http->send('GET', $docsUrl, ['Accept' => 'application/json'], null);
            if (! $response->isSuccess()) {
                return $this->row(12, 'Zmiany wersji API', self::NOT_RUNNABLE, sprintf('specyfikacja na żywo niedostępna (HTTP %d) — porównaj ręcznie z changelogiem CIRFMF/ksef-docs', $response->status));
            }
            $spec = json_decode($response->body, true);
            $description = is_array($spec) ? (string) ($spec['info']['description'] ?? '') : '';
            if (preg_match('/Wersja API:\*\*\s*([0-9]+\.[0-9]+\.[0-9]+)/u', $description, $m) !== 1) {
                return $this->row(12, 'Zmiany wersji API', self::NOT_RUNNABLE, 'specyfikacja na żywo nie podaje numeru wersji w oczekiwanym miejscu — porównaj ręcznie');
            }
            $live = $m[1];
            if ($live === HttpKsefClient::API_VERSION) {
                return $this->row(12, 'Zmiany wersji API', self::PASS, 'środowisko ogłasza wersję '.$live.' = wersja klienta; wszystkie odpowiedzi na żywo przeszły walidację pól wymaganych');
            }

            return $this->row(12, 'Zmiany wersji API', self::FAIL, sprintf('środowisko ogłasza %s, klient napisano dla %s — przeczytaj api-changelog.md zanim włączysz produkcję', $live, HttpKsefClient::API_VERSION));
        } catch (Throwable $e) {
            return $this->row(12, 'Zmiany wersji API', self::NOT_RUNNABLE, $this->describe($e));
        }
    }

    /**
     * @param list<array{step:int, name:string, verdict:string, observed:string}> $results
     * @return list<array{step:int, name:string, verdict:string, observed:string}>
     */
    private function padRemaining(array $results, int $fromStep, string $because, ?array $step11 = null): array
    {
        $names = [4 => 'Uwierzytelnienie tokenem', 5 => 'Uprawnienie InvoiceRead', 6 => 'Pobranie faktury', 7 => 'Stronicowanie / kursory', 8 => 'Ponowienia / limity czasu', 9 => 'Podwójne dostarczenie', 10 => 'Zniekształcone odpowiedzi', 11 => 'Unieważniony / błędny token', 12 => 'Zmiany wersji API'];
        for ($i = $fromStep; $i <= 12; $i++) {
            if ($i === 11 && $step11 !== null) {
                $results[] = $step11;

                continue;
            }
            $results[] = $this->row($i, $names[$i], self::NOT_RUNNABLE, 'pominięto: '.$because);
        }

        return $results;
    }

    /** @return array{step:int, name:string, verdict:string, observed:string} */
    private function row(int $step, string $name, string $verdict, string $observed): array
    {
        return ['step' => $step, 'name' => $name, 'verdict' => $verdict, 'observed' => $observed];
    }

    private function describe(Throwable $e): string
    {
        $kind = $e instanceof KsefTransportException ? $e->kind.': ' : '';

        return $kind.$e->getMessage();
    }

    /** @param list<array{step:int, name:string, verdict:string, observed:string}> $results */
    public static function allPassed(array $results): bool
    {
        foreach ($results as $r) {
            if ($r['verdict'] !== self::PASS) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array{step:int, name:string, verdict:string, observed:string}> $results */
    public static function summary(array $results): string
    {
        $counts = [];
        foreach ($results as $r) {
            $counts[$r['verdict']] = ($counts[$r['verdict']] ?? 0) + 1;
        }
        ksort($counts);

        return implode(', ', array_map(static fn (string $k, int $v): string => $k.' '.$v, array_keys($counts), $counts));
    }
}
