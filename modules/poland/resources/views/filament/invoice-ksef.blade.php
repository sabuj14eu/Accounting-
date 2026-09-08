@php use Poland\Ksef\Outgoing\KsefSubmissionState; @endphp
<div style="border:1px solid #dfe3e9;border-radius:10px;padding:12px 14px;margin-top:16px;font-size:.9rem">
    <strong>KSeF</strong>
    @if (!$submission)
        <div style="margin-top:6px">Stan: <b>NOT SENT</b> — faktura nie została przygotowana do KSeF.</div>
        <form method="post" action="{{ route('poland.ksef.submissions.prepare') }}" style="margin-top:8px">
            @csrf
            <input type="hidden" name="erp_invoice_id" value="{{ $invoice->getKey() }}">
            <button type="submit" style="padding:7px 12px;border-radius:8px;background:#14171c;color:#fff">Przygotuj FA(3) do KSeF</button>
        </form>
    @else
        <div style="margin-top:6px">Stan: <b>{{ KsefSubmissionState::from($submission->state)->label() }}</b>
            @if ($submission->ksef_number) · numer KSeF <code>{{ $submission->ksef_number }}</code> @endif
            @if ($submission->submitted_at) · wysłana {{ $submission->submitted_at->format('d.m.Y H:i') }} @endif
            @if ($submission->upo_document_id) · UPO: AVAILABLE @endif
        </div>
        @if ($submission->manual_review_reason)<div style="margin-top:4px;color:#92400e">Przegląd ręczny: {{ $submission->manual_review_reason }}</div>@endif
        @if ($submission->rejection_reason)<div style="margin-top:4px;color:#b91c1c">Odrzucona: {{ $submission->rejection_reason }}</div>@endif
        <div style="margin-top:8px"><a href="{{ route('poland.ksef.submissions.show', ['submission' => $submission->getKey()]) }}">Szczegóły, XML, UPO, historia →</a></div>
    @endif
</div>
