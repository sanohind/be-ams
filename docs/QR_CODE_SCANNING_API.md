# AMS API Documentation - QR Code Scanning

## Overview
This document describes the QR code scanning functionality in the AMS (Arrival Management System) API.

## QR Code Formats

### DN QR Code
**Format**: `DN0030176`
- Starts with "DN" followed by numbers
- Used to identify Delivery Note
- Must be scanned first to start scanning session

### Item QR Code
**Format**: `RL1IN047371BZ3000000;450;PL2502055080801018;TMI;7;1;DN0030176;4`

**Fields**:
1. **Part Number**: `RL1IN047371BZ3000000`
2. **Quantity**: `450`
3. **Lot Number**: `PL2502055080801018`
4. **Customer**: `TMI` (can be empty)
5. **Field 5**: `7` (additional field)
6. **Field 6**: `1` (additional field)
7. **DN Number**: `DN0030176` (must match session DN)
8. **Field 8**: `4` (additional field)

## API Endpoints

### 1. Scan DN QR Code
**POST** `/api/item-scan/scan-dn`

**Headers**:
```
Authorization: Bearer <jwt-token>
Content-Type: application/json
```

**Request Body**:
```json
{
    "arrival_id": 1,
    "qr_data": "DN0030176"
}
```

**Response** (Success):
```json
{
    "success": true,
    "message": "DN scanned successfully, ready for item scanning",
    "data": {
        "session": {
            "id": 1,
            "arrival_id": 1,
            "dn_number": "DN0030176",
            "operator_id": 1,
            "session_start": "2025-01-29T10:00:00.000000Z",
            "status": "in_progress"
        },
        "dn_number": "DN0030176"
    }
}
```

**Response** (Error):
```json
{
    "success": false,
    "message": "Invalid DN QR code format"
}
```

### 2. Scan Item QR Code
**POST** `/api/item-scan/scan-item`

**Headers**:
```
Authorization: Bearer <jwt-token>
Content-Type: application/json
```

**Request Body**:
```json
{
    "session_id": 1,
    "qr_data": "RL1IN047371BZ3000000;450;PL2502055080801018;TMI;7;1;DN0030176;4"
}
```

**Response** (Success):
```json
{
    "success": true,
    "message": "Item scanned successfully",
    "data": {
        "scanned_item": {
            "id": 1,
            "session_id": 1,
            "arrival_id": 1,
            "dn_number": "DN0030176",
            "part_no": "RL1IN047371BZ3000000",
            "scanned_quantity": 450,
            "lot_number": "PL2502055080801018",
            "customer": "TMI",
            "expected_quantity": 450,
            "match_status": "matched",
            "scanned_at": "2025-01-29T10:05:00.000000Z"
        },
        "match_status": "matched",
        "quantity_variance": 0
    }
}
```

**Response** (Error):
```json
{
    "success": false,
    "message": "Item DN does not match session DN"
}
```

### 3. Complete Scanning Session
**POST** `/api/item-scan/complete-session`

**Headers**:
```
Authorization: Bearer <jwt-token>
Content-Type: application/json
```

**Request Body**:
```json
{
    "session_id": 1,
    "label_part_status": "OK",
    "coa_msds_status": "OK",
    "packing_condition_status": "OK"
}
```

**Response** (Success):
```json
{
    "success": true,
    "message": "Scanning session completed successfully",
    "data": {
        "session": {
            "id": 1,
            "arrival_id": 1,
            "dn_number": "DN0030176",
            "status": "completed",
            "session_end": "2025-01-29T10:30:00.000000Z",
            "session_duration": 30
        },
        "total_items_scanned": 5,
        "session_duration": 30
    }
}
```

## Scanning Process Flow

1. **Start Session**: Scan DN QR code to create scanning session
2. **Scan Items**: Scan individual item QR codes
3. **Validation**: System validates:
   - DN matches the session DN
   - Part number and lot number combination is unique
   - Expected quantity matches SCM data
4. **Complete Session**: Finish scanning and complete quality checks

## Error Handling

### Common Error Responses

**Invalid QR Format**:
```json
{
    "success": false,
    "message": "Invalid DN QR code format"
}
```

**DN Mismatch**:
```json
{
    "success": false,
    "message": "Item DN does not match session DN"
}
```

**Duplicate Item**:
```json
{
    "success": false,
    "message": "Item with this part number and lot number already scanned"
}
```

**Session Not Active**:
```json
{
    "success": false,
    "message": "Session is not in progress"
}
```

## Validation Rules

### DN QR Code Validation
- Must start with "DN"
- Followed by one or more digits
- No spaces or special characters

### Item QR Code Validation
- Must contain exactly 8 fields separated by semicolons
- Field 2 (quantity) must be numeric
- Field 7 (DN number) must match session DN
- Field 4 (customer) can be empty

## Security Considerations

1. **Authentication Required**: All endpoints require valid JWT token
2. **Role-Based Access**: Only `operator-warehouse` and `superadmin` roles can access
3. **Session Validation**: Items can only be scanned within active sessions
4. **DN Verification**: Items must belong to the correct DN

## Testing

Use the provided test cases in `tests/Feature/QRCodeParsingTest.php` to verify QR code parsing functionality.

### Test QR Codes

**Valid DN QR Codes**:
- `DN0030176`
- `DN123456`

**Valid Item QR Codes**:
- `RL1IN047371BZ3000000;450;PL2502055080801018;TMI;7;1;DN0030176;4`
- `PART123;100;LOT456;;5;2;DN0030176;3`

**Invalid QR Codes**:
- `INVALID`
- `DN` (incomplete)
- `part;qty` (insufficient fields)
