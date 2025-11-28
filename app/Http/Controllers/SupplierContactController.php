<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SupplierContactController extends Controller
{
    /**
     * Get supplier contacts from SCM database
     */
    public function index(Request $request)
    {
        $search = $request->get('search');

        $query = DB::connection('scm')
            ->table('business_partner as bp')
            ->leftJoin('business_partner_email as bpe', 'bp.bp_code', '=', 'bpe.partner_id')
            ->leftJoin('email as e', 'bpe.email_id', '=', 'e.email_id')
            ->select(
                'bp.bp_code',
                'bp.bp_name',
                'bp.bp_status_desc',
                'bp.bp_phone',
                'bp.bp_fax',
                DB::raw('GROUP_CONCAT(DISTINCT e.email ORDER BY e.email SEPARATOR ",") as emails')
            )
            ->whereNotNull('bp.bp_name');

        if ($search) {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like) {
                $q->where('bp.bp_code', 'like', $like)
                    ->orWhere('bp.bp_name', 'like', $like)
                    ->orWhere('bp.bp_status_desc', 'like', $like)
                    ->orWhere('bp.bp_phone', 'like', $like)
                    ->orWhere('bp.bp_fax', 'like', $like);
            });
        }

        $suppliers = $query
            ->groupBy('bp.bp_code', 'bp.bp_name', 'bp.bp_status_desc', 'bp.bp_phone', 'bp.bp_fax')
            ->orderBy('bp.bp_name')
            ->get()
            ->map(function ($row) {
                return [
                    'bp_code' => $row->bp_code,
                    'bp_name' => $row->bp_name,
                    'status' => $row->bp_status_desc,
                    'phone' => $row->bp_phone,
                    'fax' => $row->bp_fax,
                    'emails' => $row->emails ? array_values(array_filter(array_map('trim', explode(',', $row->emails)))) : [],
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'data' => $suppliers,
        ]);
    }
}

