<?php

declare(strict_types=1);

namespace Poland\Laravel\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Poland\Inventory\StockQuantity;
use Poland\Inventory\StockUnit;
use Poland\Laravel\Models\ProductModel;
use Poland\Laravel\Models\TaxProfileModel;
use Poland\Laravel\Support\InventoryService;
use Poland\Laravel\Support\ProductCatalogService;
use Poland\Purchases\CostCategory;

/** Products, their tracking toggle, and the optional count. */
final class ProductController
{
    public function __construct(
        private readonly ProductCatalogService $catalog,
        private readonly InventoryService $inventory,
    ) {
    }

    public function index(Request $request): View
    {
        $profile = $this->profile($request);

        $products = $profile === null ? collect() : ProductModel::query()
            ->where('tax_profile_id', $profile->getKey())
            ->orderByDesc('inventory_tracked')->orderBy('category')->orderBy('name')
            ->get();

        $positions = [];
        foreach ($products as $product) {
            if ($product->inventory_tracked) {
                $positions[$product->getKey()] = $this->inventory->position($product);
            }
        }

        return view('poland::products', [
            'profile' => $profile,
            'products' => $products,
            'positions' => $positions,
            'units' => StockUnit::cases(),
            'categories' => CostCategory::cases(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $profile = $this->profile($request);
        if ($profile === null) {
            return redirect()->route('poland.products')->withErrors(['profile' => 'Najpierw skonfiguruj profil podatnika.']);
        }

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:40'],
            'name' => ['required', 'string', 'max:200'],
            'category' => ['nullable', 'string', 'max:40'],
            'stock_unit' => ['required', Rule::in(array_map(static fn (StockUnit $u): string => $u->value, StockUnit::cases()))],
            'cost_category' => ['required', Rule::in(array_map(static fn (CostCategory $c): string => $c->value, CostCategory::cases()))],
            'tracked' => ['nullable', 'boolean'],
        ]);

        try {
            $this->catalog->createProduct(
                $profile,
                $validated['code'],
                $validated['name'],
                StockUnit::from($validated['stock_unit']),
                CostCategory::from($validated['cost_category']),
                $validated['category'] ?? null,
                (bool) ($validated['tracked'] ?? false),
                $this->actor($request),
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['product' => $e->getMessage()])->withInput();
        }

        return redirect()->route('poland.products')->with('status', 'Dodano produkt '.$validated['name'].'.');
    }

    public function tracking(Request $request, int $product): RedirectResponse
    {
        $model = $this->product($request, $product);

        $validated = $request->validate([
            'tracked' => ['required', 'boolean'],
            'opening_count' => ['nullable', 'string', 'max:32'],
            'counted_on' => ['nullable', 'date'],
        ]);

        try {
            $opening = isset($validated['opening_count']) && trim((string) $validated['opening_count']) !== ''
                ? StockQuantity::of($validated['opening_count'], $model->stockUnit())
                : null;

            $this->catalog->setTracking(
                $model,
                (bool) $validated['tracked'],
                $this->actor($request),
                $opening,
                isset($validated['counted_on']) ? new \DateTimeImmutable($validated['counted_on']) : null,
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['tracking' => $e->getMessage()]);
        }

        return redirect()->route('poland.products')->with('status', sprintf(
            '%s: śledzenie %s.',
            $model->name,
            (bool) $validated['tracked'] ? 'WŁĄCZONE (od teraz; wcześniejsze zakupy nie są doliczane)' : 'wyłączone',
        ));
    }

    public function count(Request $request, int $product): RedirectResponse
    {
        $model = $this->product($request, $product);

        $validated = $request->validate([
            'quantity' => ['required', 'string', 'max:32'],
            'counted_on' => ['required', 'date'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        try {
            $this->inventory->recordCount(
                $model,
                StockQuantity::of($validated['quantity'], $model->stockUnit()),
                new \DateTimeImmutable($validated['counted_on']),
                $this->actor($request),
                $validated['note'] ?? null,
            );
        } catch (\Throwable $e) {
            return back()->withErrors(['count' => $e->getMessage()]);
        }

        return redirect()->route('poland.products')->with('status', 'Zapisano stan policzony: '.$model->name.'.');
    }

    private function product(Request $request, int $id): ProductModel
    {
        $profile = $this->profile($request);
        abort_if($profile === null, 404);

        return ProductModel::query()->where('tax_profile_id', $profile->getKey())->findOrFail($id);
    }

    private function profile(Request $request): ?TaxProfileModel
    {
        $query = TaxProfileModel::query();
        if ($request->filled('profile')) {
            return $query->find((int) $request->query('profile'));
        }

        return $query->orderBy('id')->first();
    }

    private function actor(Request $request): string
    {
        return (string) ($request->user()?->email ?? $request->user()?->name ?? 'system');
    }
}
