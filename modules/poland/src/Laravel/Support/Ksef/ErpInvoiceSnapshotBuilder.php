<?php

declare(strict_types=1);

namespace Poland\Laravel\Support\Ksef;

use Poland\Domain\Enums\VatStatus;
use Poland\Ksef\Fa3\ErpInvoiceSnapshot;
use Poland\Laravel\Models\KsefCredentialModel;
use Poland\Laravel\Models\KsefCustomerIdentifierModel;
use Poland\Laravel\Models\TaxProfileModel;
use RuntimeException;

/**
 * Reads the ERP's invoice, items and customer (the Liberu `App\Models\Invoice`
 * family, when the module runs inside the foundation) and the KSeF settings
 * into a framework-free {@see ErpInvoiceSnapshot}.
 *
 * It reads. It never writes to the ERP and never computes a tax figure: a
 * line's amount and tax_amount are copied as booked.
 */
final class ErpInvoiceSnapshotBuilder
{
    public const INVOICE_MODEL = 'App\\Models\\Invoice';

    public function erpAvailable(): bool
    {
        return class_exists(self::INVOICE_MODEL);
    }

    /** @return object|null the ERP invoice model */
    public function erpInvoice(int $erpInvoiceId): ?object
    {
        if (! $this->erpAvailable()) {
            return null;
        }
        $model = self::INVOICE_MODEL;

        return $model::query()->with(['items', 'customer', 'salesOrder', 'payments'])->find($erpInvoiceId);
    }

    /** @return list<array<string,mixed>> recent ERP invoices, for the "prepare" screen */
    public function recentErpInvoices(int $limit = 50): array
    {
        if (! $this->erpAvailable()) {
            return [];
        }
        $model = self::INVOICE_MODEL;
        $out = [];
        foreach ($model::query()->with('customer')->orderByDesc('id')->limit($limit)->get() as $invoice) {
            $out[] = [
                'id' => (int) $invoice->getKey(),
                'number' => (string) ($invoice->invoice_number ?? ''),
                'date' => $invoice->invoice_date?->format('Y-m-d'),
                'customer' => trim((string) ($invoice->customer?->customer_name ?? '')),
                'customer_id' => $invoice->customer_id,
                'total' => (string) ($invoice->total_amount ?? ''),
                'sales_order_id' => $invoice->sales_order_id,
            ];
        }

        return $out;
    }

    public function customerIdentifier(TaxProfileModel $profile, int $erpCustomerId): ?KsefCustomerIdentifierModel
    {
        return KsefCustomerIdentifierModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->where('erp_customer_id', $erpCustomerId)
            ->first();
    }

    public function build(TaxProfileModel $profile, KsefCredentialModel $settings, object $invoice): ErpInvoiceSnapshot
    {
        $customer = $invoice->customer;
        $identifier = $customer !== null ? $this->customerIdentifier($profile, (int) $customer->getKey()) : null;

        $lines = [];
        foreach ($invoice->items ?? [] as $item) {
            $rate = null;
            try {
                $taxRate = $item->taxRate;
                $rate = $taxRate?->rate;
            } catch (\Throwable) {
                $rate = null;
            }
            $lines[] = [
                'description' => (string) $item->description,
                'quantity' => $item->quantity,
                'unit' => null,
                'unit_price' => $item->unit_price,
                'amount' => (string) $item->amount,
                'tax_amount' => (string) ($item->tax_amount ?? '0'),
                'tax_rate' => $rate,
            ];
        }

        $paidOn = null;
        $paid = null;
        if (($invoice->payment_status ?? null) === 'paid') {
            $paid = true;
            try {
                $last = collect($invoice->payments ?? [])->sortByDesc('payment_date')->first();
                $paidOn = $last?->payment_date?->format('Y-m-d');
            } catch (\Throwable) {
                $paidOn = null;
            }
            if ($paidOn === null) {
                // "Paid" without a date cannot be stated on the invoice; leave it unstated.
                $paid = null;
            }
        }

        $domain = $profile->toDomain();
        $vatExempt = ! $domain->vatStatus->settlesVat();

        return new ErpInvoiceSnapshot(
            invoiceId: (int) $invoice->getKey(),
            number: (string) $invoice->invoice_number,
            issueDate: (string) $invoice->invoice_date?->format('Y-m-d'),
            seller: [
                'nip' => (string) $settings->nip,
                'name' => (string) $profile->name,
                'country' => (string) ($settings->seller_address_country ?? 'PL'),
                'line1' => (string) $settings->seller_address_line1,
                'line2' => $settings->seller_address_line2,
                'email' => $settings->seller_email,
                'phone' => $settings->seller_phone,
            ],
            buyer: [
                'name' => $customer !== null ? trim(implode(' ', array_filter([(string) $customer->customer_name, (string) ($customer->customer_last_name ?? '')]))) : null,
                'identifier_type' => $identifier?->identifier_type,
                'identifier_value' => $identifier?->identifier_value,
                'identifier_country' => $identifier?->identifier_country,
                'country' => $identifier?->address_country ?? 'PL',
                'line1' => $identifier?->address_line1 ?? ($customer?->customer_address ?: null),
                'line2' => $identifier?->address_line2 ?? ($customer?->customer_city ?: null),
                'email' => $customer?->customer_email ?: null,
                'jst' => (bool) ($identifier?->local_government_sub_unit ?? false),
                'gv' => (bool) ($identifier?->vat_group_member ?? false),
            ],
            lines: $lines,
            currency: (string) config('poland.currency', 'PLN'),
            saleDate: null,
            dueDate: $invoice->due_date?->format('Y-m-d'),
            paid: $paid,
            paidOn: $paidOn,
            paymentForm: $settings->default_payment_form,
            bankAccount: $settings->bank_account,
            bankName: $settings->bank_name,
            issuePlace: $settings->issue_place,
            exemptionLegalBasis: $settings->exemption_legal_basis,
            vatExemptTaxpayer: $vatExempt,
        );
    }

    /** @param array<string,mixed> $data */
    public function saveCustomerIdentifier(TaxProfileModel $profile, int $erpCustomerId, array $data, string $actor): KsefCustomerIdentifierModel
    {
        return KsefCustomerIdentifierModel::query()->updateOrCreate(
            ['tax_profile_id' => $profile->getKey(), 'erp_customer_id' => $erpCustomerId],
            [
                'identifier_type' => (string) $data['identifier_type'],
                'identifier_value' => isset($data['identifier_value']) && trim((string) $data['identifier_value']) !== '' ? trim((string) $data['identifier_value']) : null,
                'identifier_country' => isset($data['identifier_country']) && trim((string) $data['identifier_country']) !== '' ? strtoupper(trim((string) $data['identifier_country'])) : null,
                'address_country' => isset($data['address_country']) && trim((string) $data['address_country']) !== '' ? strtoupper(trim((string) $data['address_country'])) : null,
                'address_line1' => isset($data['address_line1']) && trim((string) $data['address_line1']) !== '' ? trim((string) $data['address_line1']) : null,
                'address_line2' => isset($data['address_line2']) && trim((string) $data['address_line2']) !== '' ? trim((string) $data['address_line2']) : null,
                'local_government_sub_unit' => (bool) ($data['local_government_sub_unit'] ?? false),
                'vat_group_member' => (bool) ($data['vat_group_member'] ?? false),
                'confirmed_by' => $actor,
            ],
        );
    }
}
