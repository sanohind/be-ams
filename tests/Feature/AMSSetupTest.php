<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\ArrivalTransaction;
use App\Models\ArrivalSchedule;
use App\Models\Setting;
use App\Models\External\SphereUser;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AMSSetupTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test database connections
     */
    public function test_database_connections()
    {
        // Test AMS database connection
        $this->assertDatabaseHas('arrival_schedule', []);
        
        // Test Sphere database connection
        $this->assertDatabaseHas('users', [], 'sphere');
        
        // Test SCM database connection
        $this->assertDatabaseHas('dn_header', [], 'scm');
        
        // Test Visitor database connection
        $this->assertDatabaseHas('visitor', [], 'visitor');
    }

    /**
     * Test model relationships
     */
    public function test_model_relationships()
    {
        // Create test data
        $schedule = ArrivalSchedule::create([
            'bp_code' => 'TEST001',
            'day_name' => 'monday',
            'arrival_type' => 'regular',
            'arrival_time' => '08:00:00',
            'departure_time' => '17:00:00',
            'dock' => 'DOCK01',
        ]);

        $transaction = ArrivalTransaction::create([
            'dn_number' => 'DN001',
            'po_number' => 'PO001',
            'arrival_type' => 'regular',
            'plan_delivery_date' => now()->toDateString(),
            'plan_delivery_time' => '08:00:00',
            'bp_code' => 'TEST001',
            'driver_name' => 'John Doe',
            'vehicle_plate' => 'B1234ABC',
            'schedule_id' => $schedule->id,
            'status' => 'pending',
        ]);

        // Test relationship
        $this->assertEquals($schedule->id, $transaction->schedule->id);
        $this->assertTrue($schedule->arrivalTransactions->contains($transaction));
    }

    /**
     * Test settings functionality
     */
    public function test_settings_functionality()
    {
        // Test setting value
        Setting::setValue('test_key', 'test_value', 'Test setting');
        
        $this->assertEquals('test_value', Setting::getValue('test_key'));
        $this->assertTrue(Setting::exists('test_key'));
        
        // Test setting deletion
        Setting::deleteByKey('test_key');
        $this->assertFalse(Setting::exists('test_key'));
    }

    /**
     * Test API health check
     */
    public function test_api_health_check()
    {
        $response = $this->get('/api/public/health');
        
        $response->assertStatus(200);
        $response->assertJson([
            'status' => 'ok',
            'service' => 'AMS API'
        ]);
    }

    /**
     * Test JWT middleware
     */
    public function test_jwt_middleware()
    {
        $response = $this->get('/api/dashboard');
        
        $response->assertStatus(401);
        $response->assertJson([
            'success' => false,
            'message' => 'Token not provided'
        ]);
    }
}
