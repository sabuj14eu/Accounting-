<?php

declare(strict_types=1);

namespace Poland\Laravel\Filament;

use Poland\Ksef\Outgoing\KsefSubmissionState;
use Poland\Laravel\Models\KsefSubmissionModel;

/**
 * Adds KSeF state to the ERP's own screens without editing them: a banner
 * above the Sales Orders table and a section at the end of the invoice
 * editor, both through Filament render hooks scoped to those pages.
 *
 * Everything is guarded: when Filament or the ERP page classes are absent
 * (the module's own test suite, a standalone install) nothing registers.
 */
final class KsefRenderHooks
{
    public const SALES_ORDERS_LIST = 'App\\Filament\\App\\Resources\\SalesOrders\\Pages\\ListSalesOrders';

    public const INVOICE_EDIT = 'App\\Filament\\App\\Resources\\Invoices\\Pages\\EditInvoice';

    public static function register(): void
    {
        if (! class_exists('Filament\\Support\\Facades\\FilamentView')) {
            return;
        }
        $view = 'Filament\\Support\\Facades\\FilamentView';
        $tablesHook = class_exists('Filament\\Tables\\View\\TablesRenderHook') ? constant('Filament\\Tables\\View\\TablesRenderHook::HEADER_BEFORE') : 'tables::header.before';
        $panelsHook = class_exists('Filament\\View\\PanelsRenderHook') ? constant('Filament\\View\\PanelsRenderHook::PAGE_END') : 'panels::page.end';

        $view::registerRenderHook($tablesHook, static function (): string {
            try {
                return view('poland::filament.sales-orders-ksef', ['rows' => self::recentSubmissions()])->render();
            } catch (\Throwable) {
                return '';
            }
        }, scopes: self::SALES_ORDERS_LIST);

        $view::registerRenderHook($panelsHook, static function (): string {
            try {
                $record = self::currentRecord();
                if ($record === null) {
                    return '';
                }
                $submission = KsefSubmissionModel::query()->where('erp_invoice_id', (int) $record->getKey())->orderByDesc('id')->first();

                return view('poland::filament.invoice-ksef', ['invoice' => $record, 'submission' => $submission])->render();
            } catch (\Throwable) {
                return '';
            }
        }, scopes: self::INVOICE_EDIT);
    }

    /** @return list<array<string,mixed>> */
    public static function recentSubmissions(int $limit = 25): array
    {
        $rows = [];
        foreach (KsefSubmissionModel::query()->orderByDesc('id')->limit($limit)->get() as $s) {
            $rows[] = [
                'id' => $s->getKey(),
                'erp_invoice_id' => $s->erp_invoice_id,
                'erp_sales_order_id' => $s->erp_sales_order_id,
                'invoice_number' => $s->invoice_number,
                'buyer' => $s->buyer_label,
                'gross' => $s->gross,
                'state' => $s->state,
                'label' => KsefSubmissionState::from((string) $s->state)->label(),
                'ksef_number' => $s->ksef_number,
                'url' => route('poland.ksef.submissions.show', ['submission' => $s->getKey()]),
            ];
        }

        return $rows;
    }

    private static function currentRecord(): ?object
    {
        if (! class_exists('Livewire\\Livewire')) {
            return null;
        }
        $component = \Livewire\Livewire::current();
        if ($component === null || ! method_exists($component, 'getRecord')) {
            return null;
        }
        $record = $component->getRecord();

        return is_object($record) && method_exists($record, 'getKey') ? $record : null;
    }
}
