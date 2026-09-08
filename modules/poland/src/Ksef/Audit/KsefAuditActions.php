<?php

declare(strict_types=1);

namespace Poland\Ksef\Audit;

/** The vocabulary of the KSeF audit trail. Stable identifiers, not display strings. */
final class KsefAuditActions
{
    public const CONNECTION_TESTED = 'ksef.connection_tested';

    public const AUTH_SUCCEEDED = 'ksef.auth.succeeded';

    public const AUTH_FAILED = 'ksef.auth.failed';

    public const AUTH_REFRESHED = 'ksef.auth.refreshed';

    public const SESSION_OPENED = 'ksef.session.opened';

    public const SESSION_CLOSED = 'ksef.session.closed';

    public const XML_GENERATED = 'ksef.invoice.xml_generated';

    public const XML_VALIDATION_PASSED = 'ksef.invoice.xml_validation_passed';

    public const XML_VALIDATION_FAILED = 'ksef.invoice.xml_validation_failed';

    public const INVOICE_SUBMITTED = 'ksef.invoice.submitted';

    public const INVOICE_SUBMISSION_RECOVERED = 'ksef.invoice.submission_recovered';

    public const INVOICE_STATUS_CHECKED = 'ksef.invoice.status_checked';

    public const INVOICE_ACCEPTED = 'ksef.invoice.accepted';

    public const INVOICE_REJECTED = 'ksef.invoice.rejected';

    public const UPO_RETRIEVED = 'ksef.invoice.upo_retrieved';

    public const INCOMING_SYNCED = 'ksef.incoming.synced';

    public const INCOMING_PAGE_PERSISTED = 'ksef.incoming.page_persisted';

    public const SYNC_FAILED = 'ksef.sync.failed';

    public const RETRY_SCHEDULED = 'ksef.retry_scheduled';

    public const MANUAL_REVIEW_REQUIRED = 'ksef.manual_review_required';

    public const SETTINGS_CHANGED = 'ksef.settings_changed';

    public const TOKEN_STORED = 'ksef.token_stored';
}
