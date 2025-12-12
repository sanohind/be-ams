<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class DailyReportController extends Controller
{
    /**
     * Download daily report PDF
     */
    public function download(Request $request)
    {
        $request->validate([
            'date' => 'required|date_format:Y-m-d',
        ]);
        
        $date = $request->query('date');
        $storagePath = storage_path('app/daily-reports');
        
        // Coba beberapa kemungkinan nama file
        $possibleFiles = [
            $storagePath . "/daily-report-{$date}.pdf",
            $storagePath . "/daily-report-{$date}",
        ];
        
        $fullPath = null;
        foreach ($possibleFiles as $path) {
            if (file_exists($path)) {
                $fullPath = $path;
                break;
            }
        }
        
        // Check if file exists
        if (!$fullPath) {
            return response()->json([
                'success' => false,
                'message' => 'Daily report not found for the selected date',
            ], 404);
        }
        
        try {
            $filename = "daily-report-{$date}.pdf";
            
            // Return file as download
            return response()->download($fullPath, $filename, [
                'Content-Type' => 'application/pdf',
            ]);
        } catch (\Exception $e) {
            Log::error('Error downloading daily report:', [
                'date' => $date,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to download daily report',
            ], 500);
        }
    }
}