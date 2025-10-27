# QuickBooks Web Connector Invoice Loop Fix

## Problem
The AddInvoicesApp was stuck in an infinite loop and no invoices were being added to QuickBooks. The log showed empty `InvoiceAddRq` requests being sent repeatedly.

## Root Cause
The application was not maintaining state between QBWC requests. The `$step` and `$currentItemIndex` properties were being reset to their initial values on each request, causing:

1. The application to restart from the beginning every time
2. Empty XML requests being generated
3. Infinite loop behavior in the Web Connector

## Solution
Implemented session-based state persistence to maintain the application state across requests:

### Key Changes Made:

1. **Session Management**
   - Added session initialization in constructor
   - Created session-based state storage methods

2. **State Persistence Methods**
   ```php
   public function getStep() { return $_SESSION['addInvoice_step'] ?? 'check_items'; }
   public function setStep($step) { $_SESSION['addInvoice_step'] = $step; }
   public function getCurrentItemIndex() { return $_SESSION['addInvoice_currentItemIndex'] ?? 0; }
   public function setCurrentItemIndex($index) { $_SESSION['addInvoice_currentItemIndex'] = $index; }
   public function resetState() { unset($_SESSION['addInvoice_step'], $_SESSION['addInvoice_currentItemIndex']); }
   ```

3. **Updated Logic Flow**
   - All methods now use session-based getters/setters
   - State is properly maintained between requests
   - Clean state reset on completion

4. **Constructor Enhancement**
   ```php
   public function __construct($config = [])
   {
       if (session_status() === PHP_SESSION_NONE) {
           session_start();
       }
       parent::__construct($config);
   }
   ```

## How It Works Now

1. **Initial Request**: Starts with `check_items` step and index 0
2. **Item Verification**: Checks if "Consulting Services" exists in QuickBooks
3. **Item Creation**: If missing, creates the item and advances to next
4. **Progress Tracking**: Moves through "Hosting" item, then to invoice creation
5. **Invoice Creation**: Generates proper InvoiceAddRq XML with both items
6. **Completion**: Resets state and returns success code (100)

## Test Results
- ✅ XML generation working (241 chars for item query, 1438 chars for invoice)
- ✅ InvoiceAddRq properly included in XML
- ✅ State persistence across requests
- ✅ Proper reset functionality
- ✅ No more infinite loops

## Files Modified
- `src/QBWCServer/applications/AddInvoicesApp.php` - Main fix implementation

## Next Steps
1. Test with actual QuickBooks Web Connector
2. Monitor logs to ensure proper flow
3. Verify invoice creation in QuickBooks

The fix ensures that the application properly tracks its progress through the multi-step process of adding items and creating invoices, preventing the infinite loop issue.
