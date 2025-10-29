<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Http\Controllers\ItemScanController;
use Illuminate\Foundation\Testing\RefreshDatabase;

class QRCodeParsingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test DN QR code parsing
     */
    public function test_dn_qr_code_parsing()
    {
        $controller = new ItemScanController(app(\App\Services\AuthService::class));
        
        // Test valid DN QR codes
        $this->assertEquals('DN0030176', $this->invokeMethod($controller, 'parseDnQrData', ['DN0030176']));
        $this->assertEquals('DN123456', $this->invokeMethod($controller, 'parseDnQrData', ['DN123456']));
        
        // Test invalid DN QR codes
        $this->assertNull($this->invokeMethod($controller, 'parseDnQrData', ['INVALID']));
        $this->assertNull($this->invokeMethod($controller, 'parseDnQrData', ['DN']));
        $this->assertNull($this->invokeMethod($controller, 'parseDnQrData', ['123456']));
        $this->assertNull($this->invokeMethod($controller, 'parseDnQrData', ['']));
    }

    /**
     * Test item QR code parsing
     */
    public function test_item_qr_code_parsing()
    {
        $controller = new ItemScanController(app(\App\Services\AuthService::class));
        
        // Test valid item QR code
        $qrData = 'RL1IN047371BZ3000000;450;PL2502055080801018;TMI;7;1;DN0030176;4';
        $result = $this->invokeMethod($controller, 'parseItemQrData', [$qrData]);
        
        $this->assertNotNull($result);
        $this->assertEquals('RL1IN047371BZ3000000', $result['part_no']);
        $this->assertEquals(450, $result['quantity']);
        $this->assertEquals('PL2502055080801018', $result['lot_number']);
        $this->assertEquals('TMI', $result['customer']);
        $this->assertEquals('7', $result['field5']);
        $this->assertEquals('1', $result['field6']);
        $this->assertEquals('DN0030176', $result['dn_number']);
        $this->assertEquals('4', $result['field8']);
        
        // Test item QR code with empty customer
        $qrDataEmptyCustomer = 'RL1IN047371BZ3000000;450;PL2502055080801018;;7;1;DN0030176;4';
        $resultEmptyCustomer = $this->invokeMethod($controller, 'parseItemQrData', [$qrDataEmptyCustomer]);
        
        $this->assertNotNull($resultEmptyCustomer);
        $this->assertNull($resultEmptyCustomer['customer']);
        
        // Test invalid item QR codes
        $this->assertNull($this->invokeMethod($controller, 'parseItemQrData', ['INVALID']));
        $this->assertNull($this->invokeMethod($controller, 'parseItemQrData', ['part;qty']));
        $this->assertNull($this->invokeMethod($controller, 'parseItemQrData', ['']));
    }

    /**
     * Test QR code scanning API endpoints
     */
    public function test_qr_code_scanning_endpoints()
    {
        // Test DN scanning endpoint (should require authentication)
        $response = $this->postJson('/api/item-scan/scan-dn', [
            'arrival_id' => 1,
            'qr_data' => 'DN0030176'
        ]);
        
        $response->assertStatus(401); // Unauthorized without JWT token
        
        // Test item scanning endpoint (should require authentication)
        $response = $this->postJson('/api/item-scan/scan-item', [
            'session_id' => 1,
            'qr_data' => 'RL1IN047371BZ3000000;450;PL2502055080801018;TMI;7;1;DN0030176;4'
        ]);
        
        $response->assertStatus(401); // Unauthorized without JWT token
    }

    /**
     * Helper method to invoke private methods for testing
     */
    private function invokeMethod($object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
