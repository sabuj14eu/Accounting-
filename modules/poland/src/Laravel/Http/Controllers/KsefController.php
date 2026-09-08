<?php

declare(strict_types=1);

namespace Poland\Laravel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Poland\Ksef\Error\KsefException;
use Poland\Ksef\Fa3\Fa3BuyerIdentifier;
use Poland\Ksef\Fa3\Fa3Payment;
use Poland\Ksef\KsefScope;
use Poland\Ksef\Outgoing\KsefSubmissionState;
use Poland\Laravel\Models\KsefCredentialModel;
use Poland\Laravel\Models\KsefDocumentModel;
use Poland\Laravel\Models\KsefErrorModel;
use Poland\Laravel\Models\KsefSubmissionModel;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Laravel\Support\Ksef\ErpInvoiceSnapshotBuilder;
use Poland\Laravel\Support\Ksef\KsefConnectionService;
use Poland\Laravel\Support\Ksef\KsefSettingsService;
use Poland\Laravel\Support\Ksef\KsefSubmissionService;
use Poland\Laravel\Support\Ksef\KsefTransportFactory;
use Poland\Laravel\Support\KsefIngestService;

/**
 * Settings → Poland → KSeF: status, the step-by-step configuration, the
 * connection test, manual synchronisation, and the outgoing invoices.
 *
 * Every state-changing route is a POST behind CSRF and `auth`; every message
 * a screen shows comes from a classified error, never from a raw exception.
 */
final class KsefController
{
    public function __construct(
        private readonly KsefTransportFactory $transports,
        private readonly KsefSettingsService $settings,
        private readonly KsefConnectionService $connections,
        private readonly KsefIngestService $ingest,
        private readonly KsefSubmissionService $submissions,
        private readonly ErpInvoiceSnapshotBuilder $erp,
    ) {
    }

    // --- status ----------------------------------------------------------------

    public function status(Request $request): View
    {
        $profile = $this->profile($request);

        return view('poland::ksef.status', [
            'profile' => $profile,
            'gate' => $this->transports->gate(),
            'health' => $profile !== null ? $this->connections->health($profile) : null,
            'overview' => $profile !== null ? $this->connections->overview($profile) : null,
            'unavailableReason' => $profile !== null ? $this->ingest->unavailableReason($profile) : null,
            'recentIncoming' => $profile === null ? collect() : KsefDocumentModel::query()->where('tax_profile_id', $profile->getKey())->orderByDesc('retrieved_at')->limit(10)->get(),
            'recentErrors' => $profile === null ? collect() : KsefErrorModel::query()->where('tax_profile_id', $profile->getKey())->orderByDesc('occurred_at')->limit(10)->get(),
            'review' => $profile === null ? collect() : KsefSubmissionModel::query()->where('tax_profile_id', $profile->getKey())->needingReview()->orderByDesc('id')->limit(10)->get(),
        ]);
    }

    public function test(Request $request): RedirectResponse
    {
        $profile = $this->profile($request);
        if ($profile === null) {
            return redirect()->route('poland.ksef.status')->withErrors(['profile' => 'Najpierw skonfiguruj profil podatnika.']);
        }
        try {
            $health = $this->connections->test($profile, $this->actor($request));
        } catch (\Throwable $e) {
            return redirect()->route('poland.ksef.status')->withErrors(['ksef' => 'Test połączenia nie mógł zostać wykonany: '.$e->getMessage()]);
        }
        $auth = $health->check('AUTHENTICATED');

        return redirect()->route('poland.ksef.status')->with(
            $health->isConnected() ? 'status' : 'ksef_failed',
            sprintf('Test połączenia: %s. %s', $health->overall, $auth?->detail ?? ''),
        );
    }

    public function sync(Request $request): RedirectResponse
    {
        $profile = $this->profile($request);
        if ($profile === null) {
            return redirect()->route('poland.ksef.status')->withErrors(['profile' => 'Najpierw skonfiguruj profil podatnika.']);
        }
        try {
            $reports = $this->ingest->syncIncremental($profile, null, $this->actor($request));
        } catch (\Throwable $e) {
            return redirect()->route('poland.ksef.status')->withErrors(['ksef' => $e->getMessage()]);
        }
        $lines = array_map(static fn ($r): string => $r->summary(), $reports);
        $clean = array_reduce($reports, static fn (bool $carry, $r): bool => $carry && $r->completed, true);

        return redirect()->route('poland.ksef.status')->with($clean ? 'status' : 'ksef_failed', implode(' ', $lines));
    }

    // --- wizard ----------------------------------------------------------------

    public function wizard(Request $request, int $step = 1): View
    {
        $profile = $this->profile($request);
        $credential = $profile !== null ? $this->settings->draft($profile) : null;
        $step = max(1, min(5, $step));

        return view('poland::ksef.wizard', [
            'profile' => $profile,
            'credential' => $credential,
            'step' => $step,
            'gate' => $this->transports->gate(),
            'environment' => $this->transports->environment(),
            'apiVersion' => $this->transports->apiVersion(),
            'requiredScopes' => array_map(static fn (KsefScope $s): string => $s->value, KsefScope::required()),
            'paymentForms' => Fa3Payment::FORMS,
            'vatExempt' => $profile !== null && ! $profile->toDomain()->vatStatus->settlesVat(),
            'health' => $profile !== null ? $this->connections->health($profile) : null,
        ]);
    }

    public function wizardStore(Request $request, int $step): RedirectResponse
    {
        $profile = $this->profile($request);
        if ($profile === null) {
            return redirect()->route('poland.ksef.status')->withErrors(['profile' => 'Najpierw skonfiguruj profil podatnika.']);
        }
        $credential = $this->settings->ensure($profile);

        $rules = match ($step) {
            KsefCredentialModel::STEP_ENVIRONMENT => ['confirm_environment' => ['required', 'accepted']],
            KsefCredentialModel::STEP_SELLER => [
                'nip' => ['required', 'regex:/^\d{10}$/'],
                'seller_address_country' => ['required', 'regex:/^[A-Z]{2}$/'],
                'seller_address_line1' => ['required', 'string', 'max:512'],
                'seller_address_line2' => ['nullable', 'string', 'max:512'],
                'seller_email' => ['nullable', 'email', 'max:255'],
                'seller_phone' => ['nullable', 'string', 'max:16'],
                'issue_place' => ['nullable', 'string', 'max:256'],
                'system_info' => ['nullable', 'string', 'max:256'],
            ],
            KsefCredentialModel::STEP_TOKEN => [
                'token' => [$credential->hasToken() ? 'nullable' : 'required', 'string', 'min:16', 'max:4096'],
                'token_reference' => ['nullable', 'string', 'max:64'],
                'token_valid_until' => ['nullable', 'date'],
                'token_permissions' => ['required', 'array'],
                'token_permissions.*' => ['string', 'in:InvoiceRead,InvoiceWrite'],
            ],
            KsefCredentialModel::STEP_INVOICE_DEFAULTS => [
                'exemption_legal_basis' => ['nullable', 'string', 'max:256'],
                'default_payment_form' => ['nullable', 'in:1,2,3,4,5,6,7'],
                'bank_account' => ['nullable', 'string', 'max:40'],
                'bank_name' => ['nullable', 'string', 'max:255'],
            ],
            KsefCredentialModel::STEP_TESTED => ['enabled' => ['nullable', 'boolean']],
            default => null,
        };
        if ($rules === null) {
            return redirect()->route('poland.ksef.wizard');
        }
        $data = $request->validate($rules);
        if ($step === KsefCredentialModel::STEP_TOKEN && empty($data['token'])) {
            unset($data['token']);
        }

        try {
            $this->settings->saveStep($credential, $step, $data, $this->actor($request));
        } catch (\Throwable $e) {
            return redirect()->route('poland.ksef.wizard', ['step' => $step])->withErrors(['ksef' => $e->getMessage()])->withInput($request->except('token'));
        }

        if ($step === KsefCredentialModel::STEP_TESTED) {
            return redirect()->route('poland.ksef.status')->with('status', $credential->enabled ? 'Integracja KSeF włączona.' : 'Ustawienia zapisane. Integracja pozostaje wyłączona.');
        }

        return redirect()->route('poland.ksef.wizard', ['step' => $step + 1])->with('status', 'Krok '.$step.' zapisany.');
    }

    // --- outgoing invoices -----------------------------------------------------

    public function submissions(Request $request): View
    {
        $profile = $this->profile($request);

        return view('poland::ksef.submissions', [
            'profile' => $profile,
            'gate' => $this->transports->gate(),
            'submissions' => $profile === null ? collect() : KsefSubmissionModel::query()->where('tax_profile_id', $profile->getKey())->orderByDesc('id')->limit(100)->get(),
            'erpInvoices' => $this->erp->recentErpInvoices(),
            'erpAvailable' => $this->erp->erpAvailable(),
            'identifiers' => $profile === null ? [] : $this->identifiersFor($profile),
        ]);
    }

    public function prepare(Request $request): RedirectResponse
    {
        $profile = $this->profile($request);
        if ($profile === null) {
            return redirect()->route('poland.ksef.submissions')->withErrors(['profile' => 'Najpierw skonfiguruj profil podatnika.']);
        }
        $data = $request->validate(['erp_invoice_id' => ['required', 'integer', 'min:1']]);
        try {
            $submission = $this->submissions->prepare($profile, (int) $data['erp_invoice_id'], $this->actor($request));
        } catch (\Throwable $e) {
            return redirect()->route('poland.ksef.submissions')->withErrors(['ksef' => $e->getMessage()]);
        }

        return redirect()->route('poland.ksef.submissions.show', ['submission' => $submission->getKey()]);
    }

    public function customerIdentifier(Request $request, int $customerId): RedirectResponse
    {
        $profile = $this->profile($request);
        if ($profile === null) {
            return redirect()->route('poland.ksef.submissions');
        }
        $data = $request->validate([
            'identifier_type' => ['required', 'in:'.implode(',', [Fa3BuyerIdentifier::NIP, Fa3BuyerIdentifier::VAT_UE, Fa3BuyerIdentifier::OTHER, Fa3BuyerIdentifier::NONE])],
            'identifier_value' => ['nullable', 'string', 'max:50'],
            'identifier_country' => ['nullable', 'string', 'size:2'],
            'address_country' => ['nullable', 'string', 'size:2'],
            'address_line1' => ['nullable', 'string', 'max:512'],
            'address_line2' => ['nullable', 'string', 'max:512'],
            'local_government_sub_unit' => ['nullable', 'boolean'],
            'vat_group_member' => ['nullable', 'boolean'],
        ]);
        $this->erp->saveCustomerIdentifier($profile, $customerId, $data, $this->actor($request));

        return redirect()->route('poland.ksef.submissions')->with('status', 'Zapisano identyfikator nabywcy #'.$customerId.'.');
    }

    public function show(Request $request, int $submission): View
    {
        $model = KsefSubmissionModel::query()->with(['document', 'upoDocument', 'events', 'lastError'])->findOrFail($submission);

        return view('poland::ksef.submission', [
            'submission' => $model,
            'state' => $model->stateEnum(),
            'gate' => $this->transports->gate(),
            'errors_for' => KsefErrorModel::query()->where('submission_id', $model->getKey())->orderByDesc('occurred_at')->limit(10)->get(),
        ]);
    }

    public function ready(Request $request, int $submission): RedirectResponse
    {
        return $this->act($submission, fn (KsefSubmissionModel $s) => $this->submissions->markReady($s, $this->actor($request)), 'Zatwierdzono do wysyłki.');
    }

    public function send(Request $request, int $submission): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);

        return $this->act($submission, fn (KsefSubmissionModel $s) => $this->submissions->send($s, $this->actor($request)), 'Wysłano do KSeF.');
    }

    public function poll(Request $request, int $submission): RedirectResponse
    {
        return $this->act($submission, fn (KsefSubmissionModel $s) => $this->submissions->poll($s, $this->actor($request)), 'Status sprawdzony.');
    }

    public function upo(Request $request, int $submission): RedirectResponse
    {
        return $this->act($submission, fn (KsefSubmissionModel $s) => $this->submissions->fetchUpo($s, $this->actor($request)), 'UPO pobrane.');
    }

    public function resolve(Request $request, int $submission): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:recheck,resend,cancel,accept_original'], 'note' => ['nullable', 'string', 'max:500']]);

        return $this->act($submission, fn (KsefSubmissionModel $s) => $this->submissions->resolveReview($s, $data['action'], $this->actor($request), $data['note'] ?? null), 'Decyzja zapisana.');
    }

    public function cancel(Request $request, int $submission): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return $this->act($submission, fn (KsefSubmissionModel $s) => $this->submissions->cancel($s, $this->actor($request), $data['reason']), 'Anulowano.');
    }

    public function xml(int $submission): Response
    {
        $model = KsefSubmissionModel::query()->with('document')->findOrFail($submission);
        $document = $model->document;
        abort_if($document === null, 404);

        return response((string) $document->xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="FA3-'.preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $model->invoice_number).'.xml"',
            'X-Document-SHA256' => (string) $document->xml_hash,
        ]);
    }

    public function upoXml(int $submission): Response
    {
        $model = KsefSubmissionModel::query()->with('upoDocument')->findOrFail($submission);
        $document = $model->upoDocument;
        abort_if($document === null, 404);

        return response((string) $document->xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="UPO-'.preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($model->ksef_number ?? $model->invoice_number)).'.xml"',
            'X-Document-SHA256' => (string) $document->xml_hash,
        ]);
    }

    // --- helpers ---------------------------------------------------------------

    private function act(int $submission, \Closure $work, string $okMessage): RedirectResponse
    {
        $model = KsefSubmissionModel::query()->findOrFail($submission);
        $target = redirect()->route('poland.ksef.submissions.show', ['submission' => $model->getKey()]);
        try {
            $result = $work($model);
        } catch (KsefException $e) {
            return $target->withErrors(['ksef' => sprintf('[%s] %s', $e->category->label(), $e->getMessage())]);
        } catch (\Throwable $e) {
            return $target->withErrors(['ksef' => $e->getMessage()]);
        }
        $state = $result instanceof KsefSubmissionModel ? $result->stateEnum() : null;
        $flash = match ($state) {
            KsefSubmissionState::Accepted => 'status',
            KsefSubmissionState::Rejected, KsefSubmissionState::ManualReview, KsefSubmissionState::Blocked => 'ksef_failed',
            default => 'status',
        };

        return $target->with($flash, $okMessage.($state !== null ? ' Stan: '.$state->label().'.' : ''));
    }

    /** @return array<int, array<string,mixed>> erp customer id => identifier row */
    private function identifiersFor(TaxProfileModel $profile): array
    {
        $out = [];
        foreach (\Poland\Laravel\Models\KsefCustomerIdentifierModel::query()->where('tax_profile_id', $profile->getKey())->get() as $row) {
            $out[(int) $row->erp_customer_id] = $row->toArray();
        }

        return $out;
    }

    private function profile(Request $request): ?TaxProfileModel
    {
        if ($request->filled('profile')) {
            return TaxProfileModel::query()->find((int) $request->query('profile'));
        }

        return TaxProfileModel::query()->orderBy('id')->first();
    }

    private function actor(Request $request): string
    {
        $user = $request->user();

        return $user !== null ? (string) ($user->email ?? $user->name ?? 'user#'.$user->getAuthIdentifier()) : 'anonymous';
    }
}
