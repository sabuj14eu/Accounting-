<?php

declare(strict_types=1);

namespace Poland\Laravel\Support\Ksef;

use Poland\Ksef\Error\KsefException;
use Poland\Laravel\Models\KsefErrorModel;

/** Persists a classified failure so it can be listed, counted and linked from the thing that failed. */
final class KsefErrorRecorder
{
    public function record(KsefException $e, ?int $taxProfileId = null, ?int $submissionId = null, ?int $syncRunId = null): KsefErrorModel
    {
        return KsefErrorModel::create([
            'tax_profile_id' => $taxProfileId,
            'environment' => $e->environment ?? (string) config('poland.ksef.environment', 'test'),
            'operation' => mb_substr($e->operation, 0, 64),
            'category' => $e->category->value,
            'retryable' => $e->isRetryable(),
            'outcome_known' => $e->outcomeKnown,
            'http_status' => $e->httpStatus,
            'ksef_code' => $e->ksefCode,
            'message' => $e->getMessage(),
            'details' => $e->details,
            'reference_number' => $e->referenceNumber !== null ? mb_substr($e->referenceNumber, 0, 128) : null,
            'submission_id' => $submissionId,
            'sync_run_id' => $syncRunId,
            'occurred_at' => $e->occurredAt,
        ]);
    }
}
