<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\External\ErpStockByWh;
use App\Services\AuthService;

class LevelStockController extends Controller
{
    protected $authService;

    public function __construct(AuthService $authService)
    {
        $this->authService = $authService;
    }

    /**
     * Apply allowed warehouse filter
     */
    protected function applyAllowedWarehouses($query)
    {
        $allowedWarehouses = ['WHMT01', 'WHRM01', 'WHRM02', 'WHFG01', 'WHFG02'];
        return $query->whereIn('warehouse', $allowedWarehouses);
    }

    /**
     * Get stock levels from ERP
     */
    public function index(Request $request)
    {
        $request->validate([
            'warehouse' => 'nullable|string',
            'part_no' => 'nullable|string',
            'status' => 'nullable|in:in_stock,low_stock,out_of_stock,overstock',
            'search' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $query = ErpStockByWh::query();

        // Constrain to allowed warehouses
        $this->applyAllowedWarehouses($query);

        // Filter by warehouse
        if ($request->warehouse) {
            $query->forWarehouse($request->warehouse);
        }

        // Filter by part number
        if ($request->part_no) {
            $query->forPart($request->part_no);
        }

        // Filter by stock status
        if ($request->status) {
            switch ($request->status) {
                case 'in_stock':
                    $query->inStock();
                    break;
                case 'low_stock':
                    $query->lowStock();
                    break;
                case 'out_of_stock':
                    $query->outOfStock();
                    break;
                case 'overstock':
                    $query->whereRaw('onhand >= max_stock');
                    break;
            }
        }

        // Search functionality
        if ($request->search) {
            $searchTerm = $request->search;
            $query->where(function ($q) use ($searchTerm) {
                $q->where('partno', 'like', "%{$searchTerm}%")
                  ->orWhere('partname', 'like', "%{$searchTerm}%")
                  ->orWhere('desc', 'like', "%{$searchTerm}%")
                  ->orWhere('oldpartno', 'like', "%{$searchTerm}%");
            });
        }

        $perPage = $request->get('per_page', 50);
        $stocks = $query->orderBy('partno')->paginate($perPage);

        // Transform data for frontend
        $transformedStocks = $stocks->map(function ($stock) {
            return [
                'warehouse' => $stock->warehouse,
                'part_no' => $stock->partno,
                'description' => $stock->desc,
                'part_name' => $stock->partname,
                'old_part_no' => $stock->oldpartno,
                'group' => $stock->group,
                'group_key' => $stock->groupkey,
                'product_type' => $stock->product_type,
                'model' => $stock->model,
                'customer' => $stock->customer,
                'onhand' => $stock->onhand,
                'allocated' => $stock->allocated,
                'onorder' => $stock->onorder,
                'economic_stock' => $stock->economicstock,
                'safety_stock' => $stock->safety_stock,
                'min_stock' => $stock->min_stock,
                'max_stock' => $stock->max_stock,
                'unit' => $stock->unit,
                'location' => $stock->location,
                'available_stock' => $stock->available_stock,
                'stock_status' => $stock->stock_status,
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'stocks' => $transformedStocks,
                'pagination' => [
                    'current_page' => $stocks->currentPage(),
                    'last_page' => $stocks->lastPage(),
                    'per_page' => $stocks->perPage(),
                    'total' => $stocks->total(),
                    'from' => $stocks->firstItem(),
                    'to' => $stocks->lastItem(),
                ]
            ]
        ]);
    }

    /**
     * Get stock summary statistics
     */
    public function getSummary(Request $request)
    {
        $request->validate([
            'warehouse' => 'nullable|string',
        ]);

        $query = ErpStockByWh::query();

        // Constrain to allowed warehouses
        $this->applyAllowedWarehouses($query);

        if ($request->warehouse) {
            $query->forWarehouse($request->warehouse);
        }

        $totalItems = $query->count();
        $inStockItems = $query->clone()->inStock()->count();
        $lowStockItems = $query->clone()->lowStock()->count();
        $outOfStockItems = $query->clone()->outOfStock()->count();
        $overstockItems = $query->clone()->whereRaw('onhand >= max_stock')->count();

        $totalOnhand = $query->clone()->sum('onhand');
        $totalAllocated = $query->clone()->sum('allocated');
        $totalOnorder = $query->clone()->sum('onorder');
        $totalAvailable = $totalOnhand - $totalAllocated;

        return response()->json([
            'success' => true,
            'data' => [
                'warehouse' => $request->warehouse ?? 'All',
                'total_items' => $totalItems,
                'in_stock_items' => $inStockItems,
                'low_stock_items' => $lowStockItems,
                'out_of_stock_items' => $outOfStockItems,
                'overstock_items' => $overstockItems,
                'total_onhand' => $totalOnhand,
                'total_allocated' => $totalAllocated,
                'total_onorder' => $totalOnorder,
                'total_available' => $totalAvailable,
                'in_stock_percentage' => $totalItems > 0 ? round(($inStockItems / $totalItems) * 100, 2) : 0,
                'low_stock_percentage' => $totalItems > 0 ? round(($lowStockItems / $totalItems) * 100, 2) : 0,
                'out_of_stock_percentage' => $totalItems > 0 ? round(($outOfStockItems / $totalItems) * 100, 2) : 0,
            ]
        ]);
    }

    /**
     * Get warehouses list
     */
    public function getWarehouses()
    {
        $warehouses = ErpStockByWh::select('warehouse')
            ->distinct()
            ->whereIn('warehouse', ['WHMT01', 'WHRM01', 'WHRM02', 'WHFG01', 'WHFG02'])
            ->orderBy('warehouse')
            ->pluck('warehouse');

        return response()->json([
            'success' => true,
            'data' => $warehouses
        ]);
    }

    /**
     * Get low stock alerts
     */
    public function getLowStockAlerts(Request $request)
    {
        $request->validate([
            'warehouse' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:100',
        ]);

        $query = ErpStockByWh::lowStock();

        // Constrain to allowed warehouses
        $this->applyAllowedWarehouses($query);

        if ($request->warehouse) {
            $query->forWarehouse($request->warehouse);
        }

        $limit = $request->get('limit', 20);
        $lowStockItems = $query->orderBy('onhand', 'asc')
            ->limit($limit)
            ->get();

        $alerts = $lowStockItems->map(function ($stock) {
            return [
                'part_no' => $stock->partno,
                'part_name' => $stock->partname,
                'warehouse' => $stock->warehouse,
                'onhand' => $stock->onhand,
                'min_stock' => $stock->min_stock,
                'safety_stock' => $stock->safety_stock,
                'unit' => $stock->unit,
                'location' => $stock->location,
                'stock_status' => $stock->stock_status,
                'shortage' => max(0, $stock->min_stock - $stock->onhand),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => [
                'alerts' => $alerts,
                'total_alerts' => $alerts->count(),
            ]
        ]);
    }

    /**
     * Export stock data
     */
    public function export(Request $request)
    {
        $request->validate([
            'warehouse' => 'nullable|string',
            'format' => 'required|in:csv,excel',
        ]);

        $query = ErpStockByWh::query();

        // Constrain to allowed warehouses
        $this->applyAllowedWarehouses($query);

        if ($request->warehouse) {
            $query->forWarehouse($request->warehouse);
        }

        $stocks = $query->orderBy('partno')->get();

        // Transform data for export
        $exportData = $stocks->map(function ($stock) {
            return [
                'Warehouse' => $stock->warehouse,
                'Part No' => $stock->partno,
                'Description' => $stock->desc,
                'Part Name' => $stock->partname,
                'Old Part No' => $stock->oldpartno,
                'Group' => $stock->group,
                'Product Type' => $stock->product_type,
                'Model' => $stock->model,
                'Customer' => $stock->customer,
                'On Hand' => $stock->onhand,
                'Allocated' => $stock->allocated,
                'On Order' => $stock->onorder,
                'Available' => $stock->available_stock,
                'Safety Stock' => $stock->safety_stock,
                'Min Stock' => $stock->min_stock,
                'Max Stock' => $stock->max_stock,
                'Unit' => $stock->unit,
                'Location' => $stock->location,
                'Status' => $stock->stock_status,
            ];
        });

        $filename = 'stock_levels_' . ($request->warehouse ?? 'all') . '_' . now()->format('Y-m-d_H-i-s');

        if ($request->format === 'csv') {
            return $this->exportToCsv($exportData, $filename);
        } else {
            return $this->exportToExcel($exportData, $filename);
        }
    }

    /**
     * Export to CSV
     */
    protected function exportToCsv($data, $filename)
    {
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}.csv\"",
        ];

        $callback = function () use ($data) {
            $file = fopen('php://output', 'w');
            
            // Write headers
            if ($data->isNotEmpty()) {
                fputcsv($file, array_keys($data->first()));
            }
            
            // Write data
            foreach ($data as $row) {
                fputcsv($file, $row);
            }
            
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export to Excel (simplified - would need proper Excel library)
     */
    protected function exportToExcel($data, $filename)
    {
        // This is a simplified version - in production, use Laravel Excel package
        return response()->json([
            'success' => false,
            'message' => 'Excel export not implemented. Please use CSV format.'
        ], 501);
    }
}
